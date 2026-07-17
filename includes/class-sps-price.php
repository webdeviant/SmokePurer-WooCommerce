<?php
/**
 * Price transformation.
 *
 * The feed "Price" is a TRADE / wholesale price (median ~GBP 4.51). Importing it
 * as-is would sell every product at cost. This class is the single place where
 * trade -> retail happens, so the rule is testable and consistent.
 *
 * NOTE ON VAT: the number produced here is written to the product's regular
 * price and is then interpreted by WooCommerce according to the store's existing
 * tax settings (Tax > "Prices entered with tax"). Decide that store-wide setting
 * once; this markup does not itself add or remove VAT.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Price {

	/**
	 * Convert a trade price to a retail price.
	 *
	 * @param string|float $trade Raw trade price from the feed.
	 * @return float|null         Retail price, or null if the input is not a
	 *                            usable positive number.
	 */
	public static function retail( $trade ) {
		$trade = self::to_float( $trade );
		if ( null === $trade || $trade <= 0 ) {
			return null;
		}

		$markup = (float) SPS_Settings::get( 'markup_percent', 100.0 );
		$retail = $trade * ( 1 + ( $markup / 100 ) );

		$retail = self::round( $retail, (string) SPS_Settings::get( 'rounding', 'charm99' ) );

		// Margin guard: never let rounding drag the retail price to or below cost.
		if ( SPS_Settings::get( 'min_margin_guard', true ) && $retail <= $trade ) {
			$retail = self::round( $trade * 1.10, 'charm99' );
		}

		return $retail;
	}

	/**
	 * Retail sale price. Returns null when there is no sale, or when the computed
	 * sale price would not actually be a discount on the retail regular price
	 * (in which case the caller should clear the sale, not set it).
	 *
	 * @param string|float $trade_sale     Raw trade sale price from the feed.
	 * @param float|null   $retail_regular The already-computed retail regular price.
	 * @return float|null
	 */
	public static function retail_sale( $trade_sale, $retail_regular ) {
		$trade_sale = self::to_float( $trade_sale );
		if ( null === $trade_sale || $trade_sale <= 0 || null === $retail_regular ) {
			return null;
		}
		$sale = self::retail( $trade_sale );
		if ( null === $sale || $sale >= $retail_regular ) {
			return null;
		}
		return $sale;
	}

	private static function round( $value, $mode ) {
		switch ( $mode ) {
			case 'none':
				return round( $value, 2 );
			case 'charm95':
				return floor( $value ) + 0.95;
			case 'nearest_5p':
				return round( $value * 20 ) / 20;
			case 'nearest_10p':
				return round( $value * 10 ) / 10;
			case 'charm99':
			default:
				return floor( $value ) + 0.99;
		}
	}

	private static function to_float( $value ) {
		if ( is_float( $value ) || is_int( $value ) ) {
			return (float) $value;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		// Strip anything that isn't a digit, separator or sign.
		$value = preg_replace( '/[^0-9.\-]/', '', $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return (float) $value;
	}
}
