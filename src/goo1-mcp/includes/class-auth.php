<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Auth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Authenticate a REST request via Bearer token.
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error Key data array on success, WP_Error on failure.
	 */
	public function authenticate( $request ) {
		$header = $request->get_header( 'Authorization' );

		if ( empty( $header ) ) {
			return new WP_Error(
				'goo1_mcp_no_auth',
				'Missing Authorization header. Use: Bearer <api_key>',
				array( 'status' => 401 )
			);
		}

		if ( stripos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error(
				'goo1_mcp_bad_auth',
				'Authorization header must use Bearer scheme.',
				array( 'status' => 401 )
			);
		}

		$token     = substr( $header, 7 );
		$token_hash = hash( 'sha256', $token );
		$keys       = get_option( 'goo1_mcp_api_keys', array() );

		foreach ( $keys as $index => &$key ) {
			if ( hash_equals( $key['key_hash'], $token_hash ) ) {
				// Update last_used.
				$key['last_used'] = current_time( 'mysql' );
				update_option( 'goo1_mcp_api_keys', $keys );

				// Attach key data to request for downstream use.
				$request->set_param( '_goo1_mcp_key', $key );

				return $key;
			}
		}
		unset( $key );

		// Fall back to OAuth access tokens (issued by Goo1_MCP_OAuth). These are
		// also Bearer tokens and behave like an API key with the granted scope.
		$oauth = get_transient( 'goo1_mcp_oauth_at_' . $token_hash );
		if ( is_array( $oauth ) ) {
			$request->set_param( '_goo1_mcp_key', $oauth );
			return $oauth;
		}

		return new WP_Error(
			'goo1_mcp_invalid_key',
			'Invalid or expired token.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Generate a new API key.
	 *
	 * @param string $label    Human-readable label.
	 * @param string $scope    'read' or 'full'.
	 * @param bool   $db_write Whether to allow SQL write operations.
	 * @param int    $rate_limit Requests per minute.
	 * @return array Contains 'raw_key' (show once) and 'key_data'.
	 */
	public function create_key( $label, $scope = 'read', $db_write = false, $rate_limit = 60 ) {
		$raw_key = 'goo1_mcp_' . bin2hex( random_bytes( 20 ) );

		$key_data = array(
			'key_hash'   => hash( 'sha256', $raw_key ),
			'label'      => sanitize_text_field( $label ),
			'scope'      => in_array( $scope, array( 'read', 'full' ), true ) ? $scope : 'read',
			'db_write'   => (bool) $db_write,
			'rate_limit' => max( 1, (int) $rate_limit ),
			'created_at' => current_time( 'mysql' ),
			'last_used'  => null,
			'expires_at' => null,
		);

		$keys   = get_option( 'goo1_mcp_api_keys', array() );
		$keys[] = $key_data;
		update_option( 'goo1_mcp_api_keys', $keys );

		return array(
			'raw_key'  => $raw_key,
			'key_data' => $key_data,
		);
	}

	/**
	 * Revoke an API key by its hash.
	 *
	 * @param string $key_hash
	 * @return bool
	 */
	public function revoke_key( $key_hash ) {
		$keys    = get_option( 'goo1_mcp_api_keys', array() );
		$updated = array();
		$found   = false;

		foreach ( $keys as $key ) {
			if ( $key['key_hash'] === $key_hash ) {
				$found = true;
				continue;
			}
			$updated[] = $key;
		}

		if ( $found ) {
			update_option( 'goo1_mcp_api_keys', $updated );
		}

		return $found;
	}
}
