<?php
/**
 * Stock sync - the fast, high-frequency job (default every 5 minutes).
 *
 * This is the whole reason a custom plugin is worth building. It:
 *   - reads ONLY the SKU,Quantity feed (never product data),
 *   - preloads one SKU -> product_id map in a single query,
 *   - diffs against the previous snapshot and writes ONLY changed SKUs,
 *   - writes stock through WooCommerce CRUD / wc_update_product_stock so the
 *     product lookup table, stock-status transitions and caches stay correct,
 *   - maps the 90000 sentinel to a pre-order (backorder) state.
 *
 * Because it only writes the handful of SKUs whose quantity actually changed, a
 * genuine 5-minute cadence stays cheap even at ~6,000 SKUs.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Stock_Sync {

	const SNAPSHOT_OPTION = 'sps_stock_snapshot';

	public static function run() {
		// TTL comfortably above both the recurring interval (>=300s) and a cold
		// first-run (empty snapshot => every SKU written), so a still-running job
		// is never treated as expired and double-run.
		if ( ! SPS_Lock::acquire( 'stock', 900 ) ) {
			SPS_Logger::info( 'Stock sync skipped - previous run still in progress.' );
			return;
		}

		try {
			self::do_run();
		} catch ( \Throwable $e ) {
			SPS_Logger::record_run( 'stock', 'error', 'Stock sync crashed: ' . $e->getMessage() );
		} finally {
			SPS_Lock::release( 'stock' );
		}
	}

	private static function do_run() {
		$url = (string) SPS_Settings::get( 'feed_quantity', '' );
		$tmp = SPS_Feed_Client::download( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			SPS_Logger::record_run( 'stock', 'error', $tmp->get_error_message() );
			return;
		}

		$map = SPS_CSV::header_map( $tmp, array( 'SKU', 'Quantity' ) );
		if ( is_wp_error( $map ) ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::record_run( 'stock', 'error', $map->get_error_message() );
			return;
		}

		// Circuit-breaker: a truncated quantity feed could otherwise zero the
		// stock of thousands of products.
		$row_count = SPS_CSV::count_rows( $tmp );
		$tripped   = SPS_Feed_Client::breaker_tripped( 'feed_quantity', $row_count );
		if ( $tripped ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::record_run( 'stock', 'error', $tripped );
			return;
		}

		$snapshot   = self::load_snapshot();
		$sku_to_id  = self::preload_sku_map();
		$new_snap   = array();

		$changed   = 0;
		$unchanged = 0;
		$unknown   = 0;
		$missing   = 0;

		foreach ( SPS_CSV::rows( $tmp, $map ) as $row ) {
			$sku = trim( $row['SKU'] );
			if ( '' === $sku ) {
				continue;
			}
			$qty_raw = trim( $row['Quantity'] );

			// Unknown SKU (no product yet - e.g. coming-soon before its catalogue
			// import). Do NOT record it in the snapshot, or the first real stock
			// write is suppressed once the product is later created.
			if ( ! isset( $sku_to_id[ $sku ] ) ) {
				$unknown++;
				continue;
			}

			// Known SKU -> it belongs in the new snapshot.
			$new_snap[ $sku ] = $qty_raw;

			// Unchanged since last pull? Skip - this is what keeps the job fast.
			if ( isset( $snapshot[ $sku ] ) && $snapshot[ $sku ] === $qty_raw ) {
				$unchanged++;
				continue;
			}

			if ( self::apply_stock( $sku_to_id[ $sku ], $qty_raw ) ) {
				$changed++;
			} else {
				$missing++; // In the SKU map but the product failed to load.
			}
		}

		SPS_Feed_Client::cleanup( $tmp );

		// Persist the new snapshot and baseline.
		self::save_snapshot( $new_snap );
		SPS_Feed_Client::record_good( 'feed_quantity', $row_count );

		SPS_Logger::record_run(
			'stock',
			$missing > 0 ? 'warning' : 'ok',
			sprintf( 'Stock sync: %d updated, %d unchanged, %d unknown SKU(s), %d failed (%d feed rows).', $changed, $unchanged, $unknown, $missing, $row_count ),
			array(
				'changed'   => $changed,
				'unchanged' => $unchanged,
				'unknown'   => $unknown,
				'missing'   => $missing,
				'rows'      => $row_count,
			)
		);
	}

	/**
	 * Apply a quantity to one product/variation. Returns true if written.
	 */
	private static function apply_stock( $product_id, $qty_raw ) {
		$qty_int = (int) preg_replace( '/[^0-9\-]/', '', (string) $qty_raw );

		// Pre-order sentinel -> backorder state, not literal stock.
		if ( $qty_int >= SPS_PREORDER_SENTINEL ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}
			$product->set_manage_stock( false );
			$product->set_backorders( 'notify' );
			$product->set_stock_status( 'onbackorder' );
			$product->save();
			return true;
		}

		$qty_int = max( 0, $qty_int );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		if ( ! $product->get_manage_stock() ) {
			$product->set_manage_stock( true );
			$product->save();
		}

		// wc_update_product_stock updates the quantity, recalculates stock status
		// against the manage-stock settings, fires stock hooks and refreshes the
		// product lookup table + transients.
		wc_update_product_stock( $product_id, $qty_int, 'set' );
		return true;
	}

	/**
	 * Build SKU => product_id for every product/variation in one query. The
	 * per-row SKU lookup is the real cost at 6k rows, so we pay it once.
	 */
	private static function preload_sku_map() {
		global $wpdb;
		// Constrain to real, non-trashed products/variations so stock is never
		// written to a trashed post whose _sku meta lingers.
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku' AND pm.meta_value <> ''
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')",
			ARRAY_A
		);
		$map = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				// If a SKU is somehow duplicated, last one wins - acceptable.
				$map[ $r['meta_value'] ] = (int) $r['post_id'];
			}
		}
		return $map;
	}

	private static function load_snapshot() {
		$raw = get_option( self::SNAPSHOT_OPTION, '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	private static function save_snapshot( array $snapshot ) {
		// Store as JSON string (autoload off) to keep the option compact.
		update_option( self::SNAPSHOT_OPTION, wp_json_encode( $snapshot ), false );
	}
}
