<?php
/**
 * Sign the PrivacyPilot library feed with Ed25519.
 *
 * Usage:
 *   php sign_library.php keygen
 *       Prints a new key pair. Store the SECRET key as the GitHub Actions
 *       secret PRIVACYPILOT_SIGNING_KEY and put the PUBLIC key into
 *       src/goo1-privacypilot/includes/class-signature.php (PUBLIC_KEYS).
 *
 *   PRIVACYPILOT_SIGNING_KEY=<base64 secret key> php sign_library.php sign <library.json>
 *       Writes <library.json>.sig (base64, detached signature over the exact
 *       file bytes) and prints the public key that matches the secret key.
 *
 *   php sign_library.php verify <library.json> <base64 public key>
 *       Verifies <library.json>.sig against the given public key.
 *
 * Requires ext-sodium (PHP >= 7.2 ships it on every common distribution).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	fwrite( STDERR, "ext-sodium is required\n" );
	exit( 1 );
}

$cmd = $argv[1] ?? '';

if ( 'keygen' === $cmd ) {
	$pair   = sodium_crypto_sign_keypair();
	$secret = sodium_crypto_sign_secretkey( $pair );
	$public = sodium_crypto_sign_publickey( $pair );
	echo 'SECRET (GitHub secret PRIVACYPILOT_SIGNING_KEY): ' . base64_encode( $secret ) . "\n";
	echo 'PUBLIC (class-signature.php PUBLIC_KEYS):        ' . base64_encode( $public ) . "\n";
	exit( 0 );
}

if ( 'sign' === $cmd ) {
	$file = $argv[2] ?? '';
	$key  = getenv( 'PRIVACYPILOT_SIGNING_KEY' );
	if ( ! is_readable( $file ) ) {
		fwrite( STDERR, "library file not readable: $file\n" );
		exit( 1 );
	}
	if ( ! $key ) {
		fwrite( STDERR, "PRIVACYPILOT_SIGNING_KEY is not set\n" );
		exit( 2 );
	}
	$secret = base64_decode( trim( $key ), true );
	if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
		fwrite( STDERR, "PRIVACYPILOT_SIGNING_KEY is not a base64 Ed25519 secret key\n" );
		exit( 1 );
	}
	$data = file_get_contents( $file );
	$sig  = sodium_crypto_sign_detached( $data, $secret );
	file_put_contents( $file . '.sig', base64_encode( $sig ) . "\n" );
	$public = sodium_crypto_sign_publickey_from_secretkey( $secret );
	if ( ! sodium_crypto_sign_verify_detached( $sig, $data, $public ) ) {
		fwrite( STDERR, "self-check failed\n" );
		exit( 1 );
	}
	echo 'Signed ' . $file . ' with public key ' . base64_encode( $public ) . "\n";
	exit( 0 );
}

if ( 'verify' === $cmd ) {
	$file   = $argv[2] ?? '';
	$public = base64_decode( trim( $argv[3] ?? '' ), true );
	$sig    = base64_decode( trim( (string) @file_get_contents( $file . '.sig' ) ), true );
	if ( ! $public || ! $sig || ! is_readable( $file ) ) {
		fwrite( STDERR, "usage: verify <library.json> <base64 public key>\n" );
		exit( 1 );
	}
	$ok = sodium_crypto_sign_verify_detached( $sig, file_get_contents( $file ), $public );
	echo $ok ? "OK\n" : "INVALID\n";
	exit( $ok ? 0 : 1 );
}

fwrite( STDERR, "usage: php sign_library.php keygen | sign <file> | verify <file> <pubkey>\n" );
exit( 1 );
