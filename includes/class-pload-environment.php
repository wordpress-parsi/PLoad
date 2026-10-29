<?php
/**
 * Environment check.
 *
 * Does not trust function_exists() alone: it creates a real sample image,
 * converts it with every available engine, validates the output bytes and
 * measures time and memory. The result decides which engine is used and how
 * much work is safe per run on this particular host.
 *
 * Every stage is recorded before it starts. If PHP dies mid-test (out of
 * memory, a crashing Imagick build, a host-level kill), the next page load
 * finds the unfinished stage, reports it, and later runs skip that stage.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Environment {

	const OPTION         = 'pload_env';
	const STAGE_OPTION   = 'pload_env_stage';

	/**
	 * Version of the test logic. Raise it only when the test or its rules
	 * change, so users are asked to re-run the test only when it matters.
	 */
	const SCHEMA = 1;
	const CRASHES_OPTION = 'pload_env_crashes';

	const SAMPLE_W  = 1200;
	const SAMPLE_H  = 800;
	const QUALITY   = 80;
	const MAX_MP    = 25;   // Hard ceiling for GD, whatever the memory says.
	const BUDGET    = 8;    // Default seconds of work per run.

	/** @var array|null Report being built during run(). */
	private static $report = null;

	/* ---------------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------------- */

	/** True when the saved report was made by an older version of the test. */
	public static function is_outdated( $report ) {
		if ( ! is_array( $report ) ) {
			return false;
		}
		if ( isset( $report['schema'] ) ) {
			$schema = (int) $report['schema'];
		} else {
			// Reports from 0.2–0.5 used the same rules as schema 1.
			$schema = ( ! empty( $report['version'] ) && version_compare( $report['version'], '0.2.0', '>=' ) ) ? 1 : 0;
		}
		return $schema < self::SCHEMA;
	}

	/** @return array|null Last saved report. */
	public static function get() {
		$report = get_option( self::OPTION, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * If a previous test died, turn the dangling stage marker into a report.
	 * Called on every load of the settings page. $force is set when the
	 * browser already knows the test request failed.
	 */
	public static function detect_crash( $force = false ) {
		$stage = get_option( self::STAGE_OPTION, null );
		if ( ! is_array( $stage ) || empty( $stage['name'] ) ) {
			return;
		}
		// A test that is still running right now is not a crash.
		if ( ! $force && time() - (int) $stage['t'] < 90 ) {
			return;
		}
		self::record_crash( $stage['name'] );
	}

	/**
	 * Runs the full check and saves the report.
	 *
	 * @param bool $forget_crashes Retest stages that crashed before.
	 * @return array
	 */
	public static function run( $forget_crashes = false ) {
		if ( $forget_crashes ) {
			delete_option( self::CRASHES_OPTION );
		}

		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

		if ( self::can_set_time_limit() ) {
			@set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Lets a step finish within its time budget on hosts that allow it.
		}

		self::$report = array(
			'time'    => time(),
			'version' => PLOAD_VERSION,
			'schema'  => self::SCHEMA,
			'php'     => PHP_VERSION,
			'wp'      => get_bloginfo( 'version' ),
			'status'  => 'red',
			'driver'  => '',
			'issues'  => array(),
			'limits'  => array(),
			'uploads' => array(),
			'drivers' => array(),
			'budget'  => array(),
			'wp_editor_webp' => function_exists( 'wp_image_editor_supports' )
				? (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) )
				: false,
		);

		self::stage( 'limits' );
		self::check_limits();

		self::stage( 'uploads' );
		$tmp = self::check_uploads();

		if ( $tmp ) {
			$sample = self::stage( 'sample' ) ? self::create_sample( $tmp ) : false;

			if ( $sample ) {
				self::test_imagick( $sample, $tmp );
				self::test_gd( $sample, $tmp );
			} else {
				self::issue( 'no_sample', 'red' );
			}

			self::cleanup( $tmp );
		}

		self::stage( 'decide' );
		self::decide();

		delete_option( self::STAGE_OPTION );
		update_option( self::OPTION, self::$report, false );

		Pload_Logger::log(
			'red' === self::$report['status'] ? 'error' : ( 'yellow' === self::$report['status'] ? 'warning' : 'info' ),
			sprintf(
				'Environment test finished: status=%s, driver=%s.',
				self::$report['status'],
				self::$report['driver'] ? self::$report['driver'] : 'none'
			)
		);

		$report       = self::$report;
		self::$report = null;
		return $report;
	}

	/**
	 * Catches fatal errors (memory exhaustion, timeouts) during run().
	 */
	public static function on_shutdown() {
		if ( null === self::$report ) {
			return; // run() finished normally.
		}
		$error = error_get_last();
		$stage = get_option( self::STAGE_OPTION, null );
		if ( is_array( $stage ) && ! empty( $stage['name'] ) ) {
			self::record_crash( $stage['name'], $error ? $error['message'] : '' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Checks
	 * ------------------------------------------------------------------- */

	private static function check_limits() {
		$raw_before = (string) ini_get( 'memory_limit' );

		// WordPress raises memory for image work; measure what we will really get.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$raw_after = (string) ini_get( 'memory_limit' );

		$met = (int) ini_get( 'max_execution_time' );

		self::$report['limits'] = array(
			'memory_raw'        => $raw_before,
			'memory_image_raw'  => $raw_after,
			'memory_bytes'      => self::to_bytes( $raw_after ),
			'memory_used'       => memory_get_usage(),
			'max_execution'     => $met,
			'can_set_time'      => self::can_set_time_limit(),
		);
	}

	/** @return string|false Temp directory path. */
	private static function check_uploads() {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		// A new site may not have an uploads folder yet: create it, as
		// WordPress itself would on the first upload.
		if ( $base && ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}

		$writable = $base && empty( $uploads['error'] ) && wp_is_writable( $base );

		self::$report['uploads'] = array(
			'path'     => $base,
			'writable' => $writable,
			'free'     => self::free_space( $base ),
		);

		if ( ! $writable ) {
			self::issue( 'uploads_not_writable', 'red' );
			return false;
		}

		$tmp = trailingslashit( $base ) . 'pload-tmp-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $tmp ) ) {
			self::issue( 'uploads_not_writable', 'red' );
			return false;
		}
		return $tmp;
	}

	/**
	 * Builds a photo-like JPEG (gradient + soft shapes) so the compression
	 * numbers resemble real uploads rather than a flat colour.
	 *
	 * @return string|false
	 */
	private static function create_sample( $tmp ) {
		$file = $tmp . '/sample.jpg';

		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) ) {
			$w  = self::SAMPLE_W;
			$h  = self::SAMPLE_H;
			$im = @imagecreatetruecolor( $w, $h );
			if ( $im ) {
				for ( $y = 0; $y < $h; $y++ ) {
					$p = $y / $h;
					$c = imagecolorallocate( $im, (int) ( 58 + $p * 186 ), (int) ( 123 + $p * 39 ), (int) ( 213 - $p * 124 ) );
					imageline( $im, 0, $y, $w, $y, $c );
				}
				// The same picture on every host, so timings are comparable.
				$seed = 42;
				for ( $i = 0; $i < 150; $i++ ) {
					$c = imagecolorallocatealpha( $im, self::det( $seed, 0, 255 ), self::det( $seed, 0, 255 ), self::det( $seed, 0, 255 ), self::det( $seed, 60, 110 ) );
					imagefilledellipse( $im, self::det( $seed, 0, $w ), self::det( $seed, 0, $h ), self::det( $seed, 20, 260 ), self::det( $seed, 20, 260 ), $c );
				}
				$ok = @imagejpeg( $im, $file, 90 );
				imagedestroy( $im );
				if ( $ok && is_file( $file ) ) {
					return $file;
				}
			}
		}

		if ( class_exists( 'Imagick' ) ) {
			try {
				$im = new Imagick();
				$im->newPseudoImage( self::SAMPLE_W, self::SAMPLE_H, 'gradient:#3a7bd5-#f4a259' );
				$im->addNoiseImage( Imagick::NOISE_GAUSSIAN );
				$im->setImageFormat( 'jpeg' );
				$im->setImageCompressionQuality( 90 );
				$im->writeImage( $file );
				$im->clear();
				if ( is_file( $file ) ) {
					return $file;
				}
			} catch ( Throwable $e ) {
				Pload_Logger::log( 'warning', 'Imagick could not create the sample image: ' . $e->getMessage() );
			}
		}

		return false;
	}

	private static function test_imagick( $sample, $tmp ) {
		$result = self::empty_driver();

		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			self::$report['drivers']['imagick'] = $result;
			return;
		}

		$result['available'] = true;
		try {
			$v                 = Imagick::getVersion();
			$result['version'] = isset( $v['versionString'] ) ? $v['versionString'] : '';
			$result['mem_limit'] = (int) Imagick::getResourceLimit( Imagick::RESOURCETYPE_MEMORY );
		} catch ( Throwable $e ) {
			$result['version'] = '';
		}

		$has_webp = self::imagick_has( 'WEBP' );
		$has_avif = self::imagick_has( 'AVIF' );

		if ( $has_webp && self::stage( 'imagick_webp' ) ) {
			$dest = $tmp . '/imagick.webp';
			$m    = self::measure_start();
			try {
				$im = new Imagick( $sample );
				$im->stripImage();
				$im->setImageFormat( 'webp' );
				$im->setImageCompressionQuality( self::QUALITY );
				$im->setOption( 'webp:method', '4' );
				$im->writeImage( $dest );
				$im->clear();
				$error = self::validate_webp( $dest );
			} catch ( Throwable $e ) {
				$error = $e->getMessage();
			}
			// Imagick allocates outside PHP's heap, so its memory number is not meaningful.
			$result['webp'] = self::measure_end( $m, $sample, $dest, $error, false );
		} elseif ( ! $has_webp ) {
			$result['webp']['error'] = 'not_compiled';
		} else {
			$result['webp']['error'] = 'crashed_before';
		}

		if ( $has_avif && self::stage( 'imagick_avif' ) ) {
			$dest = $tmp . '/imagick.avif';
			$m    = self::measure_start();
			try {
				$im = new Imagick( $sample );
				$im->thumbnailImage( 400, 0 );
				$im->stripImage();
				$im->setImageFormat( 'avif' );
				$im->setImageCompressionQuality( 50 );
				$im->writeImage( $dest );
				$im->clear();
				$error = self::validate_avif( $dest );
			} catch ( Throwable $e ) {
				$error = $e->getMessage();
			}
			$result['avif'] = self::measure_end( $m, $sample, $dest, $error, false );
		} elseif ( ! $has_avif ) {
			$result['avif']['error'] = 'not_compiled';
		} else {
			$result['avif']['error'] = 'crashed_before';
		}

		self::$report['drivers']['imagick'] = $result;
	}

	private static function test_gd( $sample, $tmp ) {
		$result = self::empty_driver();

		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagecreatefromjpeg' ) ) {
			self::$report['drivers']['gd'] = $result;
			return;
		}

		$result['available'] = true;
		$info                = function_exists( 'gd_info' ) ? gd_info() : array();
		$result['version']   = isset( $info['GD Version'] ) ? $info['GD Version'] : '';

		if ( function_exists( 'imagewebp' ) && self::stage( 'gd_webp' ) ) {
			$dest = $tmp . '/gd.webp';
			$m    = self::measure_start();
			$img  = @imagecreatefromjpeg( $sample );
			if ( $img ) {
				$ok = @imagewebp( $img, $dest, self::QUALITY );
				imagedestroy( $img );
				$error = $ok ? self::validate_webp( $dest ) : 'encode_failed';
			} else {
				$error = 'decode_failed';
			}
			$result['webp'] = self::measure_end( $m, $sample, $dest, $error, true );
		} elseif ( ! function_exists( 'imagewebp' ) ) {
			$result['webp']['error'] = 'not_compiled';
		} else {
			$result['webp']['error'] = 'crashed_before';
		}

		if ( function_exists( 'imageavif' ) && self::stage( 'gd_avif' ) ) {
			$dest = $tmp . '/gd.avif';
			$m    = self::measure_start();
			$img  = @imagecreatefromjpeg( $sample );
			$small = $img ? @imagescale( $img, 400 ) : false;
			if ( $img ) {
				imagedestroy( $img );
			}
			if ( $small ) {
				$ok = @imageavif( $small, $dest, 50, 10 );
				imagedestroy( $small );
				$error = $ok ? self::validate_avif( $dest ) : 'encode_failed';
			} else {
				$error = 'decode_failed';
			}
			$result['avif'] = self::measure_end( $m, $sample, $dest, $error, true );
		} elseif ( ! function_exists( 'imageavif' ) ) {
			$result['avif']['error'] = 'not_compiled';
		} else {
			$result['avif']['error'] = 'crashed_before';
		}

		self::$report['drivers']['gd'] = $result;
	}

	/* ---------------------------------------------------------------------
	 * Decision
	 * ------------------------------------------------------------------- */

	private static function decide() {
		$r       = &self::$report;
		$imagick = isset( $r['drivers']['imagick'] ) ? $r['drivers']['imagick'] : self::empty_driver();
		$gd      = isset( $r['drivers']['gd'] ) ? $r['drivers']['gd'] : self::empty_driver();

		if ( $imagick['webp']['ok'] ) {
			$r['driver'] = 'imagick';
		} elseif ( $gd['webp']['ok'] ) {
			$r['driver'] = 'gd';
		}

		if ( $gd['available'] && ! $gd['webp']['ok'] && in_array( $gd['webp']['error'], array( 'bad_length', 'bad_header', 'empty' ), true ) ) {
			self::issue( 'gd_webp_broken', 'yellow' );
		}

		if ( ! $r['driver'] ) {
			if ( ! empty( $r['uploads']['writable'] ) && ! self::has_issue( 'no_sample' ) ) {
				self::issue( 'no_webp_driver', 'red' );
			}
			$r['status'] = 'red';
			return;
		}

		/* ---- Processing budget ---- */

		$limits = $r['limits'];
		$met    = (int) $limits['max_execution'];
		$budget = self::BUDGET;
		if ( $met > 0 ) {
			$budget = (int) min( self::BUDGET, max( 3, floor( $met * 0.3 ) ) );
		}

		$mp        = ( self::SAMPLE_W * self::SAMPLE_H ) / 1000000;
		$used      = $r['drivers'][ $r['driver'] ]['webp'];
		$ms_per_mp = $used['ms'] > 0 ? (int) ceil( $used['ms'] / $mp ) : 0;

		// Largest image GD can open without running out of memory.
		$max_mp = self::MAX_MP;
		if ( 'gd' === $r['driver'] && $limits['memory_bytes'] > 0 ) {
			$available    = $limits['memory_bytes'] - memory_get_usage() - 16 * MB_IN_BYTES;
			$bytes_per_px = ( $used['mem_reliable'] && $used['mem'] > 0 ) ? $used['mem'] / ( $mp * 1000000 ) : 10;
			$bytes_per_px = max( 6, $bytes_per_px ) * 1.5; // safety margin
			$max_mp       = max( 0, min( self::MAX_MP, floor( $available / $bytes_per_px / 1000000 ) ) );
		}

		$r['budget'] = array(
			'seconds'    => $budget,
			'ms_per_mp'  => $ms_per_mp,
			'max_mp'     => (int) $max_mp,
			// Conservative: an average size is ~0.6 MP, plus disk and bookkeeping.
			'per_run'    => $ms_per_mp > 0 ? (int) floor( ( $budget * 1000 ) / ( $ms_per_mp * 0.6 + 80 ) ) : 0,
		);

		/* ---- Warnings ---- */

		if ( 'gd' === $r['driver'] ) {
			self::issue( 'gd_only', 'info' );
		}
		if ( $limits['memory_bytes'] > 0 && $limits['memory_bytes'] < 96 * MB_IN_BYTES ) {
			self::issue( 'low_memory', 'yellow', array( 'mb' => (int) round( $limits['memory_bytes'] / MB_IN_BYTES ) ) );
		}
		if ( $met > 0 && $met < 20 && ! $limits['can_set_time'] ) {
			self::issue( 'low_exec_time', 'yellow', array( 's' => $met ) );
		}
		if ( $ms_per_mp > 2500 ) {
			self::issue( 'slow_server', 'yellow', array( 'ms' => $ms_per_mp ) );
		}
		// WordPress scales uploads down to 2560 px (about 6.5 MP), and only
		// that copy and smaller sizes are converted. Below ~7 MP some normal
		// images would be skipped.
		if ( 'gd' === $r['driver'] && $max_mp < 7 ) {
			self::issue( 'large_images_skipped', 'yellow', array( 'mp' => (int) $max_mp ) );
		}
		if ( 'imagick' === $r['driver'] && ! empty( $imagick['mem_limit'] ) && $imagick['mem_limit'] < 64 * MB_IN_BYTES ) {
			self::issue( 'imagick_low_limits', 'yellow', array( 'mb' => (int) round( $imagick['mem_limit'] / MB_IN_BYTES ) ) );
		}
		$free = $r['uploads']['free'];
		if ( null !== $free && $free < 500 * MB_IN_BYTES ) {
			self::issue( 'low_disk', 'yellow', array( 'mb' => (int) round( $free / MB_IN_BYTES ) ) );
		}

		$r['status'] = self::has_level( 'red' ) ? 'red' : ( self::has_level( 'yellow' ) ? 'yellow' : 'green' );
	}

	/* ---------------------------------------------------------------------
	 * Crash tracking
	 * ------------------------------------------------------------------- */

	/**
	 * Marks a stage as started. Returns false if this stage crashed in an
	 * earlier run, so the caller can skip it.
	 */
	private static function stage( $name ) {
		$crashes = get_option( self::CRASHES_OPTION, array() );
		if ( is_array( $crashes ) && isset( $crashes[ $name ] ) ) {
			return false;
		}
		update_option( self::STAGE_OPTION, array( 'name' => $name, 't' => time() ), false );
		return true;
	}

	private static function record_crash( $stage, $message = '' ) {
		$crashes = get_option( self::CRASHES_OPTION, array() );
		if ( ! is_array( $crashes ) ) {
			$crashes = array();
		}
		$crashes[ $stage ] = time();
		update_option( self::CRASHES_OPTION, $crashes, false );
		delete_option( self::STAGE_OPTION );

		$report = self::$report ? self::$report : self::get();
		if ( ! is_array( $report ) ) {
			$report = array( 'time' => time(), 'version' => PLOAD_VERSION, 'issues' => array() );
		}
		$report['status']   = 'red';
		$report['crashed']  = $stage;
		$report['issues'][] = array( 'code' => 'test_crashed', 'level' => 'red', 'args' => array( 'stage' => $stage ) );
		update_option( self::OPTION, $report, false );

		Pload_Logger::log( 'error', sprintf( 'Environment test crashed at stage "%s". %s', $stage, $message ) );
		self::$report = null;
	}

	/** @return array Stage => timestamp. */
	public static function crashed_stages() {
		$crashes = get_option( self::CRASHES_OPTION, array() );
		return is_array( $crashes ) ? $crashes : array();
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	private static function validate_webp( $file ) {
		return Pload_Driver::validate_webp( $file );
	}

	private static function validate_avif( $file ) {
		clearstatcache( true, $file );
		if ( ! is_file( $file ) ) {
			return 'missing';
		}
		if ( filesize( $file ) < 30 ) {
			return 'empty';
		}
		$head = file_get_contents( $file, false, null, 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the first 12 bytes of a local file to validate it.
		if ( ! is_string( $head ) || 'ftyp' !== substr( $head, 4, 4 ) || ! in_array( substr( $head, 8, 4 ), array( 'avif', 'avis' ), true ) ) {
			return 'bad_header';
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Small deterministic number generator (a linear congruential one) for
	 * the test picture. Not for anything random or secret.
	 */
	private static function det( &$seed, $min, $max ) {
		$seed = ( $seed * 1103515245 + 12345 ) % 2147483648;
		return $min + (int) ( $seed % ( $max - $min + 1 ) );
	}

	private static function empty_driver() {
		$fmt = array( 'ok' => false, 'ms' => 0, 'mem' => 0, 'mem_reliable' => false, 'in' => 0, 'out' => 0, 'error' => '' );
		return array(
			'available' => false,
			'version'   => '',
			'webp'      => $fmt,
			'avif'      => $fmt,
		);
	}

	private static function measure_start() {
		$reliable = false;
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
			$reliable = true;
		}
		return array(
			't'        => microtime( true ),
			'base'     => memory_get_usage(),
			'peak'     => memory_get_peak_usage(),
			'reliable' => $reliable,
		);
	}

	private static function measure_end( $m, $source, $dest, $error, $php_heap ) {
		$peak = memory_get_peak_usage();
		clearstatcache();
		$reliable = $php_heap && ( $m['reliable'] || $peak > $m['peak'] );

		return array(
			'ok'           => '' === $error,
			'ms'           => (int) round( ( microtime( true ) - $m['t'] ) * 1000 ),
			'mem'          => $reliable ? max( 0, $peak - $m['base'] ) : 0,
			'mem_reliable' => $reliable,
			'in'           => is_file( $source ) ? (int) filesize( $source ) : 0,
			'out'          => is_file( $dest ) ? (int) filesize( $dest ) : 0,
			'error'        => (string) $error,
		);
	}

	private static function imagick_has( $format ) {
		try {
			$found = Imagick::queryFormats( $format );
			return ! empty( $found );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	private static function cleanup( $tmp ) {
		if ( ! $tmp || ! is_dir( $tmp ) ) {
			return;
		}
		foreach ( (array) glob( $tmp . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		@rmdir( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes our own empty temp folder in uploads; WP_Filesystem may need FTP credentials that background runs do not have.
	}

	private static function can_set_time_limit() {
		if ( ! function_exists( 'set_time_limit' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'set_time_limit', $disabled, true );
	}

	private static function to_bytes( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || '-1' === $raw ) {
			return -1; // unlimited
		}
		return (int) wp_convert_hr_to_bytes( $raw );
	}

	private static function free_space( $path ) {
		if ( ! $path || ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Many hosts disable this function; null is handled.
		$free = false === $free ? null : (float) $free;
		$q    = Pload_Space::quota(); // The cPanel quota, when readable, is the real limit.
		if ( $q ) {
			$free = null === $free ? $q['free'] : min( $free, $q['free'] );
		}
		return $free;
	}

	private static function issue( $code, $level, $args = array() ) {
		self::$report['issues'][] = array( 'code' => $code, 'level' => $level, 'args' => $args );
	}

	private static function has_issue( $code ) {
		foreach ( self::$report['issues'] as $i ) {
			if ( $i['code'] === $code ) {
				return true;
			}
		}
		return false;
	}

	private static function has_level( $level ) {
		foreach ( self::$report['issues'] as $i ) {
			if ( $i['level'] === $level ) {
				return true;
			}
		}
		return false;
	}
}
