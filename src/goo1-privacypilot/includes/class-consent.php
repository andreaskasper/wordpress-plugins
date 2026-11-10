<?php
/**
 * Consent banner (front end config), Google Consent Mode v2 defaults, consent
 * log, geo endpoint and the admin audit mode.
 *
 * Nothing that identifies the visitor is stored: no IP address, no user
 * agent. The log keeps a random consent id (also in the visitor's cookie),
 * the choice, the rule that applied and the versions shown.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Consent {

	const CATEGORIES = array( 'necessary', 'preferences', 'analytics', 'marketing', 'media' );

	/** EU/EEA + UK + CH: countries with opt-in rules. */
	const OPTIN_COUNTRIES = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH' );

	public static function boot() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function active() {
		if ( ! Goo1_PP_Settings::get( 'banner_enabled' ) ) {
			return self::audit_requested();
		}
		if ( Goo1_PP_Settings::get( 'exempt_admins' ) && current_user_can( 'manage_options' ) && ! self::audit_requested() ) {
			return false;
		}
		return true;
	}

	public static function audit_requested() {
		return isset( $_GET['pp-audit'] ) && is_user_logged_in() && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Services the banner has to know about (selected or detected, not necessary). */
	public static function services_for_banner( $lang ) {
		$ctx      = Goo1_PP_Questionnaire::context();
		$selected = (array) Goo1_PP_Conditions::lookup( $ctx, 'services.used' );
		$scan     = Goo1_PP_Scanner::result();
		$detected = isset( $scan['found'] ) ? array_keys( (array) $scan['found'] ) : array();
		$ids      = array_unique( array_merge( $selected, $detected ) );
		$out      = array();
		foreach ( Goo1_PP_Library::services() as $svc ) {
			if ( ! in_array( $svc['id'], $ids, true ) || 'necessary' === $svc['category'] || 'cmp' === $svc['category'] ) {
				continue;
			}
			$out[] = array(
				'id'       => $svc['id'],
				'name'     => $svc['name'],
				'provider' => $svc['provider'],
				'category' => $svc['category'],
				'purpose'  => Goo1_PP_Library::t( $svc['purpose'] ?? '', $lang ),
				'privacy'  => $svc['privacy_url'] ?? '',
			);
		}
		return $out;
	}

	public static function banner_version( array $services ) {
		$ids = wp_list_pluck( $services, 'id' );
		sort( $ids );
		return substr( md5( implode( ',', $ids ) . '|' . Goo1_PP_Settings::get( 'rule_eu' ) . Goo1_PP_Settings::get( 'rule_us' ) . Goo1_PP_Settings::get( 'rule_other' ) . Goo1_PP_Settings::get( 'geo_mode' ) ), 0, 12 );
	}

	private static function use_cloudflare() {
		$cf = Goo1_PP_Settings::get( 'cloudflare', 'auto' );
		if ( 'yes' === $cf ) {
			return true;
		}
		if ( 'no' === $cf ) {
			return false;
		}
		return ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) || (bool) get_option( 'goo1_pp_cloudflare_seen' );
	}

	public static function config( $lang ) {
		$ui       = Goo1_PP_Library::ui( $lang );
		$services = self::services_for_banner( $lang );
		$docs     = array();
		foreach ( array( 'privacy', 'cookies', 'choices', 'impressum' ) as $d ) {
			$docs[ $d ] = Goo1_PP_Documents::url( $d, $lang );
		}
		$cats = array();
		foreach ( self::CATEGORIES as $c ) {
			$cats[] = array(
				'id'          => $c,
				'label'       => $ui['categories'][ $c ]['label'] ?? ucfirst( $c ),
				'description' => $ui['categories'][ $c ]['description'] ?? '',
				'locked'      => 'necessary' === $c,
				'used'        => 'necessary' === $c || in_array( $c, wp_list_pluck( $services, 'category' ), true ),
			);
		}
		$cfg = array(
			'rest'           => esc_url_raw( rest_url( 'goo1-privacypilot/v1/' ) ),
			'lang'           => $lang,
			'categories'     => $cats,
			'services'       => $services,
			'texts'          => $ui['banner'] ?? array(),
			'placeholder'    => $ui['placeholder'] ?? array(),
			'docs'           => $docs,
			'geo'            => array(
				'mode'       => Goo1_PP_Settings::get( 'geo_mode', 'auto' ),
				'cloudflare' => self::use_cloudflare(),
				'optin'      => self::OPTIN_COUNTRIES,
				'rules'      => array(
					'eu'    => Goo1_PP_Settings::get( 'rule_eu', 'optin' ),
					'us'    => Goo1_PP_Settings::get( 'rule_us', 'optout' ),
					'other' => Goo1_PP_Settings::get( 'rule_other', 'optin' ),
				),
			),
			'gpc'            => (bool) Goo1_PP_Settings::get( 'respect_gpc', true ),
			'consentMode'    => (bool) Goo1_PP_Settings::get( 'consent_mode', true ),
			'days'           => max( 1, (int) Goo1_PP_Settings::get( 'consent_days', 365 ) ),
			'version'        => self::banner_version( $services ),
			'libraryVersion' => Goo1_PP_Library::version(),
			'position'       => Goo1_PP_Settings::get( 'banner_position', 'bottom' ),
			'enabled'        => (bool) Goo1_PP_Settings::get( 'banner_enabled' ),
		);
		if ( self::audit_requested() ) {
			$cfg['audit'] = array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'known' => self::known_cookie_patterns(),
			);
		}
		return apply_filters( 'goo1_pp_banner_config', $cfg, $lang );
	}

	/** Consent Mode defaults must run before any Google tag. */
	public static function head() {
		if ( ! self::active() ) {
			return;
		}
		$s = Goo1_PP_Settings::all();
		echo "<style id=\"goo1-pp-vars\">:root{--pp-primary:" . esc_html( sanitize_hex_color( $s['color_primary'] ) ?: '#1f4fd1' ) . ';--pp-bg:' . esc_html( sanitize_hex_color( $s['color_background'] ) ?: '#ffffff' ) . ';--pp-text:' . esc_html( sanitize_hex_color( $s['color_text'] ) ?: '#16181d' ) . "}</style>\n";
		if ( ! empty( $s['consent_mode'] ) ) {
			// Denied by default; the banner script sends the update. Visitors who
			// already chose get their stored choice applied right here, before tags load.
			echo "<script id=\"goo1-pp-consent-mode\">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
				. "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});"
				. "gtag('set','ads_data_redaction',true);"
				. "(function(){try{var m=document.cookie.match(/(?:^|; )pp_consent=([^;]+)/);if(!m)return;var c=JSON.parse(decodeURIComponent(m[1]));var g=function(k){return c.c&&c.c.indexOf(k)>-1?'granted':'denied';};"
				. "gtag('consent','update',{analytics_storage:g('analytics'),ad_storage:g('marketing'),ad_user_data:g('marketing'),ad_personalization:g('marketing'),functionality_storage:g('preferences'),personalization_storage:g('preferences')});}catch(e){}})();</script>\n";
		}
	}

	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		$lang = Goo1_PP_Documents::current_lang();
		wp_enqueue_style( 'goo1-pp-banner', GOO1_PP_URL . 'public/banner.css', array(), goo1_pp_asset_ver( 'public/banner.css' ) );
		wp_enqueue_script( 'goo1-pp-banner', GOO1_PP_URL . 'public/banner.js', array(), goo1_pp_asset_ver( 'public/banner.js' ), false );
		wp_add_inline_script( 'goo1-pp-banner', 'window.goo1PpConfig=' . wp_json_encode( self::config( $lang ) ) . ';', 'before' );
	}

	public static function routes() {
		register_rest_route(
			'goo1-privacypilot/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_consent' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'goo1-privacypilot/v1',
			'/geo',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_geo' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'goo1-privacypilot/v1',
			'/audit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_audit' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		Goo1_PP_Requests::routes();
		Goo1_PP_Hub::register_routes();
	}

	/** Simple per-IP rate limit; only a salted hash of the IP is used as transient key. */
	public static function rate_limited( $bucket, $max, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'goo1_pp_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $window );
		return false;
	}

	public static function rest_consent( WP_REST_Request $request ) {
		if ( self::rate_limited( 'consent', 60, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'pp_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		$p  = $request->get_json_params();
		$p  = is_array( $p ) ? $p : $request->get_body_params();
		$id = isset( $p['id'] ) ? strtolower( (string) $p['id'] ) : '';
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id ) ) {
			return new WP_Error( 'pp_invalid', 'Invalid consent id.', array( 'status' => 400 ) );
		}
		$cats     = array_values( array_intersect( (array) ( $p['categories'] ?? array() ), self::CATEGORIES ) );
		$known    = wp_list_pluck( Goo1_PP_Library::services(), 'id' );
		$services = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['services'] ?? array() ) ), $known ) );
		$rule     = in_array( $p['rule'] ?? '', array( 'optin', 'optout', 'gpc' ), true ) ? $p['rule'] : '';
		$action   = in_array( $p['action'] ?? '', array( 'accept_all', 'reject_all', 'custom', 'gpc', 'implicit', 'ok', 'withdraw' ), true ) ? $p['action'] : '';
		$country  = preg_match( '/^[A-Z]{2}$/', (string) ( $p['country'] ?? '' ) ) ? $p['country'] : '';

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'goo1_pp_consent_log',
			array(
				'consent_id'      => $id,
				'created_at'      => current_time( 'mysql', true ),
				'categories'      => implode( ',', $cats ),
				'services'        => implode( ',', $services ),
				'rule'            => $rule,
				'action'          => $action,
				'country'         => $country,
				'banner_version'  => substr( sanitize_key( (string) ( $p['banner_version'] ?? '' ) ), 0, 32 ),
				'library_version' => substr( sanitize_text_field( (string) ( $p['library_version'] ?? '' ) ), 0, 32 ),
			)
		);
		$res = new WP_REST_Response( array( 'ok' => true ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function rest_geo() {
		$country = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || 'XX' === $country || 'T1' === $country ) {
			$country = '';
		} elseif ( ! get_option( 'goo1_pp_cloudflare_seen' ) ) {
			update_option( 'goo1_pp_cloudflare_seen', time(), true );
		}
		$res = new WP_REST_Response( array( 'country' => $country ) );
		$res->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $res;
	}

	/** Cookie name patterns we know: necessary + every service in the catalogue. */
	public static function known_cookie_patterns() {
		$known = array(
			array( 'pattern' => '^pp_consent$', 'category' => 'necessary', 'service' => 'PrivacyPilot' ),
			array( 'pattern' => '^wordpress_', 'category' => 'necessary', 'service' => 'WordPress' ),
			array( 'pattern' => '^wp-settings-', 'category' => 'necessary', 'service' => 'WordPress' ),
			array( 'pattern' => '^wp_lang$', 'category' => 'necessary', 'service' => 'WordPress' ),
			array( 'pattern' => '^comment_author', 'category' => 'preferences', 'service' => 'WordPress' ),
			array( 'pattern' => '^(__cf_bm|cf_clearance|__cflb|_cfuvid)$', 'category' => 'necessary', 'service' => 'Cloudflare' ),
		);
		foreach ( Goo1_PP_Library::services() as $svc ) {
			foreach ( (array) ( $svc['cookies'] ?? array() ) as $c ) {
				$known[] = array(
					'pattern'  => $c['pattern'] ?? '^' . preg_quote( $c['name'], '/' ) . '$',
					'category' => $svc['category'],
					'service'  => $svc['name'],
				);
			}
		}
		return $known;
	}

	public static function rest_audit( WP_REST_Request $request ) {
		$p       = $request->get_json_params();
		$cookies = array_slice( array_map( 'sanitize_text_field', (array) ( $p['cookies'] ?? array() ) ), 0, 200 );
		$storage = array_slice( array_map( 'sanitize_text_field', (array) ( $p['storage'] ?? array() ) ), 0, 200 );
		$url     = esc_url_raw( (string) ( $p['url'] ?? '' ) );
		$known   = self::known_cookie_patterns();
		$items   = array();
		foreach ( $cookies as $name ) {
			$match = null;
			foreach ( $known as $k ) {
				if ( @preg_match( '/' . str_replace( '/', '\/', $k['pattern'] ) . '/', $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$match = $k;
					break;
				}
			}
			$items[] = array(
				'name'     => $name,
				'category' => $match ? $match['category'] : 'unknown',
				'service'  => $match ? $match['service'] : '',
			);
		}
		$problems = array_values(
			array_filter(
				$items,
				function ( $i ) {
					return ! in_array( $i['category'], array( 'necessary' ), true );
				}
			)
		);
		update_option(
			'goo1_pp_audit',
			array(
				'time'     => time(),
				'url'      => $url,
				'cookies'  => $items,
				'storage'  => $storage,
				'problems' => $problems,
			),
			false
		);
		return array( 'ok' => true, 'problems' => count( $problems ) );
	}

	public static function purge_old() {
		global $wpdb;
		$days = max( 30, (int) Goo1_PP_Settings::get( 'log_retention_days', 1095 ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'goo1_pp_consent_log WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
