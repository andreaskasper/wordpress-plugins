<?php
/**
 * Universal REST tool: call any other REST route of this site internally,
 * as the key's run-as admin. Covers everything the dedicated tools do not
 * (Redirection, menu items, Yoast, Polylang, WooCommerce REST, …) without a
 * browser session or nonce.
 *
 * GET needs read scope, every other method full scope. The bridge's own
 * namespace is excluded (use the dedicated tools for that).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Rest_Proxy_Controller extends Goo1_MCP_Base_Controller {

	const MAX_BYTES_DEFAULT = 200000;

	public function register_routes() {
		// Full scope for every method: the proxy runs as the run-as admin, so
		// even GET exposes everything (orders, gateway settings …).
		register_rest_route( self::NAMESPACE, '/rest', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'proxy' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/rest/routes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'routes' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
	}

	public function proxy( $request ) {
		$method = strtoupper( (string) ( $request->get_param( 'method' ) ?: 'GET' ) );
		$route  = '/' . ltrim( (string) $request->get_param( 'route' ), '/' );

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $this->error( 'invalid_method', 'method must be GET, POST, PUT, PATCH or DELETE.' );
		}
		if ( '/' === $route ) {
			return $this->error( 'missing_route', 'Provide `route`, e.g. /wp/v2/menu-items or /redirection/v1/redirect.' );
		}
		if ( 0 === stripos( ltrim( $route, '/' ), self::NAMESPACE ) ) {
			return $this->error( 'own_namespace', 'Use the dedicated Claude Bridge tools for the goo1-mcp namespace.', 400 );
		}
		if ( 'GET' !== $method ) {
			$perm = Goo1_MCP_Permissions::instance()->can( Goo1_MCP_Auth::instance()->current(), 'full' );
			if ( is_wp_error( $perm ) ) {
				return $this->error( $perm->get_error_code(), $perm->get_error_message(), 403 );
			}
		}

		// Allow a query string in the route ("/wp/v2/pages?per_page=5").
		$query = array();
		if ( false !== strpos( $route, '?' ) ) {
			list( $route, $qs ) = explode( '?', $route, 2 );
			wp_parse_str( $qs, $query );
		}
		$extra_query = $request->get_param( 'query' );
		if ( is_array( $extra_query ) ) {
			$query = array_merge( $query, $extra_query );
		}

		$inner = new WP_REST_Request( $method, $route );
		if ( $query ) {
			$inner->set_query_params( $query );
		}
		$body = $request->get_param( 'body' );
		if ( null !== $body && 'GET' !== $method ) {
			if ( is_string( $body ) ) {
				$decoded = json_decode( $body, true );
				$body    = is_array( $decoded ) ? $decoded : $body;
			}
			if ( is_array( $body ) ) {
				$inner->set_header( 'Content-Type', 'application/json' );
				$inner->set_body( wp_json_encode( $body ) );
				$inner->set_body_params( $body );
			} else {
				$inner->set_body( (string) $body );
			}
		}
		$headers = $request->get_param( 'headers' );
		if ( is_array( $headers ) ) {
			foreach ( $headers as $h => $v ) {
				if ( 'authorization' !== strtolower( (string) $h ) ) {
					$inner->set_header( (string) $h, (string) $v );
				}
			}
		}

		$guard    = 'GET' === $method ? null : Goo1_MCP_Guard::snapshot();
		$response = rest_do_request( $inner );
		if ( null !== $guard && Goo1_MCP_Guard::restore_if_changed( $guard ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'guard_restored' );
		}

		$server = rest_get_server();
		$data   = $server->response_to_data( $response, (bool) $request->get_param( 'embed' ) );
		if ( ! $request->get_param( 'include_links' ) ) {
			$data = $this->strip_links( $data );
		}

		if ( Goo1_MCP_Permissions::instance()->allow_unredacted( $request ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		} else {
			$data = Goo1_MCP_Redactor::redact( $data );
		}

		$max  = (int) ( $request->get_param( 'max_bytes' ) ?: self::MAX_BYTES_DEFAULT );
		$json = wp_json_encode( $data );
		$trunc = false;
		if ( $max > 0 && strlen( (string) $json ) > $max ) {
			$trunc = true;
			$data  = substr( (string) $json, 0, $max );
		}

		$keep_headers = array();
		foreach ( $response->get_headers() as $h => $v ) {
			if ( in_array( strtolower( $h ), array( 'x-wp-total', 'x-wp-totalpages', 'location', 'allow' ), true ) ) {
				$keep_headers[ $h ] = $v;
			}
		}

		return $this->success( array(
			'status'    => $response->get_status(),
			'headers'   => $keep_headers,
			'data'      => $data,
			'truncated' => $trunc,
		) );
	}

	/**
	 * Drop HAL `_links` (lots of tokens, rarely useful for an agent).
	 */
	private function strip_links( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		unset( $data['_links'] );
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = $this->strip_links( $v );
			}
		}
		return $data;
	}

	/**
	 * List registered routes, optionally filtered by namespace / search.
	 */
	public function routes( $request ) {
		$ns     = trim( (string) $request->get_param( 'namespace' ), '/' );
		$search = (string) $request->get_param( 'search' );
		$server = rest_get_server();
		$out    = array();

		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( 0 === strpos( $route, '/' . self::NAMESPACE ) ) {
				continue;
			}
			if ( $ns && 0 !== strpos( $route, '/' . $ns ) ) {
				continue;
			}
			if ( $search && false === stripos( $route, $search ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $h ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) $h['methods'] ) ) );
			}
			$out[] = array( 'route' => $route, 'methods' => array_values( array_unique( $methods ) ) );
		}

		if ( ! $ns && ! $search ) {
			// Without a filter return namespaces only (the full list is huge).
			return $this->success( array(
				'namespaces' => array_values( array_diff( $server->get_namespaces(), array( self::NAMESPACE ) ) ),
				'hint'       => 'Pass namespace (e.g. wp/v2, redirection/v1) or search to list routes.',
			) );
		}

		return $this->success( array( 'routes' => $out, 'count' => count( $out ) ) );
	}
}
