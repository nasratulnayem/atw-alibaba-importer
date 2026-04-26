<?php
/**
 * Freemius SDK initialisation.
 *
 * Replace AWI_FS_PLUGIN_ID and AWI_FS_PUBLIC_KEY with the values from your
 * Freemius dashboard (Plugins > Your Plugin > General > Plugin Details).
 *
 * The SDK directory must be placed at: alibaba-woocommerce-importer/freemius/
 * Download it from: https://freemius.com/help/documentation/selling-with-freemius/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Replace these two constants before going live ────────────────────────────
define( 'AWI_FS_PLUGIN_ID', '28475' );
define( 'AWI_FS_PUBLIC_KEY', 'pk_899cd9e07ac2b4825e4c96464c7e0' );
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the Freemius singleton. Safe to call any time after plugins_loaded.
 * Returns null when the SDK files are not present (e.g. during development
 * before the vendor directory is populated), so callers must null-check.
 */
function atw_fs(): ?object {
	global $atw_fs;

	if ( isset( $atw_fs ) ) {
		return $atw_fs;
	}

	$sdk_start = AWI_PLUGIN_DIR . 'vendor/freemius/start.php';
	if ( ! file_exists( $sdk_start ) ) {
		// SDK not installed yet — plugin works in free mode silently.
		return null;
	}

	require_once $sdk_start;

	$atw_fs = fs_dynamic_init(
		array(
			'id'                  => AWI_FS_PLUGIN_ID,
			'slug'                => 'atw-alibaba-importer',
			'type'                => 'plugin',
			'public_key'          => AWI_FS_PUBLIC_KEY,
			'is_premium'          => false,
			'has_premium_version' => true,
			'has_addons'          => false,
			'has_paid_plans'      => true,
			// Keep opt-in/out transparent — plugin fully works without account.
			'is_org_compliant'    => true,
			'menu'                => array(
				'slug'    => 'atw',
				'contact' => false,
				'support' => false,
				'account' => true,
				'pricing' => true,
				'parent'  => array(
					'slug' => 'atw',
				),
			),
			// Anonymous mode: users can skip opt-in and still use all free features.
			'anonymous_support'   => true,
			'is_live'             => true,
		)
	);

	$atw_fs->add_action( 'after_uninstall', 'atw_fs_uninstall_cleanup' );

	do_action( 'atw_fs_loaded' );

	return $atw_fs;
}

function atw_fs_uninstall_cleanup(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'awi_usage_log';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	delete_option( 'awi_ai_settings' );

	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_awi_pending_connect_%' OR option_name LIKE '_transient_timeout_awi_pending_connect_%'"
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_awi_extension_settings_v1' ), array( '%s' ) );
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_awi_rate_limit' ), array( '%s' ) );

	$uploads  = wp_upload_dir();
	$base_dir = trailingslashit( (string) $uploads['basedir'] ) . 'atw-url-import';
	if ( is_dir( $base_dir ) ) {
		atw_fs_rmdir( $base_dir );
	}
}

function atw_fs_rmdir( string $dir ): void {
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
			atw_fs_rmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/**
 * Returns true when the current site has an active paid Freemius licence
 * (paying customer or active trial). Safe to call even when SDK is absent.
 */
function atw_fs_is_pro(): bool {
	$fs = atw_fs();
	if ( $fs === null ) {
		return false;
	}
	try {
		return $fs->is_paying() || $fs->is_trial();
	} catch ( Exception $e ) {
		return false;
	}
}
