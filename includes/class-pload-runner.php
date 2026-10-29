<?php
/**
 * Keeps the queue moving when WP-Cron does not.
 *
 * WP-Cron may be switched off (DISABLE_WP_CRON, common on staging and some
 * hosts) or never fire on a quiet site, and a normal user cannot create a
 * server cron job. So, besides WP-Cron:
 *
 * 1. Site visits: when no step has run for a while, the next page load
 *    sends one non-blocking request back to the site to run a step (the
 *    same technique WP-Cron itself uses, but independent of it). Cost per
 *    page load while nothing is due: one autoloaded option read.
 * 2. An open Pload page runs a step every minute (see the admin script).
 * 3. A secret URL that an external cron service can call.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Runner {

	const TICK = 'pload_tick'; // autoloaded: ['on' => 0|1, 'due' => timestamp]
	const KEY  = 'pload_key';  // secret for the tick URL
	const GAP  = 50;          // Minimum seconds between steps started from outside.

	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_kick' ) );
		add_action( 'wp_ajax_pload_tick', array( __CLASS__, 'tick' ) );
		add_action( 'wp_ajax_nopriv_pload_tick', array( __CLASS__, 'tick' ) );
	}

	/** Called by the queue: whether outside help may be needed, and from when. */
	public static function set( $on, $delay = 75 ) {
		update_option( self::TICK, array( 'on' => $on ? 1 : 0, 'due' => time() + (int) $delay ), true );
	}

	public static function key() {
		$key = get_option( self::KEY );
		if ( ! $key ) {
			$key = wp_generate_password( 32, false );
			update_option( self::KEY, $key, false );
		}
		return $key;
	}

	public static function tick_url() {
		return add_query_arg(
			array(
				'action' => 'pload_tick',
				'key'    => self::key(),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/** Seconds since the last step of any kind, or null if none yet. */
	public static function since_last_step() {
		$last = get_option( Pload_Queue::LAST, null );
		$t    = is_array( $last ) && ! empty( $last['t'] ) ? (int) $last['t'] : 0;
		$tick = get_option( self::TICK, array() );
		// Empty runs do not update the last-run record, but they do push 'due'.
		if ( is_array( $tick ) && ! empty( $tick['due'] ) ) {
			$t = max( $t, (int) $tick['due'] - 75 );
		}
		return $t ? time() - $t : null;
	}

	public static function maybe_kick() {
		$tick = get_option( self::TICK, array() );
		if ( empty( $tick['on'] ) || time() < (int) $tick['due'] ) {
			return; // The common case: nothing to do, no extra query.
		}
		if ( wp_doing_cron() || ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'pload_tick' === $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compares the action name, to avoid starting a step from inside a step.
			return;
		}
		// Claim this slot first so simultaneous page loads don't all fire.
		self::set( true, 60 );

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				// Core's own filter for requests to this site, used exactly as spawn_cron() does.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'body'      => array(
					'action' => 'pload_tick',
					'key'    => self::key(),
					'via'    => 'visit',
				),
			)
		);
	}

	/** Runs one step for a visit kick or an external cron service. */
	public static function tick() {
		$key = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Called by cron services without a session; protected by a secret key checked with hash_equals() below.
		if ( '' === $key || ! hash_equals( self::key(), $key ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );

		$since = self::since_last_step();
		$state = Pload_Queue::state();
		$out   = array( 'reason' => 'nothing' );

		if ( null !== $since && $since < self::GAP ) {
			$out = array( 'reason' => 'recent' );
		} elseif ( 'running' === $state ) {
			// "visit": started by a page load; "url": an external cron service.
			$via = isset( $_REQUEST['via'] ) && 'visit' === $_REQUEST['via'] ? 'visit' : 'url'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Key already verified; the value is only compared with a fixed string.
			$out = Pload_Queue::run( $via );
		} elseif ( Pload_Cleanup::STATE === $state ) {
			$out = Pload_Cleanup::run();
		}
		wp_send_json( $out );
	}
}
