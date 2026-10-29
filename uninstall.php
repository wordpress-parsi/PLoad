<?php
/**
 * Removes settings and bookkeeping, and as many WebP files as fit in a
 * short time budget. On small and medium sites that is all of them; on very
 * large libraries some files may remain, which is why the settings page
 * offers "Remove all WebP files" to run before deleting the plugin.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$pload_deadline = microtime( true ) + 15;
$pload_last_id  = 0;

do {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall walks all records of the plugin; no API lists posts by meta key in batches.
	$pload_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_pload' AND post_id > %d ORDER BY post_id ASC LIMIT 200",
			$pload_last_id
		)
	);
	foreach ( (array) $pload_rows as $pload_row ) {
		$pload_last_id = (int) $pload_row->post_id;
		$pload_detail  = maybe_unserialize( $pload_row->meta_value );
		$pload_main    = get_attached_file( $pload_last_id );
		if ( ! $pload_main || ! is_array( $pload_detail ) || empty( $pload_detail['sizes'] ) ) {
			continue;
		}
		$pload_dir = trailingslashit( dirname( $pload_main ) );
		foreach ( $pload_detail['sizes'] as $pload_rec ) {
			if ( ! empty( $pload_rec['f'] ) && file_exists( $pload_dir . $pload_rec['f'] . '.webp' ) ) {
				wp_delete_file( $pload_dir . $pload_rec['f'] . '.webp' );
			}
		}
		if ( microtime( true ) > $pload_deadline ) {
			break 2;
		}
	}
} while ( $pload_rows && microtime( true ) < $pload_deadline );

foreach ( array( 'pload_env', 'pload_env_stage', 'pload_env_crashes', 'pload_log', 'pload_state', 'pload_queue', 'pload_last_run', 'pload_lock', 'pload_bytes', 'pload_idle', 'pload_deliver', 'pload_clean_total', 'pload_runs', 'pload_settings', 'pload_space', 'pload_pause_reason', 'pload_tick', 'pload_key', 'pload_version', 'pload_levels_changed' ) as $pload_option ) {
	delete_option( $pload_option );
}
delete_transient( 'pload_activation_notice' );
delete_transient( 'pload_clean_done' );
wp_clear_scheduled_hook( 'pload_run' );
delete_post_meta_by_key( '_pload' );
delete_post_meta_by_key( '_pload_status' );
delete_post_meta_by_key( '_pload_exclude' );
