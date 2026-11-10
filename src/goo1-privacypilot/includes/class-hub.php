<?php
/**
 * Hub / client: one site (the hub) keeps the questionnaire answers per legal
 * entity ("profile") and the approvals; connected client sites pull them with
 * a per-client token.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Hub {

	const PROFILES_OPTION = 'goo1_pp_hub_profiles';
	const CLIENTS_OPTION  = 'goo1_pp_hub_clients';
	const TOKEN_OPTION    = 'goo1_pp_hub_token';
	const CACHE_OPTION    = 'goo1_pp_hub_cache';
	const TOKEN_PREFIX    = 'pph_';

	/* ---------------------------------------------------------------- *
	 * Hub side
	 * ---------------------------------------------------------------- */

	public static function profiles() {
		$p = get_option( self::PROFILES_OPTION, array() );
		return is_array( $p ) ? $p : array();
	}

	public static function profile( $id ) {
		$all = self::profiles();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function own_profile() {
		return self::profile( (string) Goo1_PP_Settings::get( 'hub_profile_id', '' ) );
	}

	public static function create_profile( $name ) {
		$all = self::profiles();
		$id  = sanitize_key( sanitize_title( $name ) );
		if ( '' === $id ) {
			$id = 'profile';
		}
		$base = $id;
		$i    = 2;
		while ( isset( $all[ $id ] ) ) {
			$id = $base . '-' . $i++;
		}
		$all[ $id ] = array(
			'id'         => $id,
			'name'       => sanitize_text_field( $name ),
			'answers'    => array(),
			'approval'   => null,
			'updated_at' => time(),
		);
		update_option( self::PROFILES_OPTION, $all, false );
		return $id;
	}

	public static function delete_profile( $id ) {
		$all = self::profiles();
		unset( $all[ $id ] );
		update_option( self::PROFILES_OPTION, $all, false );
	}

	public static function save_profile_answers( $id, array $answers ) {
		$all = self::profiles();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$all[ $id ]['answers']    = $answers;
		$all[ $id ]['updated_at'] = time();
		update_option( self::PROFILES_OPTION, $all, false );
		return true;
	}

	/** Approve the profile's current answers at the current library version. */
	public static function approve_profile( $id ) {
		$all = self::profiles();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$all[ $id ]['approval'] = array(
			'library_version' => Goo1_PP_Library::version(),
			'entity_hash'     => Goo1_PP_Questionnaire::hash( (array) $all[ $id ]['answers'] ),
			'approved_at'     => time(),
			'approved_by'     => get_current_user_id(),
		);
		update_option( self::PROFILES_OPTION, $all, false );
		return true;
	}

	public static function clients() {
		$c = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Create a client token. Returns the plain token (shown once). */
	public static function create_client( $label, $profile_id ) {
		$token   = self::TOKEN_PREFIX . wp_generate_password( 40, false, false );
		$clients = self::clients();
		$id      = wp_generate_uuid4();
		$clients[ $id ] = array(
			'id'         => $id,
			'label'      => sanitize_text_field( $label ),
			'profile'    => sanitize_key( $profile_id ),
			'token_hash' => hash( 'sha256', $token ),
			'created'    => time(),
			'last_seen'  => 0,
			'site_url'   => '',
			'version'    => '',
		);
		update_option( self::CLIENTS_OPTION, $clients, false );
		return $token;
	}

	public static function revoke_client( $id ) {
		$clients = self::clients();
		unset( $clients[ $id ] );
		update_option( self::CLIENTS_OPTION, $clients, false );
	}

	public static function register_routes() {
		register_rest_route(
			'goo1-privacypilot/v1',
			'/hub/profile',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_profile' ),
				'permission_callback' => '__return_true', // token checked in the callback.
			)
		);
	}

	public static function rest_profile( WP_REST_Request $request ) {
		if ( 'hub' !== Goo1_PP_Settings::get( 'mode' ) ) {
			return new WP_Error( 'pp_not_hub', 'This site is not a PrivacyPilot hub.', array( 'status' => 404 ) );
		}
		$auth = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+(' . self::TOKEN_PREFIX . '[A-Za-z0-9]+)$/', trim( $auth ), $m ) ) {
			return new WP_Error( 'pp_unauthorized', 'Missing or malformed token.', array( 'status' => 401 ) );
		}
		$hash    = hash( 'sha256', $m[1] );
		$clients = self::clients();
		$found   = null;
		foreach ( $clients as $id => $client ) {
			if ( hash_equals( (string) $client['token_hash'], $hash ) ) {
				$found = $id;
				break;
			}
		}
		if ( null === $found ) {
			return new WP_Error( 'pp_unauthorized', 'Unknown token.', array( 'status' => 401 ) );
		}
		$profile = self::profile( $clients[ $found ]['profile'] );
		if ( ! $profile ) {
			return new WP_Error( 'pp_no_profile', 'The profile assigned to this client no longer exists.', array( 'status' => 404 ) );
		}

		// Remember when/what the client is (at most once a minute).
		if ( time() - (int) $clients[ $found ]['last_seen'] > 60 ) {
			$clients[ $found ]['last_seen'] = time();
			$clients[ $found ]['site_url']  = esc_url_raw( (string) $request->get_header( 'x-pp-site' ) );
			$clients[ $found ]['version']   = sanitize_text_field( (string) $request->get_header( 'x-pp-version' ) );
			update_option( self::CLIENTS_OPTION, $clients, false );
		}

		$response = new WP_REST_Response(
			array(
				'profile'         => array(
					'id'          => $profile['id'],
					'name'        => $profile['name'],
					'answers'     => (array) $profile['answers'],
					'entity_hash' => Goo1_PP_Questionnaire::hash( (array) $profile['answers'] ),
					'updated_at'  => (int) $profile['updated_at'],
				),
				'approval'        => $profile['approval'],
				'library_version' => Goo1_PP_Library::version(),
				'hub'             => home_url( '/' ),
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/* ---------------------------------------------------------------- *
	 * Client side
	 * ---------------------------------------------------------------- */

	public static function client_cache() {
		$c = get_option( self::CACHE_OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	public static function set_token( $token ) {
		update_option( self::TOKEN_OPTION, (string) $token, false );
	}

	public static function has_token() {
		return '' !== (string) get_option( self::TOKEN_OPTION, '' );
	}

	/** Pull profile + approval from the hub. */
	public static function sync() {
		$cache = self::client_cache();
		$url   = rtrim( (string) Goo1_PP_Settings::get( 'hub_url', '' ), '/' );
		$token = (string) get_option( self::TOKEN_OPTION, '' );
		$cache['last_attempt'] = time();

		try {
			if ( '' === $url || '' === $token ) {
				throw new \RuntimeException( 'hub URL or token missing' );
			}
			if ( 0 !== strpos( $url, 'https://' ) && ! preg_match( '#^http://(localhost|127\.0\.0\.1)#', $url ) ) {
				throw new \RuntimeException( 'the hub must be reached over https' );
			}
			$res = wp_remote_get(
				$url . '/wp-json/goo1-privacypilot/v1/hub/profile',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'X-PP-Site'     => home_url( '/' ),
						'X-PP-Version'  => GOO1_PP_VERSION,
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				throw new \RuntimeException( $res->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 !== $code || ! is_array( $data ) || empty( $data['profile'] ) ) {
				$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
				throw new \RuntimeException( 'hub answered: ' . $msg );
			}
			// Only keep answers for entity-scope questions.
			$entity_ids = wp_list_pluck( Goo1_PP_Questionnaire::questions( 'entity' ), 'id' );
			$answers    = array_intersect_key( (array) $data['profile']['answers'], array_flip( $entity_ids ) );
			$cache['profile'] = array(
				'id'          => sanitize_key( $data['profile']['id'] ),
				'name'        => sanitize_text_field( $data['profile']['name'] ),
				'answers'     => $answers,
				// Hash as computed by the hub over the full answer set; approvals refer to it.
				'entity_hash' => preg_replace( '/[^a-f0-9]/', '', (string) ( $data['profile']['entity_hash'] ?? '' ) ),
			);
			$cache['approval']        = is_array( $data['approval'] ) ? $data['approval'] : null;
			$cache['library_version'] = sanitize_text_field( (string) ( $data['library_version'] ?? '' ) );
			$cache['synced_at']       = time();
			$cache['error']           = '';
			update_option( self::CACHE_OPTION, $cache, false );
			return true;
		} catch ( \Throwable $e ) {
			$cache['error'] = $e->getMessage();
			update_option( self::CACHE_OPTION, $cache, false );
			return false;
		}
	}
}
