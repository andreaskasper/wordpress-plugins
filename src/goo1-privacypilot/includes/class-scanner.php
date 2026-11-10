<?php
/**
 * Scanner: loads a handful of the site's own pages and looks for the
 * services from the catalogue (scripts, inline code, iframes), checks active
 * plugins, and finds leftovers of other consent / policy tools.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Scanner {

	const OPTION = 'goo1_pp_scan';

	public static function result() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? $r : array();
	}

	public static function urls() {
		// Our own document pages only show generated text; one of them is
		// enough to see the theme on an inner page. The rest of the budget
		// goes to recently changed content, where embeds and pixels live.
		$own = array();
		foreach ( (array) Goo1_PP_Settings::get( 'pages', array() ) as $langs ) {
			foreach ( (array) $langs as $id ) {
				if ( $id ) {
					$own[] = (int) $id;
				}
			}
		}
		$urls = array( home_url( '/' ) );
		$doc  = Goo1_PP_Documents::url( 'privacy', Goo1_PP_Questionnaire::admin_lang() );
		if ( $doc ) {
			$urls[] = $doc;
		}
		$recent = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 12,
				'orderby'        => 'modified',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => $own,
			)
		);
		foreach ( $recent as $id ) {
			$urls[] = get_permalink( $id );
		}
		return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, (int) apply_filters( 'goo1_pp_scan_url_limit', 10 ) );
	}


	/** Detect services in a chunk of HTML. Returns [service_id => via]. */
	public static function detect_in_html( $html ) {
		$found = array();
		if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $s ) {
				$src = preg_match( '/\ssrc\s*=\s*["\']([^"\']+)/i', $s[1], $mm ) ? html_entity_decode( $mm[1] ) : '';
				$svc = Goo1_PP_Blocker::identify_script( $src, $s[2] );
				if ( $svc ) {
					$found[ $svc['id'] ] = $src ? 'script' : 'inline';
				}
			}
		}
		if ( preg_match_all( '#<iframe\b([^>]*)>#i', $html, $frames ) ) {
			foreach ( $frames[1] as $attrs ) {
				if ( preg_match( '/\s(?:data-)?src\s*=\s*["\']([^"\']+)/i', $attrs, $mm ) ) {
					$svc = Goo1_PP_Blocker::identify_iframe( html_entity_decode( $mm[1] ) );
					if ( $svc ) {
						$found[ $svc['id'] ] = 'iframe';
					}
				}
			}
		}
		// Placeholders we created ourselves still tell us what is embedded.
		if ( preg_match_all( '/data-pp-service="([a-z0-9_\-]+)"/', $html, $pp ) ) {
			foreach ( $pp[1] as $id ) {
				if ( $id && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = 'blocked';
				}
			}
		}
		// Generic link/style hints (e.g. Google Fonts stylesheet).
		foreach ( Goo1_PP_Library::services() as $svc ) {
			foreach ( (array) ( $svc['patterns']['link'] ?? array() ) as $re ) {
				if ( @preg_match( '~<link[^>]+' . str_replace( '~', '\~', $re ) . '~i', $html ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$found[ $svc['id'] ] = 'link';
				}
			}
		}
		return $found;
	}

	public static function run() {
		$result = array(
			'time'       => time(),
			'urls'       => array(),
			'found'      => array(),
			'plugins'    => array(),
			'undeclared' => array(),
			'conflicts'  => array(),
			'termageddon'=> array(),
			'errors'     => array(),
			'cloudflare' => false,
		);

		foreach ( self::urls() as $url ) {
			$res = wp_remote_get(
				add_query_arg( 'pp-scan', wp_generate_password( 6, false ), $url ),
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'user-agent'  => 'Mozilla/5.0 (compatible; PrivacyPilot-Scanner/' . GOO1_PP_VERSION . ')',
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				$result['errors'][] = $url . ': ' . $res->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$result['urls'][ $url ] = $code;
			if ( wp_remote_retrieve_header( $res, 'cf-ray' ) ) {
				$result['cloudflare'] = true;
			}
			$html = (string) wp_remote_retrieve_body( $res );
			foreach ( self::detect_in_html( $html ) as $id => $via ) {
				$result['found'][ $id ]['urls'][] = $url;
				$result['found'][ $id ]['via']    = $via;
			}
			if ( preg_match( '#(policies|app)\.termageddon\.com#i', $html ) ) {
				$result['termageddon'][] = $url;
			}
		}

		// Active plugins mapped to services.
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( Goo1_PP_Library::services() as $svc ) {
			foreach ( (array) ( $svc['plugins'] ?? array() ) as $plugin ) {
				if ( in_array( $plugin, $active, true ) ) {
					$result['plugins'][ $svc['id'] ] = $plugin;
					$result['found'][ $svc['id'] ]['via']    = $result['found'][ $svc['id'] ]['via'] ?? 'plugin';
					$result['found'][ $svc['id'] ]['urls']   = $result['found'][ $svc['id'] ]['urls'] ?? array();
				}
			}
		}

		$selected = (array) Goo1_PP_Conditions::lookup( Goo1_PP_Questionnaire::context(), 'services.used' );
		foreach ( array_keys( $result['found'] ) as $id ) {
			$svc = Goo1_PP_Library::service( $id );
			if ( ! $svc ) {
				continue;
			}
			if ( 'cmp' === $svc['category'] ) {
				$result['conflicts'][] = $id;
				continue;
			}
			if ( ! in_array( $id, $selected, true ) ) {
				$result['undeclared'][] = $id;
			}
			$result['found'][ $id ]['urls'] = array_values( array_unique( $result['found'][ $id ]['urls'] ) );
		}

		if ( $result['cloudflare'] && ! get_option( 'goo1_pp_cloudflare_seen' ) ) {
			update_option( 'goo1_pp_cloudflare_seen', time(), true );
		}

		update_option( self::OPTION, $result, false );
		return $result;
	}
}
