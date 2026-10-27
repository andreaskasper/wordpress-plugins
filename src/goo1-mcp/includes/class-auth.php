<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Auth {

	/** Write last_used at most once per this many seconds per key. */
	const LAST_USED_THROTTLE = 60;

	private static $instance = null;

	/**
	 * Key data of the request that authenticated last in this PHP process.
	 * Used by the audit log instead of a request parameter, so a client can
	 * never spoof the audit label by sending `_goo1_mcp_key` itself.
	 *
	 * @var array|null
	 */
	private $current = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Key data of the currently authenticated request (or null).
	 */
	public function current() {
		return $this->current;
	}

	/**
	 * Authenticate a REST request via Bearer token.
	 *
	 * On success the WordPress user the key/token runs as is made the current
	 * user (wp_set_current_user), so WordPress capability checks, kses and
	 * authorship behave exactly like for that admin in wp-admin.
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error Key data array on success, WP_Error on failure.
	 */
	public function authenticate( $request ) {
		// Never trust a client-supplied key-data parameter.
		$request->set_param( '_goo1_mcp_key', null );

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

		$token      = trim( substr( $header, 7 ) );
		$token_hash = hash( 'sha256', $token );
		$key_data   = $this->find_api_key( $token_hash );

		if ( null === $key_data ) {
			// Fall back to OAuth access tokens (issued by Goo1_MCP_OAuth).
			// Only tokens the authorization server issued count: they must also be
			// in the (self-protected) token index, unexpired, and their connection
			// must still exist. A transient written by other means is ignored.
			$oauth = get_transient( Goo1_MCP_OAuth::TOKEN_PREFIX . $token_hash );
			if ( is_array( $oauth ) && Goo1_MCP_OAuth::instance()->is_issued_token( $token_hash, $oauth ) ) {
				$key_data = Goo1_MCP_OAuth::instance()->hydrate_token_data( $oauth );
			}
		}

		if ( null === $key_data ) {
			return new WP_Error(
				'goo1_mcp_invalid_key',
				'Invalid or expired token.',
				array( 'status' => 401 )
			);
		}

		$user = $this->apply_run_as_user( $key_data );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$this->current = $key_data;
		$request->set_param( '_goo1_mcp_key', $key_data );

		return $key_data;
	}

	/**
	 * Look up a static API key by token hash. Updates last_used (throttled).
	 *
	 * @return array|null
	 */
	private function find_api_key( $token_hash ) {
		$keys = get_option( 'goo1_mcp_api_keys', array() );
		if ( ! is_array( $keys ) ) {
			return null;
		}
		foreach ( $keys as $index => $key ) {
			if ( isset( $key['key_hash'] ) && hash_equals( $key['key_hash'], $token_hash ) ) {
				$this->touch_last_used( $token_hash, $key );
				return $key;
			}
		}
		return null;
	}

	/**
	 * Throttled last_used update. Re-reads the option right before writing so
	 * a key created in the meantime is not lost.
	 */
	private function touch_last_used( $token_hash, $key ) {
		$last = isset( $key['last_used'] ) && $key['last_used'] ? strtotime( $key['last_used'] ) : 0;
		$now  = current_time( 'timestamp' );
		if ( $last && ( $now - $last ) < self::LAST_USED_THROTTLE ) {
			return;
		}
		$keys = get_option( 'goo1_mcp_api_keys', array() );
		foreach ( $keys as $i => $k ) {
			if ( isset( $k['key_hash'] ) && $k['key_hash'] === $token_hash ) {
				$keys[ $i ]['last_used'] = current_time( 'mysql' );
				update_option( 'goo1_mcp_api_keys', $keys );
				return;
			}
		}
	}

	/**
	 * Make the key's run-as user the current WordPress user.
	 *
	 * - Keys/tokens with a user_id run as that user; the user must still exist
	 *   and still have manage_options, otherwise the request is refused.
	 * - Legacy keys created before 1.6 (no user_id) keep the old behaviour and
	 *   run without a user; the admin UI shows a notice to assign one.
	 *
	 * @return true|WP_Error
	 */
	private function apply_run_as_user( $key_data ) {
		if ( empty( $key_data['user_id'] ) ) {
			return true;
		}
		$user = get_userdata( (int) $key_data['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error(
				'goo1_mcp_runas_invalid',
				'The WordPress user this key/connection runs as no longer exists or is no longer an administrator.',
				array( 'status' => 403 )
			);
		}
		if ( get_current_user_id() !== (int) $user->ID ) {
			wp_set_current_user( (int) $user->ID );
		}
		return true;
	}

	/**
	 * Generate a new API key.
	 *
	 * @param string $label      Human-readable label.
	 * @param string $scope      'read' or 'full'.
	 * @param bool   $db_write   Whether to allow SQL write operations.
	 * @param int    $rate_limit Requests per minute.
	 * @param int    $user_id    WordPress admin the key runs as (0 = legacy, none).
	 * @param array  $grants     Optional grants: unredacted / sql_files => null|0|until-timestamp.
	 * @return array Contains 'raw_key' (show once) and 'key_data'.
	 */
	public function create_key( $label, $scope = 'read', $db_write = false, $rate_limit = 60, $user_id = 0, $grants = array() ) {
		$raw_key = 'goo1_mcp_' . bin2hex( random_bytes( 20 ) );

		$key_data = array(
			'key_hash'   => hash( 'sha256', $raw_key ),
			'label'      => sanitize_text_field( $label ),
			'scope'      => in_array( $scope, array( 'read', 'full' ), true ) ? $scope : 'read',
			'db_write'   => (bool) $db_write,
			'rate_limit' => max( 1, (int) $rate_limit ),
			'user_id'    => (int) $user_id,
			'grants'     => Goo1_MCP_Permissions::sanitize_grants( $grants ),
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
	 * Update mutable fields of an existing key (admin UI only).
	 *
	 * @param string $key_hash
	 * @param array  $changes  Any of: user_id, grants, scope, db_write, rate_limit.
	 * @return bool
	 */
	public function update_key( $key_hash, $changes ) {
		$keys = get_option( 'goo1_mcp_api_keys', array() );
		foreach ( $keys as $i => $k ) {
			if ( isset( $k['key_hash'] ) && $k['key_hash'] === $key_hash ) {
				if ( array_key_exists( 'user_id', $changes ) ) {
					$keys[ $i ]['user_id'] = (int) $changes['user_id'];
				}
				if ( array_key_exists( 'grants', $changes ) ) {
					$keys[ $i ]['grants'] = Goo1_MCP_Permissions::sanitize_grants( $changes['grants'] );
				}
				update_option( 'goo1_mcp_api_keys', $keys );
				return true;
			}
		}
		return false;
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
