<?php
/**
 * Wires everything together.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		add_action( 'plugins_loaded', array( 'Goo1_PP_Activator', 'maybe_upgrade' ), 5 );
		add_action( 'init', array( $this, 'textdomain' ) );

		Goo1_PP_Documents::boot();
		Goo1_PP_Consent::boot();
		Goo1_PP_Blocker::boot();
		Goo1_PP_Requests::boot();
		Goo1_PP_Site_Health::boot();

		add_action( Goo1_PP_Activator::CRON_DAILY, array( $this, 'daily' ) );
		add_action( Goo1_PP_Activator::CRON_WEEKLY, array( 'Goo1_PP_Scanner', 'run' ) );
		add_action( 'goo1_pp_library_updated', array( 'Goo1_PP_Publisher', 'sync' ) );
		// A plugin update brings a new bundled library: rebuild once.
		add_action( 'admin_init', array( $this, 'maybe_resync' ) );

		// The WordPress privacy page setting should keep pointing at our page.
		add_action( 'goo1_pp_document_published', array( 'Goo1_PP_Documents', 'sync_core_privacy_page' ) );
	}

	public function textdomain() {
		load_plugin_textdomain( 'goo1-privacypilot', false, dirname( GOO1_PP_BASENAME ) . '/languages' );
	}

	public function maybe_resync() {
		if ( get_option( 'goo1_pp_synced_library' ) === Goo1_PP_Library::version() ) {
			return;
		}
		if ( get_transient( 'goo1_pp_resync_lock' ) ) {
			return;
		}
		set_transient( 'goo1_pp_resync_lock', 1, 60 );
		try {
			Goo1_PP_Publisher::sync();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		delete_transient( 'goo1_pp_resync_lock' );
	}

	public function daily() {
		try {
			Goo1_PP_Library::refresh();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		try {
			if ( 'client' === Goo1_PP_Settings::get( 'mode' ) ) {
				Goo1_PP_Hub::sync();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		try {
			Goo1_PP_Publisher::sync();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		try {
			Goo1_PP_Consent::purge_old();
			Goo1_PP_Requests::purge_old();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
