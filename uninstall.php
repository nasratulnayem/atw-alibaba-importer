<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all plugin data: custom table, options, transients, upload files, user meta.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom usage table.
$table = $wpdb->prefix . 'awi_usage_log';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options.
delete_option( 'awi_ai_settings' );

// Remove all pending-connect transients (pattern: awi_pending_connect_<hex>).
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_awi_pending_connect_%' OR option_name LIKE '_transient_timeout_awi_pending_connect_%'"
); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove user meta for extension settings and rate limit state.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_awi_extension_settings_v1' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_awi_rate_limit' ), array( '%s' ) );

// Remove url-import run files and logs from uploads directory.
$uploads  = wp_upload_dir();
$base_dir = trailingslashit( (string) $uploads['basedir'] ) . 'atw-url-import';
if ( is_dir( $base_dir ) ) {
	awi_uninstall_rmdir( $base_dir );
}

/**
 * Recursively delete a directory and its contents.
 *
 * @param string $dir Absolute path to directory.
 */
function awi_uninstall_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( $item === '.' || $item === '..' ) {
			continue;
		}
		$path = trailingslashit( $dir ) . $item;
		if ( is_dir( $path ) ) {
			awi_uninstall_rmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
