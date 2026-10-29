<?php
/**
 * The slow-and-steady part.
 *
 * Every minute (WP-Cron) one short run works through attachments until its
 * time budget is used. Order: an attachment left unfinished by the last run,
 * then new uploads, then the library from the oldest ID up, then retries.
 *
 * The library is walked with an ID cursor, so nothing has to be queued in
 * advance and new uploads are picked up automatically. All state is a single
 * small option.
 *
 * When everything is done the schedule is removed completely, so a finished
 * site pays nothing: no wp-cron.php request, no queries. A new upload, a
 * regenerated image or a library re-check wakes it up again. Pending retries
 * get a single event at their due time instead of the minute schedule.
 */

defined( 'ABSPATH' ) || exit;

/** Budget shared by everything processed in one run. */
final class Pload_Run {
	public $write_fail = 0; // Consecutive failures to write a file (a sign of a full disk).
	public $deadline;
	public $hard_cap_ms;
	public $ms_per_mp;
	public $max_mp;
	public $files = 0;
	public $saved = 0;

	public function ms_left() {
		return ( $this->deadline - microtime( true ) ) * 1000;
	}
}

final class Pload_Queue {

	const HOOK     = 'pload_run';
	const SCHEDULE = 'pload_minute';
	const STATE    = 'pload_state';     // idle | running | paused | cleaning
	const QUEUE    = 'pload_queue';
	const LAST     = 'pload_last_run';
	const RUNS     = 'pload_runs';      // Last 20 non-empty runs, for speed and ETA.
	const LOCK     = 'pload_lock';
	const IDLE     = 'pload_idle';      // Set while there is nothing to do.

