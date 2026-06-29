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

		$responses = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$result = $this->process_message( $message, $request );
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
	private function process_message( $message, WP_REST_Request $request ) {
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
				return $this->rpc_result( $id, array(
					'protocolVersion' => $client_proto,
					'capabilities'    => array(
						'tools' => array( 'listChanged' => false ),
					),
					'serverInfo'      => array(
						'name'    => 'goo1 WP Claude Bridge',
						'version' => defined( 'GOO1_MCP_VERSION' ) ? GOO1_MCP_VERSION : '1.0.0',
					),
					'instructions'    => 'WordPress site management tools. Each tool maps to a REST endpoint of this site.',
				) );

			case 'ping':
				return $this->rpc_result( $id, (object) array() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => $this->list_tools() ) );

			case 'tools/call':
				return $this->call_tool( $id, $params, $request );

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
			$tools[] = array(
				'name'        => $tool['name'],
				'description' => isset( $tool['description'] ) ? $tool['description'] : '',
				'inputSchema' => $this->normalize_schema( isset( $tool['inputSchema'] ) ? $tool['inputSchema'] : array() ),
			);
		}
		return $tools;
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
		if ( ! is_array( $schema ) ) {
			$schema = array();
		}
		if ( empty( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) || empty( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}
		return $schema;
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
		if ( ! $tool || empty( $tool['endpoint'] ) ) {
			return $this->rpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}

		$method = strtoupper( $tool['endpoint']['method'] );
		$path   = $tool['endpoint']['path'];

		// Substitute {placeholder} path segments from the arguments.
		if ( preg_match_all( '/\{(\w+)\}/', $path, $matches ) ) {
			foreach ( $matches[1] as $key ) {
				$value = isset( $args[ $key ] ) ? $args[ $key ] : '';
				$path  = str_replace( '{' . $key . '}', rawurlencode( (string) $value ), $path );
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
		$key   = $request->get_param( '_goo1_mcp_key' );
		$label = ( is_array( $key ) && isset( $key['label'] ) ) ? $key['label'] : 'oauth';
		Goo1_MCP_Audit_Log::instance()->log( $label, self::NAMESPACE_PATH() . $path, $method, $args, $status );

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
