<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Activator {

	const CRON_HOOK = 'goo1_mcp_daily_maintenance';

	/**
	 * Default settings. New keys are merged into existing installs on upgrade.
	 */
	public static function default_settings() {
		return array(
			'default_rate_limit'       => 60,
			'audit_log_retention_days' => 30,
			'db_write_enabled'         => false,
			'grants_enabled'           => false,
			'oauth_enabled'            => true,
			'oauth_dcr_enabled'        => true,
			'oauth_token_ttl'          => 3600,
			'oauth_refresh_ttl_days'   => 90,
			'oauth_default_scope'      => 'read',
			'seal_export_targets'      => '',
		);
	}

	public static function activate() {
		self::install();
	}

	/**
	 * Create/upgrade the schema and options. Idempotent; runs on activation
	 * and whenever the stored db version differs from the plugin version
	 * (plugin updates do not fire the activation hook).
	 */
	public static function install() {
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
			flags varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY api_key_label (api_key_label)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		if ( class_exists( 'Goo1_MCP_Backups' ) ) {
			dbDelta( Goo1_MCP_Backups::schema_sql( $charset_collate ) );
		}

		if ( false === get_option( 'goo1_mcp_api_keys' ) ) {
			add_option( 'goo1_mcp_api_keys', array(), '', 'yes' );
		}

		$settings = get_option( 'goo1_mcp_settings' );
		if ( false === $settings ) {
			add_option( 'goo1_mcp_settings', self::default_settings(), '', 'yes' );
		} else {
			update_option( 'goo1_mcp_settings', array_merge( self::default_settings(), (array) $settings ) );
		}

		// Pre-create every store so none can be planted as a "new" row.
		foreach ( array( 'goo1_mcp_oauth_clients', 'goo1_mcp_oauth_refresh', 'goo1_mcp_oauth_token_index', 'goo1_mcp_oauth_rt_used', 'goo1_mcp_seal_requests' ) as $opt ) {
			if ( false === get_option( $opt ) ) {
				add_option( $opt, array(), '', 'no' );
			}
		}

		// Refresh tokens issued before 1.6 never expired: give them the
		// configured lifetime starting now instead of breaking connectors.
		$refresh = get_option( 'goo1_mcp_oauth_refresh', array() );
		if ( is_array( $refresh ) ) {
			$changed = false;
			$ttl     = DAY_IN_SECONDS * (int) self::default_settings()['oauth_refresh_ttl_days'];
			foreach ( $refresh as $h => $r ) {
				if ( empty( $r['expires_at'] ) ) {
					$refresh[ $h ]['expires_at'] = time() + $ttl;
					$changed                     = true;
				}
				if ( empty( $r['family'] ) ) {
					$refresh[ $h ]['family'] = substr( $h, 0, 16 );
					$changed                 = true;
				}
			}
			if ( $changed ) {
				update_option( 'goo1_mcp_oauth_refresh', $refresh, false );
			}
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		update_option( 'goo1_mcp_db_version', GOO1_MCP_VERSION );
	}

	/**
	 * Run install() after a plugin update.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'goo1_mcp_db_version' ) !== GOO1_MCP_VERSION ) {
			self::install();
		}
	}

	/**
	 * Daily maintenance: audit-log retention, OAuth housekeeping.
	 */
	public static function daily_maintenance() {
		$settings = wp_parse_args( (array) get_option( 'goo1_mcp_settings', array() ), self::default_settings() );
		Goo1_MCP_Audit_Log::instance()->purge( max( 1, (int) $settings['audit_log_retention_days'] ) );
		Goo1_MCP_OAuth::instance()->housekeeping();
		if ( class_exists( 'Goo1_MCP_Seal' ) ) {
			Goo1_MCP_Seal::prune();
		}
	}
}

class Goo1_MCP_Deactivator {

	public static function deactivate() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_goo1_mcp_rl_%' OR option_name LIKE '_transient_timeout_goo1_mcp_rl_%'"
		);
		wp_clear_scheduled_hook( Goo1_MCP_Activator::CRON_HOOK );
	}
}
