<?php
/**
 * Maps the messy feed "Type" column to a WooCommerce product category.
 *
 * The Type column is inconsistent ("Eliquid Nic Salt" vs "E-liquid Nic Salt" vs
 * "E-Liquids") and ~30% blank, so we normalise, look the value up in an operator
 * -maintained mapping table, and only fall back to a default when unmapped. We
 * deliberately do NOT auto-create a new category per raw Type value, or the shop
 * ends up with dozens of near-duplicate categories.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Categories {

	/** @var array<string,int> Runtime cache of category name => term id. */
	private static $term_cache = array();

	/** @var array<string,string>|null Normalised map cache. */
	private static $map = null;

	/**
	 * Resolve a feed Type string to a category term id (creating the mapped
	 * category if allowed). Returns 0 when nothing sensible can be resolved.
	 */
	public static function term_id_for_type( $type ) {
		$type = self::normalise( $type );

		$map    = self::normalised_map();
		$target = '';

		if ( '' !== $type && isset( $map[ $type ] ) ) {
			$target = $map[ $type ];
		} else {
			$target = (string) SPS_Settings::get( 'category_fallback', 'Uncategorised' );
		}

		$target = trim( $target );
		if ( '' === $target ) {
			return 0;
		}

		return self::term_id_for_name( $target );
	}

	private static function term_id_for_name( $name ) {
		$cache_key = strtolower( $name );
		if ( isset( self::$term_cache[ $cache_key ] ) ) {
			return self::$term_cache[ $cache_key ];
		}

		$term = get_term_by( 'name', $name, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			self::$term_cache[ $cache_key ] = (int) $term->term_id;
			return self::$term_cache[ $cache_key ];
		}

		if ( ! SPS_Settings::get( 'auto_create_categories', true ) ) {
			self::$term_cache[ $cache_key ] = 0;
			return 0;
		}

		$created = wp_insert_term( $name, 'product_cat' );
		if ( is_wp_error( $created ) ) {
			// Race: another process may have created it between check and insert.
			$term = get_term_by( 'name', $name, 'product_cat' );
			$id   = ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		} else {
			$id = (int) $created['term_id'];
		}

		self::$term_cache[ $cache_key ] = $id;
		return $id;
	}

	private static function normalised_map() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		self::$map = array();
		$raw = SPS_Settings::get( 'category_map', array() );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $type => $cat ) {
				self::$map[ self::normalise( $type ) ] = (string) $cat;
			}
		}
		return self::$map;
	}

	/**
	 * Normalise a Type value for matching: lowercase, collapse whitespace, and
	 * fold the "eliquid / e-liquid / e liquid" family together.
	 */
	private static function normalise( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = str_replace( array( 'e-liquid', 'e liquid' ), 'eliquid', $value );
		return $value;
	}
}
