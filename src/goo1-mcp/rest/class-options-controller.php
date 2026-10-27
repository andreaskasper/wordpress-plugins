<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Options_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/options', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_options' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'keys' => array(
					'type'        => 'string',
					'description' => 'Comma-separated option names',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/options', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'update_options' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/options/all', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_all_options' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'search' => array(
					'type' => 'string',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/options/(?P<key>[a-zA-Z0-9_.-]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_option' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function get_options( $request ) {
		$keys_param = sanitize_text_field( $request['keys'] ?? '' );
		if ( empty( $keys_param ) ) {
			return $this->error( 'missing_keys', 'Provide option names via the keys parameter (comma-separated).' );
		}

		$unredacted = Goo1_MCP_Permissions::instance()->allow_unredacted( $request );
		if ( $unredacted ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		}

		$keys   = array_filter( array_map( 'trim', explode( ',', $keys_param ) ) );
		$result = array();

		foreach ( $keys as $key ) {
			$key = sanitize_text_field( $key );
			// Reads accept any name; the hidden check runs on the stored name.
			if ( $this->is_hidden( $key, $unredacted ) ) {
				$result[ $key ] = Goo1_MCP_Redactor::MASK;
				continue;
			}
			$value = get_option( $key );
			if ( false === $value ) {
				$result[ $key ] = null;
			} else {
				$result[ $key ] = $unredacted ? $value : Goo1_MCP_Redactor::redact( $value );
			}
		}

		return $this->success( $result );
	}

	public function update_options( $request ) {
		$params  = $request->get_json_params();
		$options = $params['options'] ?? $params;
		$updated = array();
		$blocked = array();
		$kept    = array();

		if ( ! is_array( $options ) ) {
			return $this->error( 'invalid_body', 'Provide an object with option key/value pairs.' );
		}
		unset( $options['unredacted'] );

		foreach ( $options as $key => $value ) {
			$key = (string) $key;
			// Plain ASCII names only (MySQL collation would map look-alikes such
			// as "ｇoo1_mcp_settings" onto real options). Bridge settings and
			// secret-bearing option names are never writable here.
			if ( ! Goo1_MCP_Guard::is_valid_option_name( $key ) || Goo1_MCP_Guard::is_protected_option( $key ) || Goo1_MCP_Redactor::is_blocked_option( Goo1_MCP_Guard::stored_name( $key ) ) ) {
				$blocked[] = $key;
				continue;
			}
			// A value read back with "[redacted]" placeholders must not overwrite the real secrets.
			if ( Goo1_MCP_Redactor::contains_mask( $value ) ) {
				$value  = Goo1_MCP_Redactor::restore_masked( $value, get_option( $key ) );
				$kept[] = $key;
			}
			update_option( $key, $value );
			$updated[] = $key;
		}

		return $this->success( array(
			'updated'          => $updated,
			'blocked'          => $blocked,
			'kept_secrets_for' => $kept,
		) );
	}

	public function list_all_options( $request ) {
		global $wpdb;

		$search     = sanitize_text_field( $request['search'] ?? '' );
		$unredacted = Goo1_MCP_Permissions::instance()->allow_unredacted( $request );
		if ( $unredacted ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		}

		if ( $search ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_name LIMIT 200",
					'%' . $wpdb->esc_like( $search ) . '%'
				)
			);
		} else {
			$results = $wpdb->get_results(
				"SELECT option_name, option_value, autoload FROM $wpdb->options WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY option_name LIMIT 500"
			);
		}

		$output = array();
		foreach ( $results as $row ) {
			if ( $this->is_hidden( $row->option_name, $unredacted ) ) {
				continue;
			}
			$value = is_serialized( $row->option_value )
				? @unserialize( $row->option_value, array( 'allowed_classes' => false ) ) // phpcs:ignore
				: $row->option_value;
			$output[] = array(
				'name'     => $row->option_name,
				'value'    => $unredacted ? $value : Goo1_MCP_Redactor::redact( $value ),
				'autoload' => $row->autoload,
			);
		}

		return $this->success( $output );
	}

	public function delete_option( $request ) {
		$key = (string) $request['key'];

		if ( ! Goo1_MCP_Guard::is_valid_option_name( $key ) || Goo1_MCP_Guard::is_protected_option( $key ) || Goo1_MCP_Redactor::is_blocked_option( Goo1_MCP_Guard::stored_name( $key ) ) ) {
			return $this->error( 'blocked_option', 'This option cannot be deleted via the API.', 403 );
		}

		if ( false === get_option( $key ) ) {
			return $this->error( 'not_found', 'Option does not exist.', 404 );
		}

		delete_option( $key );

		return $this->success( array( 'deleted' => true, 'key' => $key ) );
	}

	/**
	 * Hidden from reads: bridge credentials always; other secret-bearing
	 * option names unless the call is allowed to see secrets.
	 */
	private function is_hidden( $name, $unredacted ) {
		$name = Goo1_MCP_Guard::stored_name( $name );
		$lc   = strtolower( (string) $name );
		foreach ( array( 'goo1_mcp_api_keys', 'goo1_mcp_oauth', 'goo1_mcp_seal' ) as $always ) {
			if ( 0 === strpos( $lc, $always ) ) {
				return true;
			}
		}
		return ! $unredacted && Goo1_MCP_Redactor::is_blocked_option( $name );
	}
}
