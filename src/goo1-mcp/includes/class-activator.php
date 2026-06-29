<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Activator {

	public static function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'goo1_mcp_audit_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			api_key_label varchar(100) NOT NULL DEFAULT '',
			endpoint varchar(255) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT '',
			request_summary text,
			response_code smallint(5) unsigned NOT NULL DEFAULT 0,
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY api_key_label (api_key_label)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Set default options if not exist.
		if ( false === get_option( 'goo1_mcp_api_keys' ) ) {
			add_option( 'goo1_mcp_api_keys', array(), '', 'yes' );
		}

		if ( false === get_option( 'goo1_mcp_settings' ) ) {
			add_option( 'goo1_mcp_settings', array(
				'default_rate_limit'       => 60,
				'audit_log_retention_days' => 30,
				'db_write_enabled'         => false,
				'oauth_enabled'            => true,
				'oauth_dcr_enabled'        => true,
				'oauth_token_ttl'          => 3600,
				'oauth_default_scope'      => 'read',
			), '', 'yes' );
		} else {
			// Upgrade path: ensure the OAuth settings exist on older installs.
			$settings = (array) get_option( 'goo1_mcp_settings', array() );
			$settings = array_merge( array(
				'oauth_enabled'       => true,
				'oauth_dcr_enabled'   => true,
				'oauth_token_ttl'     => 3600,
				'oauth_default_scope' => 'read',
			), $settings );
			update_option( 'goo1_mcp_settings', $settings );
		}

		// OAuth client/token stores.
		if ( false === get_option( 'goo1_mcp_oauth_clients' ) ) {
			add_option( 'goo1_mcp_oauth_clients', array(), '', 'no' );
		}
		if ( false === get_option( 'goo1_mcp_oauth_refresh' ) ) {
			add_option( 'goo1_mcp_oauth_refresh', array(), '', 'no' );
		}
		if ( false === get_option( 'goo1_mcp_oauth_token_index' ) ) {
			add_option( 'goo1_mcp_oauth_token_index', array(), '', 'no' );
		}

		update_option( 'goo1_mcp_db_version', GOO1_MCP_VERSION );
	}
}

class Goo1_MCP_Deactivator {

	public static function deactivate() {
		// Clean up transients.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_goo1_mcp_rl_%' OR option_name LIKE '_transient_timeout_goo1_mcp_rl_%'"
		);
	}
}
