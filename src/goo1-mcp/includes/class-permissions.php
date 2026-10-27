<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Permissions {

	/**
	 * Optional grants an admin can give a key / OAuth connection.
	 *  - unredacted: tool responses may contain secrets in clear text
	 *  - sql_files:  SQL may use LOAD_FILE / INTO OUTFILE / INTO DUMPFILE
	 * Each grant is null (not granted), 0 (permanent) or a unix timestamp
	 * until which it is valid.
	 */
	const GRANTS = array( 'unredacted', 'sql_files' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if a key has the required scope.
	 *
	 * @param array  $key_data  Authenticated key data.
	 * @param string $required  Required scope: 'read', 'full', or 'db_write'.
	 * @return true|WP_Error
	 */
	public function can( $key_data, $required = 'read' ) {
		if ( ! is_array( $key_data ) ) {
			return new WP_Error( 'goo1_mcp_no_auth', 'Not authenticated.', array( 'status' => 401 ) );
		}

		if ( 'read' === $required ) {
			return true;
		}

		if ( 'full' === $required && 'full' !== $key_data['scope'] ) {
			return new WP_Error(
				'goo1_mcp_insufficient_scope',
				'This API key has read-only scope. A full-access key is required for this operation.',
				array( 'status' => 403 )
			);
		}

		if ( 'db_write' === $required ) {
			if ( 'full' !== $key_data['scope'] ) {
				return new WP_Error(
					'goo1_mcp_insufficient_scope',
					'This API key has read-only scope.',
					array( 'status' => 403 )
				);
			}
			if ( empty( $key_data['db_write'] ) ) {
				return new WP_Error(
					'goo1_mcp_no_db_write',
					'This API key does not have database write permission.',
					array( 'status' => 403 )
				);
			}
			return true;
		}

		return true;
	}

	/**
	 * Whether the global master switch for the optional grants is on.
	 */
	public static function grants_enabled() {
		$settings = get_option( 'goo1_mcp_settings', array() );
		return ! empty( $settings['grants_enabled'] );
	}

	/**
	 * Whether a grant is currently effective for a key (ignores the per-call flag).
	 */
	public function has_grant( $key_data, $grant ) {
		if ( ! self::grants_enabled() || ! is_array( $key_data ) || empty( $key_data['grants'] ) || ! is_array( $key_data['grants'] ) ) {
			return false;
		}
		if ( ! array_key_exists( $grant, $key_data['grants'] ) ) {
			return false;
		}
		$until = $key_data['grants'][ $grant ];
		if ( null === $until ) {
			return false;
		}
		return 0 === (int) $until || (int) $until > time();
	}

	/**
	 * Whether this request may receive unredacted secrets: the key needs the
	 * grant AND the call must explicitly send unredacted=true.
	 */
	public function allow_unredacted( WP_REST_Request $request ) {
		$flag = $request->get_param( 'unredacted' );
		if ( ! ( true === $flag || 'true' === $flag || '1' === $flag || 1 === $flag ) ) {
			return false;
		}
		return $this->has_grant( Goo1_MCP_Auth::instance()->current(), 'unredacted' );
	}

	/**
	 * Whether the current key may use SQL file functions.
	 */
	public function allow_sql_files() {
		return $this->has_grant( Goo1_MCP_Auth::instance()->current(), 'sql_files' );
	}

	/**
	 * Normalise a grants array from user input.
	 *
	 * @param array $grants grant => null|0|timestamp.
	 * @return array
	 */
	public static function sanitize_grants( $grants ) {
		$out = array();
		foreach ( self::GRANTS as $g ) {
			if ( ! is_array( $grants ) || ! array_key_exists( $g, $grants ) || null === $grants[ $g ] || false === $grants[ $g ] || '' === $grants[ $g ] ) {
				$out[ $g ] = null;
				continue;
			}
			$out[ $g ] = max( 0, (int) $grants[ $g ] );
		}
		return $out;
	}

	/**
	 * Build a grants array from an admin form choice per grant:
	 * '' = none, 'perm' = permanent, '<minutes>' = time-limited.
	 */
	public static function grants_from_form( $choices ) {
		$grants = array();
		foreach ( self::GRANTS as $g ) {
			$c = isset( $choices[ $g ] ) ? (string) $choices[ $g ] : '';
			if ( '' === $c ) {
				$grants[ $g ] = null;
			} elseif ( 'perm' === $c ) {
				$grants[ $g ] = 0;
			} else {
				$minutes        = max( 1, min( 60 * 24 * 30, (int) $c ) );
				$grants[ $g ] = time() + $minutes * 60;
			}
		}
		return $grants;
	}

	/**
	 * Human-readable state of one grant for the admin UI.
	 */
	public static function describe_grant( $until ) {
		if ( null === $until ) {
			return '—';
		}
		if ( 0 === (int) $until ) {
			return __( 'permanent', 'goo1-mcp' );
		}
		if ( (int) $until <= time() ) {
			return __( 'expired', 'goo1-mcp' );
		}
		/* translators: %s: human time diff */
		return sprintf( __( 'for %s', 'goo1-mcp' ), human_time_diff( time(), (int) $until ) );
	}
}
