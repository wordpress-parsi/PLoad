<?php
/**
 * Removes every WebP file Pload created, in the same short, time-budgeted
 * steps as optimization, so a large library can't time out a shared host.
 *
 * Order matters: showing WebP to visitors is switched off first, otherwise
 * pages would still offer <source type="image/webp"> for files that no
 * longer exist, and browsers do not fall back to the <img> in that case.
 *
 * Progress needs no extra bookkeeping: an attachment is "left to clean"
 * while it still has Pload meta, and forget() removes files and meta.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Cleanup {

	const TOTAL = 'pload_clean_total';
	const DONE  = 'pload_clean_done';   // Transient: show "clear your cache" once finished.
	const STATE = 'cleaning';

	public static function active() {
		return self::STATE === Pload_Queue::state();
	}

	public static function start() {
		update_option( Pload_Delivery::OPTION, 0 );
		update_option( Pload_Queue::STATE, self::STATE, false );
		delete_option( Pload_Queue::IDLE );
		wp_clear_scheduled_hook( Pload_Queue::HOOK );
		wp_schedule_event( time() + 10, Pload_Queue::SCHEDULE, Pload_Queue::HOOK );
		Pload_Runner::set( true, 30 );

		$total = self::remaining();
		update_option( self::TOTAL, $total, false );
		Pload_Logger::log( 'info', sprintf( 'Removing all WebP files started (%d images). Showing WebP to visitors was turned off.', $total ) );
	}

	/** Stops part-way. Optimization stays paused; nothing is recreated until the user resumes. */
	public static function stop() {
		update_option( Pload_Queue::STATE, 'paused', false );
		wp_clear_scheduled_hook( Pload_Queue::HOOK );
		Pload_Runner::set( false );
		delete_option( self::TOTAL );
		Pload_Logger::log( 'info', sprintf( 'Removing WebP files stopped; %d images still have WebP files.', self::remaining() ) );
	}

	/** Attachments that still have Pload data. */
	public static function remaining() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live progress of the removal; no WordPress API counts posts by meta key.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				Pload_Converter::META,
				Pload_Converter::STATUS
			)
		);
	}

	private static function next_id() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the live next item; it changes after every deletion.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) ORDER BY post_id ASC LIMIT 1",
				Pload_Converter::META,
				Pload_Converter::STATUS
			)
		);
	}

	/**
	 * One step.
	 *
	 * @return array Summary.
	 */
	public static function run() {
		if ( ! self::active() ) {
			return array( 'reason' => 'not_cleaning' );
		}
		if ( ! Pload_Queue::lock() ) {
			return array( 'reason' => 'locked' );
		}

		$env      = Pload_Environment::get();
		$budget   = Pload_Settings::budget( $env );
		$deadline = microtime( true ) + $budget;
		Pload_Runner::set( true );
		if ( ! empty( $env['limits']['can_set_time'] ) ) {
			@set_time_limit( $budget + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Lets a step finish within its time budget on hosts that allow it.
		}

		$count    = 0;
		$last     = 0;
		$finished = false;

		try {
			while ( microtime( true ) < $deadline ) {
				$id = self::next_id();
				if ( ! $id ) {
					$finished = true;
					break;
				}
				if ( $id === $last ) {
					// Meta could not be deleted; don't spin on it.
					Pload_Logger::log( 'error', sprintf( 'Could not remove Pload data of attachment #%d; stopping this step.', $id ) );
					break;
				}
				Pload_Converter::forget( $id );
				$last = $id;
				$count++;
			}
		} finally {
			Pload_Queue::unlock();
		}

		if ( $finished ) {
			self::finish();
		}
		return array( 'reason' => $finished ? 'finished' : 'budget', 'items' => $count );
	}

	private static function finish() {
		update_option( Pload_Queue::STATE, 'idle', false );
		wp_clear_scheduled_hook( Pload_Queue::HOOK );
		Pload_Runner::set( false );
		foreach ( array( Pload_Queue::QUEUE, Pload_Queue::LAST, Pload_Queue::RUNS, Pload_Queue::IDLE, self::TOTAL ) as $option ) {
			delete_option( $option );
		}
		Pload_Stats::reset();
		set_transient( self::DONE, 1, DAY_IN_SECONDS );
		Pload_Logger::log( 'info', 'All WebP files removed. Optimization is back to "not started".' );
	}
}
