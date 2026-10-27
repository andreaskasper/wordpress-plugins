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
 * On top of the key-based masking, SQL results and REST-proxy output are
 * checked against the site's known secret values (value matching), so a
 * secret is also masked when a query returns it under another column name.
 *
 * Limit: with free SQL a full-scope token can still extract a secret on
 * purpose (HEX(), SUBSTRING() …) — like it could by installing a plugin.
 * Redaction prevents secrets from reaching the conversation by accident; it
 * is not a boundary against a malicious token holder.
 * Clear text only with the admin-granted "unredacted" grant plus an explicit
 * unredacted=true on the call (flagged in the audit log).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Redactor {

	const MASK = '[redacted]';

	/** Values shorter than this are not used for value matching (too many false hits). */
	const MIN_SECRET_LEN = 6;

	/** @var string[]|null Known secret values of this site (per request). */
	private static $known = null;

	/** @var string[]|null Regexes (alternations of the known secrets), per request. */
	private static $known_re = null;

	/**
	 * Substrings that mark an option value as possibly holding secrets
	 * (used to pre-filter the options table for known_secrets()).
	 */
	private static $value_hints = array(
		'pass', 'pwd', 'secret', 'token', 'apikey', 'api_key', 'api-key', 'accesskey', 'access_key',
		'privatekey', 'private_key', 'licensekey', 'license_key', 'licencekey', 'licence_key',
		'authkey', 'auth_key', 'signing', 'encryption', 'communication', 'potential', 'servicekey',
		'service_key', '"key"',
	);

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
	private static $sensitive_columns = array( 'user_pass', 'user_activation_key', 'consumer_key', 'consumer_secret' );

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
		return (bool) preg_match( '/^(\d{9,}:)?(\$P\$|\$H\$|\$wp\$|\$generic\$|\$2[abxy]\$\d\d\$|\$argon2(id|i|d)\$)/', $v )
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
				if ( in_array( $lc, self::$sensitive_columns, true ) || self::is_sensitive_key( $lc ) ) {
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
				if ( self::looks_like_secret_value( $val ) || self::contains_known_secret( $val ) ) {
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
	 * Secret values stored on this site: the values of secret-bearing options
	 * (mwp_potential_key …) and every value under a sensitive key inside any
	 * option (UpdraftPlus secretkey, SMTP passwords, licence keys …).
	 * Computed once per request.
	 *
	 * @return string[]
	 */
	public static function known_secrets() {
		if ( null !== self::$known ) {
			return self::$known;
		}
		global $wpdb;
		$where = array();
		$args  = array();
		foreach ( self::$blocked_option_patterns as $p ) {
			$where[] = 'option_name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $p ) . '%';
		}
		foreach ( self::$value_hints as $p ) {
			$where[] = 'option_value LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $p ) . '%';
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE LENGTH(option_value) < 1048576 AND ( " . implode( ' OR ', $where ) . ' )',
				$args
			),
			ARRAY_A
		);
		$found = array();
		foreach ( (array) $rows as $r ) {
			$raw  = (string) $r['option_value'];
			$data = null;
			if ( is_serialized( $raw ) ) {
				$data = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			} elseif ( isset( $raw[0] ) && ( '{' === $raw[0] || '[' === $raw[0] ) ) {
				$data = json_decode( $raw, true );
			}
			if ( is_array( $data ) || is_object( $data ) ) {
				self::collect_secrets( $data, $found );
			} elseif ( self::is_blocked_option( $r['option_name'] ) && ! self::is_bridge_store( $r['option_name'] ) ) {
				self::add_secret( $raw, $found );
			}
		}
		self::$known = array_keys( $found );
		return self::$known;
	}

	/**
	 * The bridge's own stores hold hashes/metadata, not usable secrets.
	 */
	private static function is_bridge_store( $name ) {
		return false !== strpos( (string) $name, 'goo1_mcp_' );
	}

	private static function collect_secrets( $value, &$found, $under_secret = false, $depth = 0 ) {
		if ( $depth > 32 ) {
			return;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $k => $v ) {
			$sensitive = self::is_sensitive_key( $k );
			if ( is_array( $v ) || is_object( $v ) ) {
				self::collect_secrets( $v, $found, $sensitive, $depth + 1 );
				continue;
			}
			if ( $sensitive || ( $under_secret && ( is_int( $k ) || in_array( strtolower( (string) $k ), array( 'value', 'default', 'val', 'current' ), true ) ) ) ) {
				self::add_secret( $v, $found );
			}
		}
	}

	private static function add_secret( $v, &$found ) {
		if ( ! is_string( $v ) ) {
			return;
		}
		$v = trim( $v );
		if ( strlen( $v ) < self::MIN_SECRET_LEN || self::MASK === $v || ! self::looks_random( $v ) ) {
			return;
		}
		$found[ $v ] = true;
	}

	/**
	 * Value matching only uses values that look like generated secrets, so
	 * settings such as "lost-password" or "disabled" never mask ordinary text:
	 * no whitespace, not only digits, and a digit or mixed case.
	 */
	private static function looks_random( $v ) {
		if ( preg_match( '/\s/', $v ) || ctype_digit( $v ) ) {
			return false;
		}
		$digit = (bool) preg_match( '/[0-9]/', $v );
		$mixed = preg_match( '/[a-z]/', $v ) && preg_match( '/[A-Z]/', $v );
		return $digit || $mixed;
	}

	/**
	 * Whether a string contains one of the site's known secret values.
	 */
	public static function contains_known_secret( $val ) {
		if ( ! is_string( $val ) || strlen( $val ) < self::MIN_SECRET_LEN ) {
			return false;
		}
		if ( null === self::$known_re ) {
			self::$known_re = array();
			foreach ( array_chunk( self::known_secrets(), 200 ) as $chunk ) {
				self::$known_re[] = '/' . implode( '|', array_map( function ( $x ) { return preg_quote( $x, '/' ); }, $chunk ) ) . '/';
			}
		}
		foreach ( self::$known_re as $re ) {
			if ( preg_match( $re, $val ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mask every string anywhere in a structure that contains a known secret.
	 */
	public static function redact_known( $value, $depth = 0 ) {
		if ( $depth > 32 ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return self::contains_known_secret( $value ) ? self::MASK : $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::redact_known( $v, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Forget the cached secret list (after an option was written).
	 */
	public static function reset_known() {
		self::$known    = null;
		self::$known_re = null;
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
	 * restore_masked() for meta values that may be JSON strings (e.g.
	 * _elementor_data): decode both sides, restore, encode again.
	 */
	public static function restore_masked_value( $new, $old ) {
		if ( is_string( $new ) && self::MASK !== $new && is_string( $old ) ) {
			$n = json_decode( $new, true );
			$o = json_decode( $old, true );
			if ( is_array( $n ) && is_array( $o ) ) {
				return wp_json_encode( self::restore_masked( $n, $o ) );
			}
		}
		return self::restore_masked( $new, $old );
	}

	/**
	 * Whether a value contains the mask anywhere.
	 */
	public static function contains_mask( $value ) {
		if ( self::MASK === $value ) {
			return true;
		}
		if ( is_string( $value ) && false !== strpos( $value, '"' . self::MASK . '"' ) ) {
			return true; // JSON string holding a masked value.
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
