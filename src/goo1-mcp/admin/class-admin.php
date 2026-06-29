<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function add_menu() {
		add_menu_page(
			'goo1 Claude Bridge',
			'Claude Bridge',
			'manage_options',
			'goo1-mcp',
			array( $this, 'render_keys_page' ),
			'dashicons-rest-api',
			80
		);

		add_submenu_page(
			'goo1-mcp',
			'API Keys',
			'API Keys',
			'manage_options',
			'goo1-mcp',
			array( $this, 'render_keys_page' )
		);

		add_submenu_page(
			'goo1-mcp',
			'Connector (OAuth)',
			'Connector (OAuth)',
			'manage_options',
			'goo1-mcp-oauth',
			array( $this, 'render_oauth_page' )
		);

		add_submenu_page(
			'goo1-mcp',
			'Audit Log',
			'Audit Log',
			'manage_options',
			'goo1-mcp-audit',
			array( $this, 'render_audit_page' )
		);

		add_submenu_page(
			'goo1-mcp',
			'Settings',
			'Settings',
			'manage_options',
			'goo1-mcp-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Handle form submissions (create/revoke keys, update settings, purge log).
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Create key.
		if ( isset( $_POST['goo1_mcp_create_key'] ) && check_admin_referer( 'goo1_mcp_create_key' ) ) {
			$label      = sanitize_text_field( wp_unslash( $_POST['key_label'] ?? '' ) );
			$scope      = sanitize_text_field( wp_unslash( $_POST['key_scope'] ?? 'read' ) );
			$db_write   = ! empty( $_POST['key_db_write'] );
			$rate_limit = (int) ( $_POST['key_rate_limit'] ?? 60 );

			if ( empty( $label ) ) {
				add_settings_error( 'goo1_mcp', 'empty_label', 'Key label is required.' );
				return;
			}

			$result = Goo1_MCP_Auth::instance()->create_key( $label, $scope, $db_write, $rate_limit );

			set_transient( 'goo1_mcp_new_key', $result['raw_key'], 60 );
			add_settings_error( 'goo1_mcp', 'key_created', 'API key created. Copy it now — it will not be shown again.', 'success' );
		}

		// Revoke key.
		if ( isset( $_POST['goo1_mcp_revoke_key'] ) && check_admin_referer( 'goo1_mcp_revoke_key' ) ) {
			$key_hash = sanitize_text_field( wp_unslash( $_POST['key_hash'] ?? '' ) );
			if ( Goo1_MCP_Auth::instance()->revoke_key( $key_hash ) ) {
				add_settings_error( 'goo1_mcp', 'key_revoked', 'API key revoked.', 'success' );
			}
		}

		// Update settings.
		if ( isset( $_POST['goo1_mcp_save_settings'] ) && check_admin_referer( 'goo1_mcp_settings' ) ) {
			$scope = sanitize_text_field( wp_unslash( $_POST['oauth_default_scope'] ?? 'read' ) );
			if ( ! in_array( $scope, array( 'read', 'full', 'full_dbwrite' ), true ) ) {
				$scope = 'read';
			}
			$settings = array(
				'default_rate_limit'       => max( 1, (int) ( $_POST['default_rate_limit'] ?? 60 ) ),
				'audit_log_retention_days' => max( 1, (int) ( $_POST['audit_log_retention_days'] ?? 30 ) ),
				'db_write_enabled'         => ! empty( $_POST['db_write_enabled'] ),
				'oauth_enabled'            => ! empty( $_POST['oauth_enabled'] ),
				'oauth_dcr_enabled'        => ! empty( $_POST['oauth_dcr_enabled'] ),
				'oauth_token_ttl'          => max( 60, (int) ( $_POST['oauth_token_ttl'] ?? 3600 ) ),
				'oauth_default_scope'      => $scope,
			);
			update_option( 'goo1_mcp_settings', $settings );
			add_settings_error( 'goo1_mcp', 'settings_saved', 'Settings saved.', 'success' );
		}

		// Revoke OAuth client.
		if ( isset( $_POST['goo1_mcp_revoke_client'] ) && check_admin_referer( 'goo1_mcp_revoke_client' ) ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
			if ( Goo1_MCP_OAuth::instance()->delete_client( $client_id ) ) {
				add_settings_error( 'goo1_mcp', 'client_revoked', 'OAuth client and its tokens revoked.', 'success' );
			}
		}

		// Revoke a single OAuth access token.
		if ( isset( $_POST['goo1_mcp_revoke_token'] ) && check_admin_referer( 'goo1_mcp_revoke_token' ) ) {
			$hash = sanitize_text_field( wp_unslash( $_POST['token_hash'] ?? '' ) );
			if ( $hash ) {
				Goo1_MCP_OAuth::instance()->revoke_token( $hash );
				add_settings_error( 'goo1_mcp', 'token_revoked', 'Access token revoked.', 'success' );
			}
		}

		// Purge audit log.
		if ( isset( $_POST['goo1_mcp_purge_log'] ) && check_admin_referer( 'goo1_mcp_purge_log' ) ) {
			$settings = get_option( 'goo1_mcp_settings', array() );
			$days     = $settings['audit_log_retention_days'] ?? 30;
			$deleted  = Goo1_MCP_Audit_Log::instance()->purge( $days );
			add_settings_error( 'goo1_mcp', 'log_purged', sprintf( '%d audit log entries purged.', $deleted ), 'success' );
		}
	}

	public function render_keys_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/api-keys.php';
	}

	public function render_oauth_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/oauth-clients.php';
	}

	public function render_audit_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/audit-log.php';
	}

	public function render_settings_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/settings.php';
	}
}

Goo1_MCP_Admin::instance();
