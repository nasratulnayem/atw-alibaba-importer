<?php
/**
 * Plugin Name: Alibaba to WooCommerce Import
 * Description: Import Alibaba products into WooCommerce via Chrome extension + REST API.
 * Version: 0.1.0
 * Author: Nasratul Nayem
 * Author URI: https://codex.nayem.dev
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: awi
 *
 * Extension-first importer (no scraping UI in admin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AWI_VERSION', '0.1.0' );
define( 'AWI_PLUGIN_FILE', __FILE__ );
define( 'AWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Freemius must initialise before anything else so its hooks register on time.
require_once AWI_PLUGIN_DIR . 'includes/class-awi-freemius.php';
atw_fs(); // boot SDK (no-op if freemius/ directory not present)

require_once AWI_PLUGIN_DIR . 'includes/class-awi-rate-limiter.php';
require_once AWI_PLUGIN_DIR . 'includes/class-awi-admin.php';
require_once AWI_PLUGIN_DIR . 'includes/class-awi-rest.php';
require_once AWI_PLUGIN_DIR . 'includes/class-awi-frontend.php';
require_once AWI_PLUGIN_DIR . 'includes/class-awi-url-import.php';

final class AWI_Plugin {
	public static function init(): void {
		register_activation_hook( AWI_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'plugins_loaded' ) );
	}

	public static function activate(): void {
		AWI_Rest::create_usage_table();
	}

	public static function plugins_loaded(): void {
		if ( is_admin() ) {
			AWI_Admin::init();
			AWI_Url_Import::init();
		}

		AWI_Rest::init();
		AWI_Frontend::init();
	}
}

AWI_Plugin::init();
