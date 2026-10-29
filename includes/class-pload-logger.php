<?php
/**
 * Tiny rolling log stored in a single non-autoloaded option.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Logger {

	const OPTION   = 'pload_log';
	const MAX_ROWS = 50;

	/**
	 * @param string $level   info | warning | error
	 * @param string $message Plain text, English (developer-facing).
	 */
	public static function log( $level, $message ) {
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows[] = array(
			't' => time(),
			'l' => $level,
			'm' => (string) $message,
		);

		if ( count( $rows ) > self::MAX_ROWS ) {
			$rows = array_slice( $rows, -self::MAX_ROWS );
		}

		update_option( self::OPTION, $rows, false );
	}

	/** @return array Newest first. */
	public static function get() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_reverse( $rows ) : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