	const PRIORITY_MAX = 200;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
	}

	public static function schedules( $s ) {
		$s[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Pload: every minute',
		);
		return $s;
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------- */

	public static function state() {
		return get_option( self::STATE, 'idle' );
	}

	public static function start() {
		update_option( self::STATE, 'running', false );
		delete_option( self::IDLE );
		delete_option( Pload_Space::PAUSE_REASON );
		wp_clear_scheduled_hook( self::HOOK );
		self::ensure_schedule();
		Pload_Runner::set( true );
		Pload_Logger::log( 'info', 'Optimization started.' );
	}

	/** The queue stops itself, e.g. when the disk is nearly full. */
	public static function pause_for( $code, $data = array() ) {
		update_option( self::STATE, 'paused', false );
		wp_clear_scheduled_hook( self::HOOK );
		Pload_Runner::set( false );
		update_option( Pload_Space::PAUSE_REASON, array_merge( array( 'code' => $code, 't' => time() ), $data ), false );
		Pload_Logger::log( 'error', 'low_disk' === $code ? 'Paused automatically: less than 1 GB free on the disk.' : 'Paused automatically: files could not be written several times in a row (the disk or hosting quota may be full).' );
	}

	public static function is_idle() {
		return (bool) get_option( self::IDLE );
	}

	/** Back to the minute schedule, e.g. after an upload. */
	public static function wake() {
		if ( 'running' !== self::state() ) {
			return;
		}
		if ( self::is_idle() ) {
			delete_option( self::IDLE );
			wp_clear_scheduled_hook( self::HOOK ); // Drops a pending single retry event too.
			wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
		}
		Pload_Runner::set( true );
	}

	/**
	 * Nothing to do: remove the schedule. With retries pending, wake once
	 * at the earliest due time.
	 */
	private static function go_idle( $q ) {
		$was_idle = self::is_idle();
		update_option( self::IDLE, 1, false );
		wp_clear_scheduled_hook( self::HOOK );

		$due = $q['retry'] ? (int) min( $q['retry'] ) : 0;
		if ( $due ) {
			wp_schedule_single_event( max( $due, time() + 60 ), self::HOOK );
			Pload_Runner::set( true, max( $due, time() + 60 ) - time() + 120 );
		} else {
			Pload_Runner::set( false );
		}
		if ( ! $was_idle ) {
			Pload_Logger::log( 'info', $due ? 'All images handled; waiting to retry failed ones.' : 'All images optimized. Schedule stopped until new images arrive.' );
		}
	}

	public static function pause() {
		update_option( self::STATE, 'paused', false );
		wp_clear_scheduled_hook( self::HOOK );
		Pload_Runner::set( false );
		Pload_Logger::log( 'info', 'Optimization paused.' );
	}

	public static function ensure_schedule() {
		$next  = wp_next_scheduled( self::HOOK );
		$state = self::state();
		if ( ( 'running' === $state || Pload_Cleanup::STATE === $state ) && ! self::is_idle() && false === get_option( Pload_Runner::TICK ) ) {
			Pload_Runner::set( true, 60 ); // Installs updated from before 0.5.5.
		}
		if ( 'running' === $state || Pload_Cleanup::STATE === $state ) {
			if ( ! $next && ! self::is_idle() ) {
				wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Walk the whole library again.
	 *
	 * @param string $type "check" picks up changed files; "rebuild" also
	 *                     re-converts sizes made with other settings. Both
	 *                     are the same walk; the type is for progress display.
	 */
	public static function rescan( $type = 'check' ) {
		$q              = self::queue();
		$q['cursor']    = 0;
		$q['rescan']    = true;
		$q['scan_done'] = false;
		$q['pass']      = array(
			'type'    => 'rebuild' === $type ? 'rebuild' : 'check',
			't'       => time(),
			'total'   => Pload_Stats::counts()['total'],
			'checked' => 0,
			'files'   => 0,
			'quality' => Pload_Settings::quality(),
		);
		self::save( $q );
		self::wake();
		Pload_Logger::log(
			'info',
			'rebuild' === $type
				? sprintf( 'Rebuild started with quality %d and minimum saving %d%%.', Pload_Settings::quality(), Pload_Settings::min_saving() )
				: 'Library check started from the beginning.'
		);
	}

	/** Puts attachments first in line ("Optimize now" for several images). */
	public static function enqueue_front( $ids ) {
		$q = self::queue();
		foreach ( array_reverse( array_map( 'intval', (array) $ids ) ) as $id ) {
			$q['priority'] = array_values( array_diff( $q['priority'], array( $id ) ) );
			array_unshift( $q['priority'], $id );
		}
		$q['priority'] = array_slice( $q['priority'], 0, self::PRIORITY_MAX );
		self::save( $q );
		self::wake();
	}

	/**
	 * "Optimize now" for one image: converts it in this request when nothing
	 * else is running, otherwise puts it first in line.
	 *
	 * @return array ['result' => done|partial|queued|excluded|not_ready, 'files' => n]
	 */
	public static function process_one( $id ) {
		$id = (int) $id;
		if ( get_post_meta( $id, Pload_Converter::EXCLUDE, true ) ) {
			return array( 'result' => 'excluded', 'files' => 0 );
		}
		$env    = Pload_Environment::get();
		$driver = ( $env && ! empty( $env['driver'] ) && 'red' !== $env['status'] ) ? Pload_Drivers::make( $env['driver'] ) : null;
		if ( ! $driver ) {
			return array( 'result' => 'not_ready', 'files' => 0 );
		}
		if ( Pload_Space::too_low() || ! self::lock() ) {
			self::enqueue_front( array( $id ) );
			return array( 'result' => 'queued', 'files' => 0 );
		}

		if ( ! empty( $env['limits']['can_set_time'] ) ) {
			@set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Lets a step finish within its time budget on hosts that allow it.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$run              = new Pload_Run();
		$run->deadline    = microtime( true ) + 20;
		$run->hard_cap_ms = 30000;
		$run->ms_per_mp   = ! empty( $env['budget']['ms_per_mp'] ) ? (int) $env['budget']['ms_per_mp'] : 500;
		$run->max_mp      = ! empty( $env['budget']['max_mp'] ) ? (int) $env['budget']['max_mp'] : 6;

		try {
			$done = ( new Pload_Converter( $driver ) )->process( $id, $run );
		} finally {
			self::unlock();
		}
		if ( ! $done ) {
			self::enqueue_front( array( $id ) );
		}
		Pload_Logger::log( 'info', sprintf( 'Attachment #%d optimized on request: %d files%s.', $id, $run->files, $done ? '' : ', rest queued' ) );
		return array( 'result' => $done ? 'done' : 'partial', 'files' => $run->files );
	}

	/** Puts one attachment at the front of the queue and wakes the schedule. */
	public static function enqueue( $id ) {
		$q = self::queue();
		if ( ! in_array( (int) $id, $q['priority'], true ) && count( $q['priority'] ) < self::PRIORITY_MAX ) {
			$q['priority'][] = (int) $id;
			self::save( $q );
		}
		self::wake();
	}

	public static function queue() {
		$q = get_option( self::QUEUE, array() );
		return wp_parse_args(
			is_array( $q ) ? $q : array(),
			array(
				'cursor'    => 0,
				'current'   => 0,
				'priority'  => array(),
				'retry'     => array(),
				'rescan'    => false,
				'scan_done' => false,
				'pass'      => null,
				'dir'       => 'oldest', // Direction of the current walk.
			)
		);
	}

	private static function save( $q ) {
		update_option( self::QUEUE, $q, false );
	}

	/** @return array Recent non-empty runs, oldest first. */
	public static function runs() {
		$runs = get_option( self::RUNS, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * Seconds until the remaining work is done, from the real pace of recent
	 * runs (which includes how often WP-Cron actually fires on this site).
	 *
	 * @param int $files_left Estimated image files still to convert.
	 * @return int|null Null when there is not enough history yet.
	 */
	public static function eta( $files_left ) {
		// Manual clicks say nothing about the automatic pace.
		$runs = array_values( array_filter( self::runs(), function ( $r ) { return $r['files'] > 0 && ( ! isset( $r['src'] ) || 'manual' !== $r['src'] ); } ) );
		if ( count( $runs ) < 3 || $files_left <= 0 ) {
			return null;
		}
		// Only runs within the last hour describe the current pace.
		$recent = array_values( array_filter( $runs, function ( $r ) { return $r['t'] > time() - HOUR_IN_SECONDS; } ) );
		if ( count( $recent ) >= 3 ) {
			$runs = $recent;
		}
		// Irregular steps (a browser tab that was paused, clicks) give a
		// misleading pace: require a steady rhythm.
		$gaps = array();
		for ( $i = 1, $n = count( $runs ); $i < $n; $i++ ) {
			$gaps[] = $runs[ $i ]['t'] - $runs[ $i - 1 ]['t'];
		}
		sort( $gaps );
		$median = $gaps[ (int) floor( count( $gaps ) / 2 ) ];
		if ( $median > 5 * MINUTE_IN_SECONDS ) {
			return null;
		}
		$first = array_shift( $runs );
		$span  = end( $runs )['t'] - $first['t'];
		$files = array_sum( array_column( $runs, 'files' ) );
		if ( $span <= 0 || ! $files ) {
			return null;
		}
		return (int) ceil( $files_left / ( $files / $span ) );
	}

	/** True when a recent step was started by WP-Cron (page loads or a server cron job). */
	public static function cron_works() {
		foreach ( array_reverse( self::runs() ) as $r ) {
			if ( $r['t'] < time() - 15 * MINUTE_IN_SECONDS ) {
				break;
			}
			if ( isset( $r['src'] ) && 'cron' === $r['src'] ) {
				return true;
			}
		}
		return false;
	}

	public static function retry_later( $id, $tries ) {
		$q                     = self::queue();
		$q['retry'][ (int) $id ] = time() + 10 * MINUTE_IN_SECONDS * $tries;
		self::save( $q );
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------- */

	/** New upload or regenerated sizes: handle before the backlog. */
	public static function on_generate( $metadata, $id ) {
		if ( in_array( get_post_mime_type( $id ), Pload_Converter::MIMES, true ) && ! get_post_meta( $id, Pload_Converter::EXCLUDE, true ) ) {
			delete_post_meta( $id, Pload_Converter::STATUS );
			self::enqueue( $id );
		}
		return $metadata;
	}

	public static function on_delete( $id ) {
		if ( get_post_meta( $id, Pload_Converter::META, true ) ) {
			Pload_Converter::forget( $id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Running
	 * ------------------------------------------------------------------- */

	public static function cron() {
		$state = self::state();
		if ( 'running' === $state ) {
			self::run();
		} elseif ( Pload_Cleanup::STATE === $state ) {
			Pload_Cleanup::run();
		}
	}

	/**
	 * One run. Safe to call from cron or manually; a lock prevents overlap.
	 *
	 * @return array Summary (also saved as the last run).
	 */
	public static function run( $source = 'cron' ) {
		$env = Pload_Environment::get();
		if ( ! $env || empty( $env['driver'] ) || 'red' === $env['status'] ) {
			return array( 'reason' => 'not_ready' );
		}
		$driver = Pload_Drivers::make( $env['driver'] );
		if ( ! $driver ) {
			Pload_Logger::log( 'error', 'Image engine "' . $env['driver'] . '" is no longer available. Run the server test again.' );
			return array( 'reason' => 'no_driver' );
		}
		if ( Pload_Space::too_low() ) {
			if ( 'running' === self::state() ) {
				self::pause_for( 'low_disk', array( 'free' => Pload_Space::free() ) );
			}
			return array( 'reason' => 'low_disk' );
		}
		if ( ! self::lock() ) {
			return array( 'reason' => 'locked' );
		}

		$started = microtime( true );
		if ( 'running' === self::state() ) {
			Pload_Runner::set( true ); // A step is happening: no outside help needed for a while.
		}
		$budget  = Pload_Settings::budget( $env );
		$met     = isset( $env['limits']['max_execution'] ) ? (int) $env['limits']['max_execution'] : 30;

		if ( ! empty( $env['limits']['can_set_time'] ) ) {
			@set_time_limit( $budget + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Lets a step finish within its time budget on hosts that allow it.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$run              = new Pload_Run();
		$run->deadline    = $started + $budget;
		$run->hard_cap_ms = min( 30, $met > 0 ? $met * 0.5 : 30 ) * 1000;
		$run->ms_per_mp   = ! empty( $env['budget']['ms_per_mp'] ) ? (int) $env['budget']['ms_per_mp'] : 500;
		$run->max_mp      = ! empty( $env['budget']['max_mp'] ) ? (int) $env['budget']['max_mp'] : 6;

		$converter = new Pload_Converter( $driver );
		$q         = self::queue();
		$count     = 0;
		$reason    = 'budget';

		// The walk direction changed in settings: start the walk again.
		// Finished images are skipped in SQL, so this costs almost nothing.
		if ( $q['dir'] !== Pload_Settings::order() ) {
			$q['dir']       = Pload_Settings::order();
			$q['cursor']    = 0;
			$q['scan_done'] = false;
			self::save( $q );
		}

		try {
			while ( $run->ms_left() > 0 ) {
				list( $id, $from ) = self::next( $q );
				if ( ! $id ) {
					$reason = 'empty';
					break;
				}

				$q['current'] = $id;
				self::save( $q );

				if ( ! $converter->process( $id, $run ) ) {
					if ( $run->write_fail >= 5 ) {
						$reason = 'write_errors';
						self::pause_for( 'write_errors', array( 'free' => Pload_Space::free() ) );
					}
					break; // Stays "current" for the next run.
				}

				$count++;
				$q            = self::queue(); // The converter may have added a retry.
				$q['current'] = 0;
				// Only the library walk moves the cursor. A resumed item that came
				// from the walk is now finished, so the walk skips it anyway.
				if ( 'scan' === $from ) {
					$q['cursor'] = 'newest' === $q['dir']
						? ( $q['cursor'] ? min( (int) $q['cursor'], $id ) : $id )
						: max( (int) $q['cursor'], $id );
					if ( ! empty( $q['pass'] ) ) {
						$q['pass']['checked']++;
					}
				}
				self::save( $q );
			}

			if ( ! empty( $q['pass'] ) && $run->files ) {
				$q['pass']['files'] += $run->files;
				self::save( $q );
			}

			if ( 'empty' === $reason && ! $q['scan_done'] ) {
				$q['scan_done'] = true;
				$q['rescan']    = false;
				if ( ! empty( $q['pass'] ) ) {
					$pass = $q['pass'];
					set_transient( 'pload_pass_done', $pass, DAY_IN_SECONDS );
					Pload_Logger::log(
						'info',
						'rebuild' === $pass['type']
							? sprintf( 'Rebuild finished: %d files converted again.', $pass['files'] )
							: sprintf( 'Library check finished: %d files converted.', $pass['files'] )
					);
					$q['pass'] = null;
				} else {
					Pload_Logger::log( 'info', 'Whole library checked.' );
				}
				self::save( $q );
			}
		} finally {
			self::unlock();
		}

		if ( 'running' === self::state() && 'write_errors' !== $reason ) {
			if ( 'empty' === $reason ) {
				self::go_idle( self::queue() );
			} elseif ( self::is_idle() ) {
				self::wake(); // A retry run found more to do.
			}
		}

		// Empty runs write nothing.
		if ( ! $count && ! $run->files ) {
			return array( 'reason' => $reason, 'files' => 0, 'items' => 0 );
		}

		$summary = array(
			't'      => time(),
			'files'  => $run->files,
			'items'  => $count,
			'secs'   => round( microtime( true ) - $started, 1 ),
			'saved'  => $run->saved,
			'reason' => $reason,
		);
		update_option( self::LAST, $summary, false );

		$runs   = get_option( self::RUNS, array() );
		$runs   = is_array( $runs ) ? $runs : array();
		$runs[] = array(
			'src'   => $source,
			't'     => $summary['t'],
			'files' => $summary['files'],
			'items' => $summary['items'],
			'secs'  => $summary['secs'],
			'mem'   => memory_get_peak_usage(),
		);
		update_option( self::RUNS, array_slice( $runs, -20 ), false );

		return $summary;
	}

	/**
	 * @return array [id, source]
	 */
	private static function next( &$q ) {
		if ( $q['current'] ) {
			$id = (int) $q['current'];
			if ( get_post( $id ) ) {
				return array( $id, 'current' );
			}
			$q['current'] = 0;
		}

		while ( $q['priority'] ) {
			$id = (int) array_shift( $q['priority'] );
			if ( get_post( $id ) ) {
				return array( $id, 'priority' );
			}
		}

		$id = self::scan_next( $q['cursor'], $q['rescan'], $q['dir'] );
		if ( $id ) {
			return array( $id, 'scan' );
		}
		// Anything the walk can no longer reach (e.g. an upload whose priority
		// entry was lost while walking newest-first): restart the walk.
		if ( ! $q['rescan'] && $q['cursor'] ) {
			$id = self::scan_next( 0, false, $q['dir'] );
			if ( $id ) {
				$q['cursor'] = 0;
				return array( $id, 'scan' );
			}
		}

		$now = time();
		foreach ( $q['retry'] as $rid => $due ) {
			if ( $due <= $now ) {
				unset( $q['retry'][ $rid ] );
				if ( get_post( $rid ) ) {
					return array( (int) $rid, 'retry' );
				}
			}
		}

		return array( 0, '' );
	}

	/**
	 * Next attachment after the cursor. Outside a rescan, finished ones are
	 * skipped in SQL so a large, already-optimized library costs nothing.
	 */
	private static function scan_next( $cursor, $rescan, $dir = 'oldest' ) {
		global $wpdb;

		$cursor = (int) $cursor;
		$all    = $rescan ? 1 : 0;
		// Two fixed queries (ORDER BY can't be a placeholder). The constant
		// tests ("%d = 1", "%d = 0") are folded away by the database.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The queue needs the live next image.
		if ( 'newest' === $dir ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s)
					AND ( %d = 0 OR p.ID < %d )
					AND ( %d = 1 OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value IN ('done','skipped','failed') ) )
					ORDER BY p.ID DESC LIMIT 1",
					Pload_Converter::MIMES[0],
					Pload_Converter::MIMES[1],
					$cursor,
					$cursor,
					$all,
					Pload_Converter::STATUS
				)
			);
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s)
				AND p.ID > %d
				AND ( %d = 1 OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value IN ('done','skipped','failed') ) )
				ORDER BY p.ID ASC LIMIT 1",
				Pload_Converter::MIMES[0],
				Pload_Converter::MIMES[1],
				$cursor,
				$all,
				Pload_Converter::STATUS
			)
		);
		// phpcs:enable
	}

	/* ---------------------------------------------------------------------
	 * Lock: add_option() is atomic thanks to the unique option_name key.
	 * ------------------------------------------------------------------- */

	public static function lock() {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK );
		if ( $since && time() - $since > 300 ) {
			// A run died without releasing the lock.
			delete_option( self::LOCK );
			Pload_Logger::log( 'warning', 'Previous run did not finish cleanly; lock released.' );
			return add_option( self::LOCK, time(), '', false );
		}
		return false;
	}

	public static function unlock() {
		delete_option( self::LOCK );
	}
}
