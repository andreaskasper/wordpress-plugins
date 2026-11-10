<?php
/**
 * Sealed secret requests (see includes/class-seal.php).
 *
 *  secrets_request         create a one-time request on THIS site (target)
 *  secrets_status          is a request still open / has the inbox been filled
 *                          (secrets_request already returns the inbox link)
 *  secrets_export_sealed   on a SOURCE site: seal an existing configuration
 *                          (UpdraftPlus S3 credentials) for another site's request
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Secrets_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/secrets/request', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_request' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/secrets/request/(?P<rid>sr_[0-9a-f]{16})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/secrets/request/(?P<rid>sr_[0-9a-f]{16})/inbox-link', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'inbox_link' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		// Public: only the public key of an open request (needed by a source
		// site that seals for this site; the source fetches it itself).
		register_rest_route( self::NAMESPACE, '/secrets/public-key/(?P<rid>sr_[0-9a-f]{16})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'public_key' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/secrets/export', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'export_sealed' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function create_request( $request ) {
		$purpose = sanitize_key( (string) ( $request->get_param( 'purpose' ) ?: 's3' ) );
		$label   = (string) $request->get_param( 'label' );
		$view    = Goo1_MCP_Seal::create_request( $purpose, $label );
		if ( is_wp_error( $view ) ) {
			$data = $view->get_error_data();
			return $this->error( $view->get_error_code(), $view->get_error_message(), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400 );
		}
		$view['inbox_link'] = self::inbox_url( $view['request_id'] );
		$view['how_to']     = array(
			'from_vault'      => 'On the owner\'s computer: node goo1-seal.mjs --item <entry> --site ' . home_url() . ' --request-id ' . $view['request_id'],
			'from_other_site' => 'Call secrets_export_sealed on the source site with recipient_site=' . home_url() . ' and request_id; the source fetches the public key from this site itself.',
			'by_hand'         => 'Give the owner inbox_link; afterwards pass secret_request_id instead of sealed.',
		);
		return $this->success( $view, 201 );
	}

	public function status( $request ) {
		$rid = (string) $request['rid'];
		$r   = Goo1_MCP_Seal::get_request( $rid );
		if ( ! $r ) {
			return $this->error( 'not_found', 'Unknown or expired secret request.', 404 );
		}
		return $this->success( Goo1_MCP_Seal::public_view( $rid, $r ) );
	}

	public function inbox_link( $request ) {
		$rid = (string) $request['rid'];
		if ( ! Goo1_MCP_Seal::get_request( $rid ) ) {
			return $this->error( 'not_found', 'Unknown or expired secret request.', 404 );
		}
		return $this->success( array( 'request_id' => $rid, 'inbox_link' => self::inbox_url( $rid ) ) );
	}

	public function public_key( $request ) {
		$rid = (string) $request['rid'];
		$r   = Goo1_MCP_Seal::get_request( $rid );
		if ( ! $r ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$v = Goo1_MCP_Seal::public_view( $rid, $r );
		return new WP_REST_Response( array(
			'request_id' => $rid,
			'public_key' => $v['public_key'],
			'purpose'    => $v['purpose'],
			'expires_at' => $v['expires_at'],
			'site'       => home_url(),
		), 200 );
	}

	/**
	 * Seal this site's stored UpdraftPlus S3-Compatible credentials for a
	 * request on another site. The recipient's public key is fetched from
	 * the recipient itself (never taken from the caller), and the recipient
	 * must be on the export allowlist in Settings.
	 */
	public function export_sealed( $request ) {
		$kind      = sanitize_key( (string) ( $request->get_param( 'kind' ) ?: 'updraft_s3' ) );
		$rid       = (string) $request->get_param( 'request_id' );
		$recipient = untrailingslashit( esc_url_raw( (string) $request->get_param( 'recipient_site' ) ) );
		if ( 'updraft_s3' !== $kind ) {
			return $this->error( 'unsupported_kind', 'Supported kind: updraft_s3.' );
		}
		if ( ! preg_match( '/^sr_[0-9a-f]{16}$/', $rid ) || ! $recipient ) {
			return $this->error( 'missing_params', 'recipient_site (URL of the target site) and request_id are required.' );
		}
		if ( ! self::recipient_allowed( $recipient ) ) {
			return $this->error( 'recipient_not_allowed', 'Export to ' . wp_parse_url( $recipient, PHP_URL_HOST ) . ' is not allowed. An administrator can add the host under Claude Bridge → Settings → "Secret export targets".', 403 );
		}
		$scheme = strtolower( (string) wp_parse_url( $recipient, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $recipient, PHP_URL_HOST ) );
		if ( 'https' !== $scheme && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return $this->error( 'recipient_not_https', 'The recipient site must use https.', 400 );
		}

		$opts     = get_option( 'updraft_s3generic' );
		$settings = is_array( $opts ) && isset( $opts['settings'] ) && is_array( $opts['settings'] ) ? $opts['settings'] : array();
		$instance = (string) $request->get_param( 'instance_id' );
		$conf     = $instance && isset( $settings[ $instance ] ) ? $settings[ $instance ] : ( $settings ? reset( $settings ) : null );
		if ( ! $conf || empty( $conf['accesskey'] ) || empty( $conf['secretkey'] ) ) {
			return $this->error( 'not_configured', 'This site has no UpdraftPlus S3-Compatible credentials to export.', 404 );
		}

		// Fetch the recipient's public key ourselves (rest_route form works
		// without pretty permalinks). No redirects: the allowlist check above
		// must hold for the host that actually answers.
		$url  = add_query_arg( 'rest_route', '/goo1-mcp/v1/secrets/public-key/' . $rid, $recipient . '/' );
		$args = array( 'timeout' => 15, 'redirection' => 0 );
		$resp = ( 'https' === $scheme ) ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return $this->error( 'recipient_unreachable', 'Could not reach the recipient site.', 502 );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) || ! is_array( $body ) || empty( $body['public_key'] ) || ( $body['request_id'] ?? '' ) !== $rid ) {
			return $this->error( 'recipient_no_request', 'The recipient has no open secret request ' . $rid . ' (expired, wrong site or redirected?).', 404 );
		}
		if ( strtolower( (string) wp_parse_url( (string) ( $body['site'] ?? '' ), PHP_URL_HOST ) ) !== $host ) {
			return $this->error( 'recipient_mismatch', 'The recipient reports a different site address than ' . $host . '.', 400 );
		}
		if ( 's3' !== ( $body['purpose'] ?? '' ) ) {
			return $this->error( 'recipient_purpose', 'The recipient request is not for S3 credentials.', 400 );
		}

		$env = Goo1_MCP_Seal::seal(
			array( 'type' => 's3', 'access_key' => $conf['accesskey'], 'secret_key' => $conf['secretkey'] ),
			(string) $body['public_key'],
			$rid
		);
		if ( is_wp_error( $env ) ) {
			return $this->error( $env->get_error_code(), $env->get_error_message() );
		}
		Goo1_MCP_Audit_Log::add_flag( 'sealed_export:' . $host );
		return $this->success( array(
			'request_id'    => $rid,
			'recipient'     => $recipient,
			'envelope'      => $env,
			'source_site'   => home_url(),
			'public_fields' => array(
				'endpoint'            => isset( $conf['endpoint'] ) ? $conf['endpoint'] : '',
				'path'                => isset( $conf['path'] ) ? $conf['path'] : '',
				'bucket_access_style' => isset( $conf['bucket_access_style'] ) ? $conf['bucket_access_style'] : 'path_style',
				'signature_version'   => isset( $conf['signature_version'] ) ? $conf['signature_version'] : 'v4',
			),
			'next'          => 'On the recipient: updraft_configure_s3 with sealed=<envelope> (path usually = bucket/<recipient domain>).',
		) );
	}

	/**
	 * Allowlist from Settings ("Secret export targets"): exact hosts, one
	 * per line or comma-separated, optional ":port" (without a port only the
	 * default port matches). No wildcards. Empty list = no exports.
	 */
	public static function recipient_allowed( $url ) {
		$settings = (array) get_option( 'goo1_mcp_settings', array() );
		$list     = isset( $settings['seal_export_targets'] ) ? (string) $settings['seal_export_targets'] : '';
		$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port     = wp_parse_url( $url, PHP_URL_PORT );
		$hostport = $port ? $host . ':' . $port : $host;
		if ( '' === $host ) {
			return false;
		}
		foreach ( preg_split( '/[\s,]+/', strtolower( $list ) ) as $entry ) {
			$entry = trim( preg_replace( '#^https?://#', '', $entry ), '/ ' );
			if ( '' !== $entry && $entry === $hostport ) {
				return true;
			}
		}
		return false;
	}

	public static function inbox_url( $rid ) {
		return admin_url( 'admin.php?page=goo1-mcp-secret-inbox&request=' . rawurlencode( $rid ) );
	}
}
