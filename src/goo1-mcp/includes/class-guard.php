<?php
/**
 * Self-protection: the bridge's own settings (keys, grants, OAuth clients …)
 * live in `goo1_mcp_*` options. No bridge tool may change them, otherwise a
 * token could grant itself more rights.
 *
 * This is a tripwire, not a security boundary: a full-scope token that can
 * install plugins from any URL can run arbitrary code anyway. What it does
 * stop are the direct ways (options tools, raw SQL, the REST proxy).
 *
 *  - Option names are compared the way MySQL compares them (collation is
 *    accent- and width-insensitive: "ｇoo1_mcp_settings" IS goo1_mcp_settings),
 *    by resolving the stored name and by rejecting non-ASCII names.
 *  - Raw SQL writes run inside a transaction; if they touched bridge rows
 *    they are rolled back (no effect on concurrent legitimate writes).
 *  - Long operations (REST proxy, plugin installs) snapshot the core bridge
 *    settings and restore them if they changed.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Guard {

	const PREFIX = 'goo1_mcp_';

	/** Core settings checked around long operations (rarely written concurrently). */
	const CORE = array( 'goo1_mcp_settings', 'goo1_mcp_api_keys', 'goo1_mcp_oauth_clients' );

	/**
	 * Option names the bridge accepts from tools: plain ASCII only.
	 */
	public static function is_valid_option_name( $name ) {
		return is_string( $name ) && '' !== $name && strlen( $name ) <= 191 && (bool) preg_match( '/^[A-Za-z0-9_.\-:]+$/', $name );
	}

	/**
	 * The option name as stored in the table (resolves collation look-alikes),
	 * or the given name if no row matches.
	 */
	public static function stored_name( $name ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return $found ? (string) $found : (string) $name;
	}

	/**
	 * Whether an option belongs to the bridge itself (also via look-alikes).
	 */
	public static function is_protected_option( $name ) {
		if ( ! is_string( $name ) ) {
			return true;
		}
		foreach ( array( $name, self::stored_name( $name ) ) as $n ) {
			$n = strtolower( ltrim( $n ) );
			if ( 0 === strpos( $n, self::PREFIX ) || false !== strpos( $n, '_transient_goo1_mcp_' ) || false !== strpos( $n, '_transient_timeout_goo1_mcp_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rows that matter for self-protection: every option whose name contains
	 * goo1_mcp_ (settings and the OAuth token transients), except the rate
	 * limiter's transients which change on every request.
	 */
	private static function rows( $core_only = false ) {
		global $wpdb;
		if ( $core_only ) {
			$in   = implode( ',', array_fill( 0, count( self::CORE ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ($in)", self::CORE ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// Also every non-ASCII name: some collations ignore zero-width
					// characters for "=" but not for LIKE.
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE ( option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ) OR LENGTH(option_name) <> CHAR_LENGTH(option_name)",
					'%' . $wpdb->esc_like( self::PREFIX ) . '%',
					'%' . $wpdb->esc_like( '_transient_goo1_mcp_rl_' ) . '%',
					'%' . $wpdb->esc_like( '_transient_timeout_goo1_mcp_rl_' ) . '%'
				),
				ARRAY_A
			);
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['option_name'] ] = array( $r['option_value'], $r['autoload'] );
		}
		return $out;
	}

	private static function diff( $before, $after ) {
		$changed = array();
		foreach ( $before as $name => $row ) {
			if ( ! isset( $after[ $name ] ) || $after[ $name ][0] !== $row[0] || $after[ $name ][1] !== $row[1] ) {
				$changed[] = $name;
			}
		}
		foreach ( $after as $name => $row ) {
			if ( ! isset( $before[ $name ] ) ) {
				$changed[] = $name;
			}
		}
		return array_values( array_unique( $changed ) );
	}

	/**
	 * Run a raw SQL write; roll it back if it touched bridge rows.
	 *
	 * @param callable $fn Runs the query, returns its result.
	 * @return array( result, tampered[] , transactional )
	 */
	public static function run_sql_write( $fn ) {
		global $wpdb;
		$tx = false !== $wpdb->query( 'START TRANSACTION' );
		$before = self::rows();
		$result = call_user_func( $fn );
		$after  = self::rows();
		$tampered = self::diff( $before, $after );

		if ( $tampered ) {
			if ( $tx ) {
				$wpdb->query( 'ROLLBACK' );
				// Non-transactional tables (MyISAM) cannot be rolled back: restore by hand.
				$tampered_after = self::diff( $before, self::rows() );
				if ( $tampered_after ) {
					self::restore( $before, $tampered_after );
				}
			} else {
				self::restore( $before, $tampered );
			}
			self::flush_cache( $tampered );
		} elseif ( $tx ) {
			$wpdb->query( 'COMMIT' );
		}
		return array( 'result' => $result, 'tampered' => $tampered, 'transactional' => $tx );
	}

	/**
	 * Snapshot of the core bridge settings (for long operations).
	 */
	public static function snapshot() {
		return self::rows( true );
	}

	/**
	 * Restore core bridge settings if they changed since snapshot().
	 *
	 * @return string[] Names that were restored.
	 */
	public static function restore_if_changed( $before ) {
		$tampered = self::diff( $before, self::rows( true ) );
		if ( $tampered ) {
			self::restore( $before, $tampered );
			self::flush_cache( $tampered );
		}
		return $tampered;
	}

	private static function restore( $before, $names ) {
		global $wpdb;
		foreach ( $names as $name ) {
			if ( isset( $before[ $name ] ) ) {
				$wpdb->replace(
					$wpdb->options,
					array( 'option_name' => $name, 'option_value' => $before[ $name ][0], 'autoload' => $before[ $name ][1] ),
					array( '%s', '%s', '%s' )
				);
			} else {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
			}
		}
	}

	private static function flush_cache( $names ) {
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
			if ( 0 === strpos( $name, '_transient_' ) ) {
				wp_cache_delete( substr( $name, 11 ), 'transient' );
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
