<?php
/**
 * File tools: content checks before a file is saved (PHP syntax, JSON,
 * .htaccess lines) and text/binary detection.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_File_Rules {

	/**
	 * First line of every .htaccess the bridge writes. Only files that start
	 * with it are changed or deleted by the bridge; any other .htaccess
	 * (another plugin's, the host's) is left alone, also a pure block file.
	 */
	const HTACCESS_MARKER = '# goo1-mcp: written by goo1 Claude Bridge';

	/** Extensions checked as PHP (syntax check + loopback). */
	const PHP_EXT = array( 'php', 'phtml', 'pht', 'phar', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8' );

	/**
	 * Whether content is text: valid UTF-8 without NUL bytes.
	 */
	public static function is_text( $content ) {
		if ( '' === $content ) {
			return true;
		}
		if ( false !== strpos( $content, "\0" ) ) {
			return false;
		}
		return function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $content, 'UTF-8' ) : (bool) preg_match( '//u', $content );
	}

	/**
	 * Whether a file is checked as PHP: PHP extensions, and .inc files that
	 * contain an opening PHP tag.
	 */
	public static function is_php( $name, $content = null ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::PHP_EXT, true ) ) {
			return true;
		}
		if ( 'inc' === $ext && is_string( $content ) ) {
			return false !== stripos( $content, '<?php' ) || false !== strpos( $content, '<?=' );
		}
		return false;
	}

	public static function is_json( $name ) {
		return 'json' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	}

	public static function is_htaccess( $name ) {
		return '.htaccess' === strtolower( basename( $name ) );
	}

	/**
	 * Whether a browser would take this content for HTML, XML or SVG when
	 * the server sends no (or a generic) Content-Type: the first character
	 * after an optional UTF-8 BOM and whitespace is "<" (a superset of the
	 * WHATWG "unknown MIME type" HTML/XML patterns). Used below uploads,
	 * where WordPress allows types the web server may not know (.3gp,
	 * .midi, .rtx …) and the browser would then sniff the content.
	 */
	public static function looks_like_markup( $head ) {
		$head = (string) $head;
		if ( 0 === strpos( $head, "\xEF\xBB\xBF" ) ) {
			$head = substr( $head, 3 );
		}
		$head = ltrim( $head, "\x09\x0A\x0C\x0D\x20" );
		return '' !== $head && '<' === $head[0];
	}

	/** Whether a .htaccess was written by the bridge (first line is the marker). */
	public static function htaccess_is_own( $content ) {
		$content = (string) $content;
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}
		$nl    = strpos( $content, "\n" );
		$first = false === $nl ? $content : substr( $content, 0, $nl );
		return 0 === strpos( rtrim( $first, "\r" ), self::HTACCESS_MARKER );
	}

	/** Content with the marker as its first line (added when missing). */
	public static function htaccess_with_marker( $content ) {
		if ( self::htaccess_is_own( $content ) ) {
			return (string) $content;
		}
		$eol = 'crlf' === self::eol( (string) $content ) ? "\r\n" : "\n";
		return self::HTACCESS_MARKER . $eol . (string) $content;
	}

	/**
	 * PHP syntax check with the site's own PHP (no exec needed).
	 *
	 * @return string '' when valid, otherwise "message (line n)".
	 */
	public static function php_error( $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return sprintf( '%s (line %d)', $e->getMessage(), $e->getLine() );
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * JSON check.
	 *
	 * @return string '' when valid, otherwise the error with line and column.
	 */
	public static function json_error( $content ) {
		$content = (string) $content;
		$probe   = 0 === strpos( $content, "\xEF\xBB\xBF" ) ? substr( $content, 3 ) : $content;
		json_decode( $probe );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return '';
		}
		$msg = json_last_error_msg();
		$pos = self::json_error_offset( $probe );
		if ( null === $pos ) {
			return 'Invalid JSON: ' . $msg;
		}
		$before = substr( $probe, 0, $pos );
		$line   = substr_count( $before, "\n" ) + 1;
		$nl     = strrpos( $before, "\n" );
		$col    = $pos - ( false === $nl ? 0 : $nl + 1 ) + 1;
		return sprintf( 'Invalid JSON: %s (line %d, column %d)', $msg, $line, $col );
	}

	/**
	 * Offset of the first JSON syntax error (small scanner, iterative).
	 * Only used to locate an error json_decode() already reported.
	 *
	 * @return int|null
	 */
	private static function json_error_offset( $s ) {
		$len   = strlen( $s );
		$i     = 0;
		$stack = array(); // Containers: '{' or '['.
		$want  = 'value'; // value | key | colon | comma_or_end | end
		while ( true ) {
			while ( $i < $len && false !== strpos( " \t\r\n", $s[ $i ] ) ) {
				$i++;
			}
			if ( $i >= $len ) {
				return ( 'end' === $want && ! $stack ) ? null : $len;
			}
			$c = $s[ $i ];
			if ( 'end' === $want ) {
				return $i;
			}
			if ( 'colon' === $want ) {
				if ( ':' !== $c ) {
					return $i;
				}
				$i++;
				$want = 'value';
				continue;
			}
			if ( 'comma_or_end' === $want ) {
				$top = end( $stack );
				if ( ',' === $c ) {
					$i++;
					$want = '{' === $top ? 'key' : 'value';
					continue;
				}
				if ( ( '}' === $c && '{' === $top ) || ( ']' === $c && '[' === $top ) ) {
					array_pop( $stack );
					$i++;
					$want = $stack ? 'comma_or_end' : 'end';
					continue;
				}
				return $i;
			}
			if ( 'key' === $want ) {
				if ( '}' === $c && '{' === end( $stack ) && $i > 0 && '{' === self::prev_non_ws( $s, $i ) ) {
					array_pop( $stack );
					$i++;
					$want = $stack ? 'comma_or_end' : 'end';
					continue;
				}
				if ( '"' !== $c ) {
					return $i;
				}
				$end = self::json_string_end( $s, $i );
				if ( null === $end ) {
					return $i;
				}
				$i    = $end;
				$want = 'colon';
				continue;
			}
			// A value.
			if ( '{' === $c ) {
				$stack[] = '{';
				$i++;
				$want = 'key';
				continue;
			}
			if ( '[' === $c ) {
				$stack[] = '[';
				$i++;
				if ( preg_match( '/\G\s*\]/', $s, $m, 0, $i ) ) {
					$i += strlen( $m[0] );
					array_pop( $stack );
					$want = $stack ? 'comma_or_end' : 'end';
				}
				continue;
			}
			if ( '"' === $c ) {
				$end = self::json_string_end( $s, $i );
				if ( null === $end ) {
					return $i;
				}
				$i = $end;
			} elseif ( preg_match( '/\G(?:-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)/', $s, $m, 0, $i ) ) {
				$i += strlen( $m[0] );
			} else {
				return $i;
			}
			$want = $stack ? 'comma_or_end' : 'end';
		}
	}

	private static function prev_non_ws( $s, $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( false === strpos( " \t\r\n", $s[ $j ] ) ) {
				return $s[ $j ];
			}
		}
		return '';
	}

	/** Offset after the closing quote of a JSON string starting at $i, or null. */
	private static function json_string_end( $s, $i ) {
		$len = strlen( $s );
		for ( $j = $i + 1; $j < $len; $j++ ) {
			$c = $s[ $j ];
			if ( '\\' === $c ) {
				$j++;
				continue;
			}
			if ( '"' === $c ) {
				return $j + 1;
			}
			if ( ord( $c ) < 0x20 ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * .htaccess lines that only block access. Everything else (handlers,
	 * php_value, rewrites, Allow) could undo the PHP block below uploads.
	 *
	 * @return string[] Offending lines (empty = only allowed lines).
	 */
	public static function htaccess_bad_lines( $content ) {
		$bad   = array();
		$depth = array();
		// Per section: "Order deny,allow" without "Deny from all" makes the
		// default allow and, since mod_access_compat does not merge with the
		// parent folder, would lift a parent's "Deny from all".
		$frames = array( array( 'order_da' => false, 'deny' => false, 'line' => 0 ) );
		// Apache ends lines at LF only (a CR before it is dropped). A lone CR
		// would make this check see two lines where Apache sees one.
		foreach ( explode( "\n", (string) $content ) as $n => $raw ) {
			$raw = rtrim( $raw, "\r" );
			if ( false !== strpos( $raw, "\r" ) ) {
				$bad[] = sprintf( 'line %d: carriage return inside the line', $n + 1 );
				continue;
			}
			$line = trim( $raw );
			// A backslash at the end continues the line in Apache, also for a
			// comment, which would swallow the next (blocking) line.
			if ( '' !== $line && '\\' === substr( $line, -1 ) ) {
				$bad[] = sprintf( 'line %d: %s (line continuation with \\ is not allowed)', $n + 1, $line );
				continue;
			}
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^<(Files|FilesMatch)(\s+~)?\s+("[^"<>]*"|[^\s"<>]+)\s*>$/i', $line, $m ) ) {
				$depth[]  = strtolower( $m[1] );
				$frames[] = array( 'order_da' => false, 'deny' => false, 'line' => 0 );
				continue;
			}
			if ( preg_match( '/^<IfModule\s+!?mod_authz_core\.c\s*>$/i', $line ) ) {
				$depth[]  = 'ifmodule';
				$frames[] = array( 'order_da' => false, 'deny' => false, 'line' => 0 );
				continue;
			}
			if ( preg_match( '/^<\/(Files|FilesMatch|IfModule)\s*>$/i', $line, $m ) ) {
				if ( ! $depth || array_pop( $depth ) !== strtolower( $m[1] ) ) {
					$bad[] = sprintf( 'line %d: %s (unbalanced block)', $n + 1, $line );
				} else {
					$f = array_pop( $frames );
					if ( $f['order_da'] && ! $f['deny'] ) {
						$bad[] = sprintf( 'line %d: Order deny,allow without Deny from all in the same section (would allow access)', $f['line'] );
					}
				}
				continue;
			}
			if ( preg_match( '/^Order\s+deny\s*,\s*allow$/i', $line ) ) {
				$frames[ count( $frames ) - 1 ]['order_da'] = true;
				$frames[ count( $frames ) - 1 ]['line']     = $n + 1;
				continue;
			}
			if ( preg_match( '/^Deny\s+from\s+all$/i', $line ) ) {
				$frames[ count( $frames ) - 1 ]['deny'] = true;
				continue;
			}
			if ( preg_match( '/^(Require\s+all\s+denied|Order\s+allow\s*,\s*deny|Options\s+-Indexes)$/i', $line ) ) {
				continue;
			}
			$bad[] = sprintf( 'line %d: %s', $n + 1, $line );
		}
		if ( $depth ) {
			$bad[] = 'unclosed block: <' . implode( '>, <', $depth ) . '>';
		} elseif ( $frames[0]['order_da'] && ! $frames[0]['deny'] ) {
			$bad[] = sprintf( 'line %d: Order deny,allow without Deny from all in the same section (would allow access)', $frames[0]['line'] );
		}
		return $bad;
	}

	/**
	 * Line ending style of a text: lf, crlf, cr, mixed or none.
	 */
	public static function eol( $content ) {
		$crlf = substr_count( $content, "\r\n" );
		$lf   = substr_count( $content, "\n" ) - $crlf;
		$cr   = substr_count( $content, "\r" ) - $crlf;
		if ( ! $crlf && ! $lf && ! $cr ) {
			return 'none';
		}
		if ( $crlf && ! $lf && ! $cr ) {
			return 'crlf';
		}
		if ( $lf && ! $crlf && ! $cr ) {
			return 'lf';
		}
		if ( $cr && ! $crlf && ! $lf ) {
			return 'cr';
		}
		return 'mixed';
	}
}
