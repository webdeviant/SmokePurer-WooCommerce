<?php
/**
 * Image sideloading with source-URL de-duplication.
 *
 * The feed has ~6,200 image rows but many variations share one parent image, so
 * we key attachments by their source URL (_sps_source_url meta) and reuse an
 * existing attachment instead of re-downloading. Products remember the URL they
 * last imported (_sps_image_url) so re-runs skip unchanged images - this is what
 * keeps the catalogue import from re-fetching thousands of images every hour.
 *
 * URLs the supplier serves as 404/gone are "parked" in a dead-image registry so
 * they are not re-attempted (or re-logged) every run; they are retried
 * automatically after 30 days, or on demand via the admin "Retry missing images".
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Images {

	/** @var array<string,int> Runtime cache of source URL => attachment id. */
	private static $cache = array();

	/**
	 * Ensure the product's featured image matches $url. No-op if unchanged or if
	 * image import is disabled. Returns true if the featured image was set/changed.
	 *
	 * @param WC_Product $product
	 * @param string     $url
	 */
	public static function ensure_featured( $product, $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! SPS_Settings::get( 'import_images', true ) ) {
			return false;
		}

		// Already imported this exact URL for this product? Skip.
		$last = $product->get_meta( '_sps_image_url', true );
		if ( $last === $url && $product->get_image_id() ) {
			return false;
		}

		$attachment_id = self::attachment_for_url( $url, $product->get_id() );
		if ( ! $attachment_id ) {
			return false;
		}

		$product->set_image_id( $attachment_id );
		$product->update_meta_data( '_sps_image_url', $url );
		return true;
	}

	/**
	 * Return an attachment id for a source URL, reusing an existing one where
	 * possible and sideloading only when necessary.
	 */
	private static function attachment_for_url( $url, $parent_post_id ) {
		if ( isset( self::$cache[ $url ] ) ) {
			return self::$cache[ $url ];
		}

		// Confirmed-dead URL (a recent 404/gone)? Skip silently - no DB query, no
		// request, no log line. This is what stops the plugin re-hammering the
		// supplier's dead image URLs (and re-spamming the log) on every run.
		if ( self::is_dead( $url ) ) {
			self::$cache[ $url ] = 0;
			return 0;
		}

		// Look for a prior import of this exact source URL.
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_sps_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $url,              // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( ! empty( $existing ) ) {
			self::$cache[ $url ] = (int) $existing[0];
			return self::$cache[ $url ];
		}

		// SSRF guard: image URLs come verbatim from the untrusted feed, so refuse
		// internal/loopback/reserved hosts before fetching (same guard as feeds).
		if ( SPS_Feed_Client::is_url_blocked( $url ) ) {
			SPS_Logger::warning( sprintf( 'Image skipped - refusing a non-public host: %s', $url ) );
			self::$cache[ $url ] = 0;
			return 0;
		}

		// Sideload. Requires the media/file admin includes.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attempts   = max( 1, (int) SPS_Settings::get( 'image_retries', 2 ) + 1 );
		$last_error = '';
		$permanent  = false;
		$tries      = 0;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$tries = $attempt;
			// Throttle before each download so a large bulk import doesn't hammer
			// (and get throttled by) the supplier's image server.
			self::throttle();

			$attachment_id = media_sideload_image( $url, $parent_post_id, null, 'id' );

			if ( ! is_wp_error( $attachment_id ) ) {
				update_post_meta( $attachment_id, '_sps_source_url', $url );
				self::$cache[ $url ] = (int) $attachment_id;
				return (int) $attachment_id;
			}

			$last_error = $attachment_id->get_error_message();
			$permanent  = self::is_permanent_failure( $last_error );

			// A genuine 404/gone is permanent - retrying only wastes time and load.
			if ( $permanent || $attempt >= $attempts ) {
				break;
			}

			// Transient failure (timeout, connection reset, 5xx) - back off, retry.
			self::backoff( $attempt );
		}

		// Park a permanently-dead URL so future runs skip it (auto-retried after a
		// while, or immediately via the "Retry missing images" button). Transient
		// failures are NOT parked - they retry next run and can self-heal.
		if ( $permanent ) {
			self::mark_dead( $url );
		}

		SPS_Logger::warning( sprintf( 'Image sideload failed for %s after %d attempt(s): %s', $url, $tries, $last_error ) );
		self::$cache[ $url ] = 0;
		return 0;
	}

	/**
	 * Pause before a download to rate-limit the burst. Configurable; 0 disables it.
	 */
	private static function throttle() {
		$ms = (int) SPS_Settings::get( 'image_throttle_ms', 200 );
		if ( $ms > 0 ) {
			usleep( $ms * 1000 );
		}
	}

	/**
	 * Exponential backoff between retry attempts: base, 2x, 4x ... capped at 30s.
	 *
	 * @param int $attempt 1-based attempt number that just failed.
	 */
	private static function backoff( $attempt ) {
		$base = (int) SPS_Settings::get( 'image_retry_backoff_ms', 1000 );
		if ( $base <= 0 ) {
			return;
		}
		$delay = min( 30000, $base * ( 1 << ( $attempt - 1 ) ) );
		usleep( $delay * 1000 );
	}

	/**
	 * Whether a sideload error is permanent (don't retry) versus transient (retry).
	 * 404 / gone are permanent; timeouts, connection errors and 5xx are transient.
	 */
	private static function is_permanent_failure( $message ) {
		$message = strtolower( (string) $message );
		foreach ( array( 'not found', ' 404', '404 ', 'http 410', 'gone' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------------- */
	/* Dead-image registry (URLs the supplier serves as 404/gone)             */
	/* --------------------------------------------------------------------- */

	const DEAD_OPTION = 'sps_dead_images';

	/** @var array<string,int>|null Cache of dead source URL => timestamp. */
	private static $dead = null;

	private static function dead_registry() {
		if ( null === self::$dead ) {
			$raw        = get_option( self::DEAD_OPTION, array() );
			self::$dead = is_array( $raw ) ? $raw : array();
		}
		return self::$dead;
	}

	/** A recently-confirmed dead image URL (re-tried automatically after 30 days). */
	private static function is_dead( $url ) {
		$reg = self::dead_registry();
		if ( ! isset( $reg[ $url ] ) ) {
			return false;
		}
		return ( time() - (int) $reg[ $url ] ) < ( 30 * DAY_IN_SECONDS );
	}

	private static function mark_dead( $url ) {
		$reg         = self::dead_registry();
		$reg[ $url ] = time();
		self::$dead  = $reg;
		update_option( self::DEAD_OPTION, $reg, false );
	}

	/** How many image URLs are currently parked as dead. */
	public static function dead_count() {
		return count( self::dead_registry() );
	}

	/** Forget all parked-dead images so the next import re-attempts them. */
	public static function clear_dead() {
		self::$dead = array();
		delete_option( self::DEAD_OPTION );
	}
}
