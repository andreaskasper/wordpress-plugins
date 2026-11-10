<?php
/**
 * Uninstall: data is only removed when the admin asked for it, because the
 * consent log is the site owner's proof of consent.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$goo1_pp_settings = get_option( 'goo1_pp_settings', array() );

wp_clear_scheduled_hook( 'goo1_pp_daily' );
wp_clear_scheduled_hook( 'goo1_pp_weekly_scan' );

if ( empty( $goo1_pp_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'goo1_pp_versions', 'goo1_pp_consent_log', 'goo1_pp_requests' ) as $goo1_pp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $goo1_pp_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'goo1\\_pp\\_%' OR option_name LIKE '\\_transient\\_goo1\\_pp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_goo1\\_pp\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
