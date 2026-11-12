<?php
/**
 * File tools (since 1.9): list, read, search, write, edit, fetch, delete,
 * backups, restore – only inside folders shared in wp-admin (see
 * Goo1_MCP_File_Access). Reading needs scope read; changing needs scope full,
 * the grant "Write files" (file_write) and a folder shared read-write.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-files-write.php';

class Goo1_MCP_Files_Controller extends Goo1_MCP_Base_Controller {

	use Goo1_MCP_Files_Write;

	/** Files larger than this are not hashed in listings / not searched. */
	const HASH_MAX_BYTES   = 5242880;
	const LIST_MAX_ENTRIES = 2000;
	const LIST_HASH_BUDGET = 52428800;
	const SEARCH_MAX_HITS  = 200;
	const SEARCH_MAX_FILES = 2000;
	const SEARCH_MAX_BYTES = 20971520;
	const SEARCH_LINE_MAX  = 500;

	/** Redirects wp_file_fetch follows itself (each target checked like the URL). */
	const FETCH_MAX_REDIRECTS = 3;

	public function register_routes() {
		$read  = $this->get_permission_callback( 'read' );
		$write = $this->get_permission_callback( 'full' );
		foreach ( array(
			array( '/files/list', 'GET', 'list_files', $read ),
			array( '/files/read', 'GET', 'read_file', $read ),
			array( '/files/search', 'GET', 'search', $read ),
			array( '/files/backups', 'GET', 'backups', $read ),
			array( '/files/write', 'POST', 'write_file', $write ),
			array( '/files/edit', 'POST', 'edit_file', $write ),
			array( '/files/fetch', 'POST', 'fetch_file', $write ),
			array( '/files/delete', 'POST', 'delete_file', $write ),
			array( '/files/restore', 'POST', 'restore', $write ),
		) as $r ) {
			register_rest_route( self::NAMESPACE, $r[0], array(
				'methods'             => $r[1],
				'callback'            => array( $this, $r[2] ),
				'permission_callback' => $r[3],
			) );
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	protected function fail( $err ) {
		$data   = $err->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$body   = array(
			'success' => false,
			'error'   => array(
				'code'    => $err->get_error_code(),
				'message' => $err->get_error_message(),
			),
		);
		if ( is_array( $data ) ) {
			unset( $data['status'] );
			if ( $data ) {
				$body['error'] = array_merge( $body['error'], $data );
			}
		}
		return new WP_REST_Response( $body, $status );
	}

	protected static function flag( $request, $name ) {
		$v = $request->get_param( $name );
		return true === $v || 1 === $v || 'true' === $v || '1' === $v;
	}

	/** Changing files needs the grant "Write files" on the key/connection. */
	protected function can_write() {
		if ( Goo1_MCP_Permissions::instance()->allow_file_write() ) {
			return true;
		}
		return new WP_Error( 'goo1_mcp_no_file_write', 'Changing files needs the grant "Write files" for this key/connection (wp-admin → Claude Bridge → API Keys or Connector; master switch "Extra rights" in Settings).', array( 'status' => 403 ) );
	}

	protected static function file_info( $abs, $rel, $with_hash = true ) {
		$is_dir  = is_dir( $abs );
		$is_link = is_link( $abs );
		$info    = array(
			'path'  => $rel,
			'type'  => $is_dir ? 'dir' : 'file',
			'mtime' => gmdate( 'c', (int) @filemtime( $abs ) ), // phpcs:ignore
			'perms' => substr( sprintf( '%o', (int) @fileperms( $abs ) ), -4 ), // phpcs:ignore
		);
		if ( $is_link ) {
			$info['symlink'] = true;
		}
		if ( ! $is_dir ) {
			$info['size'] = (int) @filesize( $abs ); // phpcs:ignore
			if ( $with_hash ) {
				$info['sha256'] = $info['size'] <= self::HASH_MAX_BYTES ? Goo1_MCP_File_Access::hash_file( $abs ) : null;
			}
		}
		return $info;
	}

	/* ------------------------------------------------------------------
	 * wp_file_list
	 * ---------------------------------------------------------------- */

	public function list_files( $request ) {
		$path = trim( (string) $request->get_param( 'path' ) );
		if ( '' === $path ) {
			if ( ! Goo1_MCP_File_Access::enabled() ) {
				return $this->fail( Goo1_MCP_File_Access::resolve( '', 'read' ) );
			}
			$roots = array();
			foreach ( Goo1_MCP_File_Access::roots() as $r ) {
				$roots[] = array( 'path' => '' === $r['path'] ? '.' : $r['path'], 'mode' => $r['mode'] );
			}
			return $this->success( array(
				'shared_folders' => $roots,
				'can_write'      => Goo1_MCP_Permissions::instance()->allow_file_write(),
				'hint'           => 'Call again with one of these paths. Changing files needs a folder shared rw and the grant "Write files".',
			) );
		}
		$t = Goo1_MCP_File_Access::resolve( $path, 'read' );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		if ( ! $t['exists'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_not_found', 'Not found: ' . $t['rel'], array( 'status' => 404 ) ) );
		}
		if ( ! $t['is_dir'] ) {
			return $this->success( array( 'path' => $t['rel'], 'entries' => array( self::file_info( $t['abs'], $t['rel'] ) ) ) );
		}
		$depth   = max( 0, min( 5, (int) $request->get_param( 'depth' ) ) );
		$entries = array();
		$budget  = self::LIST_HASH_BUDGET;
		$capped  = false;
		$this->walk_list( $t['abs'], $t['rel'], $depth, $entries, $budget, $capped );
		return $this->success( array(
			'path'      => $t['rel'],
			'root'      => $t['root']['path'],
			'mode'      => $t['root']['mode'],
			'entries'   => $entries,
			'count'     => count( $entries ),
			'truncated' => $capped,
			'hint'      => $capped ? sprintf( 'Stopped after %d entries; list a subfolder or lower depth.', self::LIST_MAX_ENTRIES ) : null,
		) );
	}

	private function walk_list( $abs, $rel, $depth, &$entries, &$budget, &$capped ) {
		$names = @scandir( $abs ); // phpcs:ignore
		if ( ! is_array( $names ) ) {
			return;
		}
		foreach ( $names as $n ) {
			if ( '.' === $n || '..' === $n ) {
				continue;
			}
			if ( count( $entries ) >= self::LIST_MAX_ENTRIES ) {
				$capped = true;
				return;
			}
			$child_rel = '' === $rel ? $n : $rel . '/' . $n;
			$c         = Goo1_MCP_File_Access::resolve( $child_rel, 'read' );
			if ( is_wp_error( $c ) ) {
				$entries[] = array( 'path' => $child_rel, 'blocked' => true );
				continue;
			}
			$child_abs = $abs . '/' . $n;
			$hash      = ! $c['is_dir'] && $budget > 0;
			$info      = self::file_info( $child_abs, $child_rel, $hash );
			if ( $hash && isset( $info['size'] ) ) {
				$budget -= $info['size'];
			}
			$entries[] = $info;
			if ( $depth > 0 && $c['is_dir'] && ! is_link( $child_abs ) ) {
				$this->walk_list( $child_abs, $child_rel, $depth - 1, $entries, $budget, $capped );
				if ( $capped ) {
					return;
				}
			}
		}
	}

	/* ------------------------------------------------------------------
	 * wp_file_read
	 * ---------------------------------------------------------------- */

	public function read_file( $request ) {
		$t = Goo1_MCP_File_Access::resolve( (string) $request->get_param( 'path' ), 'read' );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		if ( ! $t['exists'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_not_found', 'Not found: ' . $t['rel'], array( 'status' => 404 ) ) );
		}
		if ( $t['is_dir'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_is_dir', 'This is a folder; use wp_file_list.', array( 'status' => 400 ) ) );
		}
		$abs      = $t['abs'];
		$size     = (int) filesize( $abs );
		$settings = Goo1_MCP_OAuth::settings();
		$max      = max( 1, (int) $settings['file_max_read_kb'] ) * 1024;
		$offset   = max( 1, (int) ( $request->get_param( 'offset' ) ?: 1 ) );
		$limit    = max( 0, (int) $request->get_param( 'limit' ) );

		$fp = @fopen( $abs, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			return $this->fail( new WP_Error( 'goo1_mcp_unreadable', 'The file cannot be read (permissions?).', array( 'status' => 500 ) ) );
		}
		$head = (string) fread( $fp, 8192 );
		$out  = array(
			'path'   => $t['rel'],
			'size'   => $size,
			// Hashing streams, but is capped like the write limit (a hash is
			// only needed to change the file).
			'sha256' => $size <= max( self::HASH_MAX_BYTES, Goo1_MCP_File_Writer::max_write_bytes() ) ? Goo1_MCP_File_Access::hash_file( $abs ) : null,
			'mtime'  => gmdate( 'c', (int) filemtime( $abs ) ),
			'perms'  => substr( sprintf( '%o', fileperms( $abs ) ), -4 ),
		);
		if ( ! Goo1_MCP_File_Rules::is_text( self::cut_utf8( $head, strlen( $head ) < 8192 ) ) ) {
			fclose( $fp );
			$ft = wp_check_filetype( $abs );
			return $this->success( $out + array(
				'binary' => true,
				'mime'   => $ft['type'] ? $ft['type'] : 'application/octet-stream',
				'hint'   => 'Binary or non-UTF-8 file: only type, size and SHA-256 are returned.',
			) );
		}

		// Collect lines from $offset until the byte limit or $limit lines.
		rewind( $fp );
		$line_no = 0;
		$buf     = '';
		$taken   = 0;
		$more    = false;
		$in_key  = false; // A private key block opened before the window.
		while ( true ) {
			if ( $line_no + 1 < $offset ) {
				// Skipped line: read in pieces (a line can be huge), carrying
				// the key-block state; 64 bytes overlap catch a split marker.
				$piece = fgets( $fp, 65536 );
				if ( false === $piece ) {
					break;
				}
				$line_no++;
				$in_key = Goo1_MCP_Redactor::private_key_open_after( $piece, $in_key );
				while ( "\n" !== substr( $piece, -1 ) && ! feof( $fp ) ) {
					$tail  = substr( $piece, -64 );
					$piece = fgets( $fp, 65536 );
					if ( false === $piece ) {
						break;
					}
					$in_key = Goo1_MCP_Redactor::private_key_open_after( $tail . $piece, $in_key );
				}
				continue;
			}
			$line = fgets( $fp, $max + 2 ); // At most $max + 1 bytes.
			if ( false === $line ) {
				break;
			}
			$line_no++;
			$partial = "\n" !== substr( $line, -1 ) && ! feof( $fp );
			if ( ( $limit && $taken >= $limit ) || ( $taken > 0 && ( $partial || strlen( $buf ) + strlen( $line ) > $max ) ) ) {
				$more = true;
				if ( $partial ) {
					self::skip_rest_of_line( $fp );
				}
				break;
			}
			if ( 0 === $taken && ( $partial || strlen( $line ) > $max ) ) {
				fclose( $fp );
				return $this->fail( new WP_Error( 'goo1_mcp_line_too_long', sprintf( 'Line %d alone is longer than the read limit (%s).', $line_no, size_format( $max ) ), array( 'status' => 413 ) ) );
			}
			$buf .= $line;
			$taken++;
		}
		$total = $line_no;
		if ( $more ) {
			// Count the lines after the one that stopped the window. That line
			// was read whole by fgets(), so the rest starts on a new line; a
			// last line without a line break counts once, and only if the
			// rest is not empty.
			$rest_len  = 0;
			$rest_last = '';
			while ( ! feof( $fp ) ) {
				$chunk = (string) fread( $fp, 1048576 );
				if ( '' === $chunk ) {
					break;
				}
				$total    += substr_count( $chunk, "\n" );
				$rest_len += strlen( $chunk );
				$rest_last = substr( $chunk, -1 );
			}
			if ( $rest_len > 0 && "\n" !== $rest_last ) {
				$total++;
			}
		}
		fclose( $fp );
		if ( ! Goo1_MCP_File_Rules::is_text( $buf ) ) {
			$ft = wp_check_filetype( $abs );
			return $this->success( $out + array( 'binary' => true, 'mime' => $ft['type'] ? $ft['type'] : 'application/octet-stream', 'hint' => 'Not valid UTF-8 text: only type, size and SHA-256 are returned.' ) );
		}

		$redactions = 0;
		if ( Goo1_MCP_Permissions::instance()->allow_unredacted( $request ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
		} elseif ( $in_key ) {
			// The window starts inside a private key block: mask from the
			// start of the window as if the block's BEGIN line were there.
			$buf = Goo1_MCP_Redactor::redact_text( "-----BEGIN PRIVATE KEY-----\n" . $buf, $redactions );
		} else {
			$buf = Goo1_MCP_Redactor::redact_text( $buf, $redactions );
		}
		$first = $taken ? $offset : 0;
		$out  += array(
			'encoding'   => 'utf-8',
			'bom'        => 1 === $offset && 0 === strpos( $buf, "\xEF\xBB\xBF" ),
			'eol'        => Goo1_MCP_File_Rules::eol( $buf ),
			'lines'      => array( 'from' => $first, 'to' => $taken ? $offset + $taken - 1 : 0, 'total' => $total ),
			'truncated'  => $more,
			'next_offset' => $more ? $offset + $taken : null,
			'redactions' => $redactions,
			'content'    => $buf,
		);
		if ( $more ) {
			$out['hint'] = sprintf( 'Partial read (limit %s per call): call again with offset=%d for the next lines.', size_format( $max ), $offset + $taken );
		}
		if ( $redactions ) {
			$out['redaction_hint'] = 'Secrets were replaced by [redacted]. To change lines containing [redacted], use wp_file_edit with an old_string that does not include them.';
		}
		return $this->success( $out );
	}

	/** Read on to the end of the current line, in pieces. */
	private static function skip_rest_of_line( $fp ) {
		while ( ( $piece = fgets( $fp, 65536 ) ) !== false ) {
			if ( "\n" === substr( $piece, -1 ) ) {
				return;
			}
		}
	}

	/** Cut a byte string so a UTF-8 sequence split at the end does not count as invalid. */
	private static function cut_utf8( $s, $complete ) {
		if ( $complete || '' === $s ) {
			return $s;
		}
		for ( $i = 1; $i <= 3 && $i <= strlen( $s ); $i++ ) {
			$b = ord( $s[ strlen( $s ) - $i ] );
			if ( ( $b & 0xC0 ) === 0xC0 ) {
				return substr( $s, 0, -$i );
			}
			if ( ( $b & 0x80 ) === 0 ) {
				break;
			}
		}
		return $s;
	}

	/* ------------------------------------------------------------------
	 * wp_file_search
	 * ---------------------------------------------------------------- */

	public function search( $request ) {
		$query = (string) $request->get_param( 'query' );
		if ( '' === $query ) {
			return $this->fail( new WP_Error( 'goo1_mcp_bad_query', 'query is required.', array( 'status' => 400 ) ) );
		}
		$t = Goo1_MCP_File_Access::resolve( (string) $request->get_param( 'path' ), 'read' );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		if ( ! $t['exists'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_not_found', 'Not found: ' . $t['rel'], array( 'status' => 404 ) ) );
		}
		$regex = self::flag( $request, 'regex' );
		$cs    = self::flag( $request, 'case_sensitive' );
		$re    = null;
		if ( $regex ) {
			$re = '~' . str_replace( '~', '\\~', $query ) . '~u' . ( $cs ? '' : 'i' );
			if ( false === @preg_match( $re, '' ) ) { // phpcs:ignore
				return $this->fail( new WP_Error( 'goo1_mcp_bad_regex', 'Invalid regular expression: ' . self::preg_error(), array( 'status' => 400 ) ) );
			}
		}
		$glob = (string) $request->get_param( 'glob' );
		$max  = max( 1, min( self::SEARCH_MAX_HITS, (int) ( $request->get_param( 'max_results' ) ?: 50 ) ) );

		$state = array(
			'hits'  => array(),
			'files' => 0,
			'bytes' => 0,
			'stop'  => null,
			'error' => null,
		);
		$files = $t['is_dir'] ? null : array( array( $t['abs'], $t['rel'] ) );
		if ( null === $files ) {
			$this->search_dir( $t['abs'], $t['rel'], 0, $query, $re, $cs, $glob, $max, $state );
		} else {
			$this->search_file( $files[0][0], $files[0][1], $query, $re, $cs, $max, $state );
		}
		if ( $state['error'] ) {
			return $this->fail( $state['error'] );
		}
		$hints = array(
			'results' => sprintf( 'Stopped at %d matches; narrow the path or the query.', $max ),
			'files'   => sprintf( 'Stopped after %d files; search a subfolder.', self::SEARCH_MAX_FILES ),
			'bytes'   => sprintf( 'Stopped after reading %s; search a subfolder.', size_format( self::SEARCH_MAX_BYTES ) ),
		);
		return $this->success( array(
			'path'          => $t['rel'],
			'query'         => $query,
			'regex'         => $regex,
			'matches'       => $state['hits'],
			'count'         => count( $state['hits'] ),
			'files_scanned' => $state['files'],
			'bytes_scanned' => $state['bytes'],
			'truncated'     => null !== $state['stop'],
			'hint'          => $state['stop'] ? $hints[ $state['stop'] ] : null,
		) );
	}

	private function search_dir( $abs, $rel, $level, $query, $re, $cs, $glob, $max, &$state ) {
		$names = @scandir( $abs ); // phpcs:ignore
		if ( ! is_array( $names ) || $level > 20 ) {
			return;
		}
		foreach ( $names as $n ) {
			if ( '.' === $n || '..' === $n || null !== $state['stop'] || $state['error'] ) {
				if ( null !== $state['stop'] || $state['error'] ) {
					return;
				}
				continue;
			}
			$child_abs = $abs . '/' . $n;
			$child_rel = '' === $rel ? $n : $rel . '/' . $n;
			if ( is_link( $child_abs ) ) {
				continue; // Never follow links while walking.
			}
			$root = Goo1_MCP_File_Access::root_for( $child_abs );
			if ( ! $root || '' !== Goo1_MCP_File_Access::blocked_reason( $child_rel, 'read', is_dir( $child_abs ), $root ) ) {
				continue;
			}
			if ( is_dir( $child_abs ) ) {
				$this->search_dir( $child_abs, $child_rel, $level + 1, $query, $re, $cs, $glob, $max, $state );
				continue;
			}
			if ( '' !== $glob && ! fnmatch( $glob, $n, FNM_CASEFOLD ) && ! fnmatch( $glob, $child_rel, FNM_CASEFOLD ) ) {
				continue;
			}
			$this->search_file( $child_abs, $child_rel, $query, $re, $cs, $max, $state );
		}
	}

	private function search_file( $abs, $rel, $query, $re, $cs, $max, &$state ) {
		$size = (int) @filesize( $abs ); // phpcs:ignore
		if ( $size > self::HASH_MAX_BYTES || $size <= 0 ) {
			return;
		}
		if ( $state['files'] >= self::SEARCH_MAX_FILES ) {
			$state['stop'] = 'files';
			return;
		}
		if ( $state['bytes'] + $size > self::SEARCH_MAX_BYTES ) {
			$state['stop'] = 'bytes';
			return;
		}
		$content = @file_get_contents( $abs ); // phpcs:ignore
		if ( false === $content ) {
			return;
		}
		$state['files']++;
		$state['bytes'] += $size;
		if ( ! Goo1_MCP_File_Rules::is_text( $content ) ) {
			return;
		}
		// Search the redacted text, so the search cannot probe a secret.
		// Lines are split at LF only, like wp_file_read, and redacted line by
		// line from the secret ranges of the whole file, so a multi-line key
		// block keeps the line numbers (they can be passed to read as offset).
		$lines = self::redacted_lines( $content );
		foreach ( $lines as $i => $line ) {
			if ( null !== $re ) {
				$m = @preg_match( $re, $line ); // phpcs:ignore
				if ( false === $m ) {
					$state['error'] = new WP_Error( 'goo1_mcp_regex_failed', sprintf( 'The regular expression failed in %s line %d (%s); simplify it.', $rel, $i + 1, self::preg_error() ), array( 'status' => 422 ) );
					return;
				}
			} else {
				$m = $cs ? false !== strpos( $line, $query ) : false !== stripos( $line, $query );
			}
			if ( ! $m ) {
				continue;
			}
			if ( count( $state['hits'] ) >= $max ) {
				$state['stop'] = 'results';
				return;
			}
			$state['hits'][] = array(
				'path'   => $rel,
				'line'   => $i + 1,
				'text'   => self::short( $line ),
				'before' => $i > 0 ? self::short( $lines[ $i - 1 ] ) : null,
				'after'  => isset( $lines[ $i + 1 ] ) ? self::short( $lines[ $i + 1 ] ) : null,
			);
		}
	}

	/**
	 * Lines of a text (split at LF, a CR before it dropped) with every part
	 * inside a secret range replaced by [redacted].
	 *
	 * @return string[]
	 */
	private static function redacted_lines( $content ) {
		$spans = Goo1_MCP_Redactor::secret_spans( $content );
		$mask  = Goo1_MCP_Redactor::MASK;
		$out   = array();
		$start = 0;
		$len   = strlen( $content );
		$k     = 0;
		while ( $start <= $len ) {
			$nl   = strpos( $content, "\n", $start );
			$end  = false === $nl ? $len : $nl;
			$line = '';
			$pos  = $start;
			while ( $k < count( $spans ) && $spans[ $k ][1] <= $start ) {
				$k++;
			}
			for ( $j = $k; $j < count( $spans ) && $spans[ $j ][0] < $end; $j++ ) {
				$a     = max( $start, $spans[ $j ][0] );
				$b     = min( $end, $spans[ $j ][1] );
				$line .= substr( $content, $pos, $a - $pos ) . $mask;
				$pos   = $b;
			}
			$line .= substr( $content, $pos, $end - $pos );
			$out[] = rtrim( $line, "\r" );
			if ( false === $nl ) {
				break;
			}
			$start = $nl + 1;
		}
		if ( $out && '' === end( $out ) && "\n" === substr( $content, -1 ) ) {
			array_pop( $out ); // No extra empty line after the last line break.
		}
		return $out;
	}

	/** preg_last_error_msg() is PHP 8.0+; the plugin supports 7.4. */
	private static function preg_error() {
		if ( function_exists( 'preg_last_error_msg' ) ) {
			return preg_last_error_msg();
		}
		$map = array(
			PREG_INTERNAL_ERROR        => 'Internal error',
			PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exhausted',
			PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exhausted',
			PREG_BAD_UTF8_ERROR        => 'Malformed UTF-8 characters',
			PREG_BAD_UTF8_OFFSET_ERROR => 'Bad UTF-8 offset',
		);
		$e = preg_last_error();
		return isset( $map[ $e ] ) ? $map[ $e ] : 'Compilation failed';
	}

	private static function short( $line ) {
		if ( strlen( $line ) <= self::SEARCH_LINE_MAX ) {
			return $line;
		}
		$cut = function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, self::SEARCH_LINE_MAX, 'UTF-8' ) : substr( $line, 0, self::SEARCH_LINE_MAX );
		return $cut . ' …';
	}

	/* ------------------------------------------------------------------
	 * wp_file_backups
	 * ---------------------------------------------------------------- */

	public function backups( $request ) {
		$path  = $request->get_param( 'path' );
		$limit = (int) ( $request->get_param( 'limit' ) ?: 50 );
		if ( null !== $path && '' !== (string) $path ) {
			$t = Goo1_MCP_File_Access::resolve( (string) $path, 'read' );
			if ( is_wp_error( $t ) ) {
				return $this->fail( $t );
			}
			$rows = Goo1_MCP_Backups::list_files( $t['rel'], $limit );
		} else {
			if ( ! Goo1_MCP_File_Access::enabled() ) {
				return $this->fail( Goo1_MCP_File_Access::resolve( '', 'read' ) );
			}
			$rows = array_values( array_filter( Goo1_MCP_Backups::list_files( null, 200 ), function ( $r ) {
				return ! is_wp_error( Goo1_MCP_File_Access::resolve( $r['label'], 'read' ) );
			} ) );
			$rows = array_slice( $rows, 0, max( 1, min( 200, $limit ) ) );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'backup_id'  => (int) $r['id'],
				'path'       => $r['label'],
				'sha256'     => $r['sha256'],
				'size'       => (int) $r['size_bytes'],
				'created_by' => (int) $r['created_by'],
				'created_at' => $r['created_at'],
			);
		}
		return $this->success( array( 'backups' => $out, 'count' => count( $out ), 'kept' => sprintf( 'last %d per file, at most %d days', Goo1_MCP_Backups::KEEP, Goo1_MCP_Backups::FILE_MAX_DAYS ) ) );
	}
}
