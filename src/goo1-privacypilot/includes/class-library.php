<?php
/**
 * Clause library: bundled copy (trusted, ships with the plugin) plus an
 * optional live feed that is only accepted with a valid Ed25519 signature and
 * a version newer than what the site already has.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Library {

	const SUPPORTED_SCHEMA = 1;
	const FEED_OPTION      = 'goo1_pp_library';
	const STATE_OPTION     = 'goo1_pp_library_state';

	/** @var array|null */
	private static $lib = null;

	/** @var array|null */
	private static $bundled = null;

	public static function bundled_path() {
		return GOO1_PP_DIR . 'library/library.json';
	}

	public static function bundled() {
		if ( null === self::$bundled ) {
			self::$bundled = array();
			$path          = self::bundled_path();
			if ( is_readable( $path ) ) {
				$data = json_decode( (string) file_get_contents( $path ), true );
				if ( is_array( $data ) && self::valid_structure( $data ) ) {
					self::$bundled = $data;
				}
			}
		}
		return self::$bundled;
	}

	public static function feed() {
		$raw = get_option( self::FEED_OPTION, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return ( is_array( $data ) && self::valid_structure( $data ) ) ? $data : array();
	}

	/** The library in use: the newer of bundled and (verified) feed. */
	public static function get() {
		if ( null === self::$lib ) {
			$bundled = self::bundled();
			$feed    = Goo1_PP_Settings::get( 'feed_enabled', true ) ? self::feed() : array();
			if ( $feed && ( ! $bundled || version_compare( $feed['version'], $bundled['version'], '>' ) ) ) {
				self::$lib           = $feed;
				self::$lib['source'] = 'feed';
			} elseif ( $bundled ) {
				self::$lib           = $bundled;
				self::$lib['source'] = 'bundled';
			} else {
				self::$lib = array(
					'version'   => '0',
					'documents' => array(),
					'questions' => array(),
					'services'  => array(),
					'clauses'   => array(),
					'ui'        => array(),
					'source'    => 'none',
				);
			}
		}
		return self::$lib;
	}

	public static function reset() {
		self::$lib     = null;
		self::$bundled = null;
	}

	public static function version() {
		$lib = self::get();
		return (string) $lib['version'];
	}

	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function valid_structure( array $data ) {
		return isset( $data['version'], $data['documents'], $data['questions'], $data['services'], $data['clauses'] )
			&& (int) ( $data['schema'] ?? 0 ) === self::SUPPORTED_SCHEMA
			&& is_array( $data['clauses'] );
	}

	/**
	 * Fetch the live feed. Returns true when a newer verified library was
	 * stored, false otherwise (reason in state['last_error']).
	 */
	public static function refresh() {
		$state                = self::state();
		$state['last_check']  = time();
		$state['last_error']  = '';
		$url                  = (string) Goo1_PP_Settings::get( 'feed_url', Goo1_PP_Settings::FEED_URL );

		try {
			if ( ! Goo1_PP_Settings::get( 'feed_enabled', true ) ) {
				throw new \RuntimeException( 'feed disabled in settings' );
			}
			if ( ! Goo1_PP_Signature::has_keys() ) {
				throw new \RuntimeException( 'no public key configured - feed updates are disabled, the bundled library is used' );
			}
			$args = array(
				'timeout'    => 15,
				'user-agent' => 'PrivacyPilot/' . GOO1_PP_VERSION . '; ' . home_url( '/' ),
			);
			$res  = wp_remote_get( $url, $args );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				throw new \RuntimeException( 'feed not reachable: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
			}
			$body = (string) wp_remote_retrieve_body( $res );
			$sres = wp_remote_get( $url . '.sig', $args );
			if ( is_wp_error( $sres ) || 200 !== (int) wp_remote_retrieve_response_code( $sres ) ) {
				throw new \RuntimeException( 'signature not reachable (feed unsigned?)' );
			}
			if ( ! Goo1_PP_Signature::verify( $body, (string) wp_remote_retrieve_body( $sres ) ) ) {
				throw new \RuntimeException( 'invalid signature - feed rejected' );
			}
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || ! self::valid_structure( $data ) ) {
				throw new \RuntimeException( 'feed has an unsupported format (schema ' . (int) ( $data['schema'] ?? 0 ) . ')' );
			}
			$current = self::get();
			// Never go backwards: an old (still validly signed) feed must not
			// replace a newer library.
			if ( ! version_compare( $data['version'], $current['version'], '>' ) ) {
				$state['last_result'] = 'up to date (' . $data['version'] . ')';
				update_option( self::STATE_OPTION, $state, false );
				return false;
			}
			update_option( self::FEED_OPTION, $body, false );
			$state['last_result'] = 'updated to ' . $data['version'];
			$state['fetched_at']  = time();
			update_option( self::STATE_OPTION, $state, false );
			self::reset();
			do_action( 'goo1_pp_library_updated', $data['version'] );
			return true;
		} catch ( \Throwable $e ) {
			$state['last_error'] = $e->getMessage();
			update_option( self::STATE_OPTION, $state, false );
			return false;
		}
	}

	/* ---------------------------------------------------------------- *
	 * Accessors
	 * ---------------------------------------------------------------- */

	public static function documents() {
		$lib = self::get();
		return (array) $lib['documents'];
	}

	public static function document( $id ) {
		foreach ( self::documents() as $doc ) {
			if ( $doc['id'] === $id ) {
				return $doc;
			}
		}
		return null;
	}

	public static function questions() {
		$lib = self::get();
		return (array) $lib['questions'];
	}

	public static function services() {
		$lib = self::get();
		return (array) $lib['services'];
	}

	public static function service( $id ) {
		foreach ( self::services() as $svc ) {
			if ( $svc['id'] === $id ) {
				return $svc;
			}
		}
		return null;
	}

	public static function clauses( $doc, $lang ) {
		$lib = self::get();
		$out = array();
		foreach ( (array) $lib['clauses'] as $clause ) {
			if ( $clause['doc'] === $doc && $clause['lang'] === $lang ) {
				$out[] = $clause;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return ( $a['order'] <=> $b['order'] ) ?: strcmp( $a['id'], $b['id'] );
			}
		);
		return $out;
	}

	/** UI strings (banner, form, placeholders) for a language. */
	public static function ui( $lang ) {
		$lib = self::get();
		$ui  = isset( $lib['ui'] ) && is_array( $lib['ui'] ) ? $lib['ui'] : array();
		if ( isset( $ui[ $lang ] ) ) {
			return $ui[ $lang ];
		}
		return isset( $ui['en'] ) ? $ui['en'] : array();
	}

	/** Translate a {en:..., de:...} map. */
	public static function t( $map, $lang ) {
		if ( ! is_array( $map ) ) {
			return (string) $map;
		}
		if ( isset( $map[ $lang ] ) ) {
			return (string) $map[ $lang ];
		}
		return isset( $map['en'] ) ? (string) $map['en'] : (string) reset( $map );
	}
}
