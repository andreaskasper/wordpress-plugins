<?php
/**
 * Sealed secret hand-over ("goo1seal1").
 *
 * Lets a secret travel to this site through an untrusted messenger (the AI
 * conversation) without the messenger ever seeing it in clear text:
 *
 *  1. This site creates a one-time request: an X25519 key pair whose private
 *     half never leaves the site (30 minutes, single use).
 *  2. The source — goo1-seal.mjs on the owner's computer (local file or
 *     Vaultwarden), another Claude Bridge site, or the admin typing into the
 *     wp-admin "secret inbox" — encrypts the secret to the request's public key.
 *  3. Only the envelope passes through the conversation; this site opens it
 *     and applies it (e.g. as UpdraftPlus S3 credentials).
 *
 * Envelope: "goo1seal1." + base64url(JSON{v,rid,epk,n,ct})
 *   shared = X25519(ephemeral_sk, request_pk)
 *   key    = HKDF-SHA256(shared, 32, info "goo1-mcp-seal-v1", salt epk||pk)
 *   ct     = ChaCha20-Poly1305-IETF(plaintext JSON, nonce n, AD "goo1seal1|" . rid)
 * Only primitives every PHP 7.4+ WordPress has (ext-sodium or WP's
 * sodium_compat, hash_hkdf) and Node's built-in crypto.
 *
 * At rest the request's private key (and an envelope typed into the inbox)
 * are wrapped with a key derived from the wp-config salts (AUTH_KEY,
 * SECURE_AUTH_KEY), so reading the option through SQL yields only
 * ciphertext. Requests are refused when those salts are not set.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Seal {

	const OPTION  = 'goo1_mcp_seal_requests';
	const PREFIX  = 'goo1seal1.';
	const INFO    = 'goo1-mcp-seal-v1';
	const TTL     = 1800;
	const PURPOSES = array( 's3' );
	const WRAP    = 'w1:';
	const WRAP_NONCE = 24;

	/**
	 * Key that wraps private keys at rest, derived from the wp-config salts.
	 *
	 * @return string|WP_Error 32 bytes.
	 */
	private static function kek() {
		$parts = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $c ) {
			$v = defined( $c ) ? (string) constant( $c ) : '';
			if ( strlen( $v ) < 32 || false !== stripos( $v, 'put your unique phrase here' ) ) {
				return new WP_Error( 'seal_no_salts', 'Sealed secrets need unique AUTH_KEY and SECURE_AUTH_KEY salts in wp-config.php (https://api.wordpress.org/secret-key/1.1/salt/).', array( 'status' => 409 ) );
			}
			$parts[] = $v;
		}
		return hash_hkdf( 'sha256', implode( '|', $parts ), 32, 'goo1-seal-kek', 'goo1-mcp' );
	}

	private static function wrap( $plain ) {
		$kek = self::kek();
		if ( is_wp_error( $kek ) ) {
			return $kek;
		}
		$n = random_bytes( self::WRAP_NONCE );
		return self::WRAP . base64_encode( $n . sodium_crypto_secretbox( (string) $plain, $n, $kek ) ); // phpcs:ignore
	}

	/**
	 * @return string|false
	 */
	private static function unwrap( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, self::WRAP ) ) {
			return false;
		}
		$kek = self::kek();
		$raw = base64_decode( substr( $stored, strlen( self::WRAP ) ), true ); // phpcs:ignore
		if ( is_wp_error( $kek ) || false === $raw || strlen( $raw ) <= self::WRAP_NONCE ) {
			return false;
		}
		try {
			return sodium_crypto_secretbox_open( substr( $raw, self::WRAP_NONCE ), substr( $raw, 0, self::WRAP_NONCE ), $kek );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Serialise read-modify-write of the request store (MySQL named lock,
	 * per site because named locks are server-wide).
	 */
	private static function with_lock( $fn ) {
		global $wpdb;
		$name = 'goo1seal_' . md5( DB_NAME . '|' . $wpdb->prefix );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
		if ( 1 !== $got ) {
			return null; // busy: callers treat this as "not done".
		}
		try {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return call_user_func( $fn );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Create a one-time request. Returns the public part.
	 *
	 * @return array|WP_Error
	 */
	public static function create_request( $purpose = 's3', $label = '' ) {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return new WP_Error( 'seal_purpose', 'Supported purpose: ' . implode( ', ', self::PURPOSES ) . '.', array( 'status' => 400 ) );
		}
		$kp  = sodium_crypto_box_keypair();
		$sk  = self::wrap( sodium_crypto_box_secretkey( $kp ) );
		if ( is_wp_error( $sk ) ) {
			return $sk;
		}
		$pk  = sodium_crypto_box_publickey( $kp );
		$rid = 'sr_' . bin2hex( random_bytes( 8 ) );
		$req = array(
			'sk'      => $sk,
			'pk'      => base64_encode( $pk ), // phpcs:ignore
			'purpose' => $purpose,
			'label'   => sanitize_text_field( (string) $label ),
			'created' => time(),
			'expires' => time() + self::TTL,
			'user_id' => get_current_user_id(),
			'inbox'   => null, // envelope entered by an admin in wp-admin (wrapped)
		);
		$ok = self::with_lock( function () use ( $rid, $req ) {
			$all = self::prune_list( get_option( self::OPTION, array() ) );
			$all[ $rid ] = $req;
			update_option( self::OPTION, $all, false );
			return true;
		} );
		if ( true !== $ok ) {
			return new WP_Error( 'seal_busy', 'The secret store is busy, try again in a moment.', array( 'status' => 503 ) );
		}

		return self::public_view( $rid, $req );
	}

	public static function public_view( $rid, $r ) {
		return array(
			'request_id' => $rid,
			'public_key' => self::b64url( base64_decode( $r['pk'] ) ), // phpcs:ignore
			'purpose'    => $r['purpose'],
			'label'      => $r['label'],
			'expires_at' => gmdate( 'c', (int) $r['expires'] ),
			'site'       => home_url(),
			'inbox_filled' => ! empty( $r['inbox'] ),
		);
	}

	/**
	 * An open request (read-only; expired ones count as missing and are
	 * removed by the next write).
	 */
	public static function get_request( $rid ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $rid ] ) || (int) $all[ $rid ]['expires'] < time() ) {
			return null;
		}
		return $all[ $rid ];
	}

	/**
	 * Remove a request. Returns false if it was already gone (consumed by a
	 * parallel call), so an envelope can only be used once.
	 */
	public static function delete_request( $rid ) {
		return true === self::with_lock( function () use ( $rid ) {
			$all = get_option( self::OPTION, array() );
			if ( ! is_array( $all ) || ! isset( $all[ $rid ] ) ) {
				return false;
			}
			unset( $all[ $rid ] );
			update_option( self::OPTION, self::prune_list( $all ), false );
			return true;
		} );
	}

	/**
	 * Store an envelope typed into the wp-admin inbox for a request.
	 */
	public static function set_inbox( $rid, $envelope ) {
		$wrapped = self::wrap( $envelope );
		if ( is_wp_error( $wrapped ) ) {
			return false;
		}
		return true === self::with_lock( function () use ( $rid, $wrapped ) {
			$all = get_option( self::OPTION, array() );
			if ( ! is_array( $all ) || ! isset( $all[ $rid ] ) || (int) $all[ $rid ]['expires'] < time() ) {
				return false;
			}
			$all[ $rid ]['inbox'] = $wrapped;
			update_option( self::OPTION, $all, false );
			return true;
		} );
	}

	/**
	 * Seal a payload for a public key (used by the inbox and by site-to-site export).
	 *
	 * @param array  $payload   JSON-serialisable secret data.
	 * @param string $pk_b64url Recipient public key (base64url, 32 bytes).
	 * @param string $rid       Recipient request id (bound as associated data).
	 * @return string|WP_Error  Envelope.
	 */
	public static function seal( $payload, $pk_b64url, $rid ) {
		$pk = self::unb64url( $pk_b64url );
		if ( 32 !== strlen( (string) $pk ) ) {
			return new WP_Error( 'seal_bad_key', 'public_key must be a base64url X25519 public key (32 bytes).' );
		}
		if ( ! preg_match( '/^sr_[0-9a-f]{16}$/', (string) $rid ) ) {
			return new WP_Error( 'seal_bad_rid', 'request_id is invalid.' );
		}
		try {
			$ekp    = sodium_crypto_box_keypair();
			$esk    = sodium_crypto_box_secretkey( $ekp );
			$epk    = sodium_crypto_box_publickey( $ekp );
			$shared = sodium_crypto_scalarmult( $esk, $pk );
			$key    = hash_hkdf( 'sha256', $shared, 32, self::INFO, $epk . $pk );
			$nonce  = random_bytes( 12 );
			$ct     = sodium_crypto_aead_chacha20poly1305_ietf_encrypt( wp_json_encode( $payload ), 'goo1seal1|' . $rid, $nonce, $key );
		} catch ( \Throwable $e ) {
			// e.g. a low-order public key from a malicious recipient.
			return new WP_Error( 'seal_bad_key', 'The recipient public key is not usable.' );
		}
		$env    = array(
			'v'   => 1,
			'rid' => $rid,
			'epk' => self::b64url( $epk ),
			'n'   => self::b64url( $nonce ),
			'ct'  => self::b64url( $ct ),
		);
		return self::PREFIX . self::b64url( wp_json_encode( $env ) );
	}

	/**
	 * Open an envelope addressed to one of this site's requests.
	 * The request is consumed (deleted) on success.
	 *
	 * @param string      $envelope
	 * @param string|null $expect_rid If given, the envelope must be for this request.
	 * @param string|null $expect_purpose
	 * @param bool        $consume  Delete the request right away (default). With
	 *                              false the caller calls consume() once the
	 *                              secret was applied successfully, so a failed
	 *                              test can be retried with the same envelope.
	 * @return array|WP_Error Payload.
	 */
	public static function open( $envelope, $expect_rid = null, $expect_purpose = null, $consume = true ) {
		$envelope = trim( (string) $envelope );
		if ( 0 !== strpos( $envelope, self::PREFIX ) ) {
			return new WP_Error( 'seal_format', 'Not a goo1seal1 envelope.' );
		}
		$env = json_decode( (string) self::unb64url( substr( $envelope, strlen( self::PREFIX ) ) ), true );
		if ( ! is_array( $env ) || 1 !== (int) ( $env['v'] ?? 0 ) || empty( $env['rid'] ) ) {
			return new WP_Error( 'seal_format', 'Envelope is damaged.' );
		}
		$rid = (string) $env['rid'];
		if ( null !== $expect_rid && $rid !== $expect_rid ) {
			return new WP_Error( 'seal_wrong_request', 'Envelope belongs to a different request.' );
		}
		$req = self::get_request( $rid );
		if ( ! $req ) {
			return new WP_Error( 'seal_no_request', 'Unknown or expired secret request on this site. Create a new one with secrets_request.' );
		}
		if ( null !== $expect_purpose && $req['purpose'] !== $expect_purpose ) {
			return new WP_Error( 'seal_purpose', 'This secret request was created for "' . $req['purpose'] . '", not "' . $expect_purpose . '".' );
		}
		$sk  = self::unwrap( $req['sk'] );
		$pk  = base64_decode( $req['pk'] ); // phpcs:ignore
		if ( false === $sk || 32 !== strlen( $sk ) ) {
			return new WP_Error( 'seal_key_unavailable', 'The private key of this request cannot be unwrapped (were the wp-config salts changed?). Create a new request.' );
		}
		$epk = self::unb64url( $env['epk'] ?? '' );
		$n   = self::unb64url( $env['n'] ?? '' );
		$ct  = self::unb64url( $env['ct'] ?? '' );
		if ( 32 !== strlen( (string) $epk ) || 12 !== strlen( (string) $n ) || strlen( (string) $ct ) < 17 ) {
			return new WP_Error( 'seal_format', 'Envelope is damaged.' );
		}
		try {
			$shared = sodium_crypto_scalarmult( $sk, $epk );
			$key    = hash_hkdf( 'sha256', $shared, 32, self::INFO, $epk . $pk );
			$plain  = sodium_crypto_aead_chacha20poly1305_ietf_decrypt( $ct, 'goo1seal1|' . $rid, $n, $key );
		} catch ( \Throwable $e ) {
			$plain = false;
		}
		if ( false === $plain ) {
			return new WP_Error( 'seal_decrypt', 'Envelope could not be opened (wrong key or tampered).' );
		}
		$payload = json_decode( $plain, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'seal_payload', 'Envelope payload is not a JSON object.' );
		}
		self::$last_rid = $rid;
		// Single use: whoever removes the request first gets the payload.
		if ( $consume && ! self::delete_request( $rid ) ) {
			return new WP_Error( 'seal_no_request', 'This secret request was already used.' );
		}
		return $payload;
	}

	/** @var string|null Request id of the last envelope opened in this request. */
	public static $last_rid = null;

	/**
	 * Mark a request as used (after open( …, false ) and a successful apply).
	 */
	public static function consume( $rid ) {
		return self::delete_request( (string) $rid );
	}

	/**
	 * Resolve the secret for a tool call: either `sealed` (envelope) or
	 * `secret_request_id` whose wp-admin inbox was filled.
	 *
	 * @return array|WP_Error|null Payload, error, or null if neither was given.
	 */
	public static function resolve( WP_REST_Request $request, $purpose, $consume = true ) {
		$sealed = $request->get_param( 'sealed' );
		$rid    = $request->get_param( 'secret_request_id' );
		if ( $sealed ) {
			return self::open( $sealed, $rid ? (string) $rid : null, $purpose, $consume );
		}
		if ( $rid ) {
			$req = self::get_request( (string) $rid );
			if ( ! $req ) {
				return new WP_Error( 'seal_no_request', 'Unknown or expired secret request.' );
			}
			if ( empty( $req['inbox'] ) ) {
				return new WP_Error( 'seal_inbox_empty', 'Nothing has been entered for this request yet (open the inbox link in wp-admin).' );
			}
			$env = self::unwrap( $req['inbox'] );
			if ( false === $env ) {
				return new WP_Error( 'seal_key_unavailable', 'The inbox entry cannot be unwrapped. Create a new request.' );
			}
			return self::open( $env, (string) $rid, $purpose, $consume );
		}
		return null;
	}

	private static function prune_list( $all ) {
		$all = is_array( $all ) ? $all : array();
		foreach ( $all as $rid => $r ) {
			if ( ! is_array( $r ) || (int) ( $r['expires'] ?? 0 ) < time() ) {
				unset( $all[ $rid ] );
			}
		}
		return $all;
	}

	/**
	 * Drop expired requests (daily maintenance).
	 */
	public static function prune() {
		self::with_lock( function () {
			$all = get_option( self::OPTION, array() );
			$new = self::prune_list( $all );
			if ( $new !== $all ) {
				update_option( self::OPTION, $new, false );
			}
		} );
	}

	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore
	}

	public static function unb64url( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		$p = strlen( $s ) % 4;
		if ( $p ) {
			$s .= str_repeat( '=', 4 - $p );
		}
		$out = base64_decode( $s, true ); // phpcs:ignore
		return false === $out ? '' : $out;
	}
}
