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

		// Sideload. Requires the media/file admin includes.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $parent_post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			SPS_Logger::warning( sprintf( 'Image sideload failed for %s: %s', $url, $attachment_id->get_error_message() ) );
			self::$cache[ $url ] = 0;
			return 0;
		}

		update_post_meta( $attachment_id, '_sps_source_url', $url );
		self::$cache[ $url ] = (int) $attachment_id;
		return (int) $attachment_id;
	}
}
