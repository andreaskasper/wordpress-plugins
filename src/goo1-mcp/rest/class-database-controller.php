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

	/**
	 * File-system access from SQL. Blocked unless the key has the sql_files grant.
	 */
	private $file_patterns = array(
		'LOAD_FILE'     => '/\bLOAD_FILE\b/',
		'INTO OUTFILE'  => '/\bOUTFILE\b/',
		'INTO DUMPFILE' => '/\bDUMPFILE\b/',
		'LOAD DATA'     => '/\bLOAD\s+(DATA|XML)\b/',
	);

	public function register_routes() {
		// Free SQL needs full scope (since 1.6): redaction of arbitrary SELECTs
		// (aliases, functions) is best effort, not a boundary.
		register_rest_route( self::NAMESPACE, '/db/query', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'execute_query' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
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

		// Also scan the full query for blocked keywords (to prevent subquery
		// attacks). Quoted string literals are data, not SQL, so they are
		// removed first — `LIKE '%drop%'` must not be blocked. Comments stay in,
		// because MySQL executes /*! … */ comments.
		// Executable comments (MySQL /*! … */, MariaDB /*M! … */) can hide any
		// code from a scanner — fail closed.
		if ( preg_match( '#/\*\s*M?!#i', $sql ) ) {
			return $this->error( 'blocked_query', 'Executable comments (/*! … */, /*M! … */) are not allowed.', 403 );
		}
		$sql_upper = strtoupper( $this->code_only( $sql ) );
		// Named locks can stall the OAuth token endpoint.
		if ( preg_match( '/\b(GET_LOCK|RELEASE_ALL_LOCKS)\b/', $sql_upper ) ) {
			return $this->error( 'blocked_query', 'GET_LOCK is not allowed.', 403 );
		}
		foreach ( $this->file_patterns as $label => $pattern ) {
			if ( preg_match( $pattern, $sql_upper ) ) {
				if ( ! Goo1_MCP_Permissions::instance()->allow_sql_files() ) {
					return $this->error(
						'blocked_query',
						sprintf( '%s is blocked. An administrator can grant "SQL file access" to this key/connection in Claude Bridge.', $label ),
						403
					);
				}
				Goo1_MCP_Audit_Log::add_flag( 'sql_files' );
			}
		}
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
		$key_data = Goo1_MCP_Auth::instance()->current();

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
			// The bridge's own settings are off limits for its own tools:
			// run in a transaction and roll back if bridge rows changed.
			$run      = Goo1_MCP_Guard::run_sql_write( function () use ( $wpdb, $sql ) {
				return $wpdb->query( $sql );
			} );
			$affected = $run['result'];
			$tampered = $run['tampered'];

			if ( $tampered ) {
				Goo1_MCP_Audit_Log::add_flag( 'guard_restored' );
				return $this->error(
					'protected_option',
					'The query touched Claude Bridge settings (' . implode( ', ', $tampered ) . ') and was rolled back; the bridge settings can only be changed in wp-admin.',
					403
				);
			}

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

		$unredacted = Goo1_MCP_Permissions::instance()->allow_unredacted( $request );
		if ( $unredacted ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		} else {
			$results = Goo1_MCP_Redactor::redact_rows( $results );
		}

		return $this->success( array(
			'rows'       => $results,
			'row_count'  => count( $results ),
			'truncated'  => $truncated,
			'max_rows'   => $max_rows,
			'redacted'   => ! $unredacted,
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

		$columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . esc_sql( $name ) . '`', ARRAY_A );
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

		$rows = $rows ?: array();
		if ( Goo1_MCP_Permissions::instance()->allow_unredacted( $request ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		} else {
			$rows = Goo1_MCP_Redactor::redact_rows( $rows );
		}

		return $this->success( array(
			'table'     => $name,
			'rows'      => $rows,
			'row_count' => count( $rows ),
		) );
	}

	/**
	 * Reduce a statement to its SQL syntax: string literals and quoted
	 * identifiers become empty quotes, comments become a space. MySQL executes
	 * "slash-star-bang" comments, so their content is kept as code.
	 */
	private function code_only( $sql ) {
		global $wpdb;
		static $backslash = null;
		if ( null === $backslash ) {
			$mode      = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
			$backslash = false === stripos( $mode, 'NO_BACKSLASH_ESCAPES' );
		}
		$out = '';
		$len = strlen( $sql );
		$i   = 0;
		while ( $i < $len ) {
			$c  = $sql[ $i ];
			$c2 = $i + 1 < $len ? $sql[ $i + 1 ] : '';
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$q     = $c;
				$start = $i + 1;
				$i++;
				while ( $i < $len ) {
					if ( $backslash && '\\' === $sql[ $i ] && '`' !== $q ) {
						$i += 2;
						continue;
					}
					if ( $sql[ $i ] === $q ) {
						if ( $i + 1 < $len && $sql[ $i + 1 ] === $q ) {
							$i += 2;
							continue;
						}
						break;
					}
					$i++;
				}
				// Backticks quote identifiers, which are code (`LOAD_FILE`(…) still
				// calls the function): keep their content. Strings are data.
				$out .= '`' === $q ? ' ' . substr( $sql, $start, max( 0, $i - $start ) ) . ' ' : $q . $q;
				$i++;
				continue;
			}
			if ( '#' === $c || ( '-' === $c && '-' === $c2 && ( $i + 2 >= $len || ctype_space( $sql[ $i + 2 ] ) || ctype_cntrl( $sql[ $i + 2 ] ) ) ) ) {
				while ( $i < $len && "\n" !== $sql[ $i ] ) {
					$i++;
				}
				$out .= ' ';
				continue;
			}
			if ( '/' === $c && '*' === $c2 ) {
				$end = strpos( $sql, '*/', $i + 2 );
				$end = false === $end ? $len : $end;
				// Executable comments are rejected before the scan; others are a space.
				$out .= ' ';
				$i = $end + 2;
				continue;
			}
			$out .= $c;
			$i++;
		}
		return $out;
	}
}
