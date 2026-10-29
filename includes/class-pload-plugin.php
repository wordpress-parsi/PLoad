<?php
/**
 * Bootstrap: wires the modules together.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Plugin {

	/** @var Pload_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The queue must be hooked on every request, cron included.
		Pload_Queue::init();
		Pload_Runner::init();
		Pload_Delivery::init();
		add_filter( 'big_image_size_threshold', array( 'Pload_Settings', 'threshold' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
			new Pload_Admin();
		}
	}

	/** Quality numbers of the levels before 1.1.0. */
	const OLD_LEVELS = array(
		'small'    => 70,
		'balanced' => 80,
		'high'     => 90,
	);

	/**
	 * Runs once after an update. 1.1.0 lowered the Balanced and High
	 * quality levels; sites that already have WebP files keep them and
	 * get a one-time offer to rebuild.
	 */
	public static function maybe_upgrade() {
		$stored = (string) get_option( 'pload_version', '' );
		if ( PLOAD_VERSION === $stored ) {
			return;
		}
		if ( '' === $stored || version_compare( $stored, '1.1.0', '<' ) ) {
			$level = Pload_Settings::all()['level'];
			$old   = isset( self::OLD_LEVELS[ $level ] ) ? self::OLD_LEVELS[ $level ] : 0;
			$new   = Pload_Settings::quality();
			if ( $old && $old !== $new && Pload_Stats::counts()['done'] > 0 ) {
				update_option(
					'pload_levels_changed',
					array(
						'from' => $old,
						'to'   => $new,
					),
					false
				);
				Pload_Logger::log( 'info', sprintf( 'Updated to %s: quality level now %d (was %d). Existing WebP files were kept.', PLOAD_VERSION, $new, $old ) );
			}
		}
		update_option( 'pload_version', PLOAD_VERSION, false );
	}

	/**
	 * The environment test is NOT run here on purpose: a fatal error on a
	 * weak host would block activation. It runs from the
	 * Pload page, in a separate request.
	 */
	public static function activate() {
		Pload_Logger::log( 'info', 'Plugin activated (v' . PLOAD_VERSION . ').' );
		if ( ! get_option( 'pload_version' ) && ! get_option( 'pload_env' ) ) {
			update_option( 'pload_version', PLOAD_VERSION, false ); // Fresh install: nothing to migrate.
		}
		set_transient( 'pload_activation_notice', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Stops the schedule but keeps the state, so reactivating resumes
	 * where it left off. WebP files stay in place.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Pload_Queue::HOOK );
		delete_option( Pload_Queue::LOCK );
		delete_option( Pload_Queue::IDLE ); // Re-check once after reactivation.
		Pload_Logger::log( 'info', 'Plugin deactivated.' );
	}
}
