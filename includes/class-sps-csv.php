<?php
/**
 * CSV reader.
 *
 * Uses PHP's fgetcsv, which is RFC-4180 aware: it correctly handles fields that
 * contain commas, quotes and EMBEDDED NEWLINES - essential here because the
 * SmokePurer "Description" column contains multi-line HTML, so a naive
 * line-splitter would shred every product with a formatted description.
 *
 * Columns are mapped strictly by header NAME (never by position), and the
 * expected header set is asserted up front, so a feed that reorders or renames a
 * column fails loudly instead of writing Price into the Quantity field.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_CSV {

	/**
	 * Read + validate the header row and return a name => column-index map.
	 *
	 * @param string   $path      File path.
	 * @param string[] $required  Header names that MUST be present.
	 * @return array|WP_Error     Map of header name => index, or WP_Error.
	 */
	public static function header_map( $path, array $required = array() ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'sps_open_failed', 'Could not open CSV: ' . $path );
		}

		$header = fgetcsv( $fh, 0, ',', '"', '' );
		fclose( $fh );

		if ( ! is_array( $header ) || empty( $header ) ) {
			return new WP_Error( 'sps_no_header', 'CSV has no header row: ' . $path );
		}

		// Strip a UTF-8 BOM from the first header cell (otherwise "SKU" becomes
		// "\xEF\xBB\xBFSKU" and every header lookup for SKU fails).
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

		$map = array();
		foreach ( $header as $index => $name ) {
			$clean = trim( (string) $name );
			if ( '' !== $clean ) {
				$map[ $clean ] = $index;
			}
		}

		$missing = array();
		foreach ( $required as $col ) {
			if ( ! array_key_exists( $col, $map ) ) {
				$missing[] = $col;
			}
		}
		if ( $missing ) {
			return new WP_Error(
				'sps_schema_drift',
				sprintf(
					'Feed schema changed - missing expected column(s): %s. Present columns: %s',
					implode( ', ', $missing ),
					implode( ', ', array_keys( $map ) )
				)
			);
		}

		return $map;
	}

	/**
	 * Stream rows as associative arrays keyed by header name. A generator, so the
	 * 2.8 MB descriptions feed is never fully loaded into memory.
	 *
	 * @param string $path  File path.
	 * @param array  $map   Header map from {@see header_map()}.
	 * @return \Generator<array<string,string>>
	 */
	public static function rows( $path, array $map ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return;
		}
		fgetcsv( $fh, 0, ',', '"', '' ); // Discard header row.

		while ( false !== ( $cols = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			// Skip fully blank lines.
			if ( null === $cols || ( 1 === count( $cols ) && null === $cols[0] ) ) {
				continue;
			}
			$assoc = array();
			foreach ( $map as $name => $index ) {
				$assoc[ $name ] = isset( $cols[ $index ] ) ? (string) $cols[ $index ] : '';
			}
			yield $assoc;
		}
		fclose( $fh );
	}

	/**
	 * Count data rows (excluding the header) using RFC-4180-aware parsing, so
	 * embedded newlines don't inflate the count. Used by the circuit-breaker.
	 */
	public static function count_rows( $path ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return 0;
		}
		$count = 0;
		fgetcsv( $fh, 0, ',', '"', '' ); // Header.
		while ( false !== ( $cols = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			if ( null === $cols || ( 1 === count( $cols ) && null === $cols[0] ) ) {
				continue;
			}
			$count++;
		}
		fclose( $fh );
		return $count;
	}

	/**
	 * Build a simple SKU => value map from a two-column side feed (quantity,
	 * weights, tags, images). Small feeds, safe to hold in memory.
	 *
	 * @param string $path      File path.
	 * @param array  $map       Header map.
	 * @param string $key_col   Header name of the key column (usually "SKU").
	 * @param string $value_col Header name of the value column.
	 * @return array<string,string>
	 */
	public static function key_value( $path, array $map, $key_col, $value_col ) {
		$out = array();
		foreach ( self::rows( $path, $map ) as $row ) {
			$sku = trim( $row[ $key_col ] );
			if ( '' !== $sku ) {
				$out[ $sku ] = $row[ $value_col ];
			}
		}
		return $out;
	}
}
