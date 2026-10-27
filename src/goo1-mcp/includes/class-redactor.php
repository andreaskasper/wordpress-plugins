<?php
/**
 * Secret redaction for everything the bridge returns.
 *
 * Secrets (S3 keys, ManageWP keys, passwords, tokens, licence keys …) must
 * never leave the site in clear text through a tool response, because tool
 * responses end up in the AI conversation. Redaction is value-based: we walk
 * nested arrays/objects (also inside serialized or JSON strings coming from
 * the database) and replace the value of every sensitive key.
 *
 * There is intentionally no switch to turn this off. Secrets move between
 * sites only sealed (see the secrets controller in 1.7).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Redactor {

	const MASK = '[redacted]';

	/**
	 * Option names whose whole value is never returned.
	 */
	private static $blocked_option_patterns = array(
		'password', 'secret', 'token', 'auth_key', 'auth_salt',
		'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt',
		'secure_auth_key', 'secure_auth_salt', 'goo1_mcp_api_keys',
		'goo1_mcp_oauth', 'goo1_mcp_seal', 'mwp_potential_key',
		'mwp_communication_key', 'mwp_service_key', 'mwp_public_keys',
		'_site_transient_wp_remote_block_patterns',
	);

	/**
	 * Array keys (anywhere in a nested value) whose value is masked.
	 * Matched case-insensitively as substrings after removing - and _.
	 */
	private static $sensitive_key_parts = array(
		'password', 'passwd', 'passphrase', 'secret', 'token', 'apikey',
		'accesskey', 'privatekey', 'licensekey', 'licencekey', 'authkey',
		'signingkey', 'encryptionkey', 'communicationkey', 'potentialkey',
		'servicekey', 'sessiontokens', 'userpass', 'useractivationkey',
		'webhooksecret', 'clientsecret', 'dbpassword', 'smtppass',
	);

	/**
	 * Key endings that describe a secret rather than hold one.
	 */
	private static $non_secret_suffixes = array(
		'ttl', 'lifetime', 'expires', 'expiresat', 'expiry', 'expiration', 'enabled', 'disabled',
		'count', 'length', 'limit', 'url', 'uri', 'type', 'method', 'label', 'required', 'mode',
		'version', 'format', 'field', 'fields', 'header', 'name', 'strength', 'policy', 'reset',
		'changed', 'updated', 'created', 'sent', 'hint',
	);

	/**
	 * Column names in SQL results that are always masked.
	 */
	private static $sensitive_columns = array( 'user_pass', 'user_activation_key' );

	/**
	 * Meta keys (usermeta/postmeta) whose meta_value is always masked.
	 */
	private static $sensitive_meta_keys = array( 'session_tokens' );

	/**
	 * Whether an option name must never be returned at all.
	 */
	public static function is_blocked_option( $name ) {
		$name = strtolower( (string) $name );
		foreach ( self::$blocked_option_patterns as $pattern ) {
			if ( false !== strpos( $name, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a (nested) array key looks like it holds a secret.
	 */
	public static function is_sensitive_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}
		$norm = str_replace( array( '-', '_', ' ', '.' ), '', strtolower( $key ) );
		if ( '' === $norm ) {
			return false;
		}
		// Settings *about* a secret are not secret (oauth_token_ttl, password_required …).
		foreach ( self::$non_secret_suffixes as $suffix ) {
			$len = strlen( $suffix );
			if ( strlen( $norm ) > $len && substr( $norm, -$len ) === $suffix ) {
				return false;
			}
		}
		foreach ( self::$sensitive_key_parts as $part ) {
			if ( false !== strpos( $norm, $part ) ) {
				return true;
			}
		}
		// Exact short keys used by common plugins (e.g. UpdraftPlus "key").
		return in_array( $norm, array( 'pass', 'pwd', 'key' ), true );
	}

	/**
	 * Recursively mask sensitive values. Scalars under a sensitive key become
	 * the mask (empty values stay empty so callers can see "not configured").
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function redact( $value, $depth = 0, $under_secret = false ) {
		if ( $depth > 32 ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return self::looks_like_secret_value( $value ) ? self::MASK : $value;
		}
		foreach ( $value as $k => $v ) {
			// Inside a sensitive key that holds a structure (e.g. WooCommerce
			// gateway settings {"secret_key":{"label":…,"value":"sk_live…"}}):
			// mask list items and value/default children.
			if ( $under_secret && ! is_array( $v ) && ! is_object( $v ) && ( is_int( $k ) || in_array( strtolower( (string) $k ), array( 'value', 'default', 'val', 'current' ), true ) ) ) {
				if ( null !== $v && '' !== $v && false !== $v ) {
					$value[ $k ] = self::MASK;
				}
				continue;
			}
			if ( self::is_sensitive_key( $k ) && ! is_array( $v ) && ! is_object( $v ) ) {
				if ( null !== $v && '' !== $v && false !== $v ) {
					$value[ $k ] = self::MASK;
				}
				continue;
			}
			if ( self::is_sensitive_key( $k ) && ( is_array( $v ) || is_object( $v ) ) && 'sessiontokens' === str_replace( array( '-', '_' ), '', strtolower( (string) $k ) ) ) {
				$value[ $k ] = self::MASK;
				continue;
			}
			$value[ $k ] = self::redact( $v, $depth + 1, self::is_sensitive_key( $k ) );
		}
		return $value;
	}

	/**
	 * Values that are secrets whatever their key is called: WordPress / bcrypt
	 * / argon password hashes and PEM private keys.
	 */
	public static function looks_like_secret_value( $v ) {
		if ( ! is_string( $v ) || strlen( $v ) < 20 ) {
			return false;
		}
		return (bool) preg_match( '/^(\$P\$|\$H\$|\$wp\$|\$2[abxy]\$\d\d\$|\$argon2(id|i|d)\$)/', $v )
			|| false !== strpos( $v, '-----BEGIN' ) && false !== strpos( $v, 'PRIVATE KEY-----' );
	}

	/**
	 * Redact a raw string that may contain a serialized PHP value or JSON.
	 * Returns the string unchanged when it is neither or holds no secrets.
	 */
	public static function redact_string( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $raw;
		}
		$trim = ltrim( $raw );
		// Serialized PHP (never instantiate objects from untrusted DB data).
		if ( is_serialized( $trim ) ) {
			$data = @unserialize( $trim, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( is_array( $data ) || is_object( $data ) ) {
				$red = self::redact( $data );
				if ( $red !== self::to_array( $data ) ) {
					return serialize( $red ); // phpcs:ignore
				}
			}
			return $raw;
		}
		// JSON.
		if ( isset( $trim[0] ) && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
			$data = json_decode( $trim, true );
			if ( is_array( $data ) ) {
				$red = self::redact( $data );
				if ( $red !== $data ) {
					return wp_json_encode( $red );
				}
			}
		}
		return $raw;
	}

	/**
	 * Redact SQL result rows (ARRAY_A). Knows the WordPress table shapes:
	 * option rows, meta rows and the users table.
	 */
	public static function redact_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$meta_key = isset( $row['meta_key'] ) ? strtolower( (string) $row['meta_key'] ) : null;
			$opt_name = isset( $row['option_name'] ) ? (string) $row['option_name'] : null;
			foreach ( $row as $col => $val ) {
				$lc = strtolower( (string) $col );
				if ( in_array( $lc, self::$sensitive_columns, true ) ) {
					$row[ $col ] = ( null === $val || '' === $val ) ? $val : self::MASK;
					continue;
				}
				if ( 'option_value' === $lc && null !== $opt_name && self::is_blocked_option( $opt_name ) ) {
					$row[ $col ] = self::MASK;
					continue;
				}
				if ( 'meta_value' === $lc && null !== $meta_key && ( in_array( $meta_key, self::$sensitive_meta_keys, true ) || self::is_sensitive_key( $meta_key ) ) ) {
					$row[ $col ] = ( null === $val || '' === $val ) ? $val : self::MASK;
					continue;
				}
				if ( self::looks_like_secret_value( $val ) ) {
					$row[ $col ] = self::MASK;
				} elseif ( is_string( $val ) && strlen( $val ) > 2 ) {
					$row[ $col ] = self::redact_string( $val );
				}
			}
			$rows[ $i ] = $row;
		}
		return $rows;
	}

	/**
	 * Merge a value written by a client back over the stored value, so that
	 * the mask placeholder never overwrites a real secret. Wherever the new
	 * value contains the mask, the old value at that position is kept.
	 */
	public static function restore_masked( $new, $old ) {
		if ( self::MASK === $new ) {
			return $old;
		}
		if ( is_array( $new ) ) {
			$old_arr = self::to_array( $old );
			foreach ( $new as $k => $v ) {
				$new[ $k ] = self::restore_masked( $v, is_array( $old_arr ) && array_key_exists( $k, $old_arr ) ? $old_arr[ $k ] : null );
			}
			return $new;
		}
		return $new;
	}

	/**
	 * Whether a value contains the mask anywhere.
	 */
	public static function contains_mask( $value ) {
		if ( self::MASK === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( self::contains_mask( $v ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function to_array( $value ) {
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return $value;
	}
}
