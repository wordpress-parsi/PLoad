<?php
/**
 * The admin page (Media → Pload): a sidebar with three views.
 * Overview shows progress, Settings holds the one settings form,
 * Tools holds the server test, library actions, log and clean-up.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Admin_Page {

	const VIEWS = array( 'overview', 'settings', 'tools' );

	/** @var array<string, mixed>|false */
	private $report = false;

	/** @var string[] */
	private $crashes = array();

	public function __construct() {
		$this->report  = Pload_Environment::get();
		$this->crashes = Pload_Environment::crashed_stages();
	}

	/** True when the server test passed and an engine can be used. */
	private function ready() {
		return $this->report && ! empty( $this->report['driver'] ) && 'red' !== $this->report['status'];
	}

	public function render() {
		$views = array(
			'overview' => array( __( 'Overview', 'pload' ), 'gauge' ),
			'settings' => array( __( 'Settings', 'pload' ), 'sliders' ),
			'tools'    => array( __( 'Tools', 'pload' ), 'tool' ),
		);

		echo '<div class="wrap pload-app">';
		wp_print_inline_script_tag( "(function(){var v=(location.hash||'').slice(1).split('/')[0];if(['overview','settings','tools'].indexOf(v)<0){v='overview';}document.currentScript.parentNode.setAttribute('data-pload-boot',v);})();" );
		echo '<hr class="wp-header-end">';
		echo '<div class="pload-shell">';

		echo '<aside class="pload-sidebar">';
		echo '<h1 class="pload-sidebar__title">' . esc_html__( 'Pload', 'pload' ) . '</h1>';
		echo '<nav class="pload-nav" aria-label="' . esc_attr__( 'Pload sections', 'pload' ) . '">';
		foreach ( $views as $key => $v ) {
			echo '<a class="pload-nav__link" href="#' . esc_attr( $key ) . '" data-pload-nav="' . esc_attr( $key ) . '">';
			Pload_UI::icon( $v[1] );
			echo '<span>' . esc_html( $v[0] ) . '</span></a>';
		}
		echo '</nav>';
		/* translators: %s: version number */
		echo '<p class="pload-sidebar__foot">' . esc_html( sprintf( __( 'Version %s', 'pload' ), PLOAD_VERSION ) ) . '</p>';
		echo '</aside>';

		echo '<main class="pload-main">';
		$this->view_overview();
		$this->view_settings();
		$this->view_tools();
		echo '</main></div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Overview
	 * ------------------------------------------------------------------- */

	private function view_overview() {
		echo '<section class="pload-view" data-pload-view="overview">';
		Pload_UI::page_head( __( 'Overview', 'pload' ), __( 'Progress of the optimization and what your visitors get.', 'pload' ) );
		echo '<div class="pload-stack">';

		if ( ! $this->report ) {
			$this->first_run();
			echo '</div></section>';
			return;
		}

		if ( Pload_Environment::is_outdated( $this->report ) ) {
			Pload_UI::alert( 'warning', __( 'The server test changed in this version. Run it again so the new rules are applied.', 'pload' ) );
		}
		if ( get_transient( Pload_Cleanup::DONE ) ) {
			delete_transient( Pload_Cleanup::DONE );
			Pload_UI::alert( 'success', __( 'All WebP files were removed. Clear your page cache now, so cached pages stop pointing to them.', 'pload' ) );
		}
		$conflicts = Pload_Conflicts::found();
		if ( $conflicts ) {
			Pload_UI::alert(
				'warning',
				/* translators: %s: plugin names */
				sprintf( __( 'Another image optimizer is active: %s', 'pload' ), implode( is_rtl() ? "\u{060C} " : ', ', $conflicts ) ),
				__( 'Two optimizers can compress the same image twice (lowering quality) or conflict over how WebP is shown. Use only one of them for WebP. If the other plugin only compresses your original files, turn off its WebP feature and turn on the lossless mode, so images are not compressed twice.', 'pload' )
			);
		}

		if ( Pload_Cleanup::active() ) {
			$this->cleaning_card();
		} elseif ( ! $this->ready() ) {
			$this->server_problem_card();
		} elseif ( 'idle' === Pload_Queue::state() ) {
			$this->ready_card();
		} else {
			$this->progress_alerts();
			echo '<div data-pload-live>';
			$this->status_card();
			echo '</div>';
			$this->delivery_card();
			$this->details_card();
		}
		echo '</div></section>';
	}

	private function first_run() {
		echo '<div class="pload-card pload-hero">';
		echo '<span class="pload-hero__icon">';
		Pload_UI::icon( 'server' );
		echo '</span>';
		echo '<h3 class="pload-card__title">' . esc_html__( 'Check this server first', 'pload' ) . '</h3>';
		echo '<p class="pload-card__desc">' . esc_html__( 'Pload converts a small test image with every image engine on this server and measures how fast and how safely it can work. Nothing in your media library is changed. It takes a few seconds.', 'pload' ) . '</p>';
		// Start the test by itself on the first visit; after a crash, wait for a click.
		Pload_UI::form_open( 'pload_run_test', 'overview', array(), 'pload-inline pload-ajax' . ( $this->crashes ? '' : ' pload-autorun' ) );
		Pload_UI::button( __( 'Run the server test', 'pload' ), '', 'play' );
		echo '<span class="pload-spinner" aria-hidden="true"></span>';
		echo '</form>';
		echo '</div>';
	}

	private function server_problem_card() {
		echo '<div class="pload-card">';
		$this->server_summary();
		echo '<div class="pload-actions">';
		Pload_UI::action_button( 'pload_run_test', 'overview', __( 'Test again', 'pload' ), '', 'refresh', array(), '', true );
		echo '<a class="pload-btn pload-btn--quiet" href="#tools">' . esc_html__( 'Server details', 'pload' ) . '</a>';
		echo '</div></div>';
	}

	/** Before the first start: what the server can do, and a start button. */
	private function ready_card() {
		$c = Pload_Stats::counts();
		$e = Pload_Space::get();
		$o = Pload_Space::outlook();
		$s = Pload_Settings::all();

		echo '<div class="pload-card">';
		echo '<div class="pload-card__head">';
		echo '<span class="pload-dot pload-dot--ok" aria-hidden="true"></span>';
		echo '<h3 class="pload-card__title">' . esc_html( $this->status_title() ) . '</h3>';
		echo '</div>';
		echo '<p class="pload-card__desc">' . esc_html__( 'When you start, Pload creates a WebP copy next to each JPEG and PNG image, a little every minute. Original images are never changed, and you can pause at any time.', 'pload' ) . '</p>';

		echo '<div class="pload-stats pload-stats--tiles">';
		Pload_UI::stat(
			number_format_i18n( $c['total'] ),
			__( 'Images in the media library', 'pload' ),
			$e ? sprintf(
				/* translators: 1: size, 2: number of files */
				__( 'about %1$s (%2$s files)', 'pload' ),
				size_format( $e['source'] ),
				number_format_i18n( (int) round( $e['files_per'] * $e['total'] ) )
			) : ''
		);
		if ( $e && $o ) {
			Pload_UI::stat(
				/* translators: %s: size */
				sprintf( __( 'about %s', 'pload' ), size_format( $o['needed'] ) ),
				__( 'Extra space needed for WebP', 'pload' )
			);
			$level = array(
				'ok'      => array( __( 'Enough space', 'pload' ), 'is-ok' ),
				'tight'   => array( __( 'Tight, keep an eye on it', 'pload' ), 'is-warn' ),
				'short'   => array( __( 'Not enough space', 'pload' ), 'is-bad' ),
				'unknown' => array( __( 'Your hosting plan’s quota may be lower. Check it in your control panel.', 'pload' ), 'is-warn' ),
			);
			$lv = isset( $level[ $o['level'] ] ) ? $level[ $o['level'] ] : array( '', '' );
			Pload_UI::stat(
				null === $o['free'] ? __( 'Unknown', 'pload' ) : size_format( $o['free'] ),
				$this->free_label( $o ),
				null === $o['free'] ? __( 'The host does not report it.', 'pload' ) : $lv[0],
				$lv[1]
			);
		}
		echo '</div>';

		if ( ! $e || ! $o ) {
			echo '<div class="pload-inline-row">';
			echo '<p class="pload-muted">' . esc_html__( 'Recommended before starting: estimate how much disk space the WebP files will need. A sample of images is measured; this takes a few seconds.', 'pload' ) . '</p>';
			Pload_UI::form_open( 'pload_space', 'overview', array(), 'pload-inline pload-ajax pload-autorun' );
			Pload_UI::button( __( 'Estimate disk space', 'pload' ), 'ghost', 'disk' );
			echo '<span class="pload-spinner" aria-hidden="true"></span></form>';
			echo '</div>';
		} else {
			if ( 'short' === $o['level'] ) {
				Pload_UI::alert( 'error', __( 'There is not enough free space for all WebP files. Optimization pauses itself when less than 1 GB is left, but free up space or increase your quota before starting.', 'pload' ) );
			} elseif ( 'tight' === $o['level'] ) {
				Pload_UI::alert( 'warning', __( 'The WebP files will use more than half of the free space. Keep an eye on it.', 'pload' ) );
			}
			if ( ! empty( $o['quota'] ) ) {
				echo '<p class="pload-help">' . esc_html( $this->quota_line( $o['quota'] ) ) . ' ' . esc_html__( 'Optimization pauses itself if less than 1 GB is left or files can no longer be written.', 'pload' ) . '</p>';
			} else {
				echo '<p class="pload-help">' . esc_html__( 'On shared hosting the real limit is your plan’s quota, which PHP cannot see. Compare the extra space needed with your hosting plan. Optimization pauses itself if the disk gets nearly full or files can no longer be written.', 'pload' ) . '</p>';
			}
		}

		Pload_UI::form_open( 'pload_queue', 'overview', array( 'op' => 'start' ), 'pload-start' );
		echo '<fieldset class="pload-fieldset"><legend class="pload-card__subtitle">' . esc_html__( 'Quality', 'pload' ) . '</legend>';
		echo '<div class="pload-choice-grid pload-choice-grid--3">';
		$this->quality_choices( $s, false );
		echo '</div>';
		echo '<p class="pload-help">' . esc_html__( 'You can change this later in Settings.', 'pload' ) . '</p>';
		echo '</fieldset>';
		echo '<div class="pload-actions pload-actions--split">';
		Pload_UI::button( __( 'Start optimizing', 'pload' ), '', 'play' );
		echo '<span class="pload-muted">' . esc_html__( 'Original images are never changed. Pause or remove everything at any time.', 'pload' ) . '</span>';
		echo '</div></form>';
		echo '</div>';
	}

	/** One-time messages above the progress card. */
	private function progress_alerts() {
		$reason = Pload_Space::pause_reason();
		if ( $reason && 'paused' === Pload_Queue::state() ) {
			Pload_UI::alert( 'error', self::pause_text( $reason ) );
		}
		$done_pass = get_transient( 'pload_pass_done' );
		if ( is_array( $done_pass ) ) {
			delete_transient( 'pload_pass_done' );
			Pload_UI::alert(
				'success',
				'rebuild' === $done_pass['type']
					/* translators: %s: number of files */
					? sprintf( _n( 'Rebuild finished: %s file was converted again with the new settings.', 'Rebuild finished: %s files were converted again with the new settings.', $done_pass['files'], 'pload' ), number_format_i18n( $done_pass['files'] ) )
					/* translators: %s: number of files */
					: sprintf( _n( 'Library check finished: %s file was converted.', 'Library check finished: %s files were converted.', $done_pass['files'], 'pload' ), number_format_i18n( $done_pass['files'] ) )
			);
		}
		$this->rebuild_prompt( 'overview' );
	}

	/** After quality changes: offer to rebuild existing WebP files. */
	private function rebuild_prompt( $view ) {
		$levels = get_option( 'pload_levels_changed' );
		if ( ! get_transient( 'pload_settings_changed' ) && ! is_array( $levels ) ) {
			return;
		}
		if ( is_array( $levels ) ) {
			Pload_UI::alert_open( 'info', __( 'New quality levels in this version', 'pload' ) );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: new quality number, 2: old quality number */
					__( 'Your quality level now uses %1$d instead of %2$d. In our tests on store photos this gives clearly smaller files with no visible difference. New images already use it; your existing WebP files were kept as they are.', 'pload' ),
					(int) $levels['to'],
					(int) $levels['from']
				)
			) . '</p>';
			echo '<p>' . esc_html__( 'Rebuilding applies it to existing images too. This runs in the background like normal optimization; visitors keep seeing the current files until each one is replaced.', 'pload' ) . '</p>';
		} else {
			Pload_UI::alert_open( 'info', __( 'Apply the new settings to existing images?', 'pload' ) );
			echo '<p>' . esc_html__( 'New images will use the new settings. To apply them to images that are already optimized, rebuild their WebP files. This runs in the background like normal optimization; visitors keep seeing the current files until each one is replaced.', 'pload' ) . '</p>';
		}
		echo '<div class="pload-actions">';
		Pload_UI::action_button( 'pload_queue', $view, __( 'Rebuild with new settings', 'pload' ), 'sm', 'refresh', array( 'op' => 'rebuild' ) );
		Pload_UI::action_button( 'pload_queue', $view, __( 'Only for new images', 'pload' ), 'quiet', '', array( 'op' => 'dismiss' ) );
		echo '</div>';
		Pload_UI::alert_close();
	}

	private function status_title() {
		$st = isset( $this->report['status'] ) ? $this->report['status'] : 'red';
		if ( 'green' === $st ) {
			return __( 'Your server is ready', 'pload' );
		}
		return 'yellow' === $st ? __( 'Your server is ready, with some limits', 'pload' ) : __( 'This server can’t optimize images yet.', 'pload' );
	}

	/**
	 * The live progress card. Also returned over AJAX, so the numbers
	 * refresh while the page is open.
	 */
	public function status_card() {
		$state = Pload_Queue::state();
		$c     = Pload_Stats::counts();
		$b     = Pload_Stats::bytes();
		$last  = get_option( Pload_Queue::LAST, null );
		$pass  = Pload_Queue::queue()['pass'];
		$idle  = 'running' === $state && Pload_Queue::is_idle();
		$since = Pload_Runner::since_last_step();

		if ( 'running' !== $state ) {
			$dot   = 'warn';
			$title = __( 'Paused', 'pload' );
			$line  = __( 'Resume any time; work continues where it stopped.', 'pload' );
		} elseif ( $idle ) {
			$next  = wp_next_scheduled( Pload_Queue::HOOK );
			$dot   = 'ok';
			$title = __( 'All images are optimized', 'pload' );
			$line  = $next
				/* translators: %s: time until next attempt */
				? sprintf( __( 'All images are handled. Failed images will be tried again in %s.', 'pload' ), human_time_diff( $next ) )
				: __( 'New uploads are handled automatically.', 'pload' );
		} elseif ( null !== $since && $since > 180 ) {
			$dot   = 'warn';
			$title = __( 'Optimizing, but steps are late', 'pload' );
			/* translators: %s: duration */
			$line = sprintf( __( 'Running, but no step has run for %s.', 'pload' ), human_time_diff( time() - $since ) ) . ' ' . __( 'See “Details” below.', 'pload' );
		} else {
			$dot   = 'live';
			$title = __( 'Optimizing', 'pload' );
			$line  = __( 'A little every minute, without putting load on the host.', 'pload' );
		}

		echo '<div class="pload-card">';
		echo '<div class="pload-status">';
		echo '<div class="pload-status__text"><div class="pload-card__head">';
		echo '<span class="pload-dot pload-dot--' . esc_attr( $dot ) . '" aria-hidden="true"></span>';
		echo '<h3 class="pload-card__title">' . esc_html( $title ) . '</h3></div>';
		echo '<p class="pload-card__desc">' . esc_html( $line ) . '</p></div>';
		if ( 'running' === $state ) {
			Pload_UI::action_button( 'pload_queue', 'overview', __( 'Pause', 'pload' ), 'ghost', 'pause', array( 'op' => 'pause' ) );
		} else {
			Pload_UI::action_button( 'pload_queue', 'overview', __( 'Resume', 'pload' ), '', 'play', array( 'op' => 'start' ) );
		}
		echo '</div>';

		if ( is_array( $pass ) ) {
			$total = max( 1, (int) $pass['total'] );
			$pct   = min( 100, (int) floor( $pass['checked'] / $total * 100 ) );
			$label = 'rebuild' === $pass['type']
				/* translators: 1: quality, 2: checked images, 3: total images */
				? sprintf( __( 'Rebuilding with quality %1$d: %2$s of %3$s images checked.', 'pload' ), $pass['quality'], number_format_i18n( $pass['checked'] ), number_format_i18n( $pass['total'] ) )
				/* translators: 1: checked images, 2: total images */
				: sprintf( __( 'Checking the library again: %1$s of %2$s images checked.', 'pload' ), number_format_i18n( $pass['checked'] ), number_format_i18n( $pass['total'] ) );
			$left = max( 0, (int) $pass['total'] - (int) $pass['checked'] );
		} else {
			$handled = $c['done'] + $c['skipped'] + $c['failed'];
			$pct     = $c['total'] > 0 ? (int) floor( $handled / $c['total'] * 100 ) : 100;
			/* translators: 1: checked images, 2: total images */
			$label = sprintf( __( '%1$s of %2$s images checked.', 'pload' ), number_format_i18n( $handled ), number_format_i18n( $c['total'] ) );
			$left  = $c['waiting'];
		}
		Pload_UI::progress( $pct, __( 'Optimization progress', 'pload' ) );
		echo '<div class="pload-progress__meta"><span>' . esc_html( $label ) . '</span><bdi>' . esc_html( number_format_i18n( $pct ) ) . '%</bdi></div>';

		// Estimated time, from the real pace of recent runs.
		$space_est = Pload_Space::get();
		if ( $c['done'] > 0 && $b['files'] > 0 ) {
			$avg_sizes = $b['files'] / $c['done'];
		} elseif ( $space_est && $space_est['files_per'] > 0 ) {
			$avg_sizes = $space_est['files_per'];
		} else {
			$avg_sizes = 6;
		}
		$eta = 'running' === $state ? Pload_Queue::eta( (int) ceil( $left * $avg_sizes ) ) : null;

		$saved = max( 0, $b['in'] - $b['out'] );
		echo '<div class="pload-stats">';
		Pload_UI::stat(
			$b['in'] > 0 ? size_format( $saved ) : '—',
			__( 'Space saved', 'pload' ),
			/* translators: %d: percent */
			$b['in'] > 0 ? sprintf( __( '%d%% smaller than the originals', 'pload' ), (int) round( $saved / $b['in'] * 100 ) ) : '',
			'is-ok'
		);
		Pload_UI::stat( number_format_i18n( $b['files'] ), __( 'WebP files created', 'pload' ) );
		Pload_UI::stat(
			number_format_i18n( $c['done'] ),
			__( 'Optimized images', 'pload' ),
			/* translators: %s: number of images */
			$c['failed'] ? sprintf( _n( '%s failed', '%s failed', $c['failed'], 'pload' ), number_format_i18n( $c['failed'] ) ) : '',
			'is-warn'
		);
		/* translators: %s: number of images */
		$waiting = sprintf( _n( '%s image waiting', '%s images waiting', $left, 'pload' ), number_format_i18n( $left ) );
		if ( $eta ) {
			Pload_UI::stat( human_time_diff( time(), time() + $eta ), __( 'Time to finish, at the current pace', 'pload' ), $waiting );
		} else {
			Pload_UI::stat(
				number_format_i18n( $left ),
				__( 'Waiting', 'pload' ),
				'running' === $state && ! $idle && $left > 0 ? __( 'The time estimate appears after a few regular automatic steps.', 'pload' ) : ''
			);
		}
		echo '</div>';

		if ( is_array( $last ) && ! empty( $last['t'] ) ) {
			echo '<p class="pload-foot-note">' . esc_html(
				sprintf(
					/* translators: 1: time ago, 2: number of files, 3: seconds */
					__( 'Last step %1$s ago: %2$d files in %3$s seconds.', 'pload' ),
					human_time_diff( $last['t'] ),
					$last['files'],
					number_format_i18n( $last['secs'], 1 )
				)
			) . '</p>';
		}
		echo '</div>';
	}

	private function delivery_card() {
		$on    = Pload_Delivery::enabled();
		$files = Pload_Stats::bytes()['files'] > 0;

		Pload_UI::form_open( 'pload_deliver', 'overview', array(), 'pload-deliver' );
		echo '<label class="pload-toggle-row pload-toggle-row--card" for="pload-deliver-on">';
		echo '<span class="pload-toggle-row__icon">';
		Pload_UI::icon( 'eye' );
		echo '</span><span class="pload-toggle-row__text">';
		echo '<span class="pload-toggle-row__label">' . esc_html__( 'Show WebP to visitors', 'pload' ) . '</span>';
		echo '<span class="pload-toggle-row__desc">';
		if ( $on ) {
			echo esc_html__( 'On.', 'pload' ) . ' ' . esc_html__( 'Browsers that support WebP get the smaller files; others keep the original images.', 'pload' );
		} elseif ( $files ) {
			echo esc_html__( 'Off. Visitors still see the original images. When you turn this on, images are wrapped in a <picture> tag that offers the WebP copy first. Original files stay as they are, so turning it off again is instant.', 'pload' );
		} else {
			echo esc_html__( 'Available once the first WebP files exist.', 'pload' );
		}
		echo '</span></span>';
		echo '<input type="hidden" name="on" value="0">';
		echo '<input type="checkbox" class="pload-switch" role="switch" id="pload-deliver-on" name="on" value="1"' . checked( $on, true, false ) . disabled( ! $on && ! $files, true, false ) . '>';
		echo '</label>';
		echo '<noscript><div class="pload-actions">';
		Pload_UI::button( __( 'Save', 'pload' ), 'sm' );
		echo '</div></noscript>';
		echo '</form>';
		echo '<p class="pload-help pload-help--after">' . esc_html__( 'After changing this, clear your page cache (cache plugin, LiteSpeed, Cloudflare) so pages are rebuilt.', 'pload' ) . '</p>';
	}

	/** Collapsed: how steps run, disk space, recent steps. */
	private function details_card() {
		$state = Pload_Queue::state();
		$c     = Pload_Stats::counts();

		echo '<details class="pload-card pload-details"><summary class="pload-details__summary">';
		echo '<span>' . esc_html__( 'Details', 'pload' ) . '</span>';
		Pload_UI::icon( 'chevron', 'pload-details__chevron' );
		echo '</summary><div class="pload-details__body">';

		if ( 'running' === $state && ! Pload_Queue::is_idle() ) {
			$this->scheduling();
		}
		if ( $c['waiting'] > 0 ) {
			echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Disk space', 'pload' ) . '</h4>';
			$this->space_block( 'overview' );
		}
		$this->runs_table();

		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Good to know', 'pload' ) . '</h4>';
		echo '<ul class="pload-list">';
		echo '<li>' . esc_html__( 'Check a few product pages, galleries and sliders. If a layout breaks, turn this off and tell us which theme or plugin it was.', 'pload' ) . '</li>';
		echo '<li>' . esc_html__( 'Images printed without the WordPress image functions are covered when compatibility mode is on (see Settings). Background images set in CSS are not converted.', 'pload' ) . '</li>';
		echo '</ul>';
		echo '</div></details>';
	}

	/** How steps are being started on this site, and what to do if they are not. */
	private function scheduling() {
		$since    = Pload_Runner::since_last_step();
		$stalled  = null !== $since && $since > 180;
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'How steps run', 'pload' ) . '</h4>';
		if ( Pload_Queue::cron_works() ) {
			echo '<p class="pload-okline">';
			Pload_UI::icon( 'check' );
			echo '<span>' . esc_html(
				$disabled
					? __( 'Steps are started every minute by the server’s cron job. Nothing else is needed.', 'pload' )
					: __( 'Steps are started regularly by WP-Cron. Nothing else is needed.', 'pload' )
			) . '</span></p>';
		} elseif ( $stalled || $disabled ) {
			Pload_UI::alert_open(
				'warning',
				$stalled
					? __( 'Automatic steps seem to be blocked on this host. Keep this page open to continue for now, and set up one of the options below for steady progress.', 'pload' )
					: __( 'WP-Cron is turned off in wp-config.php and no server cron job is running it. Pload keeps going on site visits and while this page is open, but for steady progress do one of these:', 'pload' )
			);
			echo '<ul class="pload-list">';
			if ( $disabled ) {
				echo '<li>' . esc_html__( 'If you or your host did not turn WP-Cron off on purpose (to replace it with a server cron job), remove this line from wp-config.php:', 'pload' ) . ' <code class="pload-ltr">define( \'DISABLE_WP_CRON\', true );</code></li>';
			}
			echo '<li>' . esc_html__( 'Or add a server cron job that runs every minute (command under “Run steps on a schedule”).', 'pload' ) . '</li>';
			echo '<li>' . esc_html__( 'Or put the private address under “Run steps on a schedule” into a free online cron service.', 'pload' ) . '</li>';
			echo '</ul>';
			Pload_UI::alert_close();
		}

		echo '<div id="pload-auto" class="pload-auto" data-stalled="' . ( $stalled ? '1' : '0' ) . '">';
		echo '<label class="pload-toggle-row" for="pload-auto-on">';
		echo '<span class="pload-toggle-row__icon">';
		Pload_UI::icon( 'clock' );
		echo '</span><span class="pload-toggle-row__text">';
		echo '<span class="pload-toggle-row__label">' . esc_html__( 'Keep working while this page is open (one step a minute)', 'pload' ) . '</span>';
		echo '<span class="pload-toggle-row__desc">' . esc_html__( 'Browsers pause pages in background tabs and when the computer sleeps, so this is for short sessions. For long jobs, use cron.', 'pload' ) . '</span>';
		echo '<span id="pload-auto-status" class="pload-toggle-row__desc pload-auto__status" aria-live="polite"></span>';
		echo '</span>';
		echo '<input type="checkbox" class="pload-switch" role="switch" id="pload-auto-on" checked>';
		echo '</label></div>';

		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Run steps on a schedule', 'pload' ) . '</h4>';
		echo '<p class="pload-help">' . esc_html__( 'Normally nothing is needed: steps start through WP-Cron or on site visits. On a site without visitors (like a staging copy) or a host that blocks both, use one of these:', 'pload' ) . '</p>';
		$this->copy_field( 'pload-tick-url', __( 'Online cron service', 'pload' ), __( 'Create a free job on a service such as cron-job.org that opens this address every minute. Keep the address private.', 'pload' ), Pload_Runner::tick_url() );
		$this->copy_field( 'pload-cron-cmd', __( 'Server cron job (cPanel and similar)', 'pload' ), __( 'Add a job that runs every minute with a command like this one (the PHP path may differ on your host):', 'pload' ), 'php ' . ABSPATH . 'wp-cron.php >/dev/null 2>&1' );
	}

	private function copy_field( $id, $label, $help, $value ) {
		echo '<div class="pload-field">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<div class="pload-copy">';
		echo '<input type="text" readonly class="pload-input pload-ltr" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '">';
		echo '<button type="button" class="pload-btn pload-btn--ghost pload-btn--sm" data-pload-copy="' . esc_attr( $id ) . '">';
		Pload_UI::icon( 'copy' );
		echo '<span>' . esc_html__( 'Copy', 'pload' ) . '</span></button>';
		echo '</div>';
		echo '<p class="pload-field__help">' . esc_html( $help ) . '</p>';
		echo '</div>';
	}

	private function runs_table() {
		$runs = array_reverse( Pload_Queue::runs() );
		if ( ! $runs ) {
			return;
		}
		$sources = array(
			'cron'    => __( 'Cron', 'pload' ),
			'visit'   => __( 'Site visit', 'pload' ),
			'url'     => __( 'Online cron', 'pload' ),
			'browser' => __( 'Open page', 'pload' ),
			'manual'  => __( 'Manual', 'pload' ),
			'tick'    => __( 'Site visit', 'pload' ),
		);
		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Recent steps', 'pload' ) . '</h4>';
		echo '<div class="pload-table-wrap"><table class="pload-table"><thead><tr>';
		foreach ( array( __( 'Time', 'pload' ), __( 'Started by', 'pload' ), __( 'Files', 'pload' ), __( 'Seconds', 'pload' ), __( 'Per file', 'pload' ), __( 'PHP peak memory', 'pload' ) ) as $i => $h ) {
			echo '<th scope="col"' . ( $i > 1 ? ' class="is-num"' : '' ) . '>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $runs as $r ) {
			$per = $r['files'] > 0 ? (int) round( $r['secs'] * 1000 / $r['files'] ) : 0;
			echo '<tr>';
			echo '<td><bdi>' . esc_html( wp_date( 'H:i:s', $r['t'] ) ) . '</bdi></td>';
			echo '<td>' . esc_html( isset( $r['src'], $sources[ $r['src'] ] ) ? $sources[ $r['src'] ] : '—' ) . '</td>';
			echo '<td class="is-num">' . esc_html( number_format_i18n( $r['files'] ) ) . '</td>';
			echo '<td class="is-num">' . esc_html( number_format_i18n( $r['secs'], 1 ) ) . '</td>';
			/* translators: %d: milliseconds */
			echo '<td class="is-num"><bdi>' . esc_html( sprintf( __( '%d ms', 'pload' ), $per ) ) . '</bdi></td>';
			echo '<td class="is-num"><bdi>' . esc_html( size_format( $r['mem'] ) ) . '</bdi></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private function cleaning_card() {
		$total = (int) get_option( Pload_Cleanup::TOTAL, 0 );
		$left  = Pload_Cleanup::remaining();
		$done  = max( 0, $total - $left );
		$pct   = $total > 0 ? (int) floor( $done / $total * 100 ) : 100;

		Pload_UI::alert( 'warning', __( 'Showing WebP to visitors is off. Clear your page cache now (cache plugin, LiteSpeed, Cloudflare), so cached pages stop asking for files that are being removed.', 'pload' ) );
		echo '<div class="pload-card">';
		echo '<div class="pload-card__head"><span class="pload-dot pload-dot--live" aria-hidden="true"></span>';
		echo '<h3 class="pload-card__title">' . esc_html__( 'Removing WebP files', 'pload' ) . '</h3></div>';
		/* translators: %s: number of images */
		echo '<p class="pload-card__desc">' . esc_html( sprintf( _n( '%s image still has WebP files. This runs a little every minute.', '%s images still have WebP files. This runs a little every minute.', $left, 'pload' ), number_format_i18n( $left ) ) ) . '</p>';
		Pload_UI::progress( $pct, __( 'Removing WebP files', 'pload' ) );
		echo '<div class="pload-progress__meta"><span></span><bdi>' . esc_html( number_format_i18n( $pct ) ) . '%</bdi></div>';
		echo '<div class="pload-actions">';
		Pload_UI::action_button( 'pload_run_now', 'overview', __( 'Run one step now', 'pload' ), '', 'step', array(), '', true );
		Pload_UI::action_button( 'pload_cleanup', 'overview', __( 'Stop removing', 'pload' ), 'ghost', '', array( 'op' => 'stop' ) );
		echo '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private function quality_choices( $s, $with_custom ) {
		$levels = array(
			'small'    => array( __( 'Smaller files', 'pload' ), __( 'Most savings. A slight loss may be visible in detailed photos.', 'pload' ), '' ),
			'balanced' => array( __( 'Balanced', 'pload' ), __( 'Hardly any visible difference, much smaller files.', 'pload' ), __( 'Recommended', 'pload' ) ),
			'high'     => array( __( 'High quality', 'pload' ), __( 'For product photography and sites where image detail matters.', 'pload' ), '' ),
		);
		$current = $s['level'];
		if ( ! $with_custom && 'custom' === $current ) {
			$current = '';
		}
		foreach ( $levels as $key => $l ) {
			Pload_UI::choice( 'pload[level]', $key, $current, $l[0], $l[1], $l[2] );
		}
		if ( $with_custom ) {
			Pload_UI::choice( 'pload[level]', 'custom', $current, __( 'Custom', 'pload' ), __( 'Set the quality number yourself.', 'pload' ) );
		}
	}

	private function view_settings() {
		$s = Pload_Settings::all();

		echo '<section class="pload-view" data-pload-view="settings">';
		Pload_UI::page_head( __( 'Settings', 'pload' ), __( 'How images are converted and where WebP is shown.', 'pload' ) );
		echo '<div class="pload-stack">';
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Pload_UI::alert( 'success', __( 'Settings saved.', 'pload' ) );
		}
		$this->rebuild_prompt( 'settings' );
		echo '</div>';

		Pload_UI::form_open( 'pload_settings', 'settings', array(), 'pload-settings' );

		Pload_UI::section_title( __( 'WebP quality', 'pload' ) );
		echo '<div class="pload-card">';
		echo '<p class="pload-card__desc">' . esc_html__( 'Smaller files make pages faster. A change applies to WebP files created from now on.', 'pload' ) . '</p>';
		echo '<div class="pload-choice-grid pload-choice-grid--4">';
		$this->quality_choices( $s, true );
		echo '</div>';
		echo '<div class="pload-range" data-show-if="pload[level]:custom"' . ( 'custom' === $s['level'] ? '' : ' hidden' ) . '>';
		echo '<label for="pload-custom">' . esc_html__( 'Quality', 'pload' ) . '</label>';
		echo '<input type="range" id="pload-custom" name="pload[custom]" min="50" max="95" step="1" value="' . esc_attr( $s['custom'] ) . '" data-pload-range>';
		echo '<output class="pload-range__value" for="pload-custom">' . esc_html( $s['custom'] ) . '</output>';
		echo '<span class="pload-field__help">' . esc_html__( 'from 50 to 95', 'pload' ) . '</span>';
		echo '</div>';
		echo '</div>';

		Pload_UI::section_title( __( 'Exclusions', 'pload' ) );
		echo '<div class="pload-card"><div class="pload-grid-2">';
		echo '<div class="pload-field">';
		echo '<label for="pload-skip-urls">' . esc_html__( 'Pages that keep the original images', 'pload' ) . '</label>';
		echo '<textarea id="pload-skip-urls" name="pload[skip_urls]" rows="4" class="pload-input pload-textarea pload-ltr" placeholder="/checkout&#10;/product/sample-product">' . esc_textarea( $s['skip_urls'] ) . '</textarea>';
		echo '<p class="pload-field__help">' . esc_html__( 'One per line; part of the address is enough.', 'pload' ) . '</p>';
		echo '</div>';
		echo '<div class="pload-field">';
		echo '<label for="pload-skip-classes">' . esc_html__( 'Images with these CSS classes', 'pload' ) . '</label>';
		echo '<input type="text" id="pload-skip-classes" name="pload[skip_classes]" class="pload-input pload-ltr" value="' . esc_attr( $s['skip_classes'] ) . '" placeholder="no-webp slider-image">';
		echo '<p class="pload-field__help">' . esc_html__( 'The class can be on the image tag or, for image blocks, in “Additional CSS class”. The class pload-skip always works. To stop a single image from being optimized at all, use “Don’t optimize” in the Pload column of the media library.', 'pload' ) . '</p>';
		echo '</div>';
		echo '</div></div>';

		Pload_UI::section_title( __( 'Large uploads', 'pload' ) );
		echo '<div class="pload-card">';
		echo '<div class="pload-field pload-field--flush">';
		echo '<label for="pload-max-size">' . esc_html__( 'Maximum image size', 'pload' ) . '</label>';
		echo '<div class="pload-suffix"><input type="number" id="pload-max-size" name="pload[max_size]" min="0" max="12000" step="10" class="pload-input" placeholder="2560" value="' . esc_attr( $s['max_size'] ? $s['max_size'] : '' ) . '"><span>' . esc_html__( 'pixels', 'pload' ) . '</span></div>';
		echo '<p class="pload-field__help">' . esc_html__( 'New uploads wider or taller than this are scaled down for the site. WordPress keeps the untouched original next to the scaled copy, so nothing is lost. Leave empty for the WordPress default (2560). Photos from phones are often 4000 px or more.', 'pload' ) . '</p>';
		echo '</div></div>';

		Pload_UI::section_title( __( 'Advanced', 'pload' ) );
		// Open by itself when something here differs from the defaults, so a changed setting is never hidden.
		$d        = Pload_Settings::DEFAULTS;
		$adv_open = false;
		foreach ( array( 'lazy_compat', 'compat', 'debug', 'speed', 'order', 'min_saving', 'cdn_url' ) as $k ) {
			if ( (string) $s[ $k ] !== (string) $d[ $k ] ) {
				$adv_open = true;
			}
		}
		echo '<details class="pload-advanced"' . ( $adv_open ? ' open' : '' ) . '><summary class="pload-advanced__summary">';
		echo '<span class="pload-advanced__icon">';
		Pload_UI::icon( 'sliders' );
		echo '</span><span class="pload-advanced__text">';
		echo '<span class="pload-advanced__title">' . esc_html__( 'Advanced settings', 'pload' ) . '</span>';
		echo '<span class="pload-advanced__desc">' . esc_html__( 'Theme lazy loading, compatibility mode, debug comment, processing speed, order, minimum saving and CDN. Usually nothing to change here.', 'pload' ) . '</span>';
		echo '</span>';
		echo '<span class="pload-advanced__action"><span class="pload-advanced__show">' . esc_html__( 'Show', 'pload' ) . '</span><span class="pload-advanced__hide">' . esc_html__( 'Hide', 'pload' ) . '</span>';
		Pload_UI::icon( 'chevron', 'pload-details__chevron' );
		echo '</span></summary>';
		echo '<div class="pload-advanced__body">';

		echo '<div class="pload-toggle-list">';
		Pload_UI::toggle_row( 'pload[lazy_compat]', 'pload-lazy', (bool) $s['lazy_compat'], 'layers', __( 'Support theme lazy loading', 'pload' ), __( 'Themes like WoodMart load images with their own script (the real address is kept in data-src). For these images the addresses are switched to WebP directly, so lazy loading keeps working. Turn off only if such images break.', 'pload' ) );
		Pload_UI::toggle_row( 'pload[compat]', 'pload-compat', (bool) $s['compat'], 'layout', __( 'Compatibility mode', 'pload' ), __( 'Covers images that themes, page builders and plugins print without the WordPress image functions. Costs a few milliseconds per page; with a page cache this happens once per page. Turn it on only if some images are still not shown as WebP, and test your pages after enabling it.', 'pload' ) );
		Pload_UI::toggle_row( 'pload[debug]', 'pload-debug', (bool) $s['debug'], 'code', __( 'Debug comment', 'pload' ), __( 'Adds a short comment at the end of each page’s source code: how many images got WebP, how many did not, and how long the HTML check took. Useful while testing; turn it off afterwards.', 'pload' ) );
		echo '</div>';
		if ( ! Pload_Delivery::enabled() ) {
			echo '<p class="pload-help">' . esc_html__( 'Lazy loading support and compatibility mode work only while “Show WebP to visitors” is on. It is off right now.', 'pload' ) . '</p>';
		}

		echo '<div class="pload-card pload-card--spaced">';
		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Processing speed', 'pload' ) . '</h4>';
		echo '<div class="pload-choice-grid pload-choice-grid--3">';
		Pload_UI::choice( 'pload[speed]', 'slow', $s['speed'], __( 'Slow', 'pload' ), __( 'Half the work per step. For weak hosting or busy sites.', 'pload' ) );
		Pload_UI::choice( 'pload[speed]', 'normal', $s['speed'], __( 'Normal', 'pload' ), __( 'What the server test measured as safe.', 'pload' ) );
		Pload_UI::choice( 'pload[speed]', 'fast', $s['speed'], __( 'Fast', 'pload' ), __( 'Twice the work per step. For VPS and strong hosting.', 'pload' ) );
		echo '</div>';

		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Order', 'pload' ) . '</h4>';
		echo '<div class="pload-choice-grid">';
		Pload_UI::choice( 'pload[order]', 'newest', $s['order'], __( 'Newest images first', 'pload' ), __( 'Recent products and posts get WebP first. Recommended for large, older libraries.', 'pload' ) );
		Pload_UI::choice( 'pload[order]', 'oldest', $s['order'], __( 'Oldest images first', 'pload' ), __( 'Works through the library in upload order.', 'pload' ) );
		echo '</div>';
		echo '<p class="pload-field__help">' . esc_html__( 'New uploads are always handled first, whatever the order.', 'pload' ) . '</p>';

		echo '<div class="pload-field pload-field--narrow">';
		echo '<label for="pload-min-saving">' . esc_html__( 'Minimum saving', 'pload' ) . '</label>';
		echo '<div class="pload-suffix"><input type="number" id="pload-min-saving" name="pload[min_saving]" min="0" max="50" step="1" class="pload-input" value="' . esc_attr( $s['min_saving'] ) . '"><span>%</span></div>';
		echo '<p class="pload-field__help">' . esc_html__( 'A WebP file is kept only if it is at least this much smaller than the original. Otherwise the original is used.', 'pload' ) . '</p>';
		echo '</div>';

		echo '<div class="pload-field pload-field--narrow pload-field--wide">';
		echo '<label for="pload-cdn">' . esc_html__( 'CDN address', 'pload' ) . '</label>';
		echo '<input type="url" id="pload-cdn" name="pload[cdn_url]" class="pload-input pload-ltr" placeholder="https://cdn.example.com" value="' . esc_attr( $s['cdn_url'] ) . '">';
		echo '<p class="pload-field__help">' . esc_html__( 'Only if your images are served from a CDN that mirrors this site (pull zone). The WebP addresses then point to it. Leave empty otherwise.', 'pload' ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</details>';

		echo '<div class="pload-savebar">';
		Pload_UI::button( __( 'Save changes', 'pload' ) );
		echo '</div>';
		echo '</form>';
		echo '</section>';
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private function view_tools() {
		echo '<section class="pload-view" data-pload-view="tools">';
		Pload_UI::page_head( __( 'Tools', 'pload' ), __( 'Server test, library checks, activity log and clean-up.', 'pload' ) );
		echo '<div class="pload-stack">';

		if ( ! $this->report ) {
			echo '<div class="pload-card">';
			echo '<h3 class="pload-card__title">' . esc_html__( 'Server check', 'pload' ) . '</h3>';
			echo '<p class="pload-card__desc">' . esc_html__( 'The server has not been tested yet.', 'pload' ) . '</p>';
			Pload_UI::action_button( 'pload_run_test', 'tools', __( 'Run the server test', 'pload' ), '', 'play', array(), '', true );
			echo '</div>';
		} else {
			$this->server_card();
			if ( $this->ready() && ! Pload_Cleanup::active() ) {
				$this->library_card();
			}
		}
		$this->log_card();
		if ( $this->report ) {
			$this->report_card();
			if ( ! Pload_Cleanup::active() ) {
				$this->danger_card();
			}
		}
		echo '</div></section>';
	}

	/** Status line, issues and meta of the last server test. */
	private function server_summary() {
		$status = isset( $this->report['status'] ) ? $this->report['status'] : 'red';
		$titles = array(
			'green'  => __( 'Ready. This server can create WebP images.', 'pload' ),
			'yellow' => __( 'Works, with some limits.', 'pload' ),
			'red'    => __( 'This server can’t optimize images yet.', 'pload' ),
		);
		$dots   = array(
			'green'  => 'ok',
			'yellow' => 'warn',
			'red'    => 'bad',
		);

		echo '<div class="pload-card__head">';
		echo '<span class="pload-dot pload-dot--' . esc_attr( $dots[ $status ] ) . '" aria-hidden="true"></span>';
		echo '<h3 class="pload-card__title">' . esc_html( $titles[ $status ] ) . '</h3>';
		echo '</div>';

		$meta = array();
		if ( ! empty( $this->report['driver'] ) ) {
			/* translators: %s: image engine name */
			$meta[] = sprintf( __( 'Engine in use: %s', 'pload' ), $this->engine_name( $this->report['driver'] ) );
		}
		if ( ! empty( $this->report['time'] ) ) {
			/* translators: %s: time difference, e.g. "5 mins" */
			$meta[] = sprintf( __( 'Tested %s ago', 'pload' ), human_time_diff( $this->report['time'] ) );
		}
		if ( $meta ) {
			echo '<p class="pload-card__desc">' . esc_html( implode( '. ', $meta ) ) . '.</p>';
		}

		if ( ! empty( $this->report['issues'] ) ) {
			echo '<ul class="pload-issues">';
			foreach ( $this->report['issues'] as $issue ) {
				echo '<li class="pload-issues__item is-' . esc_attr( $issue['level'] ) . '">' . esc_html( $this->issue_text( $issue ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function server_card() {
		$report = $this->report;
		echo '<div class="pload-card">';
		echo '<div class="pload-status"><div class="pload-status__text">';
		$this->server_summary();
		echo '</div><div class="pload-actions">';
		Pload_UI::action_button( 'pload_run_test', 'tools', __( 'Test again', 'pload' ), 'ghost', 'refresh', array(), '', true );
		if ( $this->crashes ) {
			Pload_UI::action_button( 'pload_run_test', 'tools', __( 'Test again, including skipped steps', 'pload' ), 'quiet', '', array( 'forget' => 1 ), '', true );
		}
		echo '</div></div>';

		if ( ! empty( $report['drivers'] ) || ! empty( $report['limits'] ) ) {
			echo '<details class="pload-details pload-details--inner"><summary class="pload-details__summary">';
			echo '<span>' . esc_html__( 'Engines and limits', 'pload' ) . '</span>';
			Pload_UI::icon( 'chevron', 'pload-details__chevron' );
			echo '</summary><div class="pload-details__body">';
			if ( ! empty( $report['drivers'] ) ) {
				$this->engines_table();
			}
			if ( ! empty( $report['limits'] ) ) {
				$this->limits();
			}
			if ( ! empty( $report['budget'] ) ) {
				$this->plan();
			}
			echo '</div></details>';
		}
		echo '</div>';
	}

	private function library_card() {
		$state = Pload_Queue::state();
		echo '<div class="pload-card">';
		echo '<h3 class="pload-card__title">' . esc_html__( 'Library and disk space', 'pload' ) . '</h3>';
		echo '<p class="pload-card__desc">' . esc_html__( 'Normally nothing is needed here: new uploads are found automatically.', 'pload' ) . '</p>';
		if ( 'idle' !== $state ) {
			echo '<div class="pload-actions">';
			Pload_UI::action_button( 'pload_queue', 'tools', __( 'Check the whole library again', 'pload' ), 'ghost', 'refresh', array( 'op' => 'rescan' ) );
			Pload_UI::action_button( 'pload_run_now', 'tools', __( 'Run one step now', 'pload' ), 'ghost', 'step', array(), '', true );
			echo '</div>';
		}
		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Disk space', 'pload' ) . '</h4>';
		$this->space_block( 'tools' );
		echo '</div>';
	}

	/** Space outlook rows and an estimate button. */
	private function space_block( $view ) {
		$e = Pload_Space::get();
		$o = Pload_Space::outlook();
		if ( ! $e || ! $o ) {
			echo '<p class="pload-help">' . esc_html__( 'Estimate how much more disk space the remaining WebP files will need.', 'pload' ) . '</p>';
			Pload_UI::action_button( 'pload_space', $view, __( 'Estimate disk space', 'pload' ), 'ghost', 'disk', array(), '', true );
			return;
		}
		Pload_UI::kv(
			array(
				/* translators: %s: size */
				__( 'Extra space needed for WebP', 'pload' ) => sprintf( __( 'about %s', 'pload' ), size_format( $o['needed'] ) ),
				$this->free_label( $o )                     => null === $o['free'] ? __( 'unknown (the host does not report it)', 'pload' ) : size_format( $o['free'] ),
			)
		);
		if ( ! empty( $o['quota'] ) ) {
			echo '<p class="pload-help">' . esc_html( $this->quota_line( $o['quota'] ) ) . '</p>';
		} elseif ( ! empty( $o['shared'] ) ) {
			echo '<p class="pload-help">' . esc_html__( 'This is the free space of the whole server disk. Your hosting plan has its own quota, which PHP cannot see; compare the extra space needed with the free space shown in your control panel. If the quota fills up, optimization pauses itself.', 'pload' ) . '</p>';
		}
		if ( 'short' === $o['level'] ) {
			Pload_UI::alert( 'error', __( 'There is not enough free space for all WebP files. Optimization pauses itself when less than 1 GB is left, but free up space or increase your quota before starting.', 'pload' ) );
		} elseif ( 'tight' === $o['level'] ) {
			Pload_UI::alert( 'warning', __( 'The WebP files will use more than half of the free space. Keep an eye on it.', 'pload' ) );
		}
		if ( $e['missing'] > 0.02 ) {
			/* translators: %d: percent */
			echo '<p class="pload-help">' . esc_html( sprintf( __( 'About %d%% of the image files listed in the database are missing on disk. They will be skipped.', 'pload' ), (int) round( $e['missing'] * 100 ) ) ) . '</p>';
		}
		echo '<div class="pload-inline-row">';
		/* translators: 1: number of sampled images, 2: time ago */
		echo '<p class="pload-help">' . esc_html( sprintf( __( 'Estimated from %1$s sampled images, %2$s ago.', 'pload' ), number_format_i18n( $e['sampled'] ), human_time_diff( $e['t'] ) ) ) . '</p>';
		Pload_UI::action_button( 'pload_space', $view, __( 'Estimate again', 'pload' ), 'quiet', 'refresh', array(), '', true );
		echo '</div>';
	}

	private function log_card() {
		$rows = Pload_Logger::get();
		echo '<div class="pload-card">';
		echo '<div class="pload-status"><div class="pload-status__text">';
		echo '<h3 class="pload-card__title">' . esc_html__( 'Activity log', 'pload' ) . '</h3>';
		if ( ! $rows ) {
			echo '<p class="pload-card__desc">' . esc_html__( 'No activity yet.', 'pload' ) . '</p></div></div></div>';
			return;
		}
		echo '</div>';
		Pload_UI::action_button( 'pload_clear_log', 'tools', __( 'Clear log', 'pload' ), 'quiet', 'trash' );
		echo '</div>';
		echo '<div class="pload-log">';
		foreach ( $rows as $row ) {
			echo '<div class="pload-log__row is-' . esc_attr( $row['l'] ) . '">';
			echo '<span class="pload-log__when"><bdi>' . esc_html( wp_date( 'Y-m-d H:i:s', $row['t'] ) ) . '</bdi></span>';
			echo '<span class="pload-log__level">' . esc_html( $row['l'] ) . '</span>';
			echo '<code class="pload-log__msg pload-ltr">' . esc_html( $row['m'] ) . '</code>';
			echo '</div>';
		}
		echo '</div></div>';
	}

	private function report_card() {
		$report  = $this->report;
		$q       = Pload_Queue::queue();
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$plugins[] = dirname( $file ) === '.' ? $file : dirname( $file );
		}
		$theme = wp_get_theme();

		$full = array(
			'plugin'    => PLOAD_VERSION,
			'state'     => Pload_Queue::state(),
			'idle'      => Pload_Queue::is_idle(),
			'deliver'   => Pload_Delivery::enabled(),
			'settings'  => Pload_Settings::all(),
			'quality'   => Pload_Settings::quality(),
			'budget'    => Pload_Settings::budget( $report ),
			'counts'    => Pload_Stats::counts(),
			'bytes'     => Pload_Stats::bytes(),
			'queue'     => array(
				'cursor'    => $q['cursor'],
				'current'   => $q['current'],
				'priority'  => count( $q['priority'] ),
				'retry'     => count( $q['retry'] ),
				'scan_done' => $q['scan_done'],
				'pass'      => $q['pass'],
			),
			'next_run'  => wp_next_scheduled( Pload_Queue::HOOK ),
			'wp_cron'   => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'runs'      => Pload_Queue::runs(),
			'conflicts' => Pload_Conflicts::found(),
			'theme'     => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'plugins'   => $plugins,
			'locale'    => get_locale(),
			'server'    => $report,
		);

		echo '<div class="pload-card">';
		echo '<div class="pload-status"><div class="pload-status__text">';
		echo '<h3 class="pload-card__title">' . esc_html__( 'Technical report (for support)', 'pload' ) . '</h3>';
		echo '<p class="pload-card__desc">' . esc_html__( 'Includes settings, progress, recent steps, the server test, the theme and the list of active plugins. It contains no content, user data or passwords.', 'pload' ) . '</p>';
		echo '</div>';
		echo '<button type="button" class="pload-btn pload-btn--ghost" data-pload-copy="pload-report">';
		Pload_UI::icon( 'copy' );
		echo '<span>' . esc_html__( 'Copy report', 'pload' ) . '</span></button>';
		echo '</div>';
		echo '<details class="pload-details pload-details--inner"><summary class="pload-details__summary"><span>' . esc_html__( 'Show report', 'pload' ) . '</span>';
		Pload_UI::icon( 'chevron', 'pload-details__chevron' );
		echo '</summary><div class="pload-details__body">';
		echo '<textarea id="pload-report" readonly rows="14" class="pload-input pload-textarea pload-ltr pload-code">' . esc_textarea( wp_json_encode( $full, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</textarea>';
		echo '</div></details>';
		echo '</div>';
	}

	private function danger_card() {
		$left = Pload_Cleanup::remaining();
		if ( ! $left ) {
			return;
		}
		$b = Pload_Stats::bytes();

		echo '<div class="pload-card pload-card--danger">';
		echo '<h3 class="pload-card__title pload-danger-text">' . esc_html__( 'Remove all WebP files', 'pload' ) . '</h3>';
		echo '<p class="pload-card__desc">' . esc_html(
			sprintf(
				/* translators: 1: number of files, 2: number of images */
				__( 'Deletes the %1$s WebP files Pload created for %2$s images and resets optimization to "not started". Your original images are not touched.', 'pload' ),
				number_format_i18n( $b['files'] ),
				number_format_i18n( $left )
			)
		) . '</p>';
		echo '<ul class="pload-list">';
		echo '<li>' . esc_html__( 'Showing WebP to visitors is turned off first, so no page asks for a deleted file.', 'pload' ) . '</li>';
		echo '<li>' . esc_html__( 'Files are removed a little every minute, so even a large library is safe on shared hosting.', 'pload' ) . '</li>';
		echo '<li>' . esc_html__( 'Use this before deleting the plugin. Deleting the plugin alone leaves the WebP files in the uploads folder.', 'pload' ) . '</li>';
		echo '</ul>';

		Pload_UI::form_open( 'pload_cleanup', 'overview', array( 'op' => 'start' ), 'pload-danger-form' );
		echo '<label class="pload-check"><input type="checkbox" name="confirm" value="1" required> <span>' . esc_html__( 'I understand that all WebP files will be deleted.', 'pload' ) . '</span></label>';
		Pload_UI::button( __( 'Remove all WebP files', 'pload' ), 'danger', 'trash', array( 'data-pload-confirm' => __( 'Remove all WebP files created by Pload?', 'pload' ) ) );
		echo '</form></div>';
	}

	private function engines_table() {
		$report = $this->report;
		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Image engines', 'pload' ) . '</h4>';
		echo '<div class="pload-table-wrap"><table class="pload-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Engine', 'pload' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'WebP (1200×800 test image)', 'pload' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'AVIF (quick check, for a later version)', 'pload' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array( 'imagick', 'gd' ) as $key ) {
			if ( ! isset( $report['drivers'][ $key ] ) ) {
				continue;
			}
			$d = $report['drivers'][ $key ];
			echo '<tr><td><strong>' . esc_html( $this->engine_name( $key ) ) . '</strong>';
			if ( $d['available'] && $d['version'] ) {
				echo '<br><span class="pload-muted">' . esc_html( $d['version'] ) . '</span>';
			}
			echo '</td>';

			if ( ! $d['available'] ) {
				echo '<td colspan="2" class="pload-muted">' . esc_html__( 'Not installed on this server', 'pload' ) . '</td></tr>';
				continue;
			}
			echo '<td>';
			$this->format_cell( $d['webp'] );
			echo '</td><td>';
			if ( $d['avif']['ok'] ) {
				echo '<span class="pload-yes">' . esc_html__( 'Supported', 'pload' ) . '</span>';
			} else {
				echo '<span class="pload-no">' . esc_html( $this->error_text( $d['avif']['error'] ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<p class="pload-help">' . esc_html__( 'The test image is synthetic and saved at high quality, so its savings look bigger than real life. On photos already saved by WordPress, expect roughly 25–35% smaller files.', 'pload' ) . '</p>';
		if ( isset( $report['wp_editor_webp'] ) ) {
			echo '<p class="pload-help">' . esc_html(
				$report['wp_editor_webp']
					? __( 'WordPress itself also reports WebP support.', 'pload' )
					: __( 'WordPress itself does not report WebP support on this server.', 'pload' )
			) . '</p>';
		}
	}

	private function limits() {
		$report = $this->report;
		$l      = $report['limits'];
		$u      = $report['uploads'];

		$memory = -1 === (int) $l['memory_bytes'] ? __( 'Unlimited', 'pload' ) : size_format( $l['memory_bytes'] );
		if ( $l['memory_raw'] !== $l['memory_image_raw'] ) {
			/* translators: %s: memory limit before WordPress raised it */
			$memory .= ' ' . sprintf( __( '(raised from %s for image work)', 'pload' ), $l['memory_raw'] );
		}

		// Read now, not from the last test: the quota and the disk change.
		$free = Pload_Space::free();

		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Server limits', 'pload' ) . '</h4>';
		Pload_UI::kv(
			array(
				__( 'PHP memory for images', 'pload' )  => $memory,
				__( 'Maximum script time', 'pload' )    => $l['max_execution'] > 0
					/* translators: %d: seconds */
					? sprintf( _n( '%d second', '%d seconds', $l['max_execution'], 'pload' ), $l['max_execution'] )
					: __( 'Unlimited', 'pload' ),
				__( 'Can extend script time', 'pload' ) => $l['can_set_time'] ? __( 'Yes', 'pload' ) : __( 'No', 'pload' ),
				__( 'Uploads folder', 'pload' )         => $u['writable'] ? __( 'Writable', 'pload' ) : __( 'Not writable', 'pload' ),
				$this->free_label( array( 'shared' => Pload_Space::shared(), 'quota' => Pload_Space::quota() ) ) => null === $free ? __( 'Unknown', 'pload' ) : size_format( $free ),
				__( 'PHP version', 'pload' )            => $report['php'],
				__( 'WordPress version', 'pload' )      => $report['wp'],
			)
		);
	}

	private function plan() {
		$report = $this->report;
		$b      = $report['budget'];
		$secs   = Pload_Settings::budget( $report );

		echo '<h4 class="pload-card__subtitle">' . esc_html__( 'Processing plan for this server', 'pload' ) . '</h4>';
		echo '<p class="pload-help">' . esc_html__( 'Optimization runs in short, regular steps within these limits. The processing speed setting changes the work time.', 'pload' ) . '</p>';
		Pload_UI::kv(
			array(
				/* translators: %d: seconds */
				__( 'Work time per run', 'pload' )              => sprintf( _n( '%d second', '%d seconds', $secs, 'pload' ), $secs ),
				__( 'Largest image processed', 'pload' )        => $b['max_mp'] > 0
					/* translators: %d: megapixels */
					? sprintf( __( 'about %d megapixels', 'pload' ), $b['max_mp'] )
					: __( 'None, memory is too low', 'pload' ),
				__( 'Image sizes per run (estimate)', 'pload' ) => $b['per_run'] > 0
					/* translators: %d: number */
					? sprintf( __( 'about %d', 'pload' ), (int) round( $b['per_run'] * $secs / max( 1, $b['seconds'] ) ) )
					: '—',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Text helpers
	 * ------------------------------------------------------------------- */

	/** On shared hosting the number is the server disk, not the plan. */
	private function free_label( $o ) {
		if ( ! empty( $o['quota'] ) ) {
			return __( 'Free in your hosting plan', 'pload' );
		}
		return ! empty( $o['shared'] ) ? __( 'Free on the server disk', 'pload' ) : __( 'Free disk space', 'pload' );
	}

	/** "Hosting plan quota (cPanel): 2 GB of 10 GB used, checked 3 hours ago." */
	private function quota_line( $q ) {
		return sprintf(
			/* translators: 1: used space, 2: quota, 3: time ago */
			__( 'Hosting plan quota (from cPanel): %1$s of %2$s used, checked %3$s ago.', 'pload' ),
			size_format( $q['used'], 1 ),
			size_format( $q['limit'] ),
			human_time_diff( $q['t'] )
		);
	}

	public static function pause_text( $r ) {
		return 'low_disk' === $r['code']
			? __( 'Optimization paused itself because less than 1 GB of disk space is left. Free up space or increase your hosting quota, then resume.', 'pload' )
			: __( 'Optimization paused itself because files could not be written several times in a row. The disk or your hosting quota may be full. Check your space, then resume.', 'pload' );
	}

	private function format_cell( $r ) {
		if ( ! $r['ok'] ) {
			echo '<span class="pload-no">' . esc_html( $this->error_text( $r['error'] ) ) . '</span>';
			return;
		}
		/* translators: %d: milliseconds */
		echo '<span class="pload-yes"><bdi>' . esc_html( sprintf( __( '%d ms', 'pload' ), $r['ms'] ) ) . '</bdi></span>';
		if ( $r['in'] > 0 && $r['out'] > 0 ) {
			echo '<br><bdi class="pload-muted">' . esc_html(
				sprintf(
					/* translators: 1: original size, 2: new size, 3: percent saved */
					__( '%1$s → %2$s (%3$d%% smaller)', 'pload' ),
					size_format( $r['in'] ),
					size_format( $r['out'] ),
					(int) round( ( 1 - $r['out'] / $r['in'] ) * 100 )
				)
			) . '</bdi>';
		}
		if ( $r['mem_reliable'] && $r['mem'] > 0 ) {
			/* translators: %s: memory size */
			echo '<br><bdi class="pload-muted">' . esc_html( sprintf( __( 'PHP memory used: %s', 'pload' ), size_format( $r['mem'] ) ) ) . '</bdi>';
		}
	}

	private function engine_name( $key ) {
		return 'imagick' === $key ? 'Imagick' : 'GD';
	}

	private function error_text( $code ) {
		$map = array(
			'not_compiled'   => __( 'Not supported by this server’s build', 'pload' ),
			'crashed_before' => __( 'Skipped: this step crashed in an earlier test', 'pload' ),
			'encode_failed'  => __( 'Conversion failed', 'pload' ),
			'decode_failed'  => __( 'Could not read the test image', 'pload' ),
			'bad_length'     => __( 'Output file is corrupted (a known bug in older GD versions)', 'pload' ),
			'bad_header'     => __( 'Output is not a valid image file', 'pload' ),
			'empty'          => __( 'Output file is empty', 'pload' ),
			'missing'        => __( 'No output file was written', 'pload' ),
			'rename_failed'  => __( 'Could not save the file in the uploads folder', 'pload' ),
			'animated'       => __( 'Animated images are not converted', 'pload' ),
			''               => __( 'Not tested', 'pload' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : $code;
	}

	private function stage_name( $stage ) {
		$map = array(
			'limits'       => __( 'reading server limits', 'pload' ),
			'uploads'      => __( 'checking the uploads folder', 'pload' ),
			'sample'       => __( 'creating the test image', 'pload' ),
			'imagick_webp' => __( 'Imagick to WebP', 'pload' ),
			'imagick_avif' => __( 'Imagick to AVIF', 'pload' ),
			'gd_webp'      => __( 'GD to WebP', 'pload' ),
			'gd_avif'      => __( 'GD to AVIF', 'pload' ),
			'decide'       => __( 'final evaluation', 'pload' ),
		);
		return isset( $map[ $stage ] ) ? $map[ $stage ] : $stage;
	}

	private function issue_text( $issue ) {
		$a = isset( $issue['args'] ) ? $issue['args'] : array();

		switch ( $issue['code'] ) {
			case 'uploads_not_writable':
				return __( 'The uploads folder isn’t writable, so optimized files can’t be saved. Ask your host to fix the permissions of wp-content/uploads.', 'pload' );
			case 'no_sample':
				return __( 'The server couldn’t create a test image. Neither GD nor Imagick is working. Ask your host to enable the GD or Imagick PHP extension.', 'pload' );
			case 'no_webp_driver':
				return __( 'Neither GD nor Imagick on this server can create WebP files. Ask your host to enable WebP support. Processing on an external server will be offered in a later version.', 'pload' );
			case 'test_crashed':
				/* translators: %s: name of the test step */
				return sprintf( __( 'The test stopped unexpectedly during: %s. This usually means the server ran out of memory or time. That step will be skipped next time so the rest of the test can finish.', 'pload' ), $this->stage_name( $a['stage'] ) );
			case 'gd_webp_broken':
				return __( 'GD on this server writes broken WebP files, so GD will not be used.', 'pload' );
			case 'gd_only':
				return __( 'Only GD is available. That is enough for normal WordPress images.', 'pload' );
			case 'low_memory':
				/* translators: %d: megabytes */
				return sprintf( __( 'PHP memory for image work is %d MB. 128 MB or more is recommended.', 'pload' ), $a['mb'] );
			case 'low_exec_time':
				/* translators: %d: seconds */
				return sprintf( __( 'Scripts may run for only %d seconds and this can’t be changed. Work will be done in smaller steps.', 'pload' ), $a['s'] );
			case 'slow_server':
				/* translators: %d: milliseconds */
				return sprintf( __( 'This server is slow at encoding images (about %d ms per megapixel). Optimization will take longer.', 'pload' ), $a['ms'] );
			case 'large_images_skipped':
				/* translators: %d: megapixels */
				return sprintf( __( 'WordPress resizes large uploads to 2560 px, which needs about 7 megapixels. With the current memory limit only images up to about %d megapixels can be processed, so some images will be skipped.', 'pload' ), $a['mp'] );
			case 'imagick_low_limits':
				/* translators: %d: megabytes */
				return sprintf( __( 'The host limits Imagick to %d MB of memory. Large images may fail.', 'pload' ), $a['mb'] );
			case 'low_disk':
				/* translators: %d: megabytes */
				return sprintf( __( 'Only %d MB of disk space is free. WebP copies need extra space.', 'pload' ), $a['mb'] );
		}
		return $issue['code'];
	}
}
