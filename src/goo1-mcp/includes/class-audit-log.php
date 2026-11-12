<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Audit_Log {

	private static $instance = null;

	/** @var string[] Flags collected for the request currently being handled. */
	private static $flags = array();

	/**
	 * Mark the current request (e.g. 'unredacted', 'sql_files', 'guard_restored').
	 */
	public static function add_flag( $flag ) {
		self::$flags[] = (string) $flag;
	}

	/**
	 * Return and clear the collected flags.
	 */
	public static function take_flags() {
		$f           = implode( ',', array_unique( self::$flags ) );
		self::$flags = array();
		return $f;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Log an API request.
	 */
	public function log( $key_label, $endpoint, $method, $params, $response_code, $flags = '' ) {
		global $wpdb;

		// Strip internal params and mask secrets in the summary.
		$safe_params = is_array( $params ) ? $params : array();
		unset( $safe_params['_goo1_mcp_key'] );
		$safe_params = Goo1_MCP_Redactor::redact( self::shrink( $safe_params ) );
		$summary     = wp_json_encode( $safe_params );
		if ( strlen( $summary ) > 2000 ) {
			$summary = substr( $summary, 0, 2000 ) . '...[truncated]';
		}

		$wpdb->insert(
			$wpdb->prefix . 'goo1_mcp_audit_log',
			array(
				'api_key_label'   => sanitize_text_field( $key_label ),
				'endpoint'        => sanitize_text_field( $endpoint ),
				'method'          => sanitize_text_field( $method ),
				'request_summary' => $summary,
				'response_code'   => (int) $response_code,
				'ip_address'      => $this->get_client_ip(),
				'flags'           => substr( sanitize_text_field( (string) $flags ), 0, 64 ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Replace oversized strings (base64 uploads, Elementor JSON …) anywhere in
	 * the params with a short placeholder.
	 */
	public static function shrink( $value, $depth = 0 ) {
		if ( is_string( $value ) && strlen( $value ) > 2048 ) {
			return '[' . strlen( $value ) . ' bytes omitted]';
		}
		if ( is_array( $value ) && $depth < 8 ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::shrink( $v, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Get paginated audit log entries.
	 */
	public function get_entries( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page' => 50,
			'page'     => 1,
			'label'    => '',
			'method'   => '',
		);
		$args  = wp_parse_args( $args, $defaults );
		$table = $wpdb->prefix . 'goo1_mcp_audit_log';
		$where = array( '1=1' );
		$values = array();

		if ( $args['label'] ) {
			$where[]  = 'api_key_label = %s';
			$values[] = $args['label'];
		}
		if ( $args['method'] ) {
			$where[]  = 'method = %s';
			$values[] = $args['method'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];

		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$query_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";

		$values_with_limit = array_merge( $values, array( (int) $args['per_page'], $offset ) );

		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$items = $wpdb->get_results( $wpdb->prepare( $query_sql, $values_with_limit ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$items = $wpdb->get_results( $wpdb->prepare( $query_sql, array( (int) $args['per_page'], $offset ) ) );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => ceil( $total / (int) $args['per_page'] ),
			'page'     => (int) $args['page'],
			'per_page' => (int) $args['per_page'],
		);
	}

	/**
	 * Purge old entries.
	 */
	public function purge( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'goo1_mcp_audit_log';
		// Entries are written in site time (current_time('mysql')); compare in
		// the same time base instead of the database server's NOW().
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - max( 1, (int) $days ) * DAY_IN_SECONDS );
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE created_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Official Cloudflare ranges (https://www.cloudflare.com/ips/, last
	 * changed 2023-09-28). Used when no trusted proxies are configured.
	 */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/**
	 * Client IP for the audit log. Forwarding headers are only believed when
	 * the request comes from a trusted proxy, otherwise anyone could write
	 * any IP into the log:
	 *  - Setting "Trusted proxies" empty: Cloudflare is recognised by its
	 *    official ranges (then CF-Connecting-IP), everything else is REMOTE_ADDR.
	 *  - Otherwise: the listed IPs/CIDRs (the word "cloudflare" adds the
	 *    Cloudflare ranges). From a trusted peer, CF-Connecting-IP (only if the
	 *    peer is Cloudflare), then the right-most untrusted X-Forwarded-For
	 *    entry, then X-Real-IP.
	 * Caveats (audit log only, the IP is not used for access decisions): a
	 * trusted proxy that only sets X-Real-IP and passes the client's own
	 * X-Forwarded-For through lets the client choose the logged IP; and with
	 * "cloudflare" trusted, a request sent from Cloudflare's network (e.g. a
	 * Worker) to a directly reachable origin can set CF-Connecting-IP.
	 */
	private function get_client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( ! self::valid_ip( $remote ) ) {
			return '0.0.0.0';
		}
		return self::client_ip_from( $remote, $_SERVER, self::trusted_proxies() ); // phpcs:ignore
	}

	/**
	 * Pure resolution logic (unit-testable).
	 *
	 * @param string $remote  REMOTE_ADDR.
	 * @param array  $server  $_SERVER-like array.
	 * @param array  $trusted array( 'ranges' => string[], 'cloudflare' => bool ).
	 * @return string
	 */
	public static function client_ip_from( $remote, $server, $trusted ) {
		$remote = self::normalize_ip( $remote );
		$is_cf  = self::ip_in_ranges( $remote, self::CLOUDFLARE_RANGES );
		if ( $is_cf && $trusted['cloudflare'] ) {
			$cf = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? self::normalize_ip( trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) ) : '';
			if ( self::valid_ip( $cf ) ) {
				return $cf;
			}
		}
		$is_trusted = ( $is_cf && $trusted['cloudflare'] ) || self::ip_in_ranges( $remote, $trusted['ranges'] );
		if ( ! $is_trusted ) {
			return $remote;
		}
		if ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			$hops = array_reverse( array_map( function ( $h ) { return self::normalize_ip( trim( $h ) ); }, explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) );
			foreach ( $hops as $hop ) {
				if ( ! self::valid_ip( $hop ) ) {
					break;
				}
				$hop_trusted = self::ip_in_ranges( $hop, $trusted['ranges'] ) || ( $trusted['cloudflare'] && self::ip_in_ranges( $hop, self::CLOUDFLARE_RANGES ) );
				if ( ! $hop_trusted ) {
					return $hop;
				}
			}
		}
		$real = isset( $server['HTTP_X_REAL_IP'] ) ? self::normalize_ip( trim( (string) $server['HTTP_X_REAL_IP'] ) ) : '';
		if ( self::valid_ip( $real ) ) {
			return $real;
		}
		return $remote;
	}

	/**
	 * Trusted proxies from the settings.
	 *
	 * @return array( 'ranges' => string[], 'cloudflare' => bool )
	 */
	public static function trusted_proxies() {
		$settings = (array) get_option( 'goo1_mcp_settings', array() );
		$raw      = isset( $settings['trusted_proxies'] ) ? (string) $settings['trusted_proxies'] : '';
		return self::parse_trusted_proxies( $raw );
	}

	public static function parse_trusted_proxies( $raw ) {
		$ranges = array();
		$cf     = false;
		$any    = false;
		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			$any = true;
			if ( 'cloudflare' === strtolower( $entry ) ) {
				$cf = true;
			} elseif ( self::valid_range( $entry ) ) {
				$ranges[] = $entry;
			}
		}
		// Empty setting: recognise Cloudflare automatically.
		return array( 'ranges' => $ranges, 'cloudflare' => $any ? $cf : true );
	}

	/**
	 * IPv4-mapped IPv6 (::ffff:172.18.0.2, dual-stack Apache) → plain IPv4,
	 * so it matches IPv4 ranges.
	 */
	public static function normalize_ip( $ip ) {
		$ip = (string) $ip;
		if ( 0 === stripos( $ip, '::ffff:' ) ) {
			$v4 = substr( $ip, 7 );
			if ( false !== filter_var( $v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $v4;
			}
		}
		return $ip;
	}

	public static function valid_ip( $ip ) {
		return is_string( $ip ) && '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	public static function valid_range( $range ) {
		$parts = explode( '/', (string) $range, 2 );
		if ( ! self::valid_ip( $parts[0] ) ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;
		return ctype_digit( $parts[1] ) && (int) $parts[1] <= $max;
	}

	/**
	 * Whether an IP lies in one of the ranges (single IPs or CIDR, v4 and v6).
	 */
	public static function ip_in_ranges( $ip, $ranges ) {
		$bin = self::valid_ip( $ip ) ? inet_pton( $ip ) : false;
		if ( false === $bin ) {
			return false;
		}
		foreach ( (array) $ranges as $range ) {
			$parts = explode( '/', (string) $range, 2 );
			$net   = self::valid_ip( $parts[0] ) ? inet_pton( $parts[0] ) : false;
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
				continue;
			}
			$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $bin ) * 8;
			$full = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $full ) !== substr( $net, 0, $full ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );
			if ( ( $bin[ $full ] & $mask ) === ( $net[ $full ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}
}
