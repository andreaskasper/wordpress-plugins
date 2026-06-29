<?php
/**
 * Fired when the plugin is uninstalled.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove custom table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}goo1_mcp_audit_log" );

// Remove options.
delete_option( 'goo1_mcp_api_keys' );
delete_option( 'goo1_mcp_settings' );
delete_option( 'goo1_mcp_db_version' );
delete_option( 'goo1_mcp_oauth_clients' );
delete_option( 'goo1_mcp_oauth_refresh' );
delete_option( 'goo1_mcp_oauth_token_index' );

// Remove transients.
$wpdb->query(
	"DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_goo1_mcp_%' OR option_name LIKE '_transient_timeout_goo1_mcp_%'"
);
