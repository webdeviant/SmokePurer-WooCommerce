<?php
/**
 * Catalogue importer: builds/updates WooCommerce products from the descriptions
 * and coming-soon feeds, joined with the weight / tag / image side feeds.
 *
 * Two stages:
 *   prepare()       - validate + circuit-break the feeds, normalise every row
 *                     into a product "group" (one simple product, or one
 *                     variable parent + its variations), and write the groups to
 *                     a JSON-lines staging file. Then kick off the first batch.
 *   process_batch() - process a slice of the staging file via WooCommerce CRUD
 *                     and self-reschedule from a byte offset until the file is
 *                     exhausted, so no single request risks a PHP timeout.
 *
 * Invariant: this importer writes product DATA only. It NEVER updates the stock
 * quantity of an existing product - stock is owned solely by SPS_Stock_Sync.
 * (It seeds stock once, only when a product is first created.)
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Catalogue_Importer {

	const GROUPS_PER_BATCH = 25;

	/* --------------------------------------------------------------------- */
	/* Stage 1: prepare                                                       */
	/* --------------------------------------------------------------------- */

	public static function prepare() {
		if ( ! SPS_Lock::acquire( 'catalogue_prepare', 900 ) ) {
			SPS_Logger::info( 'Catalogue prepare skipped - another prepare is in progress.' );
			return;
		}

		// Don't start a new cycle while one is still draining the staging file -
		// but if the cycle is unfinished yet NO batch action is pending, a batch
		// died: resume from the persisted offset rather than skipping forever.
		if ( 'running' === get_option( 'sps_cat_state', '' ) && self::staging_remaining() ) {
			if ( self::batch_in_flight() ) {
				SPS_Logger::info( 'Catalogue prepare skipped - previous import still processing.' );
				SPS_Lock::release( 'catalogue_prepare' );
				return;
			}
			$offset = (int) get_option( 'sps_cat_offset', 0 );
			SPS_Logger::warning( sprintf( 'Catalogue import stalled with no pending batch; resuming from offset %d.', $offset ) );
			SPS_Lock::release( 'catalogue_prepare' );
			self::enqueue_batch( $offset );
			return;
		}

		$desc_url = (string) SPS_Settings::get( 'feed_descriptions', '' );
		$tmp      = SPS_Feed_Client::download( $desc_url );
		if ( is_wp_error( $tmp ) ) {
			SPS_Logger::record_run( 'catalogue', 'error', $tmp->get_error_message() );
			SPS_Lock::release( 'catalogue_prepare' );
			return;
		}

		$required = array( 'SKU', 'Parent SKU', 'Name', 'Brand', 'Quantity', 'Price', 'Sale Price', 'Type', 'Description' );
		$map      = SPS_CSV::header_map( $tmp, $required );
		if ( is_wp_error( $map ) ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::record_run( 'catalogue', 'error', $map->get_error_message() );
			SPS_Lock::release( 'catalogue_prepare' );
			return;
		}

		// Circuit-breaker on the descriptions snapshot.
		$row_count = SPS_CSV::count_rows( $tmp );
		$tripped   = SPS_Feed_Client::breaker_tripped( 'feed_descriptions', $row_count );
		if ( $tripped ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::record_run( 'catalogue', 'error', $tripped );
			SPS_Lock::release( 'catalogue_prepare' );
			return;
		}

		// Side-feed maps (small enough to hold in memory).
		$weights = self::load_side_map( 'feed_weights', 'Weight' );
		$images  = self::load_side_map( 'feed_images', 'Image' );
		$tags    = self::load_side_map( 'feed_tags', 'Tag' );

		// Build groups from the descriptions feed, then merge coming-soon.
		$groups = self::build_groups( $tmp, $map, $weights, $images, $tags, false );
		SPS_Feed_Client::cleanup( $tmp );

		self::merge_coming_soon( $groups, $weights, $images, $tags );

		// Write the staging file.
		$path    = self::staging_path();
		$written = self::write_staging( $path, $groups );
		if ( is_wp_error( $written ) ) {
			SPS_Logger::record_run( 'catalogue', 'error', $written->get_error_message() );
			SPS_Lock::release( 'catalogue_prepare' );
			return;
		}

		// Baseline for the circuit-breaker + reset cycle counters.
		SPS_Feed_Client::record_good( 'feed_descriptions', $row_count );
		update_option( 'sps_cat_state', 'running', false );
		update_option( 'sps_cat_total', $written, false );
		update_option( 'sps_cat_processed', 0, false );
		update_option( 'sps_cat_offset', 0, false );
		update_option( 'sps_cat_created', 0, false );
		update_option( 'sps_cat_updated', 0, false );

		SPS_Logger::info( sprintf( 'Catalogue prepared: %d product groups staged from %d feed rows.', $written, $row_count ) );

		SPS_Lock::release( 'catalogue_prepare' );

		// Kick off processing from offset 0.
		self::enqueue_batch( 0 );
	}

	/* --------------------------------------------------------------------- */
	/* Stage 2: process a batch                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * @param int $offset Byte offset into the staging file to resume from.
	 */
	public static function process_batch( $offset = 0 ) {
		$offset = (int) $offset;
		$path   = self::staging_path();
		if ( ! file_exists( $path ) ) {
			return;
		}

		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return;
		}
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		$created  = 0;
		$updated  = 0;
		$done     = 0;

		while ( $done < self::GROUPS_PER_BATCH && false !== ( $line = fgets( $fh ) ) ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$group = json_decode( $line, true );
			if ( ! is_array( $group ) ) {
				continue;
			}

			try {
				$result = ( 'variable' === ( $group['type'] ?? '' ) )
					? self::upsert_variable( $group )
					: self::upsert_simple( $group );
				if ( 'created' === $result ) {
					$created++;
				} elseif ( 'updated' === $result ) {
					$updated++;
				}
			} catch ( \Throwable $e ) {
				SPS_Logger::warning( sprintf( 'Product upsert failed for %s: %s', $group['sku'] ?? $group['parent_sku'] ?? '?', $e->getMessage() ) );
			}
			$done++;
		}

		$new_offset = ftell( $fh );
		$eof        = feof( $fh ) || ( $new_offset >= filesize( $path ) );
		fclose( $fh );

		// Update running counters + persist the resume offset so a crashed batch
		// (PHP fatal / OOM / timeout) can be resumed by prepare() instead of
		// wedging the import in the "running" state forever.
		self::bump( 'sps_cat_processed', $done );
		self::bump( 'sps_cat_created', $created );
		self::bump( 'sps_cat_updated', $updated );
		update_option( 'sps_cat_offset', (int) $new_offset, false );

		if ( ! $eof && $done > 0 ) {
			self::enqueue_batch( $new_offset );
			return;
		}

		self::finalize();
	}

	private static function finalize() {
		$total     = (int) get_option( 'sps_cat_total', 0 );
		$created   = (int) get_option( 'sps_cat_created', 0 );
		$updated   = (int) get_option( 'sps_cat_updated', 0 );

		update_option( 'sps_cat_state', 'idle', false );

		$path = self::staging_path();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		SPS_Logger::record_run(
			'catalogue',
			'ok',
			sprintf( 'Catalogue import complete: %d created, %d updated (of %d groups).', $created, $updated, $total ),
			array(
				'created' => $created,
				'updated' => $updated,
				'total'   => $total,
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Group building                                                         */
	/* --------------------------------------------------------------------- */

	private static function build_groups( $path, $map, $weights, $images, $tags, $preorder ) {
		$groups = array();

		foreach ( SPS_CSV::rows( $path, $map ) as $row ) {
			$sku = trim( $row['SKU'] );
			if ( '' === $sku ) {
				continue;
			}
			$parent = trim( $row['Parent SKU'] );

			$weight_g  = isset( $weights[ $sku ] ) ? $weights[ $sku ] : ( $row['Weight'] ?? '' );
			$image_url = isset( $images[ $sku ] ) ? $images[ $sku ] : ( $row['Image'] ?? '' );
			$tag       = isset( $tags[ $sku ] ) ? $tags[ $sku ] : '';

			if ( '' === $parent ) {
				// Simple product.
				$groups[ 'S:' . $sku ] = array(
					'type'        => 'simple',
					'sku'         => $sku,
					'name'        => self::clean_name( $row['Name'] ),
					'brand'       => trim( $row['Brand'] ),
					'category'    => trim( $row['Type'] ),
					'description' => (string) $row['Description'],
					'trade_price' => $row['Price'],
					'trade_sale'  => $row['Sale Price'],
					'weight_g'    => $weight_g,
					'image_url'   => $image_url,
					'tag'         => $tag,
					'seed_qty'    => $row['Quantity'],
					'preorder'    => $preorder,
				);
			} else {
				// Variation - accumulate under its parent.
				$key = 'V:' . $parent;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'type'        => 'variable',
						'parent_sku'  => $parent,
						'parent_name' => self::clean_name( $row['Parent Name'] ),
						'brand'       => trim( $row['Brand'] ),
						'category'    => trim( $row['Type'] ),
						'description' => (string) $row['Description'], // Parent has no row of its own; use first child's.
						'tag'         => $tag,
						'preorder'    => $preorder,
						'children'    => array(),
					);
				}
				$label = self::unique_child_label(
					$groups[ $key ]['children'],
					self::variation_label( $row['Variation'] ?? '', $row['Name'] ),
					$sku
				);
				$groups[ $key ]['children'][] = array(
					'sku'         => $sku,
					'variation'   => $label,
					'trade_price' => $row['Price'],
					'trade_sale'  => $row['Sale Price'],
					'weight_g'    => $weight_g,
					'image_url'   => $image_url,
					'seed_qty'    => $row['Quantity'],
				);
			}
		}

		return $groups;
	}

	private static function merge_coming_soon( &$groups, $weights, $images, $tags ) {
		$url = (string) SPS_Settings::get( 'feed_coming_soon', '' );
		if ( '' === $url ) {
			return;
		}
		$tmp = SPS_Feed_Client::download( $url );
		if ( is_wp_error( $tmp ) ) {
			SPS_Logger::warning( 'Coming-soon feed skipped: ' . $tmp->get_error_message() );
			return;
		}
		$required = array( 'SKU', 'Parent SKU', 'Name', 'Brand', 'Price', 'Sale Price', 'Type', 'Description' );
		$map      = SPS_CSV::header_map( $tmp, $required );
		if ( is_wp_error( $map ) ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::warning( 'Coming-soon feed schema drift: ' . $map->get_error_message() );
			return;
		}
		$cs = self::build_groups( $tmp, $map, $weights, $images, $tags, true );
		SPS_Feed_Client::cleanup( $tmp );

		// Coming-soon child SKUs are absent from the main feed, but a pre-order
		// may introduce a NEW variation under a variable parent that already
		// exists in the descriptions feed. In that case the group key collides, so
		// merge the new children in (deduped by SKU) rather than dropping them.
		foreach ( $cs as $key => $group ) {
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = $group;
				continue;
			}
			if ( 'variable' !== ( $group['type'] ?? '' ) || 'variable' !== ( $groups[ $key ]['type'] ?? '' ) ) {
				continue; // Simple collision: keep the descriptions-feed version.
			}
			$existing_skus = array();
			foreach ( $groups[ $key ]['children'] as $child ) {
				$existing_skus[ $child['sku'] ] = true;
			}
			foreach ( $group['children'] as $child ) {
				if ( isset( $existing_skus[ $child['sku'] ] ) ) {
					continue;
				}
				$child['variation'] = self::unique_child_label( $groups[ $key ]['children'], $child['variation'], $child['sku'] );
				$groups[ $key ]['children'][] = $child;
			}
		}
	}

	private static function write_staging( $path, $groups ) {
		wp_mkdir_p( dirname( $path ) );
		$fh = fopen( $path, 'w' );
		if ( ! $fh ) {
			return new WP_Error( 'sps_staging_write', 'Could not open staging file for writing: ' . $path );
		}
		$count = 0;
		foreach ( $groups as $group ) {
			fwrite( $fh, wp_json_encode( $group ) . "\n" );
			$count++;
		}
		fclose( $fh );
		return $count;
	}

	/* --------------------------------------------------------------------- */
	/* Upserts (WooCommerce CRUD)                                             */
	/* --------------------------------------------------------------------- */

	private static function upsert_simple( $g ) {
		$existing_id = wc_get_product_id_by_sku( $g['sku'] );
		$is_new      = ! $existing_id;
		$product     = $is_new ? new WC_Product_Simple() : wc_get_product( $existing_id );

		if ( ! $product ) {
			return 'skipped';
		}

		// Guard against a destructive product-type change: if this SKU already
		// exists as a variable product or a variation, don't overwrite it with
		// simple-product data (mirrors the variable/variation paths).
		if ( ! $is_new && ( $product->is_type( 'variable' ) || $product instanceof WC_Product_Variation ) ) {
			SPS_Logger::warning( sprintf( 'SKU %s exists as a "%s" product; skipping simple upsert to avoid a destructive type change.', $g['sku'], $product->get_type() ) );
			return 'skipped';
		}

		$product->set_sku( $g['sku'] );
		$product->set_name( $g['name'] );
		if ( '' !== trim( (string) $g['description'] ) ) {
			$product->set_description( wp_kses_post( $g['description'] ) );
		}

		self::apply_prices( $product, $g['trade_price'], $g['trade_sale'] );
		self::apply_category( $product, $g['category'] );
		self::apply_weight( $product, $g['weight_g'] );

		$owned = array();
		self::maybe_add_brand_attribute( $owned, $g['brand'] );
		if ( $owned ) {
			self::set_owned_attributes( $product, $owned );
		}

		if ( $is_new ) {
			self::seed_stock( $product, $g['seed_qty'], ! empty( $g['preorder'] ) );
			$product->set_status( (string) SPS_Settings::get( 'new_product_status', 'draft' ) );
		}

		$product->update_meta_data( '_sps_managed', 'yes' );
		$product_id = $product->save();

		self::apply_brand_taxonomy( $product_id, $g['brand'] );
		self::apply_tags( $product_id, $g['tag'] );

		if ( '' !== trim( (string) $g['image_url'] ) && SPS_Images::ensure_featured( $product, $g['image_url'] ) ) {
			$product->save();
		}

		return $is_new ? 'created' : 'updated';
	}

	private static function upsert_variable( $g ) {
		$existing_id = wc_get_product_id_by_sku( $g['parent_sku'] );
		$is_new      = ! $existing_id;

		if ( ! $is_new ) {
			$parent = wc_get_product( $existing_id );
			if ( ! $parent instanceof WC_Product_Variable ) {
				// A SKU that used to be simple is now variable (or vice-versa).
				// Changing product type destructively is risky - log and skip.
				SPS_Logger::warning( sprintf( 'SKU %s exists but is not a variable product; skipping to avoid a destructive type change.', $g['parent_sku'] ) );
				return 'skipped';
			}
		} else {
			$parent = new WC_Product_Variable();
		}

		$parent->set_sku( $g['parent_sku'] );
		$parent->set_name( $g['parent_name'] );
		if ( '' !== trim( (string) $g['description'] ) ) {
			$parent->set_description( wp_kses_post( $g['description'] ) );
		}
		self::apply_category( $parent, $g['category'] );

		// Variation attribute built from the distinct child "Variation" labels.
		$labels = array();
		foreach ( $g['children'] as $child ) {
			$label = $child['variation'];
			if ( '' !== $label && ! in_array( $label, $labels, true ) ) {
				$labels[] = $label;
			}
		}
		$attr_label = (string) SPS_Settings::get( 'attribute_label', 'Options' );

		$owned = array();
		$owned[ sanitize_title( $attr_label ) ] = self::custom_attribute( $attr_label, $labels, true, true );
		self::maybe_add_brand_attribute( $owned, $g['brand'] );
		self::set_owned_attributes( $parent, $owned );

		$parent->set_manage_stock( false ); // Variable parents derive stock from variations.

		if ( $is_new ) {
			$parent->set_status( (string) SPS_Settings::get( 'new_product_status', 'draft' ) );
		}
		$parent->update_meta_data( '_sps_managed', 'yes' );
		$parent_id = $parent->save();

		self::apply_brand_taxonomy( $parent_id, $g['brand'] );
		self::apply_tags( $parent_id, $g['tag'] );

		// Variations.
		$attr_key = sanitize_title( $attr_label );
		foreach ( $g['children'] as $child ) {
			self::upsert_variation( $parent_id, $attr_key, $child, ! empty( $g['preorder'] ) );
		}

		// The parent has no SKU row of its own in the image feed, so give it a
		// featured image by reusing the first variation's image (deduped) - else
		// the variable product shows a placeholder as its main shop image.
		self::ensure_parent_image( $parent_id, $g['children'] );

		// Recompute price range / stock status aggregation on the parent.
		WC_Product_Variable::sync( $parent_id );
		wc_delete_product_transients( $parent_id );

		return $is_new ? 'created' : 'updated';
	}

	private static function ensure_parent_image( $parent_id, array $children ) {
		if ( ! SPS_Settings::get( 'import_images', true ) ) {
			return;
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent || $parent->get_image_id() ) {
			return;
		}
		foreach ( $children as $child ) {
			if ( '' !== trim( (string) $child['image_url'] ) && SPS_Images::ensure_featured( $parent, $child['image_url'] ) ) {
				$parent->save();
				return;
			}
		}
	}

	private static function upsert_variation( $parent_id, $attr_key, $child, $preorder ) {
		$existing_id = wc_get_product_id_by_sku( $child['sku'] );
		$is_new      = ! $existing_id;
		$variation   = $is_new ? new WC_Product_Variation() : wc_get_product( $existing_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			if ( ! $is_new ) {
				SPS_Logger::warning( sprintf( 'SKU %s exists but is not a variation; skipping.', $child['sku'] ) );
				return;
			}
			$variation = new WC_Product_Variation();
		}

		$variation->set_parent_id( $parent_id );
		$variation->set_sku( $child['sku'] );
		$variation->set_attributes( array( $attr_key => $child['variation'] ) );

		self::apply_prices( $variation, $child['trade_price'], $child['trade_sale'] );
		self::apply_weight( $variation, $child['weight_g'] );

		if ( $is_new ) {
			self::seed_stock( $variation, $child['seed_qty'], $preorder );
		}

		$variation->update_meta_data( '_sps_managed', 'yes' );
		$variation_id = $variation->save();

		if ( '' !== trim( (string) $child['image_url'] ) && SPS_Images::ensure_featured( $variation, $child['image_url'] ) ) {
			$variation->save();
		}
	}

	/* --------------------------------------------------------------------- */
	/* Field helpers                                                          */
	/* --------------------------------------------------------------------- */

	private static function apply_prices( $product, $trade_price, $trade_sale ) {
		$regular = SPS_Price::retail( $trade_price );
		if ( null === $regular ) {
			// No usable trade price: leave the regular untouched rather than zero
			// it, but still reconcile the sale against this full snapshot - a sale
			// can't be trusted without a regular, so clear it.
			self::clear_sale( $product );
			return;
		}
		$decimals = wc_get_price_decimals();
		$product->set_regular_price( wc_format_decimal( $regular, $decimals ) );

		$sale = SPS_Price::retail_sale( $trade_sale, $regular );
		if ( null !== $sale ) {
			$product->set_sale_price( wc_format_decimal( $sale, $decimals ) );
		} else {
			// Full-snapshot feed: a vanished sale must be actively cleared.
			self::clear_sale( $product );
		}
	}

	private static function clear_sale( $product ) {
		$product->set_sale_price( '' );
		$product->set_date_on_sale_from( null );
		$product->set_date_on_sale_to( null );
	}

	private static function apply_category( $product, $type ) {
		$term_id = SPS_Categories::term_id_for_type( $type );
		if ( ! $term_id ) {
			return;
		}
		// Union, don't replace: preserve any curated / secondary categories a
		// merchandiser or SEO plugin added rather than forcing a single category.
		$existing = $product->get_category_ids();
		$existing = is_array( $existing ) ? $existing : array();
		if ( ! in_array( $term_id, $existing, true ) ) {
			$existing[] = $term_id;
			$product->set_category_ids( $existing );
		}
	}

	/**
	 * Replace only the product attributes this plugin owns (the variation
	 * attribute and the fallback Brand), preserving any others added by hand or
	 * by other plugins.
	 *
	 * @param WC_Product             $product
	 * @param array<string,WC_Product_Attribute> $owned Keyed by sanitised attribute name.
	 */
	private static function set_owned_attributes( $product, array $owned ) {
		$existing = $product->get_attributes();
		$existing = is_array( $existing ) ? $existing : array();
		foreach ( $owned as $key => $attribute ) {
			$existing[ $key ] = $attribute;
		}
		$product->set_attributes( $existing );
	}

	private static function apply_weight( $product, $grams_raw ) {
		$grams = trim( (string) $grams_raw );
		if ( '' === $grams || ! is_numeric( preg_replace( '/[^0-9.\-]/', '', $grams ) ) ) {
			return;
		}
		$grams = (float) preg_replace( '/[^0-9.\-]/', '', $grams );
		if ( $grams <= 0 ) {
			return;
		}
		$unit = get_option( 'woocommerce_weight_unit', 'kg' );
		switch ( $unit ) {
			case 'g':
				$weight = $grams;
				break;
			case 'lbs':
				$weight = $grams * 0.00220462;
				break;
			case 'oz':
				$weight = $grams * 0.035274;
				break;
			case 'kg':
			default:
				$weight = $grams / 1000;
				break;
		}
		$product->set_weight( (string) round( $weight, 4 ) );
	}

	private static function seed_stock( $product, $qty_raw, $preorder ) {
		$qty = trim( (string) $qty_raw );
		if ( '' === $qty && ! $preorder ) {
			return;
		}
		$qty_int = (int) preg_replace( '/[^0-9\-]/', '', $qty );

		if ( $preorder || $qty_int >= SPS_PREORDER_SENTINEL ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'onbackorder' );
			$product->set_backorders( 'notify' );
			return;
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( max( 0, $qty_int ) );
		$product->set_stock_status( $qty_int > 0 ? 'instock' : 'outofstock' );
	}

	private static function custom_attribute( $label, array $options, $variation, $visible ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 ); // Custom (non-taxonomy) attribute.
		$attribute->set_name( $label );
		$attribute->set_options( $options );
		$attribute->set_visible( $visible );
		$attribute->set_variation( $variation );
		return $attribute;
	}

	private static function maybe_add_brand_attribute( array &$owned, $brand ) {
		$brand = trim( (string) $brand );
		if ( '' === $brand || taxonomy_exists( 'product_brand' ) ) {
			return; // Native brand taxonomy handles it after save.
		}
		$owned['brand'] = self::custom_attribute( 'Brand', array( $brand ), false, true );
	}

	private static function apply_brand_taxonomy( $product_id, $brand ) {
		$brand = trim( (string) $brand );
		if ( '' === $brand || ! $product_id || ! taxonomy_exists( 'product_brand' ) ) {
			return;
		}
		wp_set_object_terms( $product_id, $brand, 'product_brand', false );
	}

	private static function apply_tags( $product_id, $tag ) {
		$tag = trim( (string) $tag );
		if ( '' === $tag || ! $product_id ) {
			return;
		}
		// Normalise case ("End of Line" / "End of line").
		$tag = ucwords( strtolower( $tag ) );
		wp_set_object_terms( $product_id, $tag, 'product_tag', false );
	}

	private static function clean_name( $name ) {
		// Trim the Excel text-force apostrophe artefact and surrounding space.
		$name = trim( (string) $name );
		$name = preg_replace( "/^'+/", '', $name );
		return trim( $name );
	}

	private static function variation_label( $variation, $fallback ) {
		$label = trim( (string) $variation );
		if ( '' !== $label ) {
			return $label;
		}
		$label = trim( (string) $fallback );
		return '' !== $label ? $label : 'Standard';
	}

	/**
	 * Ensure a variation label is unique within its parent, so each variation is
	 * distinguishable on the storefront. Collisions (e.g. several children with an
	 * empty Variation column) are disambiguated with the child's unique SKU.
	 */
	private static function unique_child_label( array $children, $label, $sku ) {
		$used = array();
		foreach ( $children as $child ) {
			$used[ strtolower( $child['variation'] ) ] = true;
		}
		if ( ! isset( $used[ strtolower( $label ) ] ) ) {
			return $label;
		}
		return $label . ' (' . $sku . ')';
	}

	/* --------------------------------------------------------------------- */
	/* Staging + counters                                                     */
	/* --------------------------------------------------------------------- */

	private static function load_side_map( $feed_key, $value_col ) {
		$url = (string) SPS_Settings::get( $feed_key, '' );
		if ( '' === $url ) {
			return array();
		}
		$tmp = SPS_Feed_Client::download( $url );
		if ( is_wp_error( $tmp ) ) {
			SPS_Logger::warning( sprintf( 'Side feed %s skipped: %s', $feed_key, $tmp->get_error_message() ) );
			return array();
		}
		$map = SPS_CSV::header_map( $tmp, array( 'SKU', $value_col ) );
		if ( is_wp_error( $map ) ) {
			SPS_Feed_Client::cleanup( $tmp );
			SPS_Logger::warning( sprintf( 'Side feed %s schema drift: %s', $feed_key, $map->get_error_message() ) );
			return array();
		}
		$out = SPS_CSV::key_value( $tmp, $map, 'SKU', $value_col );
		SPS_Feed_Client::cleanup( $tmp );
		return $out;
	}

	private static function enqueue_batch( $offset ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( SPS_HOOK_CATALOGUE_BATCH, array( (int) $offset ), 'smokepurer-sync' );
		} else {
			// Fallback: process inline (e.g. manual CLI run without scheduler).
			self::process_batch( $offset );
		}
	}

	private static function bump( $option, $delta ) {
		update_option( $option, (int) get_option( $option, 0 ) + (int) $delta, false );
	}

	private static function staging_path() {
		$dir = wp_upload_dir();
		return trailingslashit( $dir['basedir'] ) . SPS_STAGING_DIRNAME . '/catalogue-staging.jsonl';
	}

	private static function staging_remaining() {
		$path = self::staging_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		return (int) get_option( 'sps_cat_processed', 0 ) < (int) get_option( 'sps_cat_total', 0 );
	}

	/**
	 * True if a catalogue batch is still pending or in progress in Action
	 * Scheduler. Used to distinguish "still processing" from "batch died".
	 */
	private static function batch_in_flight() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false; // Can't tell -> assume not, so a stall can recover.
		}
		return as_has_scheduled_action( SPS_HOOK_CATALOGUE_BATCH, null, 'smokepurer-sync' );
	}
}
