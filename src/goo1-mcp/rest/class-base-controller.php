<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Goo1_MCP_Base_Controller {

	const NAMESPACE = 'goo1-mcp/v1';

	abstract public function register_routes();

	/**
	 * Build a permission callback that chains auth, rate limiting, and scope check.
	 *
	 * @param string $scope 'read', 'full', or 'db_write'.
	 * @return callable
	 */
	protected function get_permission_callback( $scope = 'read' ) {
		return function ( WP_REST_Request $request ) use ( $scope ) {
			$auth = Goo1_MCP_Auth::instance()->authenticate( $request );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}

			$rate = Goo1_MCP_Rate_Limiter::instance()->check( $auth );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			return Goo1_MCP_Permissions::instance()->can( $auth, $scope );
		};
	}

	/**
	 * Standard success response.
	 */
	protected function success( $data, $status = 200 ) {
		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $data,
		), $status );
	}

	/**
	 * Standard error response.
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new WP_REST_Response( array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		), $status );
	}

	/**
	 * Get standard collection params (pagination, search).
	 */
	protected function get_collection_params() {
		return array(
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page' => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'search' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
