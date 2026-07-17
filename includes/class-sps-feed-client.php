<?php
/**
 * Fetches a feed to a temp file and validates it before anyone parses a single
 * row. This is the first half of the safety story: a truncated download, a 502
 * error page served with a 200, or a wrong content-type never reaches the
 * importer. The second half (row-count circuit-breaker) lives in
 * {@see SPS_Feed_Client::breaker_tripped()}.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Feed_Client {

	const LASTGOOD_OPTION = 'sps_feed_lastgood';

	/**
	 * Download a feed to a temp file and sanity-check it.
	 *
	 * @param string $url          Feed URL.
	 * @param int    $min_bytes    Reject anything smaller (guards empty/error bodies).
	 * @param int    $timeout      HTTP timeout in seconds.
	 * @return string|WP_Error     Path to the temp file, or WP_Error.
	 */
	public static function download( $url, $min_bytes = 40, $timeout = 60 ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Feed URLs are admin-configured, but refuse an obviously-internal host so
		// a mistaken/compromised setting can't turn a scheduled job into an SSRF.
		$host    = wp_parse_url( $url, PHP_URL_HOST );
		$blocked = ! $host || self::is_blocked_host( $host );
		/**
		 * Filter whether a feed host is blocked. Return false to allow a specific
		 * internal host - e.g. a store that self-hosts its feeds on a private
		 * address. Default keeps loopback/private IP hosts blocked.
		 *
		 * @param bool        $blocked Whether the host is currently blocked.
		 * @param string|null $host    The parsed host.
		 * @param string      $url     The full feed URL.
		 */
		$blocked = apply_filters( 'sps_block_feed_host', $blocked, $host, $url );
		if ( $blocked ) {
			return new WP_Error( 'sps_blocked_host', sprintf( 'Refusing to fetch a feed from a non-public host: %s', $url ) );
		}

		$tmp = download_url( $url, $timeout );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'sps_download_failed', sprintf( 'Download failed for %s: %s', $url, $tmp->get_error_message() ) );
		}

		$size = (int) filesize( $tmp );
		if ( $size < $min_bytes ) {
			self::cleanup( $tmp );
			return new WP_Error( 'sps_too_small', sprintf( 'Feed %s is only %d bytes - looks empty or truncated.', $url, $size ) );
		}

		// Peek at the first line: it must not look like HTML, and must contain a
		// SKU header column. Handles a UTF-8 BOM.
		$first = self::first_line( $tmp );
		$first_trimmed = ltrim( $first, "\xEF\xBB\xBF \t" );
		if ( '' === $first_trimmed || '<' === substr( $first_trimmed, 0, 1 ) ) {
			self::cleanup( $tmp );
			return new WP_Error( 'sps_not_csv', sprintf( 'Feed %s did not return CSV (looks like an HTML/error page).', $url ) );
		}
		if ( false === stripos( $first_trimmed, 'sku' ) ) {
			self::cleanup( $tmp );
			return new WP_Error( 'sps_no_sku_header', sprintf( 'Feed %s header row has no SKU column: %s', $url, substr( $first_trimmed, 0, 120 ) ) );
		}

		return $tmp;
	}

	/**
	 * Circuit-breaker for full-snapshot feeds. Compares this run's row count with
	 * the last known-good count for the same feed key; trips (returns true) if the
	 * count collapsed by more than the configured percentage.
	 *
	 * @param string $feed_key   Settings key, e.g. "feed_quantity".
	 * @param int    $row_count  Rows counted in the current download.
	 * @return string|false      Reason string if the breaker tripped, false if safe.
	 */
	public static function breaker_tripped( $feed_key, $row_count ) {
		$store    = get_option( self::LASTGOOD_OPTION, array() );
		$store    = is_array( $store ) ? $store : array();
		$previous = isset( $store[ $feed_key ]['rows'] ) ? (int) $store[ $feed_key ]['rows'] : 0;

		if ( $previous <= 0 ) {
			return false; // No baseline yet (first ever run) - allow.
		}

		$percent   = max( 1, (int) SPS_Settings::get( 'breaker_percent', 15 ) );
		$threshold = (int) floor( $previous * ( 100 - $percent ) / 100 );

		if ( $row_count < $threshold ) {
			return sprintf(
				'Row count for %s dropped to %d (last good was %d; breaker fires below %d = -%d%%). Aborting to avoid mass changes.',
				$feed_key,
				$row_count,
				$previous,
				$threshold,
				$percent
			);
		}
		return false;
	}

	/**
	 * Record a successful run's row count as the new baseline for the breaker.
	 */
	public static function record_good( $feed_key, $row_count ) {
		$store = get_option( self::LASTGOOD_OPTION, array() );
		$store = is_array( $store ) ? $store : array();

		$store[ $feed_key ] = array(
			'rows' => (int) $row_count,
			'time' => time(),
		);
		update_option( self::LASTGOOD_OPTION, $store, false );
	}

	public static function cleanup( $path ) {
		if ( $path && is_string( $path ) && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Block localhost and literal private/reserved IP hosts. (A hostname that
	 * resolves to a private IP is not caught here - full protection would need
	 * DNS resolution; this is a cheap guard for a low-risk, admin-only input.)
	 */
	private static function is_blocked_host( $host ) {
		$host = strtolower( (string) $host );
		if ( 'localhost' === $host || 'ip6-localhost' === $host || '0.0.0.0' === $host ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	private static function first_line( $path ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return '';
		}
		$line = fgets( $fh, 8192 );
		fclose( $fh );
		return false === $line ? '' : $line;
	}
}
