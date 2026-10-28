<?php
/**
 * MCP Streamable-HTTP transport endpoint.
 *
 * Exposes a single route — /goo1-mcp/v1/mcp — that speaks the MCP JSON-RPC
 * protocol (initialize / tools/list / tools/call / ping). Tool definitions are
 * read from mcp-server.json, and tools/call is dispatched internally to the
 * existing REST controllers via rest_do_request(), so scope checks, rate
 * limiting and audit logging all apply per tool.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_MCP_Controller extends Goo1_MCP_Base_Controller {

	const PROTOCOL_VERSION = '2025-06-18';

	/** Protocol versions this server speaks (newest first). */
	const SUPPORTED_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	private $tools_cache = null;

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/mcp', array(
			array(
				'methods'             => 'POST, GET, DELETE',
				'callback'            => array( $this, 'handle' ),
				// Only authentication is enforced here; per-tool scope is checked
				// during the internal dispatch of each tools/call.
				'permission_callback' => array( $this, 'authenticate_only' ),
			),
		) );
		// Lean profile: four meta tools instead of the full list (saves the
		// client's context window). Same auth, scopes, redaction and audit.
		register_rest_route( self::NAMESPACE, '/mcp/lean', array(
			array(
				'methods'             => 'POST, GET, DELETE',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authenticate_only' ),
			),
		) );
	}

	/** Lean meta tools (wp_find_tools, wp_describe_tool, wp_call_read, wp_call_write). */
	const LEAN_TOOLS = array( 'wp_find_tools', 'wp_describe_tool', 'wp_call_read', 'wp_call_write' );

	public static function is_lean_route( $route ) {
		return '/mcp/lean' === substr( (string) $route, -9 );
	}

	/**
	 * Permission callback: require a valid bearer token (API key or OAuth),
	 * but do not enforce a scope at this layer.
	 */
	public function authenticate_only( WP_REST_Request $request ) {
		$auth = Goo1_MCP_Auth::instance()->authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return true;
	}

	public function handle( WP_REST_Request $request ) {
		$method = $request->get_method();

		// MCP servers without a server-initiated SSE stream answer GET with 405.
		if ( 'GET' === $method ) {
			return new WP_REST_Response( null, 405 );
		}
		// Session teardown — nothing to clean up (tokens are bearer, not session-bound).
		if ( 'DELETE' === $method ) {
			return new WP_REST_Response( null, 204 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			return $this->rpc_error_response( null, -32700, 'Parse error: expected a JSON-RPC request.' );
		}

		// Batch request (array of message objects).
		$is_batch = array_keys( $body ) === range( 0, count( $body ) - 1 );
		$messages = $is_batch ? $body : array( $body );

		$lean      = self::is_lean_route( $request->get_route() );
		$responses = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$result = $this->process_message( $message, $request, $lean );
			if ( null !== $result ) {
				$responses[] = $result;
			}
		}

		// All messages were notifications → no response body.
		if ( empty( $responses ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$payload = $is_batch ? $responses : $responses[0];
		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Process one JSON-RPC message. Returns the response array, or null for
	 * notifications (messages without an id).
	 */
	private function process_message( $message, WP_REST_Request $request, $lean = false ) {
		$id        = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$method    = isset( $message['method'] ) ? (string) $message['method'] : '';
		$params    = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$is_notify = ! array_key_exists( 'id', $message );

		// Notifications never get a response.
		if ( $is_notify ) {
			return null;
		}

		switch ( $method ) {
			case 'initialize':
				$client_proto = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : self::PROTOCOL_VERSION;
				// Echo the client's version if we speak it, otherwise offer ours.
				$proto = in_array( $client_proto, self::SUPPORTED_VERSIONS, true ) ? $client_proto : self::PROTOCOL_VERSION;
				return $this->rpc_result( $id, array(
					'protocolVersion' => $proto,
					'capabilities'    => array(
						'tools' => array( 'listChanged' => false ),
					),
					'serverInfo'      => array(
						'name'    => 'goo1 WP Claude Bridge',
						'version' => defined( 'GOO1_MCP_VERSION' ) ? GOO1_MCP_VERSION : '1.0.0',
					),
					'instructions'    => $this->instructions( $lean ),
				) );

			case 'ping':
				return $this->rpc_result( $id, (object) array() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => $lean ? $this->lean_tool_definitions() : $this->list_tools() ) );

			case 'tools/call':
				return $lean ? $this->call_lean( $id, $params, $request ) : $this->call_tool( $id, $params, $request );

			case 'resources/list':
				return $this->rpc_result( $id, array( 'resources' => array() ) );

			case 'prompts/list':
				return $this->rpc_result( $id, array( 'prompts' => array() ) );

			default:
				return $this->rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Tool definitions from mcp-server.json (without the internal `endpoint` field).
	 */
	private function list_tools() {
		$tools = array();
		foreach ( $this->load_tools() as $tool ) {
			if ( ! $this->tool_available( $tool ) ) {
				continue;
			}
			$entry = array(
				'name'        => $tool['name'],
				'description' => isset( $tool['description'] ) ? $tool['description'] : '',
				'inputSchema' => $this->normalize_schema( isset( $tool['inputSchema'] ) ? $tool['inputSchema'] : array() ),
			);
			if ( ! empty( $tool['annotations'] ) && is_array( $tool['annotations'] ) ) {
				$entry['annotations'] = $tool['annotations'];
			}
			$tools[] = $entry;
		}
		return $tools;
	}

	/**
	 * Tools of optional integrations are only offered when the plugin is active.
	 * mcp-server.json marks them with "requires": "<integration>".
	 */
	private function tool_available( $tool ) {
		if ( empty( $tool['requires'] ) ) {
			return true;
		}
		switch ( $tool['requires'] ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' );
			case 'elementor':
				return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
			case 'updraftplus':
				return class_exists( 'UpdraftPlus' );
			case 'managewp':
				return function_exists( 'mwp_get_potential_key' ) || defined( 'MWP_WORKER_VERSION' );
			default:
				return (bool) apply_filters( 'goo1_mcp_tool_available', true, $tool );
		}
	}

	/**
	 * Server instructions sent on initialize: the few things a client must
	 * know to use these tools well.
	 */
	private function instructions( $lean = false ) {
		$user = wp_get_current_user();
		$lines = array(
			$lean
				? 'WordPress site management for ' . home_url() . ' (lean profile). Discover tools with wp_find_tools, get the exact input schema with wp_describe_tool, then run read-only tools with wp_call_read and everything else with wp_call_write (tool + arguments).'
				: 'WordPress site management tools for ' . home_url() . '. Each tool maps to a REST endpoint of this site.',
			$user && $user->ID ? 'Requests run as WordPress user "' . $user->user_login . '" (ID ' . $user->ID . ').' : 'Requests run without a WordPress user (legacy API key): kses filtering applies and authorless content is created.',
			'Secrets (passwords, API/S3 keys, tokens) are returned as "[redacted]". Writing "[redacted]" back keeps the stored secret.',
			'Secrets never travel in clear text: create a request with secrets_request, then pass the sealed envelope (from goo1-seal.mjs on the owner\'s computer or secrets_export_sealed on another Bridge site) or secret_request_id after the owner used the inbox link.',
			'Use wp_rest_request for any other REST route of this site (Redirection, menu items, Yoast, Polylang …); wp_rest_routes lists them.',
			'Responses are slim by default: pass include_meta=true or meta_keys to wp_get_post when you need post meta.',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Normalize a tool input schema so it always serializes as a JSON object.
	 * mcp-server.json is decoded to associative arrays, so an empty
	 * `properties` ({} in the file) becomes an empty PHP array and would
	 * json_encode back to "[]" — but MCP requires both `inputSchema` and
	 * `inputSchema.properties` to be objects ("{}"), and Claude rejects the
	 * tool list with "Input should be a valid dictionary" otherwise.
	 */
	private function normalize_schema( $schema ) {
		return $this->fix_schema_node( $schema, true );
	}

	/**
	 * Recursively repair a JSON Schema that was decoded with json_decode($x, true).
	 * PHP turns an empty object {} into an empty array [], which json_encode then
	 * re-emits as "[]" — invalid for any schema keyword that must be an object
	 * (properties, items, …) and rejected by the API ("JSON schema is invalid …
	 * draft 2020-12"). We walk the tree, restore objects for those keywords, and
	 * guarantee the top level is an object schema with an object `properties`.
	 *
	 * @param mixed $node    A (sub-)schema, as decoded to an associative array.
	 * @param bool  $is_root Whether $node is the tool's top-level inputSchema.
	 * @return mixed
	 */
	private function fix_schema_node( $node, $is_root = false ) {
		if ( ! is_array( $node ) ) {
			return $node; // booleans (e.g. additionalProperties: false), scalars.
		}

		// Keywords whose value is a single nested schema.
		$single = array( 'items', 'additionalProperties', 'contains', 'not', 'if', 'then', 'else', 'propertyNames' );
		// Keywords whose value is a map of name => schema.
		$maps   = array( 'properties', 'patternProperties', '$defs', 'definitions' );
		// Keywords whose value is a list of schemas.
		$lists  = array( 'allOf', 'anyOf', 'oneOf', 'prefixItems' );

		foreach ( $maps as $k ) {
			if ( array_key_exists( $k, $node ) ) {
				if ( ! is_array( $node[ $k ] ) || empty( $node[ $k ] ) ) {
					$node[ $k ] = new stdClass(); // {} must stay an object.
				} else {
					$fixed = array();
					foreach ( $node[ $k ] as $name => $sub ) {
						$fixed[ $name ] = $this->fix_schema_node( $sub );
					}
					$node[ $k ] = $fixed;
				}
			}
		}

		foreach ( $single as $k ) {
			if ( array_key_exists( $k, $node ) ) {
				if ( is_array( $node[ $k ] ) && empty( $node[ $k ] ) ) {
					$node[ $k ] = new stdClass();
				} else {
					$node[ $k ] = $this->fix_schema_node( $node[ $k ] );
				}
			}
		}

		foreach ( $lists as $k ) {
			if ( array_key_exists( $k, $node ) && is_array( $node[ $k ] ) ) {
				$node[ $k ] = array_map(
					function ( $sub ) { return $this->fix_schema_node( $sub ); },
					array_values( $node[ $k ] )
				);
			}
		}

		if ( $is_root ) {
			if ( empty( $node['type'] ) ) {
				$node['type'] = 'object';
			}
			if ( ! array_key_exists( 'properties', $node ) ) {
				$node['properties'] = new stdClass();
			}
		}

		return $node;
	}

	/* ---- Lean profile ---- */

	private function lean_tool_definitions() {
		$call_props = array(
			'tool'      => array( 'type' => 'string', 'description' => 'Tool name from wp_find_tools, e.g. wp_list_posts.' ),
			'arguments' => array( 'type' => 'object', 'description' => 'Arguments exactly as in the inputSchema from wp_describe_tool.' ),
		);
		$defs = array(
			array(
				'name'        => 'wp_find_tools',
				'description' => 'Search the tools of this WordPress site (' . home_url() . ') by keywords, e.g. "elementor widget", "updraft backup", "plugin update". Returns name, short description and access (read/write). Without a query: all tool names. Then use wp_describe_tool for the input schema.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string', 'description' => 'Keywords (any order). Empty = list all tool names.' ),
						'limit' => array( 'type' => 'integer', 'default' => 25 ),
					),
				),
				'annotations' => array( 'title' => 'wp find tools', 'readOnlyHint' => true, 'openWorldHint' => false ),
			),
			array(
				'name'        => 'wp_describe_tool',
				'description' => 'Full description and input schema of one or more tools (comma-separated names, max 10).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string', 'description' => 'Tool name, or several comma-separated.' ),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array( 'title' => 'wp describe tool', 'readOnlyHint' => true, 'openWorldHint' => false ),
			),
			array(
				'name'        => 'wp_call_read',
				'description' => 'Run a READ-ONLY tool of this site (access=read in wp_find_tools). Refuses tools that change anything — use wp_call_write for those.',
				'inputSchema' => array( 'type' => 'object', 'properties' => $call_props, 'required' => array( 'tool' ) ),
				'annotations' => array( 'title' => 'wp call read', 'readOnlyHint' => true, 'openWorldHint' => false ),
			),
			array(
				'name'        => 'wp_call_write',
				'description' => 'Run a tool of this site that changes data (create/update/delete, plugin updates, SQL, REST proxy …). Check the schema with wp_describe_tool first.',
				'inputSchema' => array( 'type' => 'object', 'properties' => $call_props, 'required' => array( 'tool' ) ),
				'annotations' => array( 'title' => 'wp call write', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false ),
			),
		);
		foreach ( $defs as $i => $d ) {
			$defs[ $i ]['inputSchema'] = $this->normalize_schema( $d['inputSchema'] );
		}
		return $defs;
	}

	private static function tool_access( $tool ) {
		return ( isset( $tool['annotations']['readOnlyHint'] ) && true === $tool['annotations']['readOnlyHint'] ) ? 'read' : 'write';
	}

	private static function short_description( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( preg_match( '/^(.{20,}?[.!?])\s/u', $text, $m ) ) {
			$text = $m[1];
		}
		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 180, '…', 'UTF-8' ) : substr( $text, 0, 180 );
	}

	/** Available (integration present) tools, keyed by name. */
	private function available_tools() {
		$out = array();
		foreach ( $this->load_tools() as $tool ) {
			if ( ! empty( $tool['name'] ) && $this->tool_available( $tool ) ) {
				$out[ $tool['name'] ] = $tool;
			}
		}
		return $out;
	}

	private function lean_text( $id, $data, $is_error = false ) {
		return $this->rpc_result( $id, array(
			'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
			'isError' => (bool) $is_error,
		) );
	}

	private function call_lean( $id, $params, WP_REST_Request $request ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$all  = $this->available_tools();

		switch ( $name ) {
			case 'wp_find_tools':
				return $this->lean_text( $id, $this->find_tools( $all, isset( $args['query'] ) ? (string) $args['query'] : '', isset( $args['limit'] ) ? (int) $args['limit'] : 25 ) );

			case 'wp_describe_tool':
				$names = array_filter( array_map( 'trim', explode( ',', isset( $args['name'] ) && is_scalar( $args['name'] ) ? (string) $args['name'] : '' ) ) );
				$out   = array();
				$miss  = array();
				foreach ( array_slice( array_values( array_unique( $names ) ), 0, 10 ) as $n ) {
					if ( ! isset( $all[ $n ] ) ) {
						$miss[] = $n;
						continue;
					}
					$t     = $all[ $n ];
					$out[] = array(
						'name'        => $t['name'],
						'access'      => self::tool_access( $t ),
						'description' => isset( $t['description'] ) ? $t['description'] : '',
						'inputSchema' => $this->normalize_schema( isset( $t['inputSchema'] ) ? $t['inputSchema'] : array() ),
						'annotations' => isset( $t['annotations'] ) ? $t['annotations'] : (object) array(),
					);
				}
				if ( ! $out ) {
					return $this->lean_text( $id, array( 'error' => 'Unknown tool(s): ' . implode( ', ', $miss ? $miss : array( '(none given)' ) ) . '. Use wp_find_tools.' ), true );
				}
				return $this->lean_text( $id, array( 'tools' => $out, 'unknown' => $miss ) );

			case 'wp_call_read':
			case 'wp_call_write':
				$tool_name = isset( $args['tool'] ) && is_scalar( $args['tool'] ) ? (string) $args['tool'] : '';
				if ( ! isset( $all[ $tool_name ] ) ) {
					return $this->lean_text( $id, array( 'error' => 'Unknown tool: ' . $tool_name . '. Use wp_find_tools.' ), true );
				}
				if ( 'wp_call_read' === $name && 'read' !== self::tool_access( $all[ $tool_name ] ) ) {
					return $this->lean_text( $id, array( 'error' => $tool_name . ' can change data and is not available through wp_call_read. Use wp_call_write.' ), true );
				}
				$inner = isset( $args['arguments'] ) ? $args['arguments'] : array();
				if ( is_string( $inner ) && '' !== trim( $inner ) ) {
					$inner = json_decode( $inner, true );
				}
				if ( null === $inner || '' === $inner ) {
					$inner = array();
				}
				if ( ! is_array( $inner ) ) {
					return $this->lean_text( $id, array( 'error' => '`arguments` must be an object.' ), true );
				}
				return $this->call_tool( $id, array( 'name' => $tool_name, 'arguments' => $inner ), $request );

			default:
				return $this->rpc_error( $id, -32602, 'Unknown tool: ' . $name . ' (lean profile: ' . implode( ', ', self::LEAN_TOOLS ) . ')' );
		}
	}

	/**
	 * Keyword search over tool names and descriptions. Every keyword scores
	 * (name hits weigh more); tools without any hit are dropped.
	 */
	private function find_tools( $all, $query, $limit ) {
		$limit = max( 1, min( 200, $limit ) );
		$query = strtolower( trim( $query ) );
		if ( '' === $query ) {
			$list = array();
			foreach ( $all as $t ) {
				$list[] = $t['name'] . ' (' . self::tool_access( $t ) . ')';
			}
			return array( 'site' => home_url(), 'total' => count( $list ), 'tools' => $list, 'hint' => 'Search with query for short descriptions; wp_describe_tool for schemas.' );
		}
		$words = array_values( array_filter( preg_split( '/[\s_,\/-]+/', $query ), function ( $w ) { return strlen( $w ) > 1; } ) );
		$hits  = array();
		foreach ( $all as $t ) {
			$name  = strtolower( $t['name'] );
			$desc  = strtolower( isset( $t['description'] ) ? $t['description'] : '' );
			$score = 0;
			foreach ( $words as $w ) {
				$stem = strlen( $w ) > 4 ? rtrim( $w, 's' ) : $w; // "plugins" finds "plugin".
				if ( false !== strpos( $name, $stem ) ) {
					$score += 3;
				} elseif ( false !== strpos( $desc, $stem ) ) {
					$score += 1;
				}
			}
			if ( $score > 0 ) {
				$hits[] = array( 'score' => $score, 'tool' => $t );
			}
		}
		usort( $hits, function ( $a, $b ) {
			return $b['score'] - $a['score'] ?: strcmp( $a['tool']['name'], $b['tool']['name'] );
		} );
		$out = array();
		foreach ( array_slice( $hits, 0, $limit ) as $h ) {
			$out[] = array(
				'name'        => $h['tool']['name'],
				'access'      => self::tool_access( $h['tool'] ),
				'description' => self::short_description( isset( $h['tool']['description'] ) ? $h['tool']['description'] : '' ),
			);
		}
		return array( 'site' => home_url(), 'query' => $query, 'matches' => count( $hits ), 'tools' => $out );
	}

	private function call_tool( $id, $params, WP_REST_Request $request ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$tool = null;
		foreach ( $this->load_tools() as $candidate ) {
			if ( $candidate['name'] === $name ) {
				$tool = $candidate;
				break;
			}
		}
		if ( ! $tool || empty( $tool['endpoint'] ) || ! $this->tool_available( $tool ) ) {
			return $this->rpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}

		$method = strtoupper( $tool['endpoint']['method'] );
		$path   = $tool['endpoint']['path'];

		// Substitute {placeholder} path segments from the arguments.
		if ( preg_match_all( '/\{(\w+)\}/', $path, $matches ) ) {
			foreach ( $matches[1] as $key ) {
				if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) || '' === (string) $args[ $key ] ) {
					return $this->rpc_result( $id, array(
						'content' => array( array( 'type' => 'text', 'text' => 'Missing or invalid (must be a string/number) argument: ' . $key ) ),
						'isError' => true,
					) );
				}
				// Plugin files contain "/" (e.g. worker/init.php): keep slashes, encode the rest.
				$value = str_replace( '%2F', '/', rawurlencode( (string) $args[ $key ] ) );
				$path  = str_replace( '{' . $key . '}', $value, $path );
				unset( $args[ $key ] );
			}
		}

		$inner = new WP_REST_Request( $method, self::NAMESPACE_PATH() . $path );
		// Forward the bearer token so the inner request authenticates identically.
		$auth_header = $request->get_header( 'authorization' );
		if ( $auth_header ) {
			$inner->set_header( 'Authorization', $auth_header );
		}
		if ( 'GET' !== $method ) {
			$inner->set_header( 'Content-Type', 'application/json' );
		}
		foreach ( $args as $key => $value ) {
			$inner->set_param( $key, $value );
		}

		$response = rest_do_request( $inner );
		$status   = $response->get_status();
		$data     = rest_get_server()->response_to_data( $response, false );

		// Audit each tool call under its real endpoint. (rest_do_request does not
		// trigger rest_post_dispatch, so the global audit hook would miss these.)
		$key   = Goo1_MCP_Auth::instance()->current();
		$label = ( is_array( $key ) && isset( $key['label'] ) ) ? $key['label'] : 'oauth';
		Goo1_MCP_Audit_Log::instance()->log( $label, self::NAMESPACE_PATH() . $path, $method, $args, $status, Goo1_MCP_Audit_Log::take_flags() );

		$text = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$is_error = ( $status >= 400 ) || ( is_array( $data ) && isset( $data['success'] ) && false === $data['success'] );

		return $this->rpc_result( $id, array(
			'content' => array(
				array( 'type' => 'text', 'text' => $text ),
			),
			'isError' => $is_error,
		) );
	}

	/**
	 * Load + cache tool definitions from mcp-server.json.
	 */
	private function load_tools() {
		if ( null !== $this->tools_cache ) {
			return $this->tools_cache;
		}
		$this->tools_cache = array();
		$file = GOO1_MCP_PLUGIN_DIR . 'mcp-server.json';
		if ( is_readable( $file ) ) {
			$decoded = json_decode( file_get_contents( $file ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['tools'] ) && is_array( $decoded['tools'] ) ) {
				$this->tools_cache = $decoded['tools'];
			}
		}
		return $this->tools_cache;
	}

	private static function NAMESPACE_PATH() {
		return '/' . self::NAMESPACE;
	}

	/* ---- JSON-RPC envelope helpers ---- */

	private function rpc_result( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private function rpc_error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	private function rpc_error_response( $id, $code, $message ) {
		return new WP_REST_Response( $this->rpc_error( $id, $code, $message ), 200 );
	}
}
