<?php
/**
 * Logging + run-status tracking.
 *
 * Detailed lines go to the WooCommerce logger (WooCommerce > Status > Logs,
 * source "smokepurer-sync"). A compact summary of the last run of each job is
 * kept in an option for the admin dashboard and the dead-man's-switch.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Logger {

	const RUNS_OPTION = 'sps_last_runs';

	/** @var \WC_Logger_Interface|null */
	private static $wc_logger = null;

	private static function logger() {
		if ( null === self::$wc_logger && function_exists( 'wc_get_logger' ) ) {
			self::$wc_logger = wc_get_logger();
		}
		return self::$wc_logger;
	}

	private static function log( $level, $message, array $context = array() ) {
		$logger = self::logger();
		if ( $logger ) {
			$logger->log( $level, $message, array_merge( array( 'source' => 'smokepurer-sync' ), $context ) );
		}
	}

	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Record the outcome of a job run for the dashboard / monitoring.
	 *
	 * @param string $job      One of: stock | catalogue | reconcile.
	 * @param string $status   ok | warning | error.
	 * @param string $message  Human-readable summary.
	 * @param array  $stats    Arbitrary counters (created, updated, skipped ...).
	 */
	public static function record_run( $job, $status, $message, array $stats = array() ) {
		$runs = get_option( self::RUNS_OPTION, array() );
		$runs = is_array( $runs ) ? $runs : array();

		$runs[ $job ] = array(
			'status'    => $status,
			'message'   => $message,
			'stats'     => $stats,
			'timestamp' => time(),
		);

		update_option( self::RUNS_OPTION, $runs, false );

		if ( 'error' === $status ) {
			self::error( sprintf( '[%s] %s', $job, $message ), $stats );
		} else {
			self::info( sprintf( '[%s] %s', $job, $message ), $stats );
		}
	}

	public static function get_runs() {
		$runs = get_option( self::RUNS_OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}
}
