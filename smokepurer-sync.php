<?php
/**
 * Plugin Name:       SmokePurer Sync for WooCommerce
 * Plugin URI:        https://www.e-liquids.uk/
 * Description:        Imports the SmokePurer dropship CSV feeds into WooCommerce products and keeps stock levels in sync from the 5-minute quantity feed. Dependency-free (uses WooCommerce + Action Scheduler).
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            e-liquids.uk
 * License:           GPL-2.0-or-later
 * Text Domain:       smokepurer-sync
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * ---------------------------------------------------------------------------
 * ARCHITECTURE (why it is built this way)
 * ---------------------------------------------------------------------------
 * The SmokePurer feeds are a "star schema": one authoritative catalogue feed
 * (product descriptions) plus several side feeds keyed on SKU. The stock feed
 * refreshes every 5 minutes; the catalogue is slow-moving. Doing both in one
 * heavyweight import cannot honour a 5-minute cadence on real hosting, so the
 * plugin runs TWO decoupled jobs:
 *
 *   1. Stock sync   (default every 5 min) - reads ONLY SKU,Quantity, diffs
 *      against the last snapshot, and writes ONLY changed stock via WooCommerce
 *      CRUD. Never touches product data. This is the crux of the whole design.
 *
 *   2. Catalogue import (default hourly) - builds/updates simple + variable
 *      products from the descriptions + coming-soon feeds, joined with the
 *      weight / tag / image side feeds. Batched via Action Scheduler with a
 *      byte-offset cursor so no single request risks a PHP timeout. NEVER
 *      writes stock on update (stock is owned solely by job #1).
 *
 *   3. Reconcile    (default daily) - retires products listed in the disabled
 *      7-day delta feed.
 *
 * Safety rails baked in: every full-snapshot feed is validated (HTTP ok, not an
 * HTML error page, expected header set present) and guarded by a circuit-breaker
 * that aborts the run if the row count collapses vs the last good pull - so a
 * truncated download can never zero the catalogue's stock or prices.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'SPS_VERSION', '1.0.0' );
define( 'SPS_PLUGIN_FILE', __FILE__ );
define( 'SPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPS_STAGING_DIRNAME', 'smokepurer-sync' );

// The quantity feed uses 90000 as a sentinel meaning "pre-order", not literal stock.
define( 'SPS_PREORDER_SENTINEL', 90000 );

// Action Scheduler hook names.
define( 'SPS_HOOK_CATALOGUE', 'sps_import_catalogue' );
define( 'SPS_HOOK_CATALOGUE_BATCH', 'sps_process_catalogue_batch' );
define( 'SPS_HOOK_STOCK', 'sps_sync_stock' );
define( 'SPS_HOOK_RECONCILE', 'sps_reconcile' );

/**
 * PSR-ish autoloader: SPS_Feed_Client -> includes/class-sps-feed-client.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'SPS_' ) !== 0 ) {
			return;
		}
		$file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$path = SPS_PLUGIN_DIR . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Declare HPOS (High-Performance Order Storage) compatibility. This plugin only
 * touches products, but WooCommerce shows a scary incompatibility notice unless
 * every active plugin declares support.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SPS_PLUGIN_FILE, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded, so WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>SmokePurer Sync</strong> requires WooCommerce to be installed and active.</p></div>';
				}
			);
			return;
		}
		SPS_Plugin::instance();
	}
);

// Activation / deactivation: schedule and tear down the recurring jobs.
register_activation_hook( __FILE__, array( 'SPS_Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'SPS_Plugin', 'on_deactivation' ) );
