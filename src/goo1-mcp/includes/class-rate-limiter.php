<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Rate_Limiter {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check rate limit for an authenticated key.
	 *
	 * @param array $key_data Authenticated key data.
	 * @return true|WP_Error
	 */
	public function check( $key_data ) {
		$limit          = isset( $key_data['rate_limit'] ) ? (int) $key_data['rate_limit'] : 60;
		$window         = 60; // 1 minute sliding window.
		$transient_key  = 'goo1_mcp_rl_' . substr( $key_data['key_hash'], 0, 16 );

		$timestamps = get_transient( $transient_key );
		if ( ! is_array( $timestamps ) ) {
			$timestamps = array();
		}

		$now        = microtime( true );
		$cutoff     = $now - $window;

		// Remove expired entries.
		$timestamps = array_values( array_filter( $timestamps, function ( $ts ) use ( $cutoff ) {
			return $ts > $cutoff;
		} ) );

		if ( count( $timestamps ) >= $limit ) {
			$retry_after = (int) ceil( $timestamps[0] - $cutoff );

			return new WP_Error(
				'goo1_mcp_rate_limited',
				sprintf( 'Rate limit exceeded. %d requests per minute allowed.', $limit ),
				array(
					'status'  => 429,
					'headers' => array( 'Retry-After' => max( 1, $retry_after ) ),
				)
			);
		}

		$timestamps[] = $now;
		set_transient( $transient_key, $timestamps, $window );

		return true;
	}
}
