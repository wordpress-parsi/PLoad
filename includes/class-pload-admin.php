<?php
/**
 * Admin wiring: menu, assets, form handlers, AJAX and notices.
 * The page itself is Pload_Admin_Page; the media library is Pload_Media.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Admin {

	const SLUG   = 'pload';
	const CAP    = 'manage_options';
	const SCREEN = 'media_page_pload';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_action( 'wp_ajax_pload_run_test', array( $this, 'ajax_run_test' ) );
		add_action( 'admin_post_pload_run_test', array( $this, 'post_run_test' ) );
		add_action( 'admin_post_pload_clear_log', array( $this, 'post_clear_log' ) );
		add_action( 'admin_post_pload_queue', array( $this, 'post_queue' ) );
		add_action( 'admin_post_pload_deliver', array( $this, 'post_deliver' ) );
		add_action( 'admin_post_pload_cleanup', array( $this, 'post_cleanup' ) );
		add_action( 'admin_post_pload_settings', array( $this, 'post_settings' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'wp_ajax_pload_run_now', array( $this, 'ajax_run_now' ) );
		add_action( 'wp_ajax_pload_auto', array( $this, 'ajax_auto' ) );
		add_action( 'wp_ajax_pload_overview', array( $this, 'ajax_overview' ) );
		add_action( 'wp_ajax_pload_space', array( $this, 'ajax_space' ) );
		add_action( 'admin_post_pload_space', array( $this, 'post_space' ) );
		add_action( 'admin_notices', array( $this, 'auto_pause_notice' ) );
		add_action( 'admin_post_pload_run_now', array( $this, 'post_run_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLOAD_FILE ), array( $this, 'action_links' ) );

		new Pload_Media();
	}

	public function menu() {
		add_media_page(
			__( 'Pload', 'pload' ),
			__( 'Pload', 'pload' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param array<string, string|int> $args
	 * @param string                    $view overview|settings|tools
	 */
	public static function url( $args = array(), $view = '' ) {
		$url = add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'upload.php' ) );
		return $view ? $url . '#' . $view : $url;
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( array(), 'settings' ) ) . '">' . esc_html__( 'Settings', 'pload' ) . '</a>' );
		return $links;
	}

	/** @param string[] $ids */
	private static function on_screens( $ids ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, $ids, true );
	}

	public function assets( $hook ) {
		if ( self::SCREEN !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pload-admin', PLOAD_URL . 'assets/admin.css', array(), PLOAD_VERSION );
		wp_enqueue_script( 'pload-admin', PLOAD_URL . 'assets/admin.js', array(), PLOAD_VERSION, true );
		wp_localize_script(
			'pload-admin',
			'ploadAdmin',
			array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'url'        => self::url(),
				'failed'     => self::url( array( 'pload_failed' => 1 ) ),
				'nonce'      => wp_create_nonce( 'pload_run_now' ),
				'running'    => __( 'Working… this can take up to a minute on slow servers.', 'pload' ),
				'copied'     => __( 'Copied', 'pload' ),
				/* translators: 1: number of files, 2: seconds, 3: clock time */
				'autoDone'   => __( 'Last step from this page: %1$s files in %2$s seconds (%3$s).', 'pload' ),
				/* translators: %s: clock time */
				'autoRecent' => __( 'Checked at %s: a step ran a moment ago, so this page waited.', 'pload' ),
				'autoIdle'   => __( 'Nothing left to do right now.', 'pload' ),
				'autoBack'   => __( 'Back from the background: catching up…', 'pload' ),
				/* translators: %s: clock time */
				'autoOther'  => __( 'Checked at %s.', 'pload' ),
				/* translators: %s: clock time */
				'autoError'  => __( 'The step at %s did not answer. It will be tried again in a minute.', 'pload' ),
			)
		);
	}

	/** Back to the page, on the view the form came from. */
	private static function back( $default_view, $args = array() ) {
		$view = isset( $_POST['pload_view'] ) ? sanitize_key( wp_unslash( $_POST['pload_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Every caller verified its nonce first.
		if ( ! in_array( $view, Pload_Admin_Page::VIEWS, true ) ) {
			$view = $default_view;
		}
		wp_safe_redirect( self::url( $args, $view ) );
		exit;
	}

	private static function deny() {
		wp_die( esc_html__( 'You are not allowed to do this.', 'pload' ) );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	/**
	 * The test runs in its own request so a fatal error on a weak host
	 * can never break the page the user is looking at.
	 */
	public function ajax_run_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'pload_run_test' );
		$report = Pload_Environment::run( ! empty( $_POST['forget'] ) );
		wp_send_json_success( array( 'status' => $report['status'] ) );
	}

	/** No-JS fallback. */
	public function post_run_test() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_run_test' );
		Pload_Environment::run( ! empty( $_POST['forget'] ) );
		self::back( 'overview' );
	}

	public function post_queue() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_queue' );
		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		if ( 'start' === $op ) {
			// The first start can carry the quality picked on the overview.
			if ( isset( $_POST['pload']['level'] ) ) {
				Pload_Settings::save( array( 'level' => sanitize_key( wp_unslash( $_POST['pload']['level'] ) ) ) );
			}
			Pload_Queue::start();
		} elseif ( 'pause' === $op ) {
			Pload_Queue::pause();
		} elseif ( 'rescan' === $op ) {
			Pload_Queue::rescan( 'check' );
		} elseif ( 'rebuild' === $op ) {
			delete_transient( 'pload_settings_changed' );
			delete_option( 'pload_levels_changed' );
			Pload_Queue::rescan( 'rebuild' );
			if ( 'running' !== Pload_Queue::state() ) {
				Pload_Queue::start();
			}
		} elseif ( 'dismiss' === $op ) {
			delete_transient( 'pload_settings_changed' );
			delete_option( 'pload_levels_changed' );
		}
		self::back( 'overview' );
	}

	public function post_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_settings' );
		$before = Pload_Settings::all();
		$q_old  = Pload_Settings::quality();
		$input  = isset( $_POST['pload'] ) && is_array( $_POST['pload'] ) ? map_deep( wp_unslash( $_POST['pload'] ), 'sanitize_textarea_field' ) : array(); // Nonce checked above; save() validates each field again.
		$after  = Pload_Settings::save( $input ); // Each field is sanitized in save().
		$q_new  = Pload_Settings::quality();

		Pload_Logger::log( 'info', sprintf( 'Settings saved: quality %d, minimum saving %d%%, speed %s.', $q_new, $after['min_saving'], $after['speed'] ) );

		$changed = $q_old !== $q_new || $before['min_saving'] !== $after['min_saving'];
		if ( $changed && Pload_Stats::counts()['done'] > 0 ) {
			set_transient( 'pload_settings_changed', 1, HOUR_IN_SECONDS );
		}
		self::back( 'settings', array( 'saved' => 1 ) );
	}

	public function post_cleanup() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_cleanup' );
		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		if ( 'start' === $op && ! empty( $_POST['confirm'] ) && ! Pload_Cleanup::active() ) {
			Pload_Cleanup::start();
		} elseif ( 'stop' === $op && Pload_Cleanup::active() ) {
			Pload_Cleanup::stop();
		}
		self::back( 'overview' );
	}

	/** Reminder on the Plugins screen, while the plugin can still show one. */
	public function row_meta( $meta, $file ) {
		if ( plugin_basename( PLOAD_FILE ) === $file && Pload_Stats::bytes()['files'] > 0 ) {
			$meta[] = sprintf(
				/* translators: %s: link to the settings page */
				esc_html__( 'Before deleting Pload, remove its WebP files on the %s page.', 'pload' ),
				'<a href="' . esc_url( self::url( array(), 'tools' ) ) . '">' . esc_html__( 'Media → Pload', 'pload' ) . '</a>'
			);
		}
		return $meta;
	}

	public function post_deliver() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_deliver' );
		$on = ! empty( $_POST['on'] ) && ! Pload_Cleanup::active();
		update_option( Pload_Delivery::OPTION, $on ? 1 : 0 );
		Pload_Logger::log( 'info', $on ? 'Showing WebP to visitors: on.' : 'Showing WebP to visitors: off.' );
		self::back( 'overview' );
	}

	public function ajax_space() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'pload_space' );
		wp_send_json_success( Pload_Space::estimate() );
	}

	public function post_space() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_space' );
		Pload_Space::estimate();
		self::back( 'overview' );
	}

	/**
	 * A self-pause (disk full) must not go unnoticed, but it is shown only
	 * on the Dashboard, Plugins and Media screens, and only while paused.
	 */
	public function auto_pause_notice() {
		$r = Pload_Space::pause_reason();
		if ( ! $r || 'paused' !== Pload_Queue::state() || ! current_user_can( self::CAP ) || ! self::on_screens( array( 'dashboard', 'plugins', 'upload' ) ) ) {
			return; // The Pload page shows it in place.
		}
		echo '<div class="notice notice-error"><p><strong>Pload:</strong> ' . esc_html( Pload_Admin_Page::pause_text( $r ) ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open Pload', 'pload' ) . '</a></p></div>';
	}

	public function ajax_run_now() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'pload_run_now' );
		wp_send_json_success( Pload_Cleanup::active() ? Pload_Cleanup::run() : Pload_Queue::run( 'manual' ) );
	}

	/** "Keep working while this page is open": one step, unless one ran recently. */
	public function ajax_auto() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'pload_run_now' );
		$state = Pload_Queue::state();
		$since = Pload_Runner::since_last_step();
		if ( 'running' !== $state && Pload_Cleanup::STATE !== $state ) {
			wp_send_json_success( array( 'reason' => 'stopped' ) );
		}
		if ( 'running' === $state && Pload_Queue::is_idle() ) {
			wp_send_json_success( array( 'reason' => 'idle' ) );
		}
		if ( null !== $since && $since < Pload_Runner::GAP ) {
			wp_send_json_success( array( 'reason' => 'recent' ) );
		}
		wp_send_json_success( Pload_Cleanup::active() ? Pload_Cleanup::run() : Pload_Queue::run( 'browser' ) );
	}

	/** Fresh progress card, so the numbers move while the page is open. */
	public function ajax_overview() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'pload_run_now' );
		wp_cache_delete( 'counts', 'pload' );
		ob_start();
		( new Pload_Admin_Page() )->status_card();
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	public function post_run_now() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_run_now' );
		if ( Pload_Cleanup::active() ) {
			Pload_Cleanup::run();
		} else {
			Pload_Queue::run( 'manual' );
		}
		self::back( 'overview' );
	}

	public function post_clear_log() {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'pload_clear_log' );
		Pload_Logger::clear();
		self::back( 'tools' );
	}

	/** Once, on the Plugins screen right after activation. */
	public function activation_notice() {
		if ( ! get_transient( 'pload_activation_notice' ) || ! current_user_can( self::CAP ) || ! self::on_screens( array( 'plugins' ) ) ) {
			return;
		}
		delete_transient( 'pload_activation_notice' );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Pload is installed. Run a quick server test to see whether this host can optimize images.', 'pload' ),
			esc_url( self::url() ),
			esc_html__( 'Run the test', 'pload' )
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		Pload_Environment::detect_crash( ! empty( $_GET['pload_failed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		delete_transient( 'pload_activation_notice' );
		( new Pload_Admin_Page() )->render();
	}
}
