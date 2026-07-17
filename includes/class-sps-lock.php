<?php
/**
 * Lightweight cooperative lock backed by a transient, so overlapping runs of the
 * same job (e.g. a slow catalogue import triggered again before it finished)
 * don't stomp on each other. Auto-expires so a crashed run can't wedge the job.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Lock {

	/** @var array<string,string> Tokens for locks held in this request. */
	private static $tokens = array();

	/**
	 * Try to acquire a named lock. The lock value is "deadline:token"; a unique
	 * token lets release() delete only our own lock, so a run that overran its
	 * TTL can never have its (now someone else's) lock deleted out from under it.
	 *
	 * @param string $name Lock name, e.g. "stock" or "catalogue".
	 * @param int    $ttl  Seconds before the lock auto-expires (crash safety).
	 * @return bool True if acquired, false if held by someone else.
	 */
	public static function acquire( $name, $ttl = 600 ) {
		$key = self::key( $name );
		$now = time();

		$existing = get_option( $key, '' );
		if ( '' !== $existing ) {
			$deadline = (int) explode( ':', (string) $existing, 2 )[0];
			if ( $deadline >= $now ) {
				return false; // Held and not yet expired.
			}
			delete_option( $key ); // Expired (crashed holder) -> steal.
		}

		$token = self::token();
		$value = ( $now + (int) $ttl ) . ':' . $token;

		// add_option returns false if the option already exists -> lost the race.
		if ( ! add_option( $key, $value, '', false ) ) {
			return false;
		}
		self::$tokens[ $name ] = $token;
		return true;
	}

	public static function release( $name ) {
		if ( ! isset( self::$tokens[ $name ] ) ) {
			return; // We never held it in this request; don't touch it.
		}
		$key      = self::key( $name );
		$existing = get_option( $key, '' );
		if ( '' !== $existing ) {
			$holder = explode( ':', (string) $existing, 2 );
			if ( isset( $holder[1] ) && $holder[1] === self::$tokens[ $name ] ) {
				delete_option( $key );
			}
		}
		unset( self::$tokens[ $name ] );
	}

	public static function is_locked( $name ) {
		$existing = get_option( self::key( $name ), '' );
		if ( '' === $existing ) {
			return false;
		}
		$deadline = (int) explode( ':', (string) $existing, 2 )[0];
		return $deadline >= time();
	}

	private static function token() {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'sps', true );
	}

	private static function key( $name ) {
		return 'sps_lock_' . sanitize_key( $name );
	}
}
