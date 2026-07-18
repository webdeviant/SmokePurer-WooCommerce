<?php
/**
 * Uninstall cleanup. Runs only when the plugin is deleted (not on deactivate).
 * Removes the plugin's own options and staging file. It deliberately does NOT
 * delete any products, images or categories it created - those are your shop
 * data and should be removed manually if wanted.
 *
 * @package SmokePurer_Sync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'sps_settings',
	'sps_last_runs',
	'sps_feed_lastgood',
	'sps_stock_snapshot',
	'sps_dead_images',
	'sps_cat_state',
	'sps_cat_total',
	'sps_cat_processed',
	'sps_cat_offset',
	'sps_cat_created',
	'sps_cat_updated',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove any leftover lock options (sps_lock_*).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sps_lock_%'" );

// Remove the staging file.
$upload = wp_upload_dir();
if ( ! empty( $upload['basedir'] ) ) {
	$staging = trailingslashit( $upload['basedir'] ) . 'smokepurer-sync/catalogue-staging.jsonl';
	if ( file_exists( $staging ) ) {
		wp_delete_file( $staging );
	}
}
