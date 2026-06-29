<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Permissions {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if a key has the required scope.
	 *
	 * @param array  $key_data  Authenticated key data.
	 * @param string $required  Required scope: 'read', 'full', or 'db_write'.
	 * @return true|WP_Error
	 */
	public function can( $key_data, $required = 'read' ) {
		if ( 'read' === $required ) {
			return true;
		}

		if ( 'full' === $required && 'full' !== $key_data['scope'] ) {
			return new WP_Error(
				'goo1_mcp_insufficient_scope',
				'This API key has read-only scope. A full-access key is required for this operation.',
				array( 'status' => 403 )
			);
		}

		if ( 'db_write' === $required ) {
			if ( 'full' !== $key_data['scope'] ) {
				return new WP_Error(
					'goo1_mcp_insufficient_scope',
					'This API key has read-only scope.',
					array( 'status' => 403 )
				);
			}
			if ( empty( $key_data['db_write'] ) ) {
				return new WP_Error(
					'goo1_mcp_no_db_write',
					'This API key does not have database write permission.',
					array( 'status' => 403 )
				);
			}
			return true;
		}

		return true;
	}
}
