<?php
/**
 * Removes everything the Emailit plugin stored when it's deleted.
 *
 * The webhook it created in Emailit is deleted too, when the API key is still available.
 *
 * @package Emailit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function emailit_uninstall_site() {
	global $wpdb;

	$webhook = get_option( 'emailit_webhook' );
	if ( is_array( $webhook ) && ! empty( $webhook['id'] ) ) {
		if ( ! class_exists( '\Emailit\WordPress\Settings' ) ) {
			require_once __DIR__ . '/includes/class-settings.php';
		}
		$key = \Emailit\WordPress\Settings::api_key();
		if ( '' !== $key ) {
			wp_remote_request(
				\Emailit\WordPress\Settings::api_base() . 'webhooks/' . rawurlencode( (string) $webhook['id'] ),
				array(
					'method'  => 'DELETE',
					'timeout' => 10,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				)
			);
		}
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}emailit_emails" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}emailit_jobs" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	foreach ( array( 'emailit_settings', 'emailit_throttle', 'emailit_db_version', 'emailit_queue_lock', 'emailit_account', 'emailit_webhook', 'emailit_integrations', 'emailit_commerce', 'emailit_seen_forms', 'emailit_wc_products', 'emailit_wc_import', 'emailit_activated' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'emailit_audiences' );
	delete_transient( 'emailit_webhook_seen' );
	wp_clear_scheduled_hook( 'emailit_process_queue' );
	wp_clear_scheduled_hook( 'emailit_cleanup' );

	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) ) {
		$dir = trailingslashit( $uploads['basedir'] ) . 'emailit-queue';
		if ( is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $file ) {
				if ( is_file( $dir . '/' . $file ) ) {
					wp_delete_file( $dir . '/' . $file );
				}
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder the plugin created.
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $emailit_site_id ) {
		switch_to_blog( $emailit_site_id );
		emailit_uninstall_site();
		restore_current_blog();
	}
} else {
	emailit_uninstall_site();
}
