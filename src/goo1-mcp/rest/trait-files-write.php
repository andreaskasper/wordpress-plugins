<?php
/**
 * File tools that change files (write, edit, fetch, delete, restore). Each
 * needs scope full (route permission), the grant "Write files" and a folder
 * shared read-write; everything goes through Goo1_MCP_File_Writer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Goo1_MCP_Files_Write {

	/** Common options of the changing tools. */
	protected static function write_options( $request ) {
		return array(
			'overwrite'          => self::flag( $request, 'overwrite' ),
			'if_sha256'          => (string) $request->get_param( 'if_sha256' ),
			'verify'             => null === $request->get_param( 'verify' ) ? null : self::flag( $request, 'verify' ),
			'no_backup'          => self::flag( $request, 'no_backup' ),
			'allow_invalid_json' => self::flag( $request, 'allow_invalid_json' ),
		);
	}

	protected function writable_target( $request, $op = 'write' ) {
		$ok = $this->can_write();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return Goo1_MCP_File_Access::resolve( (string) $request->get_param( 'path' ), $op );
	}

	protected function done( $result ) {
		return is_wp_error( $result ) ? $this->fail( $result ) : $this->success( $result );
	}

	/* ------------------------------------------------------------------
	 * wp_file_write
	 * ---------------------------------------------------------------- */

	public function write_file( $request ) {
		$t = $this->writable_target( $request );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		$content = $request->get_param( 'content' );
		if ( ! is_string( $content ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_bad_content', 'content (string) is required.', array( 'status' => 400 ) ) );
		}
		if ( ! Goo1_MCP_File_Rules::is_text( $content ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_not_text', 'wp_file_write only writes UTF-8 text; use wp_file_fetch for binary files.', array( 'status' => 400 ) ) );
		}
		$mask = Goo1_MCP_Redactor::MASK;
		if ( false !== strpos( $content, $mask ) ) {
			// Only files up to the write limit are read for this (a larger
			// one counts as having no [redacted], so content with it is refused).
			$old = $t['exists'] && ! $t['is_dir'] && (int) @filesize( $t['abs'] ) <= Goo1_MCP_File_Writer::max_write_bytes() ? (string) @file_get_contents( $t['abs'] ) : ''; // phpcs:ignore
			if ( substr_count( $content, $mask ) > substr_count( $old, $mask ) ) {
				return $this->fail( new WP_Error( 'goo1_mcp_redacted_content', 'content contains [redacted], but the file has a secret there. Writing it would replace the secret with the placeholder. Use wp_file_edit for the lines you want to change.', array( 'status' => 400 ) ) );
			}
		}
		$o            = self::write_options( $request );
		$o['content'] = $content;
		$o['reason']  = 'write';
		return $this->done( Goo1_MCP_File_Writer::save( $t, $o ) );
	}

	/* ------------------------------------------------------------------
	 * wp_file_edit
	 * ---------------------------------------------------------------- */

	public function edit_file( $request ) {
		$t = $this->writable_target( $request );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		if ( ! $t['exists'] || $t['is_dir'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_not_found', 'File not found: ' . $t['rel'] . ' (use wp_file_write to create it).', array( 'status' => 404 ) ) );
		}
		$old_s = $request->get_param( 'old_string' );
		$new_s = $request->get_param( 'new_string' );
		if ( ! is_string( $old_s ) || '' === $old_s || ! is_string( $new_s ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_bad_edit', 'old_string (non-empty) and new_string (may be empty) are required.', array( 'status' => 400 ) ) );
		}
		clearstatcache( true, $t['abs'] );
		if ( (int) @filesize( $t['abs'] ) > Goo1_MCP_File_Writer::max_write_bytes() ) { // phpcs:ignore
			return $this->fail( new WP_Error( 'goo1_mcp_too_large', sprintf( 'The file is larger than the write limit of %s (Claude Bridge → Settings → File access).', size_format( Goo1_MCP_File_Writer::max_write_bytes() ) ), array( 'status' => 413 ) ) );
		}
		$cur = @file_get_contents( $t['abs'] ); // phpcs:ignore
		if ( false === $cur ) {
			return $this->fail( new WP_Error( 'goo1_mcp_unreadable', 'The file cannot be read.', array( 'status' => 500 ) ) );
		}
		if ( ! Goo1_MCP_File_Rules::is_text( $cur ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_not_text', 'wp_file_edit only changes UTF-8 text files.', array( 'status' => 400 ) ) );
		}
		$sha    = Goo1_MCP_File_Access::hash( $cur );
		$if_sha = strtolower( trim( (string) $request->get_param( 'if_sha256' ) ) );
		if ( '' !== $if_sha && ! hash_equals( $sha, $if_sha ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_sha_mismatch', 'The file changed since it was read (if_sha256 does not match); nothing was changed. Read it again.', array( 'status' => 409, 'current_sha256' => $sha ) ) );
		}
		// Matching runs on the redacted view of the file (what wp_file_read
		// shows), the change on the real file. So every answer – not found,
		// ambiguous, unchanged – depends only on text the caller can read,
		// and old_string cannot be used to guess a [redacted] secret.
		$mask = Goo1_MCP_Redactor::MASK;
		if ( Goo1_MCP_Permissions::instance()->allow_unredacted( $request ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'unredacted' );
			$secret = array();
		} else {
			$secret = Goo1_MCP_Redactor::secret_spans( $cur );
		}
		$has_mask = false !== strpos( $old_s, $mask ) || false !== strpos( $new_s, $mask );
		if ( $has_mask && ( $secret || false === strpos( $cur, $mask ) ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_redacted_content', 'old_string/new_string contain [redacted]: that is a secret masked in the read result, not the real text. Choose an old_string next to it that does not include [redacted].', array( 'status' => 400 ) ) );
		}
		// View: secrets replaced by the mask; $marks maps view ranges back.
		$view  = '';
		$marks = array(); // array( view_start, view_end, shift after this mark )
		$prev  = 0;
		$shift = 0;
		foreach ( $secret as $sp ) {
			$view   .= substr( $cur, $prev, $sp[0] - $prev );
			$vs      = strlen( $view );
			$view   .= $mask;
			$shift  += ( $sp[1] - $sp[0] ) - strlen( $mask );
			$marks[] = array( $vs, strlen( $view ), $shift );
			$prev    = $sp[1];
		}
		$view .= substr( $cur, $prev );
		$to_cur = function ( $p ) use ( $marks ) {
			$add = 0;
			foreach ( $marks as $m ) {
				if ( $m[1] <= $p ) {
					$add = $m[2];
				} else {
					break;
				}
			}
			return $p + $add;
		};

		$all      = self::flag( $request, 'replace_all' );
		$eol_mode = false !== strpos( $view, "\r\n" ) && false === strpos( $old_s, "\r" );
		if ( $eol_mode ) {
			// CRLF or mixed line endings: match with CRLF read as LF, so an LF
			// and a CRLF occurrence both count for the uniqueness check.
			$found = self::eol_spans( $view, $old_s );
		} else {
			$found = array();
			for ( $pos = strpos( $view, $old_s ); false !== $pos; $pos = strpos( $view, $old_s, $pos + strlen( $old_s ) ) ) {
				$found[] = array( $pos, strlen( $old_s ) );
			}
		}
		// A match touching a mask is not a match (it would change a secret).
		$spans = array();
		foreach ( $found as $f ) {
			$touch = false;
			foreach ( $marks as $m ) {
				if ( $f[0] < $m[1] && $f[0] + $f[1] > $m[0] ) {
					$touch = true;
					break;
				}
			}
			if ( ! $touch ) {
				$spans[] = $f;
			}
		}
		$count = count( $spans );
		if ( 0 === $count ) {
			return $this->fail( new WP_Error( 'goo1_mcp_edit_not_found', 'old_string was not found in the file (it must match exactly, including spaces, tabs and line breaks, and must not overlap [redacted]). Read the file again.', array( 'status' => 404 ) ) );
		}
		if ( $count > 1 && ! $all ) {
			return $this->fail( new WP_Error( 'goo1_mcp_edit_ambiguous', sprintf( 'old_string appears %d times; include more surrounding text to make it unique, or set replace_all=true.', $count ), array( 'status' => 409 ) ) );
		}
		$new = $cur;
		foreach ( array_reverse( $all ? $spans : array( $spans[0] ) ) as $sp ) {
			$cs   = $to_cur( $sp[0] );
			$ce   = $to_cur( $sp[0] + $sp[1] );
			$repl = $eol_mode ? self::eol_like( $new_s, $view, $sp[0], $sp[1] ) : $new_s;
			$new  = substr_replace( $new, $repl, $cs, $ce - $cs );
		}
		if ( $new === $cur ) {
			return $this->success( array( 'path' => $t['rel'], 'unchanged' => true, 'sha256' => $sha ) );
		}
		$o              = self::write_options( $request );
		$o['content']   = $new;
		$o['reason']    = 'edit';
		$o['if_sha256'] = $sha;
		$res            = Goo1_MCP_File_Writer::save( $t, $o );
		if ( ! is_wp_error( $res ) ) {
			$res['replacements'] = $all ? $count : 1;
		}
		return $this->done( $res );
	}

	/**
	 * Occurrences of $needle (no CR) in $text with every CRLF read as LF.
	 *
	 * @return array[] array( offset, length ) in $text, non-overlapping.
	 */
	protected static function eol_spans( $text, $needle ) {
		$norm = str_replace( "\r\n", "\n", $text );
		// Normalised positions of the removed CRs (each CRLF shifts by one).
		$q = array();
		$i = 0;
		for ( $p = strpos( $text, "\r\n" ); false !== $p; $p = strpos( $text, "\r\n", $p + 2 ) ) {
			$q[] = $p - $i;
			$i++;
		}
		$to_orig = function ( $n ) use ( $q ) {
			// Number of removed CRs before normalised offset $n (binary search).
			$lo = 0;
			$hi = count( $q );
			while ( $lo < $hi ) {
				$mid = ( $lo + $hi ) >> 1;
				if ( $q[ $mid ] < $n ) {
					$lo = $mid + 1;
				} else {
					$hi = $mid;
				}
			}
			return $n + $lo;
		};
		$out = array();
		$len = strlen( $needle );
		for ( $pos = strpos( $norm, $needle ); false !== $pos; $pos = strpos( $norm, $needle, $pos + $len ) ) {
			$start = $to_orig( $pos );
			$out[] = array( $start, $to_orig( $pos + $len ) - $start );
		}
		return $out;
	}

	/**
	 * The replacement with the line endings of the text it replaces: CRLF if
	 * that text has CRLF, LF if it has LF; without a line break in it, the
	 * ending of the line it is on.
	 */
	protected static function eol_like( $replacement, $text, $start, $length ) {
		$lf   = str_replace( "\r\n", "\n", (string) $replacement );
		$span = substr( $text, $start, $length );
		if ( false !== strpos( $span, "\r\n" ) ) {
			return str_replace( "\n", "\r\n", $lf );
		}
		if ( false !== strpos( $span, "\n" ) ) {
			return $lf;
		}
		$nl = strpos( $text, "\n", $start + $length );
		if ( false !== $nl && $nl > 0 && "\r" === $text[ $nl - 1 ] ) {
			return str_replace( "\n", "\r\n", $lf );
		}
		return $lf;
	}

	/* ------------------------------------------------------------------
	 * wp_file_fetch
	 * ---------------------------------------------------------------- */

	public function fetch_file( $request ) {
		$t = $this->writable_target( $request );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		if ( Goo1_MCP_File_Rules::is_htaccess( $t['rel'] ) ) {
			return $this->fail( new WP_Error( 'goo1_mcp_fetch_htaccess', 'A .htaccess cannot be downloaded; write it with wp_file_write.', array( 'status' => 400 ) ) );
		}
		if ( $t['is_dir'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_is_dir', 'The path is a folder; give the target file name.', array( 'status' => 400 ) ) );
		}
		$o = self::write_options( $request );
		if ( $t['exists'] && ! $o['overwrite'] && '' === $o['if_sha256'] ) {
			return $this->fail( new WP_Error( 'goo1_mcp_exists', 'The file already exists. Pass overwrite=true or if_sha256 to replace it.', array( 'status' => 409, 'current_sha256' => Goo1_MCP_File_Access::hash_file( $t['abs'] ) ) ) );
		}
		$url = esc_url_raw( (string) $request->get_param( 'url' ), array( 'http', 'https' ) );
		$bad = self::fetch_url_error( $url );
		if ( $bad ) {
			return $this->fail( $bad );
		}
		$max = Goo1_MCP_File_Writer::max_fetch_bytes();
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'goo1-mcp-fetch' );
		if ( ! $tmp ) {
			return $this->fail( new WP_Error( 'goo1_mcp_tmp_failed', 'Could not create a temporary file.', array( 'status' => 500 ) ) );
		}
		// Redirects are followed by the helper, each target checked like the URL.
		$dl      = Goo1_MCP_File_Access::download( $url, array(
			'timeout'             => 120,
			'stream'              => true,
			'filename'            => $tmp,
			'limit_response_size' => $max + 1,
		), self::FETCH_MAX_REDIRECTS );
		$res     = $dl['response'];
		$current = $dl['url'];
		$fail    = $dl['error'];
		if ( $fail ) {
			// Redirect refused or too many redirects (from download()).
		} elseif ( is_wp_error( $res ) ) {
			$fail = new WP_Error( 'goo1_mcp_fetch_failed', 'Download failed: ' . $res->get_error_message(), array( 'status' => 502 ) );
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$fail = new WP_Error( 'goo1_mcp_fetch_failed', sprintf( 'Download failed: HTTP %d.', (int) wp_remote_retrieve_response_code( $res ) ), array( 'status' => 502 ) );
		} else {
			clearstatcache( true, $tmp );
			$size = (int) @filesize( $tmp ); // phpcs:ignore
			if ( $size > $max ) {
				$fail = new WP_Error( 'goo1_mcp_too_large', sprintf( 'The download is larger than the limit of %s (Claude Bridge → Settings → File access).', size_format( $max ) ), array( 'status' => 413 ) );
			} else {
				$got  = (string) hash_file( 'sha256', $tmp );
				$want = strtolower( trim( (string) $request->get_param( 'sha256' ) ) );
				if ( '' === $got ) {
					$fail = new WP_Error( 'goo1_mcp_fetch_failed', 'The download could not be read back.', array( 'status' => 500 ) );
				} elseif ( '' !== $want && ! hash_equals( $got, $want ) ) {
					$fail = new WP_Error( 'goo1_mcp_fetch_sha', 'The download does not match the expected sha256; nothing was saved.', array( 'status' => 422, 'download_sha256' => $got ) );
				}
			}
		}
		if ( $fail ) {
			@unlink( $tmp ); // phpcs:ignore
			return $this->fail( $fail );
		}
		$o['source_file'] = $tmp;
		$o['reason']      = 'fetch';
		$o['max_bytes']   = $max;
		$out              = Goo1_MCP_File_Writer::save( $t, $o );
		@unlink( $tmp ); // phpcs:ignore
		if ( ! is_wp_error( $out ) ) {
			$out['url']             = $url;
			$out['download_sha256'] = $got; // Plain SHA-256 of the download (sha256 is the keyed file hash).
			if ( $current !== $url ) {
				$out['final_url'] = $current;
			}
			$out['content_type'] = (string) wp_remote_retrieve_header( $res, 'content-type' );
		}
		return $this->done( $out );
	}

	/** Why a fetch URL is refused, or null (see Goo1_MCP_File_Access::url_error()). */
	protected static function fetch_url_error( $url ) {
		return Goo1_MCP_File_Access::url_error( $url );
	}

	/* ------------------------------------------------------------------
	 * wp_file_delete
	 * ---------------------------------------------------------------- */

	public function delete_file( $request ) {
		$t = $this->writable_target( $request, 'delete' );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		return $this->done( Goo1_MCP_File_Writer::delete( $t, self::write_options( $request ) ) );
	}

	/* ------------------------------------------------------------------
	 * wp_file_restore
	 * ---------------------------------------------------------------- */

	public function restore( $request ) {
		$ok = $this->can_write();
		if ( is_wp_error( $ok ) ) {
			return $this->fail( $ok );
		}
		$b = Goo1_MCP_Backups::get_file( (int) $request->get_param( 'backup_id' ) );
		if ( ! $b ) {
			return $this->fail( new WP_Error( 'goo1_mcp_backup_not_found', 'Backup not found (or damaged). List them with wp_file_backups.', array( 'status' => 404 ) ) );
		}
		$t = Goo1_MCP_File_Access::resolve( $b['path'], 'write' );
		if ( is_wp_error( $t ) ) {
			return $this->fail( $t );
		}
		$o                  = self::write_options( $request );
		$o['content']       = $b['content'];
		$o['reason']        = 'restore';
		$o['overwrite']     = true;
		$o['check_content'] = false;
		$o['max_bytes']     = max( Goo1_MCP_Backups::FILE_MAX_BYTES, Goo1_MCP_File_Writer::max_write_bytes() );
		$res                = Goo1_MCP_File_Writer::save( $t, $o );
		if ( ! is_wp_error( $res ) ) {
			$res['restored_from'] = array( 'backup_id' => $b['id'], 'created_at' => $b['created_at'] );
		}
		return $this->done( $res );
	}
}
