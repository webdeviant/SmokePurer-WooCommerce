<?php
/**
 * Settings store + defaults. Everything the operator can tune lives in a single
 * option (`sps_settings`).
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Settings {

	const OPTION = 'sps_settings';

	/** @var array|null Runtime cache. */
	private static $cache = null;

	/**
	 * Default settings. Feed URLs are pre-filled with the SmokePurer endpoints
	 * so the plugin works out of the box once a markup is set.
	 */
	public static function defaults() {
		return array(
			'enabled'                 => true,

			// Feeds.
			'feed_descriptions'       => 'https://dropship.smokepurer.com/export/smokepurer-product-descriptions.csv',
			'feed_coming_soon'        => 'https://dropship.smokepurer.com/export/smokepurer-product-descriptions-coming-soon.csv',
			'feed_quantity'           => 'https://dropship.smokepurer.com/public/sp-sku-quantity.csv',
			'feed_images'             => 'https://www.smokepurer.com/export/sp-inventory-images.csv',
			'feed_tags'               => 'https://dropship.smokepurer.com/public/sku-tag.csv',
			'feed_weights'            => 'https://dropship.smokepurer.com/productexports/skuweight.csv',
			'feed_disabled_7d'        => 'https://www.smokepurer.com/storageReplaced/files/csv/cron/disabled_lines/sp_disabled_products_last_7_days.csv',

			// Schedules (seconds). Minimums are enforced in the scheduler.
			'stock_interval'          => 300,          // 5 minutes.
			'catalogue_interval'      => 3600,         // hourly.
			'reconcile_interval'      => DAY_IN_SECONDS,

			// Pricing. Trade price -> retail price.
			'markup_percent'          => 100.0,        // 100% = double the trade price.
			'rounding'                => 'charm99',    // none | charm99 | charm95 | nearest_5p | nearest_10p.
			'min_margin_guard'        => true,         // Never publish a price <= trade price.

			// Product build.
			'new_product_status'      => 'draft',      // draft | publish. Draft = compliance review gate.
			'attribute_label'         => 'Options',    // Variation attribute label (feed gives only a bare value).
			'import_images'           => true,
			'auto_create_categories'  => true,
			'seed_stock_on_create'    => true,         // Set initial stock from the feed only when first creating a product.

			// Category mapping: feed "Type" value => WooCommerce category name.
			// Anything unmapped falls back to `category_fallback`.
			'category_map'            => array(),
			'category_fallback'       => 'Uncategorised',

			// Retirement (disabled feed) action.
			'retire_action'           => 'draft',      // draft | outofstock.
			'retire_max_percent'      => 20,           // Safety cap: abort if a single run would retire > this % of catalogue.

			// Circuit-breaker: abort a snapshot run if row count drops more than
			// this % below the last good pull (guards truncated / error-page fetches).
			'breaker_percent'         => 15,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored     = get_option( self::OPTION, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $fallback;
	}

	public static function update( array $values ) {
		$merged = wp_parse_args( $values, self::all() );
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
	}

	public static function maybe_seed_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, self::defaults(), false );
		}
	}

	/**
	 * The list of feed keys that use full-snapshot semantics (and therefore need
	 * the circuit-breaker). The disabled feed is a delta and is excluded.
	 */
	public static function snapshot_feed_keys() {
		return array( 'feed_descriptions', 'feed_quantity', 'feed_images', 'feed_weights' );
	}
}
