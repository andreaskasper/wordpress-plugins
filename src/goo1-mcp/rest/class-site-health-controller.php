<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Site_Health_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_health' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/errors', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_error_log' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'lines' => array(
					'type'    => 'integer',
					'default' => 100,
					'maximum' => 500,
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/cron', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_cron' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/cron/(?P<hook>[a-zA-Z0-9_-]+)/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_cron' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/hooks', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_hooks' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'search' => array(
					'type' => 'string',
				),
				'type' => array(
					'type'    => 'string',
					'enum'    => array( 'action', 'filter', 'all' ),
					'default' => 'all',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/hooks/(?P<name>.+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_hook' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/transients', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_transients' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
			'args'                => array(
				'search' => array(
					'type' => 'string',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/transients/(?P<name>.+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_transient' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function get_health( $request ) {
		global $wpdb;

		$active_plugins = get_option( 'active_plugins', array() );

		// Database size.
		$db_size = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s",
				DB_NAME
			)
		);

		// Upload directory size.
		$upload_dir  = wp_upload_dir();
		$upload_size = null;

		$data = array(
			'wordpress_version'  => get_bloginfo( 'version' ),
			'php_version'        => phpversion(),
			'mysql_version'      => $wpdb->db_version(),
			'environment_type'   => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'site_url'           => get_site_url(),
			'home_url'           => get_home_url(),
			'multisite'          => is_multisite(),
			'active_plugins'     => count( $active_plugins ),
			'active_theme'       => get_stylesheet(),
			'database_name'      => DB_NAME,
			'database_prefix'    => $wpdb->prefix,
			'database_size'      => $db_size ? (int) $db_size : null,
			'table_count'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = '" . esc_sql( DB_NAME ) . "'" ),
			'upload_dir'         => $upload_dir['basedir'],
			'memory_limit'       => WP_MEMORY_LIMIT,
			'max_upload_size'    => wp_max_upload_size(),
			'debug_mode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_log'          => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'cron_enabled'       => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'timezone'           => get_option( 'timezone_string' ) ?: 'UTC' . get_option( 'gmt_offset' ),
			'language'           => get_locale(),
			'server_software'    => sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' ),
			'php_extensions'     => get_loaded_extensions(),
			'wp_constants'       => array(
				'WP_DEBUG'           => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
				'WP_DEBUG_LOG'       => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
				'WP_DEBUG_DISPLAY'   => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : true,
				'SCRIPT_DEBUG'       => defined( 'SCRIPT_DEBUG' ) ? SCRIPT_DEBUG : false,
				'WP_CACHE'           => defined( 'WP_CACHE' ) ? WP_CACHE : false,
				'CONCATENATE_SCRIPTS'=> defined( 'CONCATENATE_SCRIPTS' ) ? CONCATENATE_SCRIPTS : true,
			),
		);

		return $this->success( $data );
	}

	public function get_error_log( $request ) {
		$lines = min( (int) $request['lines'], 500 );

		// Determine log file path.
		$log_file = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$log_file = WP_DEBUG_LOG;
		}

		if ( ! file_exists( $log_file ) || ! is_readable( $log_file ) ) {
			return $this->success( array(
				'file'    => $log_file,
				'exists'  => file_exists( $log_file ),
				'lines'   => array(),
				'message' => 'Debug log file not found or not readable.',
			) );
		}

		// Read last N lines efficiently.
		$file_lines = array();
		$fp = fopen( $log_file, 'r' );
		if ( $fp ) {
			// Seek from end.
			fseek( $fp, 0, SEEK_END );
			$pos   = ftell( $fp );
			$count = 0;
			$buffer = '';

			while ( $pos > 0 && $count < $lines ) {
				$pos--;
				fseek( $fp, $pos );
				$char = fgetc( $fp );
				if ( $char === "\n" && $buffer !== '' ) {
					$file_lines[] = $buffer;
					$buffer = '';
					$count++;
				} else {
					$buffer = $char . $buffer;
				}
			}
			if ( $buffer !== '' ) {
				$file_lines[] = $buffer;
			}
			fclose( $fp );

			$file_lines = array_reverse( $file_lines );
		}

		return $this->success( array(
			'file'      => $log_file,
			'size'      => filesize( $log_file ),
			'lines'     => $file_lines,
			'count'     => count( $file_lines ),
			'requested' => $lines,
		) );
	}

	public function list_cron( $request ) {
		$cron_array = _get_cron_array();
		if ( empty( $cron_array ) ) {
			return $this->success( array() );
		}

		$events = array();
		foreach ( $cron_array as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events_data ) {
				foreach ( $events_data as $key => $event ) {
					$events[] = array(
						'hook'       => $hook,
						'timestamp'  => $timestamp,
						'datetime'   => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'schedule'   => $event['schedule'] ?: 'single',
						'interval'   => $event['interval'] ?? null,
						'args'       => $event['args'],
						'is_overdue' => $timestamp < time(),
					);
				}
			}
		}

		// Sort by timestamp.
		usort( $events, function ( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		return $this->success( $events );
	}

	public function run_cron( $request ) {
		$hook = sanitize_text_field( $request['hook'] );

		// Check if hook exists in cron.
		$cron_array = _get_cron_array();
		$found      = false;

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return $this->error( 'not_found', 'Cron hook not found.', 404 );
		}

		do_action( $hook );

		return $this->success( array( 'executed' => true, 'hook' => $hook ) );
	}

	public function list_hooks( $request ) {
		global $wp_filter;

		$search = sanitize_text_field( $request['search'] ?? '' );
		$output = array();

		foreach ( $wp_filter as $name => $hook ) {
			if ( $search && strpos( $name, $search ) === false ) {
				continue;
			}

			$callbacks_count = 0;
			$priorities      = array();

			if ( $hook instanceof WP_Hook ) {
				foreach ( $hook->callbacks as $priority => $callbacks ) {
					$callbacks_count += count( $callbacks );
					$priorities[]     = $priority;
				}
			}

			$output[] = array(
				'name'            => $name,
				'callback_count'  => $callbacks_count,
				'priorities'      => $priorities,
			);
		}

		// Sort by name.
		usort( $output, function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		} );

		// Limit output to prevent massive responses.
		$total  = count( $output );
		$output = array_slice( $output, 0, 500 );

		return $this->success( array(
			'hooks'     => $output,
			'total'     => $total,
			'truncated' => $total > 500,
		) );
	}

	public function get_hook( $request ) {
		global $wp_filter;

		$name = sanitize_text_field( $request['name'] );

		if ( ! isset( $wp_filter[ $name ] ) ) {
			return $this->error( 'not_found', 'Hook not found.', 404 );
		}

		$hook      = $wp_filter[ $name ];
		$callbacks = array();

		if ( $hook instanceof WP_Hook ) {
			foreach ( $hook->callbacks as $priority => $priority_callbacks ) {
				foreach ( $priority_callbacks as $id => $callback ) {
					$func = $callback['function'];
					$name_str = '';

					if ( is_string( $func ) ) {
						$name_str = $func;
					} elseif ( is_array( $func ) && count( $func ) === 2 ) {
						$class  = is_object( $func[0] ) ? get_class( $func[0] ) : $func[0];
						$name_str = $class . '::' . $func[1];
					} elseif ( $func instanceof Closure ) {
						$name_str = '{closure}';
					}

					$callbacks[] = array(
						'priority'        => $priority,
						'function'        => $name_str,
						'accepted_args'   => $callback['accepted_args'],
					);
				}
			}
		}

		return $this->success( array(
			'hook'      => $request['name'],
			'callbacks' => $callbacks,
		) );
	}

	public function list_transients( $request ) {
		global $wpdb;

		$search = sanitize_text_field( $request['search'] ?? '' );

		$sql = "SELECT option_name, LENGTH(option_value) as size FROM $wpdb->options WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'";

		if ( $search ) {
			$sql .= $wpdb->prepare( ' AND option_name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		$sql .= ' ORDER BY option_name LIMIT 200';

		$results = $wpdb->get_results( $sql, ARRAY_A );
		$output  = array();

		foreach ( $results as $row ) {
			$name    = str_replace( '_transient_', '', $row['option_name'] );
			$timeout = get_option( '_transient_timeout_' . $name );

			$output[] = array(
				'name'       => $name,
				'size'       => (int) $row['size'],
				'expires_at' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : null,
				'expired'    => $timeout ? ( (int) $timeout < time() ) : false,
			);
		}

		return $this->success( $output );
	}

	public function delete_transient( $request ) {
		$name   = sanitize_text_field( $request['name'] );
		$result = delete_transient( $name );

		return $this->success( array(
			'deleted' => $result,
			'name'    => $name,
		) );
	}
}
