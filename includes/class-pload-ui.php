<?php
/**
 * Small markup helpers for the admin page, so the views stay short.
 * Every helper escapes its own output.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_UI {

	/** Inner SVG markup of the line icons (24px viewBox). */
	const ICONS = array(
		'gauge'    => '<path d="M12 14l4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
		'sliders'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
		'tool'     => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
		'play'     => '<path d="M7 4.5v15l12-7.5z"/>',
		'pause'    => '<path d="M8 5v14M16 5v14"/>',
		'check'    => '<path d="M20 6L9 17l-5-5"/>',
		'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
		'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
		'disk'     => '<path d="M22 12H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><path d="M6 16h.01M10 16h.01"/>',
		'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
		'bolt'     => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
		'layers'   => '<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
		'layout'   => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
		'code'     => '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>',
		'trash'    => '<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
		'refresh'  => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
		'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		'server'   => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
		'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
		'step'     => '<path d="M5 4l10 8-10 8z"/><path d="M19 5v14"/>',
		'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'chevron'  => '<path d="M6 9l6 6 6-6"/>',
	);

	public static function icon( $name, $extra_class = '' ) {
		if ( ! isset( self::ICONS[ $name ] ) ) {
			return;
		}
		$svg = '<svg class="pload-icon' . ( $extra_class ? ' ' . $extra_class : '' ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[ $name ] . '</svg>';
		echo wp_kses( $svg, self::svg_tags() );
	}

	/** @return array<string, array<string, bool>> */
	private static function svg_tags() {
		$shape = array(
			'd'      => true,
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
		);
		return array(
			'svg'    => array(
				'class'           => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'path'   => $shape,
			'circle' => $shape,
			'rect'   => $shape,
		);
	}

	public static function page_head( $title, $desc = '' ) {
		echo '<div class="pload-page-head"><div>';
		echo '<h2 class="pload-page-head__title">' . esc_html( $title ) . '</h2>';
		if ( $desc ) {
			echo '<p class="pload-page-head__desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div></div>';
	}

	public static function section_title( $title ) {
		echo '<h3 class="pload-section-title">' . esc_html( $title ) . '</h3>';
	}

	/**
	 * @param string $type success|warning|error|info
	 * @param string $title Bold first line (optional).
	 * @param string $text  Plain text.
	 */
	public static function alert( $type, $title, $text = '' ) {
		$icons = array(
			'success' => 'check',
			'warning' => 'alert',
			'error'   => 'alert',
			'info'    => 'info',
		);
		echo '<div class="pload-alert pload-alert--' . esc_attr( $type ) . '"' . ( 'error' === $type ? ' role="alert"' : '' ) . '>';
		self::icon( isset( $icons[ $type ] ) ? $icons[ $type ] : 'info' );
		echo '<div class="pload-alert__body">';
		if ( $title ) {
			echo '<strong class="pload-alert__title">' . esc_html( $title ) . '</strong>';
		}
		if ( $text ) {
			echo '<p>' . esc_html( $text ) . '</p>';
		}
		echo '</div></div>';
	}

	/** Opens an alert whose body the caller fills; close with alert_close(). */
	public static function alert_open( $type, $title ) {
		echo '<div class="pload-alert pload-alert--' . esc_attr( $type ) . '">';
		self::icon( 'success' === $type ? 'check' : ( 'info' === $type ? 'info' : 'alert' ) );
		echo '<div class="pload-alert__body"><strong class="pload-alert__title">' . esc_html( $title ) . '</strong>';
	}

	public static function alert_close() {
		echo '</div></div>';
	}

	/**
	 * A switch row. The hidden input keeps "0" when the box is unchecked.
	 */
	public static function toggle_row( $name, $id, $checked, $icon, $label, $desc ) {
		echo '<label class="pload-toggle-row" for="' . esc_attr( $id ) . '">';
		echo '<span class="pload-toggle-row__icon">';
		self::icon( $icon );
		echo '</span><span class="pload-toggle-row__text">';
		echo '<span class="pload-toggle-row__label">' . esc_html( $label ) . '</span>';
		echo '<span class="pload-toggle-row__desc">' . esc_html( $desc ) . '</span>';
		echo '</span>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
		echo '<input type="checkbox" class="pload-switch" role="switch" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '>';
		echo '</label>';
	}

	/** A radio card. */
	public static function choice( $name, $value, $current, $title, $desc, $badge = '' ) {
		echo '<label class="pload-choice">';
		echo '<input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $current, $value, false ) . '>';
		echo '<span class="pload-choice__title">' . esc_html( $title );
		if ( $badge ) {
			echo ' <span class="pload-badge pload-badge--accent">' . esc_html( $badge ) . '</span>';
		}
		echo '</span>';
		echo '<span class="pload-choice__desc">' . esc_html( $desc ) . '</span>';
		echo '</label>';
	}

	/** Big number tile. */
	public static function stat( $value, $label, $sub = '', $sub_class = '' ) {
		echo '<div class="pload-stat">';
		echo '<div class="pload-stat__value"><bdi>' . esc_html( $value ) . '</bdi></div>';
		echo '<div class="pload-stat__label">' . esc_html( $label ) . '</div>';
		if ( $sub ) {
			echo '<div class="pload-stat__sub' . ( $sub_class ? ' ' . esc_attr( $sub_class ) : '' ) . '">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}

	/** Label / value rows. @param array<string, string> $rows */
	public static function kv( $rows ) {
		echo '<dl class="pload-kv">';
		foreach ( $rows as $k => $v ) {
			echo '<div class="pload-kv__row"><dt>' . esc_html( $k ) . '</dt><dd><bdi>' . esc_html( $v ) . '</bdi></dd></div>';
		}
		echo '</dl>';
	}

	/** Progress bar with a percent label. */
	public static function progress( $pct, $label ) {
		echo '<div class="pload-progress">';
		echo '<progress class="pload-progress__bar" max="100" value="' . esc_attr( (string) $pct ) . '" aria-label="' . esc_attr( $label ) . '"></progress>';
		echo '</div>';
	}

	/**
	 * Opening tag of a form posting to admin-post.php, with nonce, action
	 * and the view to come back to.
	 *
	 * @param array<string, string|int> $hidden
	 */
	public static function form_open( $action, $view, $hidden = array(), $class = '', $nonce = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' data-pload-return="' . esc_attr( $view ) . '">';
		wp_nonce_field( $nonce ? $nonce : $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="pload_view" value="' . esc_attr( $view ) . '">';
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
	}

	/**
	 * @param string $variant ''|ghost|quiet|danger
	 */
	public static function button( $label, $variant = '', $icon = '', $attrs = array() ) {
		$cls = 'pload-btn' . ( $variant ? ' pload-btn--' . $variant : '' );
		echo '<button type="submit" class="' . esc_attr( $cls ) . '"';
		foreach ( $attrs as $k => $v ) {
			echo ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		echo '>';
		if ( $icon ) {
			self::icon( $icon );
		}
		echo '<span>' . esc_html( $label ) . '</span></button>';
	}

	/** One-button form. */
	public static function action_button( $action, $view, $label, $variant = '', $icon = '', $hidden = array(), $nonce = '', $ajax = false ) {
		self::form_open( $action, $view, $hidden, 'pload-inline' . ( $ajax ? ' pload-ajax' : '' ), $nonce );
		self::button( $label, $variant, $icon );
		if ( $ajax ) {
			echo '<span class="pload-spinner" aria-hidden="true"></span>';
		}
		echo '</form>';
	}
}
