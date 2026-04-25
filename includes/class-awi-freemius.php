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
define( 'AWI_FS_PLUGIN_ID', '0000' );              // e.g. '12345'
define( 'AWI_FS_PUBLIC_KEY', 'pk_REPLACE_WITH_YOUR_KEY' ); // e.g. 'pk_abc123...'
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

	$sdk_start = AWI_PLUGIN_DIR . 'freemius/start.php';
	if ( ! file_exists( $sdk_start ) ) {
		// SDK not installed yet — plugin works in free mode silently.
		return null;
	}

	require_once $sdk_start;

	$atw_fs = fs_dynamic_init(
		array(
			'id'                  => AWI_FS_PLUGIN_ID,
			'slug'                => 'alibaba-woocommerce-importer',
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

	do_action( 'atw_fs_loaded' );

	return $atw_fs;
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
