<?php
/**
 * Main orchestrator: wires settings, admin, scheduling and the Action Scheduler
 * job handlers together.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Plugin {

	/** @var SPS_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin UI.
		if ( is_admin() ) {
			new SPS_Admin();
		}

		// Register the Action Scheduler job handlers. These must be registered on
		// every load so the scheduler can invoke them from its own cron context.
		add_action( SPS_HOOK_STOCK, array( 'SPS_Stock_Sync', 'run' ) );
		add_action( SPS_HOOK_CATALOGUE, array( 'SPS_Catalogue_Importer', 'prepare' ) );
		add_action( SPS_HOOK_CATALOGUE_BATCH, array( 'SPS_Catalogue_Importer', 'process_batch' ) );
		add_action( SPS_HOOK_RECONCILE, array( 'SPS_Reconciler', 'run' ) );

		// Ensure recurring jobs are scheduled (lazy - runs once WooCommerce +
		// Action Scheduler are fully loaded).
		add_action( 'init', array( $this, 'maybe_schedule' ), 20 );
	}

	/**
	 * Schedule the recurring jobs if they are not already scheduled and the
	 * plugin is configured with feed URLs. Called on every load but cheap.
	 */
	public function maybe_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return; // Action Scheduler not available yet.
		}
		if ( ! SPS_Settings::get( 'enabled', true ) ) {
			return;
		}
		if ( '' === trim( (string) SPS_Settings::get( 'feed_quantity', '' ) ) ) {
			return; // Not configured.
		}

		$group = 'smokepurer-sync';

		if ( false === as_next_scheduled_action( SPS_HOOK_STOCK, array(), $group ) ) {
			as_schedule_recurring_action(
				time() + 120,
				max( 60, (int) SPS_Settings::get( 'stock_interval', 300 ) ),
				SPS_HOOK_STOCK,
				array(),
				$group,
				true // $unique: reject a duplicate schedule for this hook/group.
			);
		}
		if ( false === as_next_scheduled_action( SPS_HOOK_CATALOGUE, array(), $group ) ) {
			as_schedule_recurring_action(
				time() + 300,
				max( 900, (int) SPS_Settings::get( 'catalogue_interval', 3600 ) ),
				SPS_HOOK_CATALOGUE,
				array(),
				$group,
				true // $unique: reject a duplicate schedule for this hook/group.
			);
		}
		if ( false === as_next_scheduled_action( SPS_HOOK_RECONCILE, array(), $group ) ) {
			as_schedule_recurring_action(
				time() + 600,
				max( 3600, (int) SPS_Settings::get( 'reconcile_interval', DAY_IN_SECONDS ) ),
				SPS_HOOK_RECONCILE,
				array(),
				$group,
				true // $unique: reject a duplicate schedule for this hook/group.
			);
		}
	}

	/**
	 * Remove and re-create the recurring schedule. Called after settings change
	 * so new intervals take effect.
	 */
	public static function reschedule() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( SPS_HOOK_STOCK, array(), 'smokepurer-sync' );
		as_unschedule_all_actions( SPS_HOOK_CATALOGUE, array(), 'smokepurer-sync' );
		as_unschedule_all_actions( SPS_HOOK_RECONCILE, array(), 'smokepurer-sync' );
		self::instance()->maybe_schedule();
	}

	public static function on_activation() {
		// Seed default settings on first activation.
		SPS_Settings::maybe_seed_defaults();
		// Scheduling happens lazily on `init` once Action Scheduler is loaded.
	}

	public static function on_deactivation() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( SPS_HOOK_STOCK, array(), 'smokepurer-sync' );
		as_unschedule_all_actions( SPS_HOOK_CATALOGUE, array(), 'smokepurer-sync' );
		as_unschedule_all_actions( SPS_HOOK_CATALOGUE_BATCH, array(), 'smokepurer-sync' );
		as_unschedule_all_actions( SPS_HOOK_RECONCILE, array(), 'smokepurer-sync' );
	}
}
