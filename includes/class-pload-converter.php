<?php
/**
 * Converts one attachment, one image size at a time.
 *
 * Output sits next to the source: photo-300x200.jpg -> photo-300x200.jpg.webp
 * The source is never modified. The unscaled original WordPress keeps after
 * creating "-scaled" (original_image) is skipped: it is never shown on the
 * site, and it is by far the most memory-hungry file.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Converter {

	const META    = '_pload';
	const STATUS  = '_pload_status';
	const EXCLUDE = '_pload_exclude';
	const MIMES   = array( 'image/jpeg', 'image/png' ); // SQL uses "post_mime_type IN (%s, %s)" with these; keep the counts in step.
	const QUALITY_BEFORE_05 = 80; // Records without a stored quality were made with this.
	const TRIES   = 3;

	/** Size states that need no more work while the source is unchanged. */
	const FINAL_STATES = array( 'ok', 'larger', 'big' );

	/** @var Pload_Driver */
	private $driver;

	/** @var int */
	private $quality;

	/** @var int Percent. */
	private $min_saving;

	public function __construct( Pload_Driver $driver ) {
		$this->driver     = $driver;
		$this->quality    = Pload_Settings::quality();
		$this->min_saving = Pload_Settings::min_saving();
	}

	/**
	 * @param int      $id
	 * @param Pload_Run $run Shared budget for the current run.
	 * @return bool True when the attachment is finished (any outcome),
	 *              false when the run ran out of time part-way.
	 */
	public function process( $id, Pload_Run $run ) {
		if ( get_post_meta( $id, self::EXCLUDE, true ) ) {
			self::exclude( $id );
			return true;
		}

		$mime = get_post_mime_type( $id );
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			update_post_meta( $id, self::STATUS, 'skipped' );
			return true;
		}

		$files = self::files_for( $id );
		if ( ! $files || ! file_exists( $files['full']['path'] ) ) {
			update_post_meta( $id, self::STATUS, 'failed' );
			update_post_meta( $id, self::META, array( 'sizes' => array(), 'tries' => self::TRIES, 'err' => 'missing_file', 't' => time() ) );
			Pload_Logger::log( 'warning', sprintf( 'Attachment #%d: source file not found, skipped.', $id ) );
			return true;
		}

		$detail = get_post_meta( $id, self::META, true );
		if ( ! is_array( $detail ) ) {
			$detail = array();
		}
		$detail = wp_parse_args( $detail, array( 'sizes' => array(), 'tries' => 0 ) );

		$errors = array();
		$d_in   = 0;
		$d_out  = 0;
		$d_n    = 0;

		foreach ( $files as $key => $f ) {
			$path = $f['path'];
			$rec  = isset( $detail['sizes'][ $key ] ) ? $detail['sizes'][ $key ] : null;

			if ( ! file_exists( $path ) ) {
				$detail['sizes'][ $key ] = array( 's' => 'missing' );
				continue;
			}

			$mt = (int) filemtime( $path );

			if ( $rec && isset( $rec['mt'] ) && (int) $rec['mt'] === $mt && in_array( $rec['s'], self::FINAL_STATES, true ) ) {
				$q_used  = isset( $rec['q'] ) ? (int) $rec['q'] : self::QUALITY_BEFORE_05;
				$ms_used = isset( $rec['ms'] ) ? (int) $rec['ms'] : 0; // Before 0.5: kept if smaller at all.
				$q_same  = 'big' === $rec['s'] || ( $q_used === $this->quality && $ms_used === $this->min_saving );
				$present = 'ok' !== $rec['s'] || file_exists( $path . '.webp' );
				if ( $q_same && $present ) {
					continue; // Nothing changed since last time.
				}
			}

			$w = (int) $f['w'];
			$h = (int) $f['h'];
			if ( ! $w || ! $h ) {
				$dims = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged file must not stop the run; false is handled.
				$w    = $dims ? (int) $dims[0] : 0;
				$h    = $dims ? (int) $dims[1] : 0;
			}
			$mp = ( $w * $h ) / 1000000;

			if ( $mp > $run->max_mp ) {
				$detail['sizes'][ $key ] = array( 's' => 'big', 'mt' => $mt );
				continue;
			}

			$estimate = $run->ms_per_mp * $mp * 1.5 + 150;

			if ( $run->files > 0 && $run->ms_left() < $estimate ) {
				// Out of time: keep what is done, continue next run.
				$this->flush( $id, $detail, $d_in, $d_out, $d_n );
				return false;
			}
			if ( 0 === $run->files && $estimate > $run->hard_cap_ms ) {
				$detail['sizes'][ $key ] = array( 's' => 'big', 'mt' => $mt );
				continue;
			}

			// Replacing an earlier conversion: take it out of the totals first,
			// and remove its file if the size now points at a different file.
			if ( $rec && 'ok' === $rec['s'] ) {
				$d_in  -= (int) $rec['in'];
				$d_out -= (int) $rec['out'];
				$d_n--;
				self::delete_old( $path, $rec );
			}

			$dest  = $path . '.webp';
			$error = $this->driver->make( $path, $dest, $this->quality, $mime );
			$run->files++;

			// Failures to write (not to read the source) in a row suggest a full disk.
			if ( in_array( $error, array( 'encode_failed', 'rename_failed', 'empty', 'missing' ), true ) ) {
				$run->write_fail++;
				if ( $run->write_fail >= 5 ) {
					$detail['sizes'][ $key ] = array( 's' => 'error', 'e' => $error, 'mt' => $mt );
					$this->flush( $id, $detail, $d_in, $d_out, $d_n );
					return false;
				}
			} elseif ( '' === $error ) {
				$run->write_fail = 0;
			}

			if ( '' !== $error ) {
				// A WebP left from an older version of this file would now be stale.
				if ( file_exists( $dest ) ) {
					wp_delete_file( $dest );
				}
				$detail['sizes'][ $key ] = array( 's' => 'error', 'e' => $error, 'mt' => $mt );
				$errors[]                = $key . ': ' . $error;
				continue;
			}

			clearstatcache( true, $dest );
			$in  = (int) filesize( $path );
			$out = (int) filesize( $dest );

			if ( $out > $in * ( 1 - $this->min_saving / 100 ) ) {
				// Not worth it (common for tiny PNGs): keep the original only.
				wp_delete_file( $dest );
				$detail['sizes'][ $key ] = array( 's' => 'larger', 'mt' => $mt, 'q' => $this->quality, 'ms' => $this->min_saving );
				continue;
			}

			$detail['sizes'][ $key ] = array( 's' => 'ok', 'in' => $in, 'out' => $out, 'mt' => $mt, 'f' => wp_basename( $path ), 'q' => $this->quality, 'ms' => $this->min_saving );
			$d_in  += $in;
			$d_out += $out;
			$d_n++;
			$run->saved += $in - $out;
		}

		// Sizes that no longer exist (e.g. after a theme change): remove
		// their WebP files and take them out of the totals.
		foreach ( array_diff_key( $detail['sizes'], $files ) as $rec ) {
			if ( isset( $rec['s'] ) && 'ok' === $rec['s'] ) {
				$d_in  -= (int) $rec['in'];
				$d_out -= (int) $rec['out'];
				$d_n--;
				self::delete_old( $files['full']['path'], $rec, true );
			}
		}
		$detail['sizes'] = array_intersect_key( $detail['sizes'], $files );

		if ( $errors ) {
			$detail['tries']++;
			$detail['err'] = implode( '; ', $errors );
			$status        = 'failed';
			Pload_Logger::log( 'error', sprintf( 'Attachment #%d (try %d/%d): %s', $id, $detail['tries'], self::TRIES, $detail['err'] ) );
			if ( $detail['tries'] < self::TRIES ) {
				Pload_Queue::retry_later( $id, $detail['tries'] );
			}
		} else {
			$detail['tries'] = 0;
			unset( $detail['err'] );
			$status = 'done';
		}

		$detail['t'] = time();
		$this->flush( $id, $detail, $d_in, $d_out, $d_n );
		update_post_meta( $id, self::STATUS, $status );
		return true;
	}

	private function flush( $id, $detail, $in, $out, $n ) {
		update_post_meta( $id, self::META, $detail );
		Pload_Stats::add( $in, $out, $n );
	}

	/**
	 * Deletes the WebP of an earlier conversion recorded in $rec when it
	 * belongs to a different source file than $path (or always, if $always).
	 */
	private static function delete_old( $path, $rec, $always = false ) {
		if ( empty( $rec['f'] ) ) {
			return;
		}
		if ( ! $always && wp_basename( $path ) === $rec['f'] ) {
			return; // Same file: it is overwritten in place.
		}
		$old = trailingslashit( dirname( $path ) ) . $rec['f'] . '.webp';
		if ( file_exists( $old ) ) {
			wp_delete_file( $old );
		}
	}

	/**
	 * Every file of an attachment that is shown on the site.
	 *
	 * @return array key => [path, w, h]. Key "full" is the attached file.
	 */
	public static function files_for( $id ) {
		$main = get_attached_file( $id );
		if ( ! $main ) {
			return array();
		}
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		$dir  = trailingslashit( dirname( $main ) );

		$files = array(
			'full' => array(
				'path' => $main,
				'w'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'h'    => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			),
		);

		$seen = array( wp_basename( $main ) => true );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) || isset( $seen[ $size['file'] ] ) ) {
					continue; // Several size names can point at one file.
				}
				if ( ! empty( $size['mime-type'] ) && ! in_array( $size['mime-type'], self::MIMES, true ) ) {
					continue;
				}
				$seen[ $size['file'] ] = true;
				$files[ $name ]        = array(
					'path' => $dir . $size['file'],
					'w'    => isset( $size['width'] ) ? (int) $size['width'] : 0,
					'h'    => isset( $size['height'] ) ? (int) $size['height'] : 0,
				);
			}
		}
		return $files;
	}

	/**
	 * Where an image stands, for the media library.
	 *
	 * @return string waiting | failed | current | outdated | excluded | other
	 */
	public static function state_of( $id ) {
		if ( ! in_array( get_post_mime_type( $id ), self::MIMES, true ) ) {
			return 'other';
		}
		if ( get_post_meta( $id, self::EXCLUDE, true ) ) {
			return 'excluded';
		}
		$status = get_post_meta( $id, self::STATUS, true );
		if ( 'failed' === $status ) {
			return 'failed';
		}
		if ( 'done' !== $status ) {
			return 'waiting';
		}
		// Made with other settings than the current ones?
		$detail = get_post_meta( $id, self::META, true );
		$q      = Pload_Settings::quality();
		$ms     = Pload_Settings::min_saving();
		if ( is_array( $detail ) && ! empty( $detail['sizes'] ) ) {
			foreach ( $detail['sizes'] as $rec ) {
				if ( ! isset( $rec['s'] ) || ! in_array( $rec['s'], array( 'ok', 'larger' ), true ) ) {
					continue;
				}
				$rq  = isset( $rec['q'] ) ? (int) $rec['q'] : self::QUALITY_BEFORE_05;
				$rms = isset( $rec['ms'] ) ? (int) $rec['ms'] : 0;
				if ( $rq !== $q || $rms !== $ms ) {
					return 'outdated';
				}
			}
		}
		return 'current';
	}

	/** Removes any WebP files of the attachment and keeps it out of the queue. */
	public static function exclude( $id ) {
		if ( get_post_meta( $id, self::META, true ) ) {
			self::forget( $id );
		}
		update_post_meta( $id, self::EXCLUDE, 1 );
		update_post_meta( $id, self::STATUS, 'skipped' );
	}

	/** Lets the attachment be optimized again. */
	public static function include_again( $id ) {
		delete_post_meta( $id, self::EXCLUDE );
		delete_post_meta( $id, self::STATUS );
		Pload_Queue::enqueue( $id );
	}

	/**
	 * Removes our files and totals for an attachment.
	 */
	public static function forget( $id ) {
		$detail = get_post_meta( $id, self::META, true );
		$in     = 0;
		$out    = 0;
		$n      = 0;

		$files = self::files_for( $id );
		$dir   = $files ? trailingslashit( dirname( $files['full']['path'] ) ) : '';
		$paths = array();

		// Current sizes, plus file names recorded at conversion time (the
		// sizes may have changed since).
		foreach ( $files as $f ) {
			$paths[] = $f['path'] . '.webp';
		}
		if ( is_array( $detail ) && ! empty( $detail['sizes'] ) ) {
			foreach ( $detail['sizes'] as $rec ) {
				if ( isset( $rec['s'] ) && 'ok' === $rec['s'] ) {
					$in  += (int) $rec['in'];
					$out += (int) $rec['out'];
					$n++;
				}
				if ( $dir && ! empty( $rec['f'] ) ) {
					$paths[] = $dir . $rec['f'] . '.webp';
				}
			}
		}
		foreach ( array_unique( $paths ) as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			if ( file_exists( $path . '.ploadtmp' ) ) {
				wp_delete_file( $path . '.ploadtmp' );
			}
		}
		Pload_Stats::add( -$in, -$out, -$n );
		delete_post_meta( $id, self::META );
		delete_post_meta( $id, self::STATUS );
	}
}
