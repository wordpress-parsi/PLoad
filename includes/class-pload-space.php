<?php
/**
 * Disk space: an estimate before starting, and a guard while running.
 *
 * Measuring every file of a large library would itself be heavy, so a random
 * sample of attachments is measured and scaled up. PHP can only see free
 * space on the whole disk, not a shared-hosting account quota, so the
 * estimate is shown to the user to compare with their plan.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Space {

	const ESTIMATE     = 'pload_space';       // option: last estimate
	const PAUSE_REASON = 'pload_pause_reason'; // option: why the queue paused itself
	const SAMPLE       = 300;
	const RESERVE      = 1073741824;         // Keep at least 1 GB free.
	const RATIO        = 0.75;               // WebP size vs source, used until real data exists.

	/** @return array|null */
	public static function get() {
		$e = get_option( self::ESTIMATE, null );
		return is_array( $e ) ? $e : null;
	}

	/** @var array|false|null Per-request cache of quota(). */
	private static $quota = null;

	/**
	 * Free bytes for new files, or null when the host hides it. The cPanel
	 * account quota wins when it is known, since the server disk can be
	 * far bigger than the plan.
	 */
	public static function free() {
		$disk = self::disk_free();
		$q    = self::quota();
		if ( $q ) {
			return null === $disk ? $q['free'] : min( $disk, $q['free'] );
		}
		return $disk;
	}

	/** Free bytes on the uploads disk, or null when the host hides it. */
	public static function disk_free() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$dir  = wp_get_upload_dir();
		$free = @disk_free_space( $dir['basedir'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Many hosts disable this function; null is handled.
		return false === $free ? null : (float) $free;
	}

	/**
	 * The cPanel account quota, read from the cache file cPanel keeps in the
	 * account's home folder (~/.cpanel/datastore/_Cpanel::Quota.pm__USER).
	 * Its "data" holds: bytes used, byte limit, bytes left, files used,
	 * file limit, files left. cPanel refreshes it when its pages load, so
	 * the age is shown next to the numbers.
	 *
	 * @return array|null [used, limit, free, t] or null when unknown or unlimited.
	 */
	public static function quota() {
		if ( null !== self::$quota ) {
			return self::$quota ? self::$quota : null;
		}
		self::$quota = false;

		foreach ( self::home_candidates() as $home ) {
			$files = @glob( $home . '/.cpanel/datastore/_Cpanel::Quota.pm__*', GLOB_NOSORT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may forbid the folder; that just means "unknown".
			if ( ! $files ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( ! @is_readable( $file ) || @filesize( $file ) > 4096 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same as above.
					continue;
				}
				$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A small local file.
				$d    = is_array( $json ) && isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : null;
				if ( ! $d || ! isset( $d[0], $d[1] ) || ! is_numeric( $d[0] ) || ! is_numeric( $d[1] ) || (float) $d[1] <= 0 ) {
					continue; // Missing, malformed, or an unlimited plan.
				}
				$used        = (float) $d[0];
				$limit       = (float) $d[1];
				self::$quota = array(
					'used'  => $used,
					'limit' => $limit,
					'free'  => max( 0, $limit - $used ),
					't'     => (int) filemtime( $file ),
				);
				return self::$quota;
			}
		}
		return null;
	}

	/** Where the account home folder may be, without guessing outside it. */
	private static function home_candidates() {
		$list = array();
		$env  = getenv( 'HOME' );
		if ( $env ) {
			$list[] = $env;
		}
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$pw = posix_getpwuid( posix_geteuid() );
			if ( is_array( $pw ) && ! empty( $pw['dir'] ) ) {
				$list[] = $pw['dir'];
			}
		}
		// /home/USER/public_html/... → /home/USER
		$path = str_replace( '\\', '/', ABSPATH );
		if ( preg_match( '#^(.+?)/(public_html|www)/#', $path, $m ) ) {
			$list[] = $m[1];
		}
		$list = (array) apply_filters( 'pload_home_dirs', $list );
		$list = array_unique( array_map( 'untrailingslashit', $list ) );
		return array_values( array_filter( $list, function ( $dir ) {
			return '' !== $dir && '/' !== $dir && '/root' !== $dir;
		} ) );
	}

	/**
	 * True when the site looks like a shared-hosting account (cPanel,
	 * DirectAdmin, Plesk). There, disk_free_space() reports the whole server
	 * disk, while the real limit is the account quota, which PHP can't see.
	 */
	public static function shared() {
		$dir  = wp_get_upload_dir();
		$path = str_replace( '\\', '/', (string) $dir['basedir'] );
		$hit  = (bool) preg_match( '#/(public_html|httpdocs|private_html)/#', $path );
		return (bool) apply_filters( 'pload_shared_hosting', $hit );
	}

	/**
	 * Measures a random sample of attachments (time-boxed) and scales it up.
	 */
	public static function estimate() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off sampling on request.
		$range = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(ID) AS lo, MAX(ID) AS hi, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s)", Pload_Converter::MIMES[0], Pload_Converter::MIMES[1] ) );
		$total = $range ? (int) $range->n : 0;

		$ids = array();
		if ( $total > 0 ) {
			// Random points in the ID range, each resolved to the next image:
			// avoids ORDER BY RAND() over the whole table.
			$tries = 0;
			while ( count( $ids ) < min( self::SAMPLE, $total ) && $tries < self::SAMPLE * 3 ) {
				$tries++;
				$at = wp_rand( (int) $range->lo, (int) $range->hi );
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s) AND ID >= %d ORDER BY ID ASC LIMIT 1", Pload_Converter::MIMES[0], Pload_Converter::MIMES[1], $at ) );
				if ( $id ) {
					$ids[ $id ] = true;
				}
			}
		}

		// phpcs:enable

		$deadline = microtime( true ) + 8;
		$n        = 0;
		$bytes    = 0;
		$files    = 0;
		$missing  = 0;
		foreach ( array_keys( $ids ) as $id ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$n++;
			foreach ( Pload_Converter::files_for( $id ) as $f ) {
				if ( is_file( $f['path'] ) ) {
					$bytes += (int) filesize( $f['path'] );
					$files++;
				} else {
					$missing++;
				}
			}
		}

		$per    = $n ? $bytes / $n : 0;
		$result = array(
			't'          => time(),
			'total'      => $total,
			'sampled'    => $n,
			'files_per'  => $n ? $files / $n : 0,
			'missing'    => $n ? $missing / max( 1, $files + $missing ) : 0, // share of listed files that are gone
			'source'     => $per * $total,
			'free'       => self::free(),
		);
		update_option( self::ESTIMATE, $result, false );
		Pload_Logger::log( 'info', sprintf( 'Space estimate from %d sampled images: about %s of image files.', $n, size_format( $result['source'] ) ) );
		return $result;
	}

	/**
	 * WebP size compared with the source. The first files converted are not
	 * a fair sample (e.g. the newest images first), so real data is blended
	 * in gradually and only fully trusted after 5,000 files.
	 */
	public static function ratio() {
		$b = Pload_Stats::bytes();
		if ( $b['in'] <= 0 || $b['files'] <= 0 ) {
			return self::RATIO;
		}
		$real = min( 1, $b['out'] / $b['in'] );
		$w    = min( 1, $b['files'] / 5000 );
		return $w * $real + ( 1 - $w ) * self::RATIO;
	}

	/**
	 * Space still needed for the images not handled yet.
	 *
	 * On shared hosting a roomy server disk says nothing about the quota,
	 * so "ok" becomes "unknown" there, unless the cPanel quota was read.
	 *
	 * @return array|null [needed, free, level: ok|tight|short|unknown, shared, quota]
	 */
	public static function outlook() {
		$e = self::get();
		if ( ! $e || ! $e['total'] ) {
			return null;
		}
		$c      = Pload_Stats::counts();
		$left   = $c['total'] > 0 ? $c['waiting'] / $c['total'] : 1;
		$needed = $e['source'] * self::ratio() * $left;
		$free   = self::free();

		$level = 'ok';
		if ( null !== $free ) {
			if ( $needed > $free - self::RESERVE ) {
				$level = 'short';
			} elseif ( $needed > ( $free - self::RESERVE ) * 0.5 ) {
				$level = 'tight';
			}
		}
		$quota  = self::quota();
		$shared = self::shared();
		if ( 'ok' === $level && ! $quota && ( $shared || null === $free ) ) {
			$level = 'unknown';
		}
		return array( 'needed' => $needed, 'free' => $free, 'level' => $level, 'shared' => $shared && ! $quota, 'quota' => $quota );
	}

	/** True when the disk is too full to keep writing. */
	public static function too_low() {
		$free = self::free();
		return null !== $free && $free < self::RESERVE;
	}

	public static function pause_reason() {
		$r = get_option( self::PAUSE_REASON, null );
		return is_array( $r ) ? $r : null;
	}
}
