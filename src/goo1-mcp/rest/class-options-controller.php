<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Options_Controller extends Goo1_MCP_Base_Controller {

	/**
	 * Option name patterns that should never be exposed.
	 */
	private $blocked_patterns = array(
		'password', 'secret', 'token', 'auth_key', 'auth_salt',
		'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt',
		'secure_auth_key', 'secure_auth_salt', 'goo1_mcp_api_keys',
	);

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

		register_rest_route( self::NAMESPACE, '/options/(?P<key>[a-zA-Z0-9_-]+)', array(
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

		$keys   = array_map( 'trim', explode( ',', $keys_param ) );
		$result = array();

		foreach ( $keys as $key ) {
			$key = sanitize_text_field( $key );
			if ( $this->is_blocked( $key ) ) {
				$result[ $key ] = '[blocked]';
				continue;
			}
			$value = get_option( $key );
			if ( false === $value ) {
				$result[ $key ] = null;
			} else {
				$result[ $key ] = $value;
			}
		}

		return $this->success( $result );
	}

	public function update_options( $request ) {
		$params  = $request->get_json_params();
		$options = $params['options'] ?? $params;
		$updated = array();
		$blocked = array();

		if ( ! is_array( $options ) ) {
			return $this->error( 'invalid_body', 'Provide an object with option key/value pairs.' );
		}

		foreach ( $options as $key => $value ) {
			$key = sanitize_text_field( $key );
			if ( $this->is_blocked( $key ) ) {
				$blocked[] = $key;
				continue;
			}
			update_option( $key, $value );
			$updated[] = $key;
		}

		return $this->success( array(
			'updated' => $updated,
			'blocked' => $blocked,
		) );
	}

	public function list_all_options( $request ) {
		global $wpdb;

		$search = sanitize_text_field( $request['search'] ?? '' );

		if ( $search ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_name LIMIT 200",
					'%' . $wpdb->esc_like( $search ) . '%'
				)
			);
		} else {
			$results = $wpdb->get_results(
				"SELECT option_name, option_value, autoload FROM $wpdb->options WHERE autoload = 'yes' ORDER BY option_name LIMIT 500"
			);
		}

		$output = array();
		foreach ( $results as $row ) {
			if ( $this->is_blocked( $row->option_name ) ) {
				continue;
			}
			$value = maybe_unserialize( $row->option_value );
			$output[] = array(
				'name'     => $row->option_name,
				'value'    => $value,
				'autoload' => $row->autoload,
			);
		}

		return $this->success( $output );
	}

	public function delete_option( $request ) {
		$key = sanitize_text_field( $request['key'] );

		if ( $this->is_blocked( $key ) ) {
			return $this->error( 'blocked_option', 'This option cannot be deleted via the API.', 403 );
		}

		if ( false === get_option( $key ) ) {
			return $this->error( 'not_found', 'Option does not exist.', 404 );
		}

		delete_option( $key );

		return $this->success( array( 'deleted' => true, 'key' => $key ) );
	}

	private function is_blocked( $name ) {
		$name_lower = strtolower( $name );
		foreach ( $this->blocked_patterns as $pattern ) {
			if ( strpos( $name_lower, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}
}
