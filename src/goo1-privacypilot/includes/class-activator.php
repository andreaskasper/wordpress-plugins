<?php
/**
 * Install / upgrade / deactivate.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Activator {

	const CRON_DAILY  = 'goo1_pp_daily';
	const CRON_WEEKLY = 'goo1_pp_weekly_scan';

	public static function activate() {
		self::install();
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_DAILY );
		wp_clear_scheduled_hook( self::CRON_WEEKLY );
	}

	/** Runs on plugins_loaded: activation hooks do not fire on updates. */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'goo1_pp_db_version', 0 ) < GOO1_PP_DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY ) || ! wp_next_scheduled( self::CRON_WEEKLY ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_DAILY );
		}
		if ( ! wp_next_scheduled( self::CRON_WEEKLY ) ) {
			wp_schedule_event( time() + 900, 'weekly', self::CRON_WEEKLY );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$versions = $wpdb->prefix . 'goo1_pp_versions';
		$consent  = $wpdb->prefix . 'goo1_pp_consent_log';
		$requests = $wpdb->prefix . 'goo1_pp_requests';

		dbDelta(
			"CREATE TABLE {$versions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				doc varchar(32) NOT NULL,
				lang varchar(8) NOT NULL,
				version_no int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'draft',
				html longtext NOT NULL,
				manifest longtext NOT NULL,
				content_hash char(64) NOT NULL DEFAULT '',
				library_version varchar(32) NOT NULL DEFAULT '',
				entity_hash char(64) NOT NULL DEFAULT '',
				site_hash char(64) NOT NULL DEFAULT '',
				change_type varchar(16) NOT NULL DEFAULT 'material',
				effective_date date DEFAULT NULL,
				created_at datetime NOT NULL,
				published_at datetime DEFAULT NULL,
				published_by bigint(20) unsigned NOT NULL DEFAULT 0,
				note text NULL,
				PRIMARY KEY  (id),
				KEY doc_lang_status (doc, lang, status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$consent} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id char(36) NOT NULL,
				created_at datetime NOT NULL,
				categories varchar(191) NOT NULL DEFAULT '',
				services text NULL,
				rule varchar(16) NOT NULL DEFAULT '',
				action varchar(16) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				banner_version varchar(32) NOT NULL DEFAULT '',
				library_version varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$requests} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				due_at datetime DEFAULT NULL,
				type varchar(32) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'new',
				name varchar(191) NOT NULL DEFAULT '',
				email varchar(191) NOT NULL DEFAULT '',
				region varchar(16) NOT NULL DEFAULT '',
				lang varchar(8) NOT NULL DEFAULT '',
				message text NULL,
				note text NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		if ( false === get_option( Goo1_PP_Settings::OPTION, false ) ) {
			$defaults = Goo1_PP_Settings::defaults();
			// German sites get both languages by default.
			if ( 0 === strpos( get_locale(), 'de' ) ) {
				$defaults['languages']        = array( 'de', 'en' );
				$defaults['default_language'] = 'de';
				$defaults['documents'][]      = 'impressum';
			}
			add_option( Goo1_PP_Settings::OPTION, $defaults, '', true );
		}

		// Stores that must never be autoloaded (large / rarely needed).
		foreach ( array( 'goo1_pp_library', 'goo1_pp_scan', 'goo1_pp_audit', 'goo1_pp_hub_cache', 'goo1_pp_hub_profiles', 'goo1_pp_hub_clients', 'goo1_pp_hub_token' ) as $opt ) {
			if ( false === get_option( $opt, false ) ) {
				add_option( $opt, '', '', false );
			}
		}

		update_option( 'goo1_pp_db_version', GOO1_PP_DB_VERSION, true );
	}
}
