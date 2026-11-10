<?php
/**
 * Ed25519 signature check for the library feed.
 *
 * WordPress core ships sodium_compat (wp-includes/sodium_compat, loaded from
 * compat.php since WP 5.2), so sodium_crypto_sign_verify_detached() exists on
 * every supported install even without ext-sodium.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Signature {

	/**
	 * Trusted public keys (base64). More than one allows key rotation: add the
	 * new key, release, then switch the signing secret, later drop the old key.
	 *
	 * Additional keys can be added per site with the constant
	 * GOO1_PP_EXTRA_PUBLIC_KEYS (comma separated) or the filter
	 * goo1_pp_public_keys - only the site owner can do either, so this does not
	 * widen trust beyond the person who already controls the site.
	 */
	const PUBLIC_KEYS = array(
		// Key 1, created 2026-11-10. The secret half is the GitHub Actions secret PRIVACYPILOT_SIGNING_KEY.
		'WVdjiT7yGPdIuk1MiWxk4LOnaI0t44HbrCCpWvXddDY=',
	);

	public static function public_keys() {
		$keys = self::PUBLIC_KEYS;
		if ( defined( 'GOO1_PP_EXTRA_PUBLIC_KEYS' ) && GOO1_PP_EXTRA_PUBLIC_KEYS ) {
			$keys = array_merge( $keys, array_map( 'trim', explode( ',', (string) GOO1_PP_EXTRA_PUBLIC_KEYS ) ) );
		}
		$keys = apply_filters( 'goo1_pp_public_keys', $keys );
		return array_values( array_filter( array_unique( (array) $keys ) ) );
	}

	public static function has_keys() {
		return count( self::public_keys() ) > 0;
	}

	/**
	 * @param string $data      Exact bytes that were signed.
	 * @param string $signature Base64 detached signature.
	 */
	public static function verify( $data, $signature ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$sig = base64_decode( trim( (string) $signature ), true );
		if ( false === $sig || strlen( $sig ) !== 64 ) {
			return false;
		}
		foreach ( self::public_keys() as $key_b64 ) {
			$key = base64_decode( $key_b64, true );
			if ( false === $key || strlen( $key ) !== 32 ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $sig, (string) $data, $key ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return false;
	}
}
