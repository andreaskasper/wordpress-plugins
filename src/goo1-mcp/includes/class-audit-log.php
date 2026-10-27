<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Audit_Log {

	private static $instance = null;

	/** @var string[] Flags collected for the request currently being handled. */
	private static $flags = array();

	/**
	 * Mark the current request (e.g. 'unredacted', 'sql_files', 'guard_restored').
	 */
	public static function add_flag( $flag ) {
		self::$flags[] = (string) $flag;
	}

	/**
	 * Return and clear the collected flags.
	 */
	public static function take_flags() {
		$f           = implode( ',', array_unique( self::$flags ) );
		self::$flags = array();
		return $f;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Log an API request.
	 */
	public function log( $key_label, $endpoint, $method, $params, $response_code, $flags = '' ) {
		global $wpdb;

		// Strip internal params and mask secrets in the summary.
		$safe_params = is_array( $params ) ? $params : array();
		unset( $safe_params['_goo1_mcp_key'] );
		$safe_params = Goo1_MCP_Redactor::redact( self::shrink( $safe_params ) );
		$summary     = wp_json_encode( $safe_params );
		if ( strlen( $summary ) > 2000 ) {
			$summary = substr( $summary, 0, 2000 ) . '...[truncated]';
		}

		$wpdb->insert(
			$wpdb->prefix . 'goo1_mcp_audit_log',
			array(
				'api_key_label'   => sanitize_text_field( $key_label ),
				'endpoint'        => sanitize_text_field( $endpoint ),
				'method'          => sanitize_text_field( $method ),
				'request_summary' => $summary,
				'response_code'   => (int) $response_code,
				'ip_address'      => $this->get_client_ip(),
				'flags'           => substr( sanitize_text_field( (string) $flags ), 0, 64 ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Replace oversized strings (base64 uploads, Elementor JSON …) anywhere in
	 * the params with a short placeholder.
	 */
	public static function shrink( $value, $depth = 0 ) {
		if ( is_string( $value ) && strlen( $value ) > 2048 ) {
			return '[' . strlen( $value ) . ' bytes omitted]';
		}
		if ( is_array( $value ) && $depth < 8 ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::shrink( $v, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Get paginated audit log entries.
	 */
	public function get_entries( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
			'label'    => '',
			'method'   => '',
		);
		$args  = wp_parse_args( $args, $defaults );
		$table = $wpdb->prefix . 'goo1_mcp_audit_log';
		$where = array( '1=1' );
		$values = array();

		if ( $args['label'] ) {
			$where[]  = 'api_key_label = %s';
			$values[] = $args['label'];
		}
		if ( $args['method'] ) {
			$where[]  = 'method = %s';
			$values[] = $args['method'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];

		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$query_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";

		$values_with_limit = array_merge( $values, array( (int) $args['per_page'], $offset ) );

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$items = $wpdb->get_results( $wpdb->prepare( $query_sql, $values_with_limit ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$items = $wpdb->get_results( $wpdb->prepare( $query_sql, array( (int) $args['per_page'], $offset ) ) );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => ceil( $total / (int) $args['per_page'] ),
			'page'     => (int) $args['page'],
			'per_page' => (int) $args['per_page'],
		);
	}

	/**
	 * Purge old entries.
	 */
	public function purge( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'goo1_mcp_audit_log';
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				(int) $days
			)
		);
	}

	private function get_client_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				return trim( $ip[0] );
			}
		}
		return '0.0.0.0';
	}
}
