<?php
/**
 * Counters. Byte totals live in one option and are updated once per
 * attachment; status counts come straight from postmeta.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Stats {

	const BYTES = 'pload_bytes';

	public static function bytes() {
		$b = get_option( self::BYTES, array() );
		return wp_parse_args( is_array( $b ) ? $b : array(), array( 'in' => 0, 'out' => 0, 'files' => 0 ) );
	}

	/** Adds (or with negative numbers, removes) converted files. */
	public static function add( $in, $out, $files ) {
		if ( ! $in && ! $out && ! $files ) {
			return;
		}
		$b          = self::bytes();
		$b['in']    = max( 0, $b['in'] + $in );
		$b['out']   = max( 0, $b['out'] + $out );
		$b['files'] = max( 0, $b['files'] + $files );
		update_option( self::BYTES, $b, false );
	}

	public static function reset() {
		delete_option( self::BYTES );
	}

	/**
	 * @return array total, done, skipped, failed, waiting
	 */
	public static function counts() {
		global $wpdb;

		// Counting a large library on every admin page load is not free;
		// a short cache keeps the numbers fresh enough.
		$cached = wp_cache_get( 'counts', 'pload' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counting across the library has no WordPress API.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s)", Pload_Converter::MIMES[0], Pload_Converter::MIMES[1] )
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS s, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value",
				Pload_Converter::STATUS
			)
		);
		// phpcs:enable

		$c = array( 'total' => $total, 'done' => 0, 'skipped' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $c[ $row->s ] ) ) {
				$c[ $row->s ] = (int) $row->n;
			}
		}
		$c['waiting'] = max( 0, $total - $c['done'] - $c['skipped'] - $c['failed'] );
		wp_cache_set( 'counts', $c, 'pload', 30 );
		return $c;
	}
}
