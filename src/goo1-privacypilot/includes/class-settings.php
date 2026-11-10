<?php
/**
 * Plugin settings (one option, merged with defaults).
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Settings {

	const OPTION = 'goo1_pp_settings';

	const FEED_URL = 'https://raw.githubusercontent.com/andreaskasper/wordpress-plugins/main/distmeta/privacypilot/library.json';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// standalone | hub | client.
			'mode'                   => 'standalone',
			'languages'              => array( 'en' ),
			'default_language'       => 'en',
			// Documents this site publishes (impressum only makes sense for DE).
			'documents'              => array( 'privacy', 'cookies', 'terms', 'choices' ),
			// [doc][lang] => page id.
			'pages'                  => array(),
			'footer_links'           => false,

			// Banner and blocking stay off until the site goes live (shadow mode).
			'banner_enabled'         => false,
			'blocking_enabled'       => false,
			'remove_other_cmps'      => false,
			'exempt_admins'          => false,
			// auto = by country when it can be determined, strict = opt-in everywhere.
			'geo_mode'               => 'auto',
			// auto | yes | no.
			'cloudflare'             => 'auto',
			'rule_eu'                => 'optin',
			'rule_us'                => 'optout',
			'rule_other'             => 'optin',
			'respect_gpc'            => true,
			'consent_mode'           => true,
			'consent_days'           => 365,
			'banner_position'        => 'bottom',
			'color_primary'          => '#1f4fd1',
			'color_background'       => '#ffffff',
			'color_text'             => '#16181d',

			// Library feed.
			'feed_url'               => self::FEED_URL,
			'feed_enabled'           => true,
			'auto_publish_deadline'  => true,

			// Requests and retention.
			'request_recipient'      => get_option( 'admin_email' ),
			'request_confirmation'   => true,
			'log_retention_days'     => 1095,
			'request_retention_days' => 1095,

			// Hub client.
			'hub_url'                => '',
			'hub_profile_id'         => '',

			'delete_data_on_uninstall' => false,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ) {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$merged  = array_merge( $current, $values );
		update_option( self::OPTION, $merged, true );
		self::$cache = null;
	}

	/** Languages the site publishes, always at least one. */
	public static function languages() {
		$langs = (array) self::get( 'languages', array( 'en' ) );
		$langs = array_values( array_intersect( $langs, array( 'en', 'de' ) ) );
		return $langs ? $langs : array( 'en' );
	}

	public static function documents() {
		return (array) self::get( 'documents', array() );
	}

	public static function page_id( $doc, $lang ) {
		$pages = (array) self::get( 'pages', array() );
		return isset( $pages[ $doc ][ $lang ] ) ? (int) $pages[ $doc ][ $lang ] : 0;
	}

	public static function set_page_id( $doc, $lang, $page_id ) {
		$pages                  = (array) self::get( 'pages', array() );
		$pages[ $doc ][ $lang ] = (int) $page_id;
		self::update( array( 'pages' => $pages ) );
	}

	public static function flush_cache() {
		self::$cache = null;
	}
}
