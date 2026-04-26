<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'atw_fs' ) ) {

	function atw_fs(): ?object {
		global $atw_fs;

		if ( isset( $atw_fs ) ) {
			return $atw_fs;
		}

		$sdk_start = AWI_PLUGIN_DIR . 'vendor/freemius/start.php';
		if ( ! file_exists( $sdk_start ) ) {
			return null;
		}

		require_once $sdk_start;

		$atw_fs = fs_dynamic_init(
			array(
				'id'                  => '28475',
				'slug'                => 'atw-alibaba-importer',
				'premium_slug'        => 'atw-alibaba-product-importer-premium',
				'type'                => 'plugin',
				'public_key'          => 'pk_899cd9e07ac2b4825e4c96464c7e0',
				'is_premium'          => false,
				'premium_suffix'      => 'Pro',
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'is_org_compliant'    => true,
				'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
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
			)
		);

		$atw_fs->add_action( 'after_uninstall', 'atw_fs_uninstall_cleanup' );

		do_action( 'atw_fs_loaded' );

		return $atw_fs;
	}

	atw_fs();
}

if ( ! function_exists( 'atwi_fs' ) ) {
	function atwi_fs(): ?object {
		return atw_fs();
	}
}

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
