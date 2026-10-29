<?php
/**
 * User settings, stored in one option.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Settings {

	const OPTION = 'pload_settings';

	/** Quality presets. "Balanced" equals the value used before 0.5. */
	const LEVELS = array(
		'small'    => 70,
		'balanced' => 75,
		'high'     => 85,
	);

	/** Multiplier on the per-run time the server test measured. */
	const SPEEDS = array(
		'slow'   => 0.5,
		'normal' => 1,
		'fast'   => 2,
	);

	const DEFAULTS = array(
		'level'      => 'balanced',
		'custom'     => 75,
		'min_saving' => 5,
		'speed'      => 'normal',
		'skip_urls'    => '',
		'skip_classes' => '',
		'compat'       => 0,
		'debug'        => 0,
		'order'        => 'newest',
		'lazy_compat'  => 1,
		'max_size'     => 0,  // 0 = keep WordPress's own limit (2560 px).
		'cdn_url'      => '',
	);

	public static function all() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::DEFAULTS );
	}

	public static function save( $input ) {
		$old = self::all();
		$new = array(
			'level'      => isset( $input['level'] ) && ( array_key_exists( $input['level'], self::LEVELS ) || 'custom' === $input['level'] ) ? $input['level'] : $old['level'],
			'custom'     => isset( $input['custom'] ) ? min( 95, max( 50, (int) $input['custom'] ) ) : $old['custom'],
			'min_saving' => isset( $input['min_saving'] ) ? min( 50, max( 0, (int) $input['min_saving'] ) ) : $old['min_saving'],
			'speed'      => isset( $input['speed'] ) && array_key_exists( $input['speed'], self::SPEEDS ) ? $input['speed'] : $old['speed'],
			'skip_urls'    => isset( $input['skip_urls'] ) ? sanitize_textarea_field( $input['skip_urls'] ) : $old['skip_urls'],
			'skip_classes' => isset( $input['skip_classes'] ) ? sanitize_text_field( $input['skip_classes'] ) : $old['skip_classes'],
			'compat'       => isset( $input['compat'] ) ? (int) ! empty( $input['compat'] ) : $old['compat'],
			'debug'        => isset( $input['debug'] ) ? (int) ! empty( $input['debug'] ) : $old['debug'],
			'lazy_compat'  => isset( $input['lazy_compat'] ) ? (int) ! empty( $input['lazy_compat'] ) : $old['lazy_compat'],
			'order'        => isset( $input['order'] ) && in_array( $input['order'], array( 'newest', 'oldest' ), true ) ? $input['order'] : $old['order'],
			'max_size'     => isset( $input['max_size'] ) ? self::clean_max_size( $input['max_size'] ) : $old['max_size'],
			'cdn_url'      => isset( $input['cdn_url'] ) ? self::clean_cdn_url( $input['cdn_url'] ) : $old['cdn_url'],
		);
		// Autoloaded: delivery reads it on every front-end request.
		update_option( self::OPTION, $new, true );
		return $new;
	}

	/** Empty or 0 keeps WordPress's limit; otherwise 1000–12000 px. */
	private static function clean_max_size( $v ) {
		$v = (int) $v;
		return $v <= 0 ? 0 : min( 12000, max( 1000, $v ) );
	}

	/** Base address of a CDN that mirrors this site, e.g. https://cdn.example.com */
	private static function clean_cdn_url( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $v ) ) {
			$v = 'https://' . ltrim( $v, '/' );
		}
		$v = esc_url_raw( $v, array( 'http', 'https' ) );
		return wp_parse_url( $v, PHP_URL_HOST ) ? untrailingslashit( $v ) : '';
	}

	/**
	 * WordPress scales uploads larger than this many pixels and keeps the
	 * untouched original next to the scaled copy. Hooked to
	 * big_image_size_threshold.
	 */
	public static function threshold( $threshold ) {
		$max = (int) self::all()['max_size'];
		return $max > 0 ? $max : $threshold;
	}

	public static function cdn_url() {
		return (string) self::all()['cdn_url'];
	}

	/** @return string[] Non-empty lines of the "don't show WebP on" list. */
	public static function skip_urls() {
		$lines = preg_split( '/\R/', (string) self::all()['skip_urls'] );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/** @return string[] CSS classes whose images are left alone. "pload-skip" always works. */
	public static function skip_classes() {
		$list = preg_split( '/[\s,]+/', (string) self::all()['skip_classes'] );
		$list = array_filter( array_map( 'sanitize_html_class', $list ) );
		$list[] = 'pload-skip';
		return array_values( array_unique( $list ) );
	}

	/** Process the final page HTML as well (covers images printed without WP functions). */
	public static function compat() {
		return (bool) self::all()['compat'];
	}

	/** Give WebP to images handled by theme/plugin lazy-load scripts (by rewriting their data-* URLs). */
	public static function lazy_compat() {
		return (bool) self::all()['lazy_compat'];
	}

	/** Library walk direction: "newest" or "oldest" first. */
	public static function order() {
		return 'oldest' === self::all()['order'] ? 'oldest' : 'newest';
	}

	/** Add a summary comment to the end of each page's HTML. */
	public static function debug() {
		return (bool) self::all()['debug'];
	}

	/** WebP quality 50–95. */
	public static function quality() {
		$s = self::all();
		if ( 'custom' === $s['level'] ) {
			return (int) $s['custom'];
		}
		$levels = self::LEVELS;
		return isset( $levels[ $s['level'] ] ) ? $levels[ $s['level'] ] : 75;
	}

	/** A WebP is kept only if it is at least this many percent smaller. */
	public static function min_saving() {
		return (int) self::all()['min_saving'];
	}

	/**
	 * Seconds of work per run: the server test's value times the speed
	 * setting, never more than half the PHP time limit, never under 3.
	 */
	public static function budget( $env ) {
		$base = ! empty( $env['budget']['seconds'] ) ? (int) $env['budget']['seconds'] : 8;
		$met  = isset( $env['limits']['max_execution'] ) ? (int) $env['limits']['max_execution'] : 30;
		$speeds = self::SPEEDS;
		$speed  = self::all()['speed'];
		$sec    = $base * ( isset( $speeds[ $speed ] ) ? $speeds[ $speed ] : 1 );
		$cap  = $met > 0 ? $met * 0.5 : 30;
		if ( ! empty( $env['limits']['can_set_time'] ) ) {
			$cap = 30; // set_time_limit() extends the run, so allow up to 30s.
		}
		return (int) max( 3, min( $sec, $cap ) );
	}
}
