<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Database_Controller extends Goo1_MCP_Base_Controller {

	/**
	 * SQL keywords that are always blocked, even with db_write enabled.
	 */
	private $always_blocked = array( 'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'RENAME', 'GRANT', 'REVOKE' );

	/**
	 * SQL keywords that require db_write permission.
	 */
	private $write_keywords = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' );

	/**
	 * SQL keywords allowed for read-only access.
	 */
	private $read_keywords = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/db/query', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'execute_query' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/db/tables', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_tables' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/db/tables/(?P<name>[a-zA-Z0-9_]+)/schema', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'describe_table' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/db/tables/(?P<name>[a-zA-Z0-9_]+)/sample', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'sample_table' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'limit' => array(
					'type'    => 'integer',
					'default' => 10,
					'maximum' => 100,
				),
			),
		) );
	}

	public function execute_query( $request ) {
		$params    = $request->get_json_params();
		$sql       = trim( $params['sql'] ?? '' );
		$bindings  = $params['params'] ?? array();
		$max_rows  = min( (int) ( $params['max_rows'] ?? 1000 ), 10000 );

		if ( empty( $sql ) ) {
			return $this->error( 'empty_query', 'SQL query is required.' );
		}

		// Parse the first keyword to determine query type.
		$first_keyword = strtoupper( strtok( $sql, " \t\n\r(" ) );

		// Check always-blocked keywords.
		if ( in_array( $first_keyword, $this->always_blocked, true ) ) {
			return $this->error(
				'blocked_query',
				sprintf( '%s queries are not allowed.', $first_keyword ),
				403
			);
		}

		// Also scan the full query for blocked keywords (to prevent subquery attacks).
		$sql_upper = strtoupper( $sql );
		foreach ( $this->always_blocked as $keyword ) {
			if ( preg_match( '/\b' . $keyword . '\b/', $sql_upper ) ) {
				return $this->error(
					'blocked_query',
					sprintf( 'Query contains blocked keyword: %s', $keyword ),
					403
				);
			}
		}

		// Determine required permission level.
		$key_data = $request->get_param( '_goo1_mcp_key' );

		if ( in_array( $first_keyword, $this->write_keywords, true ) ) {
			// Check global toggle.
			$settings = get_option( 'goo1_mcp_settings', array() );
			if ( empty( $settings['db_write_enabled'] ) ) {
				return $this->error(
					'db_writes_disabled',
					'Database writes are globally disabled. Enable in plugin settings.',
					403
				);
			}

			// Check key-level permission.
			$perm = Goo1_MCP_Permissions::instance()->can( $key_data, 'db_write' );
			if ( is_wp_error( $perm ) ) {
				return $perm;
			}
		} elseif ( ! in_array( $first_keyword, $this->read_keywords, true ) ) {
			return $this->error(
				'unsupported_query',
				sprintf( 'Query type "%s" is not supported.', $first_keyword ),
				400
			);
		}

		global $wpdb;

		// Prepare query with bindings if provided.
		if ( ! empty( $bindings ) && is_array( $bindings ) ) {
			$sql = $wpdb->prepare( $sql, $bindings );
			if ( null === $sql ) {
				return $this->error( 'prepare_failed', 'Failed to prepare query. Check parameter count matches placeholders.' );
			}
		}

		// Execute based on query type.
		if ( in_array( $first_keyword, $this->write_keywords, true ) ) {
			$affected = $wpdb->query( $sql );

			if ( false === $affected ) {
				return $this->error( 'query_failed', $wpdb->last_error ?: 'Unknown database error.' );
			}

			return $this->success( array(
				'affected_rows' => $affected,
				'insert_id'     => $wpdb->insert_id ?: null,
			) );
		}

		// Read queries.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( null === $results && $wpdb->last_error ) {
			return $this->error( 'query_failed', $wpdb->last_error );
		}

		$results = $results ?: array();
		$truncated = false;

		if ( count( $results ) > $max_rows ) {
			$results   = array_slice( $results, 0, $max_rows );
			$truncated = true;
		}

		return $this->success( array(
			'rows'      => $results,
			'row_count' => count( $results ),
			'truncated' => $truncated,
			'max_rows'  => $max_rows,
		) );
	}

	public function list_tables( $request ) {
		global $wpdb;

		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$output = array();

		foreach ( $tables as $table ) {
			$output[] = array(
				'name'       => $table['Name'],
				'engine'     => $table['Engine'],
				'rows'       => (int) $table['Rows'],
				'data_size'  => (int) $table['Data_length'],
				'index_size' => (int) $table['Index_length'],
				'collation'  => $table['Collation'],
				'created'    => $table['Create_time'],
				'updated'    => $table['Update_time'],
			);
		}

		return $this->success( $output );
	}

	public function describe_table( $request ) {
		global $wpdb;

		$name = sanitize_text_field( $request['name'] );

		// Validate table exists.
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! in_array( $name, $tables, true ) ) {
			return $this->error( 'not_found', 'Table not found.', 404 );
		}

		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM `' . esc_sql( $name ) . '`' ), ARRAY_A );
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $name ) . '`', ARRAY_A );

		return $this->success( array(
			'table'   => $name,
			'columns' => $columns,
			'indexes' => $indexes,
		) );
	}

	public function sample_table( $request ) {
		global $wpdb;

		$name  = sanitize_text_field( $request['name'] );
		$limit = min( (int) $request['limit'], 100 );

		// Validate table exists.
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! in_array( $name, $tables, true ) ) {
			return $this->error( 'not_found', 'Table not found.', 404 );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM `' . esc_sql( $name ) . '` LIMIT %d', $limit ),
			ARRAY_A
		);

		return $this->success( array(
			'table'     => $name,
			'rows'      => $rows ?: array(),
			'row_count' => count( $rows ?: array() ),
		) );
	}
}
