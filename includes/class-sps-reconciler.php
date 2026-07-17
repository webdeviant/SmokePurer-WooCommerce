<?php
/**
 * Retirement / reconciliation.
 *
 * SmokePurer signals discontinued lines through the "disabled last 7 days" delta
 * feed. We act ONLY on that feed - we deliberately do NOT draft a product merely
 * because it vanished from the descriptions snapshot, because a single truncated
 * download would then delist thousands of live products. A safety cap aborts the
 * run if it would retire an implausibly large share of the catalogue.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Reconciler {

	public static function run() {
		if ( ! SPS_Lock::acquire( 'reconcile', 300 ) ) {
			return;
		}
		try {
			self::do_run();
		} catch ( \Throwable $e ) {
			SPS_Logger::record_run( 'reconcile', 'error', 'Reconcile crashed: ' . $e->getMessage() );
		} finally {
			SPS_Lock::release( 'reconcile' );
		}
	}

	private static function do_run() {
		$url = (string) SPS_Settings::get( 'feed_disabled_7d', '' );
		if ( '' === $url ) {
			return;
		}
		$tmp = SPS_Feed_Client::download( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			SPS_Logger::record_run( 'reconcile', 'error', $tmp->get_error_message() );
			return;
		}

		$map = SPS_CSV::header_map( $tmp, array( 'SKU' ) );
		if ( is_wp_error( $map ) ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::record_run( 'reconcile', 'error', $map->get_error_message() );
			return;
		}

		// Collect target SKUs first so we can apply the safety cap before writing.
		$skus = array();
		foreach ( SPS_CSV::rows( $tmp, $map ) as $row ) {
			$sku = trim( $row['SKU'] );
			if ( '' !== $sku ) {
				$skus[ $sku ] = true;
			}
		}
		SPS_Feed_Client::cleanup( $tmp );

		$catalogue_size = self::catalogue_size();
		$cap_percent    = max( 1, (int) SPS_Settings::get( 'retire_max_percent', 20 ) );
		$cap            = (int) ceil( $catalogue_size * $cap_percent / 100 );

		if ( $catalogue_size > 0 && count( $skus ) > $cap && $cap > 0 ) {
			SPS_Logger::record_run(
				'reconcile',
				'error',
				sprintf( 'Reconcile aborted: disabled feed lists %d SKUs, above the %d%% safety cap (%d) of a %d-product catalogue.', count( $skus ), $cap_percent, $cap, $catalogue_size )
			);
			return;
		}

		$action  = (string) SPS_Settings::get( 'retire_action', 'draft' );
		$retired = 0;

		foreach ( array_keys( $skus ) as $sku ) {
			$product_id = wc_get_product_id_by_sku( $sku );
			if ( ! $product_id ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			if ( 'outofstock' === $action ) {
				$product->set_stock_status( 'outofstock' );
				if ( $product->get_manage_stock() ) {
					$product->set_stock_quantity( 0 );
				}
			} else {
				if ( 'draft' === $product->get_status() ) {
					continue;
				}
				$product->set_status( 'draft' );
			}
			$product->update_meta_data( '_sps_retired', current_time( 'mysql' ) );
			$product->save();

			// If this is a variation, retire its variations siblings? No - retire
			// the specific SKU only. Parent status is left for a full catalogue run.
			$retired++;
		}

		SPS_Logger::record_run(
			'reconcile',
			'ok',
			sprintf( 'Reconcile: retired %d product(s) via "%s" from %d disabled SKUs.', $retired, $action, count( $skus ) ),
			array(
				'retired' => $retired,
				'listed'  => count( $skus ),
			)
		);
	}

	private static function catalogue_size() {
		$total  = 0;
		$counts = wp_count_posts( 'product' );
		if ( is_object( $counts ) ) {
			foreach ( array( 'publish', 'draft', 'private', 'pending' ) as $status ) {
				if ( isset( $counts->$status ) ) {
					$total += (int) $counts->$status;
				}
			}
		}
		// The disabled feed is keyed on raw SKUs, ~59% of which are variations, so
		// count variations too - otherwise the % cap uses a denominator ~35%
		// smaller than the population and trips on legitimate large retirements.
		$variations = wp_count_posts( 'product_variation' );
		if ( is_object( $variations ) ) {
			foreach ( array( 'publish', 'private' ) as $status ) {
				if ( isset( $variations->$status ) ) {
					$total += (int) $variations->$status;
				}
			}
		}
		return $total;
	}
}
