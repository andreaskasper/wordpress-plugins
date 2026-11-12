<?php
/**
 * File tools: checks after a file was saved.
 *
 * PHP: loopback requests to the front page and to admin-ajax.php (is_admin(),
 * admin_init runs, no login needed) with WordPress' own scrape parameters
 * (wp_scrape_key / wp_scrape_nonce, see wp_start_scraping_edited_file_errors()).
 * WordPress then appends the last fatal error (file, line, message) to the
 * response and skips recovery mode, so the site owner gets no
 * "technical issue" e-mail for a test. This works with DISALLOW_FILE_EDIT.
 *
 * .htaccess: one request to the folder and one to a file in it; a 500 means
 * the server refuses a directive (e.g. Options not allowed by AllowOverride).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_File_Verify {

	const TIMEOUT = 15;

	private static function request( $url ) {
		return wp_remote_get( $url, array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // Same as core's loopback checks.
			'headers'     => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
		) );
	}

	/**
	 * Load the site after a PHP change.
	 *
	 * @return array status ok|fatal|inconclusive, error (fatal), checks[].
	 */
	public static function php() {
		$key   = md5( wp_generate_password( 32, false ) );
		$nonce = wp_generate_password( 24, false );
		set_transient( 'scrape_key_' . $key, $nonce, 120 );

		$args = array(
			'wp_scrape_key'   => $key,
			'wp_scrape_nonce' => $nonce,
			'goo1_mcp_cb'     => wp_generate_password( 8, false ),
		);
		$targets = array(
			'front' => add_query_arg( $args, home_url( '/' ) ),
			'admin' => add_query_arg( array_merge( array( 'action' => 'goo1_mcp_file_check' ), $args ), admin_url( 'admin-ajax.php' ) ),
		);

		$checks = array();
		$fatal  = null;
		$unsure = array();
		foreach ( $targets as $name => $url ) {
			$res = self::request( $url );
			if ( is_wp_error( $res ) ) {
				$checks[ $name ] = array( 'result' => 'no_response', 'message' => $res->get_error_message() );
				$unsure[]        = $name . ': ' . $res->get_error_message();
				continue;
			}
			$code   = (int) wp_remote_retrieve_response_code( $res );
			$body   = (string) wp_remote_retrieve_body( $res );
			$result = self::scrape_result( $body, $key );
			if ( is_array( $result ) && isset( $result['message'] ) && ! isset( $result['code'] ) ) {
				$err = array(
					'message' => Goo1_MCP_Redactor::redact_text( (string) $result['message'] ),
					'file'    => isset( $result['file'] ) ? (string) $result['file'] : '',
					'line'    => isset( $result['line'] ) ? (int) $result['line'] : 0,
				);
				$checks[ $name ] = array( 'result' => 'fatal', 'http_status' => $code ) + $err;
				if ( ! $fatal ) {
					$fatal = $err;
				}
			} elseif ( true === $result ) {
				$checks[ $name ] = array( 'result' => 'ok', 'http_status' => $code );
			} elseif ( $code >= 500 ) {
				// No scrape result but a server error: treat as broken.
				$checks[ $name ] = array( 'result' => 'http_error', 'http_status' => $code );
				if ( ! $fatal ) {
					$fatal = array( 'message' => sprintf( 'HTTP %d without a PHP error report', $code ), 'file' => '', 'line' => 0 );
				}
			} else {
				$why             = is_array( $result ) && isset( $result['code'] ) ? (string) $result['code'] : 'no scrape result in the response (cache, firewall or challenge page?)';
				$checks[ $name ] = array( 'result' => 'inconclusive', 'http_status' => $code, 'message' => $why );
				$unsure[]        = $name . ': HTTP ' . $code . ', ' . $why;
			}
		}
		delete_transient( 'scrape_key_' . $key );

		if ( $fatal ) {
			return array( 'status' => 'fatal', 'error' => $fatal, 'checks' => $checks );
		}
		if ( $unsure ) {
			return array( 'status' => 'inconclusive', 'reason' => implode( '; ', $unsure ), 'checks' => $checks );
		}
		return array( 'status' => 'ok', 'checks' => $checks );
	}

	/**
	 * Parse the block WordPress appends in scrape mode.
	 *
	 * @return mixed true, an error array, or null when absent.
	 */
	private static function scrape_result( $body, $key ) {
		$start = '###### wp_scraping_result_start:' . $key . ' ######';
		$end   = '###### wp_scraping_result_end:' . $key . ' ######';
		$a     = strrpos( $body, $start );
		if ( false === $a ) {
			return null;
		}
		$a += strlen( $start );
		$b  = strpos( $body, $end, $a );
		if ( false === $b ) {
			return null;
		}
		return json_decode( trim( substr( $body, $a, $b - $a ) ), true );
	}

	/**
	 * Check a folder after its .htaccess changed.
	 *
	 * @param string $dir_rel Folder relative to ABSPATH.
	 * @param string $abs_dir Absolute folder path (to pick a sample file).
	 * @return array status ok|error|inconclusive, folder (HTTP status), sample.
	 */
	public static function htaccess( $dir_rel, $abs_dir ) {
		$base   = trailingslashit( site_url( '/' ) );
		$encode = function ( $rel ) {
			return implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
		};
		$cb  = 'goo1_mcp_cb=' . wp_generate_password( 8, false );
		$out = array( 'status' => 'ok' );

		$res = self::request( $base . $encode( $dir_rel ) . '/?' . $cb );
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 'inconclusive', 'reason' => $res->get_error_message() );
		}
		$out['folder_status'] = (int) wp_remote_retrieve_response_code( $res );

		$sample = '';
		foreach ( (array) @scandir( $abs_dir ) as $f ) { // phpcs:ignore
			// Never request a script: that would run it without a login.
			$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, Goo1_MCP_File_Access::SCRIPT_EXT, true ) || in_array( $ext, Goo1_MCP_File_Rules::PHP_EXT, true ) || 'inc' === $ext ) {
				continue;
			}
			if ( '.' !== $f[0] && is_file( $abs_dir . '/' . $f ) ) {
				$sample = $f;
				break;
			}
		}
		if ( '' !== $sample ) {
			$res = self::request( $base . $encode( $dir_rel . '/' . $sample ) . '?' . $cb );
			$out['sample'] = array(
				'file'        => $dir_rel . '/' . $sample,
				'http_status' => is_wp_error( $res ) ? null : (int) wp_remote_retrieve_response_code( $res ),
			);
		}
		if ( $out['folder_status'] >= 500 || ( isset( $out['sample']['http_status'] ) && $out['sample']['http_status'] >= 500 ) ) {
			$out['status'] = 'error';
		}
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : ''; // phpcs:ignore
		if ( false !== strpos( $software, 'nginx' ) ) {
			$out['note'] = 'The web server reports nginx: .htaccess files have no effect there (unless Apache sits behind it).';
		}
		return $out;
	}
}
