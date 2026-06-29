<?php
/**
 * OAuth 2.1 Authorization Server for the goo1 MCP Bridge.
 *
 * Implements the pieces an MCP remote connector (e.g. Claude Desktop / claude.ai)
 * needs to authenticate:
 *   - Protected Resource Metadata     (RFC 9728)  /.well-known/oauth-protected-resource
 *   - Authorization Server Metadata   (RFC 8414)  /.well-known/oauth-authorization-server
 *   - Dynamic Client Registration     (RFC 7591)  /goo1-mcp-oauth/register
 *   - Authorization Code flow + PKCE  (RFC 7636)  /goo1-mcp-oauth/authorize
 *   - Token + Refresh                             /goo1-mcp-oauth/token
 *
 * Discovery is served from TWO locations so it survives server hardening that
 * 403-blocks the root /.well-known/ directory (a common "hide dotfiles" rule,
 * which also breaks ACME). The issuer is therefore path-based (…/goo1-mcp-oauth)
 * so the OIDC-style "well-known appended to the issuer path" discovery URL —
 * /goo1-mcp-oauth/.well-known/oauth-authorization-server — is reachable, while
 * the canonical root /.well-known/ handlers keep working once hardening allows
 * them. The WWW-Authenticate resource_metadata pointer (which the client uses
 * verbatim, RFC 9728) points at the non-blocked path too.
 *
 * Issued access tokens are plain Bearer tokens and are validated by
 * Goo1_MCP_Auth alongside the static API keys, so every existing REST
 * endpoint accepts them transparently.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_OAuth {

	const CLIENTS_OPTION  = 'goo1_mcp_oauth_clients';
	const REFRESH_OPTION  = 'goo1_mcp_oauth_refresh';
	const INDEX_OPTION    = 'goo1_mcp_oauth_token_index';
	const TOKEN_PREFIX    = 'goo1_mcp_oauth_at_';   // transient prefix for access tokens.
	const CODE_PREFIX     = 'goo1_mcp_oauth_code_'; // transient prefix for auth codes.
	const CODE_TTL        = 600;                    // 10 minutes.

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Effective OAuth settings merged with defaults.
	 */
	public static function settings() {
		$defaults = array(
			'default_rate_limit' => 60,
			'oauth_enabled'      => true,
			'oauth_dcr_enabled'  => true,
			'oauth_token_ttl'    => 3600,
			'oauth_default_scope'=> 'read',
		);
		return wp_parse_args( (array) get_option( 'goo1_mcp_settings', array() ), $defaults );
	}

	/* ---------------------------------------------------------------------
	 * Front-controller: intercept the well-known + OAuth action endpoints.
	 * Runs on `init` (priority 0) so it has full WordPress context including
	 * cookie authentication, and bypasses the REST permission stack.
	 * ------------------------------------------------------------------- */
	public function maybe_handle_request() {
		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path    = (string) parse_url( $req_uri, PHP_URL_PATH );

		// Make the path relative to the WordPress home path (handles subdir installs).
		$home_path = (string) parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return;
		}

		if ( 0 === strpos( $path, '.well-known/oauth-protected-resource' )
			|| 0 === strpos( $path, 'goo1-mcp-oauth/.well-known/oauth-protected-resource' ) ) {
			$this->serve_protected_resource_metadata();
		} elseif ( 0 === strpos( $path, '.well-known/oauth-authorization-server' )
			|| '.well-known/openid-configuration' === $path
			|| 0 === strpos( $path, 'goo1-mcp-oauth/.well-known/oauth-authorization-server' )
			|| 'goo1-mcp-oauth/.well-known/openid-configuration' === $path ) {
			$this->serve_authorization_server_metadata();
		} elseif ( 'goo1-mcp-oauth/authorize' === $path ) {
			$this->handle_authorize();
		} elseif ( 'goo1-mcp-oauth/token' === $path ) {
			$this->handle_token();
		} elseif ( 'goo1-mcp-oauth/register' === $path ) {
			$this->handle_register();
		}
	}

	/* ---------------------------------------------------------------------
	 * URL helpers
	 * ------------------------------------------------------------------- */
	public static function issuer() {
		// Path-based issuer so the OIDC-style discovery URL
		// (/goo1-mcp-oauth/.well-known/oauth-authorization-server) is reachable
		// even when the root /.well-known/ directory is hardening-blocked.
		return untrailingslashit( home_url( '/goo1-mcp-oauth' ) );
	}
	public static function mcp_resource_url() {
		return rest_url( 'goo1-mcp/v1/mcp' );
	}
	public static function authorize_endpoint() {
		return home_url( '/goo1-mcp-oauth/authorize' );
	}
	public static function token_endpoint() {
		return home_url( '/goo1-mcp-oauth/token' );
	}
	public static function register_endpoint() {
		return home_url( '/goo1-mcp-oauth/register' );
	}
	public static function protected_resource_url() {
		// Served from the non-blocked path; the client uses this URL verbatim
		// (RFC 9728), so it does not need to live under the root /.well-known/.
		return home_url( '/goo1-mcp-oauth/.well-known/oauth-protected-resource' );
	}

	/* ---------------------------------------------------------------------
	 * Discovery metadata
	 * ------------------------------------------------------------------- */
	private function serve_protected_resource_metadata() {
		$this->maybe_preflight();
		$this->json_out( array(
			'resource'                => self::mcp_resource_url(),
			'authorization_servers'   => array( self::issuer() ),
			'scopes_supported'        => array( 'read', 'full', 'db_write' ),
			'bearer_methods_supported'=> array( 'header' ),
		) );
	}

	private function serve_authorization_server_metadata() {
		$this->maybe_preflight();
		$meta = array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_endpoint(),
			'token_endpoint'                        => self::token_endpoint(),
			'scopes_supported'                      => array( 'read', 'full', 'db_write' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'client_secret_post', 'client_secret_basic', 'none' ),
			'code_challenge_methods_supported'      => array( 'S256', 'plain' ),
		);
		if ( ! empty( self::settings()['oauth_dcr_enabled'] ) ) {
			$meta['registration_endpoint'] = self::register_endpoint();
		}
		$this->json_out( $meta );
	}

	/* ---------------------------------------------------------------------
	 * Dynamic Client Registration (RFC 7591)
	 * ------------------------------------------------------------------- */
	private function handle_register() {
		$this->maybe_preflight();

		$settings = self::settings();
		if ( empty( $settings['oauth_enabled'] ) || empty( $settings['oauth_dcr_enabled'] ) ) {
			$this->json_out( array( 'error' => 'access_denied', 'error_description' => 'Dynamic client registration is disabled.' ), 403 );
		}
		if ( 'POST' !== $this->method() ) {
			$this->json_out( array( 'error' => 'invalid_request', 'error_description' => 'POST required.' ), 405 );
		}

		$body = $this->read_params();

		$redirect_uris = array();
		if ( ! empty( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ) {
			foreach ( $body['redirect_uris'] as $uri ) {
				$uri = esc_url_raw( trim( (string) $uri ) );
				if ( $uri ) {
					$redirect_uris[] = $uri;
				}
			}
		}

		$auth_method = isset( $body['token_endpoint_auth_method'] ) ? sanitize_text_field( $body['token_endpoint_auth_method'] ) : 'client_secret_post';
		if ( ! in_array( $auth_method, array( 'client_secret_post', 'client_secret_basic', 'none' ), true ) ) {
			$auth_method = 'client_secret_post';
		}

		$grant_types = array( 'authorization_code', 'refresh_token' );
		if ( ! empty( $body['grant_types'] ) && is_array( $body['grant_types'] ) ) {
			$grant_types = array_values( array_intersect(
				array_map( 'sanitize_text_field', $body['grant_types'] ),
				array( 'authorization_code', 'refresh_token' )
			) );
			if ( empty( $grant_types ) ) {
				$grant_types = array( 'authorization_code' );
			}
		}

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP Client';

		$created = $this->store_client( array(
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'token_endpoint_auth_method' => $auth_method,
			'source'                     => 'dcr',
		) );

		$response = array(
			'client_id'                  => $created['client']['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $auth_method,
		);
		if ( null !== $created['secret'] ) {
			$response['client_secret']            = $created['secret'];
			$response['client_secret_expires_at'] = 0; // never expires.
		}

		$this->json_out( $response, 201 );
	}

	/* ---------------------------------------------------------------------
	 * Authorization endpoint (RFC 6749 §4.1 + PKCE)
	 * ------------------------------------------------------------------- */
	private function handle_authorize() {
		$this->maybe_preflight();

		$settings = self::settings();
		if ( empty( $settings['oauth_enabled'] ) ) {
			$this->html_error( __( 'OAuth is disabled on this site.', 'goo1-mcp' ) );
		}

		$client_id     = isset( $_REQUEST['client_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client_id'] ) ) : '';
		$redirect_uri  = isset( $_REQUEST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_uri'] ) ) : '';
		$response_type = isset( $_REQUEST['response_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['response_type'] ) ) : '';
		$state         = isset( $_REQUEST['state'] ) ? wp_unslash( $_REQUEST['state'] ) : '';
		$challenge     = isset( $_REQUEST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code_challenge'] ) ) : '';
		$challenge_m   = isset( $_REQUEST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code_challenge_method'] ) ) : 'plain';

		$client = $this->get_client( $client_id );
		if ( ! $client ) {
			$this->html_error( __( 'Unknown client_id.', 'goo1-mcp' ) );
		}
		if ( ! $this->validate_redirect( $client, $redirect_uri ) ) {
			// Never redirect to an unvalidated URI — show an error instead.
			$this->html_error( __( 'Invalid or unregistered redirect_uri for this client.', 'goo1-mcp' ) );
		}
		if ( 'code' !== $response_type ) {
			$this->redirect_error( $redirect_uri, 'unsupported_response_type', $state );
		}
		if ( '' === $challenge ) {
			$this->redirect_error( $redirect_uri, 'invalid_request', $state, 'PKCE code_challenge is required.' );
		}
		if ( ! in_array( $challenge_m, array( 'S256', 'plain' ), true ) ) {
			$this->redirect_error( $redirect_uri, 'invalid_request', $state, 'Unsupported code_challenge_method.' );
		}

		// Require a logged-in administrator to approve the connection.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->current_url() ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->html_error( __( 'You must be an administrator to authorize an MCP connector.', 'goo1-mcp' ) );
		}

		// Decision submitted?
		if ( 'POST' === $this->method() && isset( $_POST['goo1_oauth_decision'] ) ) {
			check_admin_referer( 'goo1_mcp_oauth_consent' );

			if ( 'approve' !== $_POST['goo1_oauth_decision'] ) {
				$this->redirect_error( $redirect_uri, 'access_denied', $state );
			}

			$granted = isset( $_POST['goo1_oauth_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['goo1_oauth_scope'] ) ) : 'read';
			list( $scope, $db_write ) = $this->map_scope_choice( $granted );

			$code = 'goo1_code_' . bin2hex( random_bytes( 24 ) );
			set_transient( self::CODE_PREFIX . hash( 'sha256', $code ), array(
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scope,
				'db_write'              => $db_write,
				'user_id'               => get_current_user_id(),
				'code_challenge'        => $challenge,
				'code_challenge_method' => $challenge_m,
			), self::CODE_TTL );

			$location = add_query_arg(
				array_filter( array( 'code' => $code, 'state' => $state ), function ( $v ) { return '' !== $v && null !== $v; } ),
				$redirect_uri
			);
			wp_redirect( $location );
			exit;
		}

		$this->render_consent_page( $client, $redirect_uri, $state, $challenge, $challenge_m, $response_type, $settings['oauth_default_scope'] );
	}

	/* ---------------------------------------------------------------------
	 * Token endpoint
	 * ------------------------------------------------------------------- */
	private function handle_token() {
		$this->maybe_preflight();

		if ( 'POST' !== $this->method() ) {
			$this->json_out( array( 'error' => 'invalid_request', 'error_description' => 'POST required.' ), 405 );
		}

		$params = $this->read_params();
		$creds  = $this->client_credentials_from_request( $params );
		$client = $this->get_client( $creds['client_id'] );

		if ( ! $client ) {
			$this->json_out( array( 'error' => 'invalid_client', 'error_description' => 'Unknown client.' ), 401 );
		}
		if ( ! $this->authenticate_client( $client, $creds['client_secret'] ) ) {
			$this->json_out( array( 'error' => 'invalid_client', 'error_description' => 'Client authentication failed.' ), 401 );
		}

		$grant_type = isset( $params['grant_type'] ) ? sanitize_text_field( $params['grant_type'] ) : '';

		if ( 'authorization_code' === $grant_type ) {
			$this->grant_authorization_code( $client, $params );
		} elseif ( 'refresh_token' === $grant_type ) {
			$this->grant_refresh_token( $client, $params );
		} else {
			$this->json_out( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
	}

	private function grant_authorization_code( $client, $params ) {
		$code          = isset( $params['code'] ) ? (string) $params['code'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$code_verifier = isset( $params['code_verifier'] ) ? (string) $params['code_verifier'] : '';

		$key  = self::CODE_PREFIX . hash( 'sha256', $code );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$this->json_out( array( 'error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid or expired.' ), 400 );
		}
		delete_transient( $key ); // single use.

		if ( $data['client_id'] !== $client['client_id'] ) {
			$this->json_out( array( 'error' => 'invalid_grant', 'error_description' => 'Code was issued to a different client.' ), 400 );
		}
		if ( $redirect_uri !== $data['redirect_uri'] ) {
			$this->json_out( array( 'error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch.' ), 400 );
		}
		if ( ! $this->verify_pkce( $code_verifier, $data['code_challenge'], $data['code_challenge_method'] ) ) {
			$this->json_out( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ), 400 );
		}

		$this->json_out( $this->issue_tokens( $client, $data['user_id'], $data['scope'], $data['db_write'], true ) );
	}

	private function grant_refresh_token( $client, $params ) {
		$rt = isset( $params['refresh_token'] ) ? (string) $params['refresh_token'] : '';
		if ( '' === $rt ) {
			$this->json_out( array( 'error' => 'invalid_request', 'error_description' => 'refresh_token required.' ), 400 );
		}

		$refresh = get_option( self::REFRESH_OPTION, array() );
		$hash    = hash( 'sha256', $rt );
		if ( empty( $refresh[ $hash ] ) ) {
			$this->json_out( array( 'error' => 'invalid_grant', 'error_description' => 'Unknown refresh token.' ), 400 );
		}
		$record = $refresh[ $hash ];
		if ( $record['client_id'] !== $client['client_id'] ) {
			$this->json_out( array( 'error' => 'invalid_grant', 'error_description' => 'Refresh token belongs to another client.' ), 400 );
		}

		// Issue a fresh access token; keep the existing refresh token (no rotation).
		$this->json_out( $this->issue_tokens( $client, $record['user_id'], $record['scope'], $record['db_write'], false ) );
	}

	/* ---------------------------------------------------------------------
	 * Token issuance + storage
	 * ------------------------------------------------------------------- */
	private function issue_tokens( $client, $user_id, $scope, $db_write, $issue_refresh = true ) {
		$settings = self::settings();
		$ttl      = max( 60, (int) $settings['oauth_token_ttl'] );

		$at      = 'goo1_at_' . bin2hex( random_bytes( 32 ) );
		$at_hash = hash( 'sha256', $at );

		$token_data = array(
			'oauth'      => true,
			'key_hash'   => $at_hash,
			'label'      => 'OAuth: ' . $client['client_name'],
			'client_id'  => $client['client_id'],
			'user_id'    => (int) $user_id,
			'scope'      => $scope,
			'db_write'   => (bool) $db_write,
			'rate_limit' => (int) $settings['default_rate_limit'],
			'created_at' => current_time( 'mysql' ),
		);
		set_transient( self::TOKEN_PREFIX . $at_hash, $token_data, $ttl );
		$this->index_add( $at_hash, array(
			'client_id'   => $client['client_id'],
			'client_name' => $client['client_name'],
			'scope'       => $scope,
			'db_write'    => (bool) $db_write,
			'user_id'     => (int) $user_id,
			'created_at'  => current_time( 'mysql' ),
			'expires'     => time() + $ttl,
		) );

		$response = array(
			'access_token' => $at,
			'token_type'   => 'Bearer',
			'expires_in'   => $ttl,
			'scope'        => $scope,
		);

		if ( $issue_refresh ) {
			$rt      = 'goo1_rt_' . bin2hex( random_bytes( 32 ) );
			$refresh = get_option( self::REFRESH_OPTION, array() );
			$refresh[ hash( 'sha256', $rt ) ] = array(
				'client_id'   => $client['client_id'],
				'client_name' => $client['client_name'],
				'user_id'     => (int) $user_id,
				'scope'       => $scope,
				'db_write'    => (bool) $db_write,
				'created_at'  => current_time( 'mysql' ),
			);
			update_option( self::REFRESH_OPTION, $refresh, false );
			$response['refresh_token'] = $rt;
		}

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Client storage / management (also used by the admin UI)
	 * ------------------------------------------------------------------- */

	/**
	 * Create a client. Returns array( 'client' => record, 'secret' => raw|null ).
	 */
	public function store_client( $args ) {
		$defaults = array(
			'client_name'                => 'MCP Client',
			'redirect_uris'              => array(),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => 'client_secret_post',
			'source'                     => 'manual',
		);
		$args = wp_parse_args( $args, $defaults );

		$client_id = 'goo1_client_' . bin2hex( random_bytes( 8 ) );
		$secret    = null;
		$hash      = null;
		if ( 'none' !== $args['token_endpoint_auth_method'] ) {
			$secret = 'goo1_secret_' . bin2hex( random_bytes( 24 ) );
			$hash   = hash( 'sha256', $secret );
		}

		$record = array(
			'client_id'                  => $client_id,
			'client_secret_hash'         => $hash,
			'client_name'                => sanitize_text_field( $args['client_name'] ),
			'redirect_uris'              => array_values( (array) $args['redirect_uris'] ),
			'grant_types'                => array_values( (array) $args['grant_types'] ),
			'token_endpoint_auth_method' => $args['token_endpoint_auth_method'],
			'source'                     => $args['source'],
			'created_at'                 => current_time( 'mysql' ),
		);

		$clients = get_option( self::CLIENTS_OPTION, array() );
		$clients[ $client_id ] = $record;
		update_option( self::CLIENTS_OPTION, $clients, false );

		return array( 'client' => $record, 'secret' => $secret );
	}

	public function get_client( $client_id ) {
		if ( '' === (string) $client_id ) {
			return null;
		}
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	public function delete_client( $client_id ) {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		if ( ! isset( $clients[ $client_id ] ) ) {
			return false;
		}
		unset( $clients[ $client_id ] );
		update_option( self::CLIENTS_OPTION, $clients, false );

		// Revoke this client's refresh tokens and indexed access tokens.
		$refresh = get_option( self::REFRESH_OPTION, array() );
		foreach ( $refresh as $h => $r ) {
			if ( $r['client_id'] === $client_id ) {
				unset( $refresh[ $h ] );
			}
		}
		update_option( self::REFRESH_OPTION, $refresh, false );

		$index = get_option( self::INDEX_OPTION, array() );
		foreach ( $index as $h => $r ) {
			if ( $r['client_id'] === $client_id ) {
				delete_transient( self::TOKEN_PREFIX . $h );
				unset( $index[ $h ] );
			}
		}
		update_option( self::INDEX_OPTION, $index, false );

		return true;
	}

	/**
	 * Revoke a single access token by its hash (also drops the index entry).
	 */
	public function revoke_token( $token_hash ) {
		delete_transient( self::TOKEN_PREFIX . $token_hash );
		$index = get_option( self::INDEX_OPTION, array() );
		if ( isset( $index[ $token_hash ] ) ) {
			unset( $index[ $token_hash ] );
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Active (non-expired) tokens for the admin list. Prunes stale index rows.
	 */
	public function active_tokens() {
		$index   = get_option( self::INDEX_OPTION, array() );
		$active  = array();
		$changed = false;
		foreach ( $index as $hash => $row ) {
			if ( false === get_transient( self::TOKEN_PREFIX . $hash ) ) {
				unset( $index[ $hash ] );
				$changed = true;
				continue;
			}
			$row['hash'] = $hash;
			$active[]    = $row;
		}
		if ( $changed ) {
			update_option( self::INDEX_OPTION, $index, false );
		}
		return $active;
	}

	private function index_add( $hash, $row ) {
		$index = get_option( self::INDEX_OPTION, array() );
		$index[ $hash ] = $row;
		update_option( self::INDEX_OPTION, $index, false );
	}

	private function authenticate_client( $client, $secret ) {
		// Public client (PKCE only): no secret required.
		if ( empty( $client['client_secret_hash'] ) ) {
			return true;
		}
		if ( '' === (string) $secret ) {
			return false;
		}
		return hash_equals( $client['client_secret_hash'], hash( 'sha256', $secret ) );
	}

	private function client_credentials_from_request( $params ) {
		$client_id     = isset( $params['client_id'] ) ? sanitize_text_field( $params['client_id'] ) : '';
		$client_secret = isset( $params['client_secret'] ) ? (string) $params['client_secret'] : '';

		// HTTP Basic (client_secret_basic) takes precedence if present.
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : '';
		if ( $header && 0 === stripos( $header, 'Basic ' ) ) {
			$decoded = base64_decode( substr( $header, 6 ) );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $bid, $bsecret ) = explode( ':', $decoded, 2 );
				$client_id     = sanitize_text_field( rawurldecode( $bid ) );
				$client_secret = rawurldecode( $bsecret );
			}
		}

		return array( 'client_id' => $client_id, 'client_secret' => $client_secret );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */
	private function map_scope_choice( $choice ) {
		switch ( $choice ) {
			case 'full_dbwrite':
				return array( 'full', true );
			case 'full':
				return array( 'full', false );
			case 'read':
			default:
				return array( 'read', false );
		}
	}

	private function verify_pkce( $verifier, $challenge, $method ) {
		if ( '' === (string) $verifier ) {
			return false;
		}
		if ( 'S256' === $method ) {
			$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			return hash_equals( $challenge, $computed );
		}
		// plain
		return hash_equals( $challenge, $verifier );
	}

	private function is_loopback( $uri ) {
		$host = parse_url( $uri, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
	}

	private function validate_redirect( $client, $uri ) {
		if ( '' === (string) $uri || ! filter_var( $uri, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$list = isset( $client['redirect_uris'] ) ? (array) $client['redirect_uris'] : array();
		if ( ! empty( $list ) ) {
			foreach ( $list as $registered ) {
				if ( hash_equals( $registered, $uri ) ) {
					return true;
				}
			}
			// Native apps may use a loopback redirect with a dynamic port (OAuth 2.1).
			return $this->is_loopback( $uri );
		}
		// No URIs registered (manual client left blank): allow https or loopback only.
		$scheme = parse_url( $uri, PHP_URL_SCHEME );
		return 'https' === $scheme || $this->is_loopback( $uri );
	}

	private function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * Merge form-encoded POST params with a JSON request body.
	 */
	private function read_params() {
		$params = array();
		if ( ! empty( $_POST ) ) {
			$params = wp_unslash( $_POST );
		}
		$raw = file_get_contents( 'php://input' );
		if ( $raw ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				$params = array_merge( $params, $json );
			}
		}
		return $params;
	}

	private function cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, mcp-protocol-version' );
		header( 'Access-Control-Max-Age: 86400' );
	}

	private function maybe_preflight() {
		if ( 'OPTIONS' === $this->method() ) {
			$this->cors_headers();
			status_header( 204 );
			exit;
		}
	}

	private function json_out( $data, $status = 200 ) {
		nocache_headers();
		$this->cors_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data );
		exit;
	}

	private function redirect_error( $redirect_uri, $error, $state = '', $description = '' ) {
		$args = array( 'error' => $error );
		if ( '' !== $description ) {
			$args['error_description'] = $description;
		}
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( $args, $redirect_uri ) );
		exit;
	}

	private function html_error( $message ) {
		status_header( 400 );
		nocache_headers();
		wp_die(
			esc_html( $message ),
			esc_html__( 'Authorization Error', 'goo1-mcp' ),
			array( 'response' => 400, 'back_link' => false )
		);
	}

	private function render_consent_page( $client, $redirect_uri, $state, $challenge, $challenge_m, $response_type, $default_scope ) {
		$user      = wp_get_current_user();
		$site_name = get_bloginfo( 'name' );
		$choices   = array(
			'read'         => __( 'Read-only — view content, no changes', 'goo1-mcp' ),
			'full'         => __( 'Full access — create, update and delete content', 'goo1-mcp' ),
			'full_dbwrite' => __( 'Full access + direct SQL writes (INSERT/UPDATE/DELETE)', 'goo1-mcp' ),
		);
		$preselect = isset( $choices[ $default_scope ] ) ? $default_scope : 'read';

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Authorize MCP Connector', 'goo1-mcp' ); ?></title>
	<style>
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f0f0f1;margin:0;padding:40px 16px;color:#1d2327}
		.card{max-width:480px;margin:0 auto;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:28px 32px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
		h1{font-size:20px;margin:0 0 4px}
		.muted{color:#646970;font-size:13px;margin:0 0 20px}
		.client{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin:0 0 20px;font-size:14px}
		.client strong{display:block;font-size:15px}
		fieldset{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin:0 0 20px}
		legend{font-weight:600;padding:0 6px}
		label.opt{display:block;padding:7px 0;font-size:14px;cursor:pointer}
		.actions{display:flex;gap:12px}
		button{flex:1;padding:11px;border-radius:5px;border:1px solid;font-size:14px;font-weight:600;cursor:pointer}
		.approve{background:#2271b1;border-color:#2271b1;color:#fff}
		.deny{background:#fff;border-color:#c3c4c7;color:#1d2327}
		code{word-break:break-all;font-size:12px}
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'Authorize MCP Connector', 'goo1-mcp' ); ?></h1>
		<p class="muted">
			<?php
			printf(
				/* translators: 1: site name, 2: user login */
				esc_html__( 'on %1$s — signed in as %2$s', 'goo1-mcp' ),
				esc_html( $site_name ),
				esc_html( $user->user_login )
			);
			?>
		</p>

		<div class="client">
			<strong><?php echo esc_html( $client['client_name'] ); ?></strong>
			<?php esc_html_e( 'wants to connect to your site and act with the permissions you grant below.', 'goo1-mcp' ); ?>
			<br><span class="muted"><?php esc_html_e( 'Redirect:', 'goo1-mcp' ); ?> <code><?php echo esc_html( $redirect_uri ); ?></code></span>
		</div>

		<form method="post" action="<?php echo esc_url( self::authorize_endpoint() ); ?>">
			<?php wp_nonce_field( 'goo1_mcp_oauth_consent' ); ?>
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>">
			<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
			<input type="hidden" name="response_type" value="<?php echo esc_attr( $response_type ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $challenge ); ?>">
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $challenge_m ); ?>">

			<fieldset>
				<legend><?php esc_html_e( 'Grant permission level', 'goo1-mcp' ); ?></legend>
				<?php foreach ( $choices as $value => $text ) : ?>
					<label class="opt">
						<input type="radio" name="goo1_oauth_scope" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $preselect ); ?>>
						<?php echo esc_html( $text ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="actions">
				<button type="submit" class="deny" name="goo1_oauth_decision" value="deny"><?php esc_html_e( 'Deny', 'goo1-mcp' ); ?></button>
				<button type="submit" class="approve" name="goo1_oauth_decision" value="approve"><?php esc_html_e( 'Approve', 'goo1-mcp' ); ?></button>
			</div>
		</form>
	</div>
</body>
</html>
		<?php
		exit;
	}
}
