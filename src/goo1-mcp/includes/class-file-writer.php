<?php
/**
 * File tools: the one path every change takes.
 *
 * save(): overwrite rules (if_sha256 / overwrite) → content checks (PHP
 * syntax, JSON, .htaccess lines, [redacted] placeholders) → backup →
 * atomic write (temp file + rename, permissions kept) → opcache_invalidate
 * → check after saving (PHP loopback, .htaccess status) → on failure the
 * previous state is put back.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_File_Writer {

	public static function err( $code, $message, $status = 400, $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	public static function max_write_bytes() {
		$s = Goo1_MCP_OAuth::settings();
		return max( 1, (int) $s['file_max_write_kb'] ) * 1024;
	}

	public static function max_fetch_bytes() {
		$s = Goo1_MCP_OAuth::settings();
		return max( 1, (int) $s['file_max_fetch_mb'] ) * 1048576;
	}

	/**
	 * Save a file.
	 *
	 * @param array $t Result of Goo1_MCP_File_Access::resolve( …, 'write' ).
	 * @param array $o content | source_file, reason (write|edit|fetch|restore),
	 *                 overwrite, if_sha256, verify (null = default), no_backup,
	 *                 allow_invalid_json, check_content (false for restore),
	 *                 max_bytes.
	 * @return array|WP_Error
	 */
	public static function save( $t, $o ) {
		$abs    = $t['abs'];
		$rel    = $t['rel'];
		$name   = basename( $rel );
		$reason = isset( $o['reason'] ) ? $o['reason'] : 'write';
		$check  = ! isset( $o['check_content'] ) || $o['check_content'];

		clearstatcache( true, $abs );
		$exists = file_exists( $abs );
		if ( $exists && is_dir( $abs ) ) {
			return self::err( 'goo1_mcp_is_dir', 'The path is a folder, not a file.', 409 );
		}

		// Overwrite rules.
		$old     = null;
		$old_sha = null;
		if ( $exists ) {
			$old = @file_get_contents( $abs ); // phpcs:ignore
			if ( false === $old ) {
				return self::err( 'goo1_mcp_unreadable', 'The existing file cannot be read.', 500 );
			}
			$old_sha = Goo1_MCP_File_Access::hash( $old );
		}
		$if_sha = isset( $o['if_sha256'] ) ? strtolower( trim( (string) $o['if_sha256'] ) ) : '';
		if ( '' !== $if_sha ) {
			if ( ! $exists ) {
				return self::err( 'goo1_mcp_sha_mismatch', 'if_sha256 was given, but the file does not exist (any more).', 409, array( 'current_sha256' => null ) );
			}
			if ( ! hash_equals( $old_sha, $if_sha ) ) {
				return self::err( 'goo1_mcp_sha_mismatch', 'The file changed since it was read (if_sha256 does not match); nothing was changed. Read it again.', 409, array( 'current_sha256' => $old_sha ) );
			}
		} elseif ( $exists && empty( $o['overwrite'] ) ) {
			return self::err( 'goo1_mcp_exists', 'The file already exists. Pass if_sha256 (from wp_file_read / wp_file_list) or overwrite=true to replace it.', 409, array( 'current_sha256' => $old_sha ) );
		}

		// New content.
		$source = isset( $o['source_file'] ) ? (string) $o['source_file'] : '';
		if ( '' !== $source ) {
			$size = (int) @filesize( $source ); // phpcs:ignore
		} else {
			$content = (string) $o['content'];
			$size    = strlen( $content );
		}
		$max = isset( $o['max_bytes'] ) ? (int) $o['max_bytes'] : self::max_write_bytes();
		if ( $size > $max ) {
			return self::err( 'goo1_mcp_too_large', sprintf( 'The new file would be %s; the limit is %s (Claude Bridge → Settings → File access).', size_format( $size ), size_format( $max ) ), 413 );
		}
		$needs_text = $check && ( Goo1_MCP_File_Rules::is_htaccess( $name ) || Goo1_MCP_File_Rules::is_json( $name ) || in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array_merge( Goo1_MCP_File_Rules::PHP_EXT, array( 'inc' ) ), true ) );
		if ( '' !== $source && ( $needs_text || Goo1_MCP_File_Rules::is_htaccess( $name ) ) ) {
			$content = (string) @file_get_contents( $source ); // phpcs:ignore
		}
		$is_php = isset( $content ) ? Goo1_MCP_File_Rules::is_php( $name, $content ) : Goo1_MCP_File_Rules::is_php( $name );

		// .htaccess: only blocking lines, only files the bridge wrote itself
		// (marker line), never over a foreign file (also for restore).
		if ( Goo1_MCP_File_Rules::is_htaccess( $name ) ) {
			if ( $exists && ( ! Goo1_MCP_File_Rules::htaccess_is_own( $old ) || Goo1_MCP_File_Rules::htaccess_bad_lines( $old ) ) ) {
				return self::err( 'goo1_mcp_foreign_htaccess', 'This .htaccess was not written by the bridge (its first line is not "' . Goo1_MCP_File_Rules::HTACCESS_MARKER . '"), probably by another plugin or the host; the bridge does not change it.', 403 );
			}
			$content = Goo1_MCP_File_Rules::htaccess_with_marker( $content );
			$bad     = Goo1_MCP_File_Rules::htaccess_bad_lines( $content );
			if ( $bad ) {
				return self::err( 'goo1_mcp_htaccess_rules', 'Only access blocks are allowed in .htaccess (Require all denied, Deny from all, Order, <Files>/<FilesMatch>, <IfModule [!]mod_authz_core.c>, Options -Indexes, comments). Refused: ' . implode( '; ', array_slice( $bad, 0, 10 ) ), 400 );
			}
		}
		if ( $check ) {
			if ( $is_php ) {
				$perr = Goo1_MCP_File_Rules::php_error( $content );
				if ( '' !== $perr ) {
					return self::err( 'goo1_mcp_php_syntax', 'PHP syntax error, nothing was saved: ' . $perr, 400 );
				}
			}
			if ( Goo1_MCP_File_Rules::is_json( $name ) && empty( $o['allow_invalid_json'] ) ) {
				$jerr = Goo1_MCP_File_Rules::json_error( $content );
				if ( '' !== $jerr ) {
					return self::err( 'goo1_mcp_json_invalid', $jerr . ' – nothing was saved. Pass allow_invalid_json=true if this file is not meant to be JSON.', 400 );
				}
			}
		}

		// Backup of the current state.
		$backup_id = null;
		if ( $exists && empty( $o['no_backup'] ) ) {
			$backup_id = Goo1_MCP_Backups::create_file( $rel, $abs, $reason );
			if ( is_wp_error( $backup_id ) ) {
				return $backup_id;
			}
		}

		// Parent folders.
		$created_dirs = array();
		$dir          = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			$missing = array();
			for ( $d = $dir; ! is_dir( $d ) && strlen( $d ) > 1; $d = dirname( $d ) ) {
				$missing[] = $d;
			}
			foreach ( array_reverse( $missing ) as $d ) {
				if ( ! @mkdir( $d, 0755 ) && ! is_dir( $d ) ) { // phpcs:ignore
					self::remove_dirs( $created_dirs );
					return self::err( 'goo1_mcp_mkdir_failed', 'Could not create the folder ' . Goo1_MCP_File_Access::rel_of( $d ) . ' (permissions?).', 500 );
				}
				$created_dirs[] = $d;
			}
		}

		$perms = $exists ? ( @fileperms( $abs ) & 0777 ) : 0644; // phpcs:ignore
		$ok    = '' !== $source ? self::put_from_file( $abs, $source, $perms ) : self::put( $abs, $content, $perms );
		if ( is_wp_error( $ok ) ) {
			self::remove_dirs( $created_dirs );
			Goo1_MCP_Backups::discard_file( $backup_id ); // Nothing changed.
			return $ok;
		}
		Goo1_MCP_Audit_Log::add_flag( 'file_write' );
		clearstatcache( true, $abs );
		$new_sha = Goo1_MCP_File_Access::hash_file( $abs );

		$result = array(
			'path'            => $rel,
			'created'         => ! $exists,
			'size'            => (int) filesize( $abs ),
			'sha256'          => $new_sha,
			'previous_sha256' => $old_sha,
			'backup_id'       => $backup_id,
		);
		if ( $created_dirs ) {
			$result['created_folders'] = array_map( array( 'Goo1_MCP_File_Access', 'rel_of' ), $created_dirs );
		}

		// Check after saving.
		$verify = array_key_exists( 'verify', $o ) && null !== $o['verify'] ? (bool) $o['verify'] : true;
		$failed = null;
		if ( $is_php ) {
			if ( $verify ) {
				$v                = Goo1_MCP_File_Verify::php();
				$result['verify'] = $v;
				if ( 'fatal' === $v['status'] ) {
					$failed = $v;
				}
			} else {
				$result['verify'] = array( 'status' => 'skipped' );
			}
		} elseif ( Goo1_MCP_File_Rules::is_htaccess( $name ) ) {
			$v                = Goo1_MCP_File_Verify::htaccess( Goo1_MCP_File_Access::rel_of( dirname( $abs ) ), dirname( $abs ) );
			$result['verify'] = $v;
			if ( 'error' === $v['status'] ) {
				$failed = $v;
			}
		}

		if ( $failed ) {
			$undo = self::undo( $abs, $exists, $old, $perms, $created_dirs );
			$data = array(
				'path'     => $rel,
				'verify'   => $failed,
				'restored' => ! is_wp_error( $undo ),
			);
			if ( is_wp_error( $undo ) ) {
				$data['restore_error'] = $undo->get_error_message();
				$data['backup_id']     = $backup_id;
			} else {
				// The file is in the backed-up state again: the backup of this
				// attempt must not push out an older, different one.
				Goo1_MCP_Backups::discard_file( $backup_id );
			}
			if ( ! is_wp_error( $undo ) && $is_php ) {
				$again = Goo1_MCP_File_Verify::php();
				if ( 'fatal' === $again['status'] ) {
					$data['note'] = 'The site still fails after restoring the previous state, so the error was probably not caused by this change. The change was not kept; pass verify=false to save it without the check.';
				}
			}
			$msg = $is_php
				? 'The site failed to load after saving (' . ( isset( $failed['error']['message'] ) ? $failed['error']['message'] : 'error' ) . '); the previous state was put back.'
				: 'The server answered 500 after saving the .htaccess (a directive is not allowed here); the previous state was put back.';
			return self::err( 'goo1_mcp_verify_failed', $msg, 422, array( 'details' => $data ) );
		}
		if ( $backup_id ) {
			Goo1_MCP_Backups::prune_file( $rel );
		}
		return $result;
	}

	/**
	 * Delete a file or an empty folder.
	 */
	public static function delete( $t, $o ) {
		$abs = $t['abs'];
		$rel = $t['rel'];
		if ( ! $t['exists'] ) {
			return self::err( 'goo1_mcp_not_found', 'Not found: ' . $rel, 404 );
		}
		if ( $t['is_dir'] ) {
			$entries = array_diff( (array) @scandir( $abs ), array( '.', '..' ) ); // phpcs:ignore
			if ( $entries ) {
				return self::err( 'goo1_mcp_not_empty', sprintf( 'The folder is not empty (%d entries); only empty folders are deleted.', count( $entries ) ), 409 );
			}
			if ( ! @rmdir( $abs ) ) { // phpcs:ignore
				return self::err( 'goo1_mcp_delete_failed', 'Could not delete the folder (permissions?).', 500 );
			}
			Goo1_MCP_Audit_Log::add_flag( 'file_write' );
			return array( 'path' => $rel, 'deleted' => 'folder' );
		}

		$old = @file_get_contents( $abs ); // phpcs:ignore
		if ( false === $old ) {
			return self::err( 'goo1_mcp_unreadable', 'The file cannot be read.', 500 );
		}
		$old_sha = Goo1_MCP_File_Access::hash( $old );
		$if_sha  = isset( $o['if_sha256'] ) ? strtolower( trim( (string) $o['if_sha256'] ) ) : '';
		if ( '' !== $if_sha && ! hash_equals( $old_sha, $if_sha ) ) {
			return self::err( 'goo1_mcp_sha_mismatch', 'The file changed since it was read (if_sha256 does not match); nothing was deleted.', 409, array( 'current_sha256' => $old_sha ) );
		}
		$name = basename( $rel );
		if ( Goo1_MCP_File_Rules::is_htaccess( $name ) && ( ! Goo1_MCP_File_Rules::htaccess_is_own( $old ) || Goo1_MCP_File_Rules::htaccess_bad_lines( $old ) ) ) {
			return self::err( 'goo1_mcp_foreign_htaccess', 'This .htaccess was not written by the bridge (its first line is not "' . Goo1_MCP_File_Rules::HTACCESS_MARKER . '"), probably by another plugin or the host; the bridge does not delete it.', 403 );
		}
		$backup_id = null;
		if ( empty( $o['no_backup'] ) ) {
			$backup_id = Goo1_MCP_Backups::create_file( $rel, $abs, 'delete' );
			if ( is_wp_error( $backup_id ) ) {
				return $backup_id;
			}
		}
		$perms = @fileperms( $abs ) & 0777; // phpcs:ignore
		if ( ! @unlink( $abs ) ) { // phpcs:ignore
			Goo1_MCP_Backups::discard_file( $backup_id );
			return self::err( 'goo1_mcp_delete_failed', 'Could not delete the file (permissions?).', 500 );
		}
		self::invalidate( $abs );
		Goo1_MCP_Audit_Log::add_flag( 'file_write' );
		$result = array( 'path' => $rel, 'deleted' => 'file', 'previous_sha256' => $old_sha, 'backup_id' => $backup_id );

		$verify = array_key_exists( 'verify', $o ) && null !== $o['verify'] ? (bool) $o['verify'] : true;
		if ( Goo1_MCP_File_Rules::is_php( $name, $old ) && $verify ) {
			$v                = Goo1_MCP_File_Verify::php();
			$result['verify'] = $v;
			if ( 'fatal' === $v['status'] ) {
				$undo = self::put( $abs, $old, $perms );
				if ( ! is_wp_error( $undo ) ) {
					Goo1_MCP_Backups::discard_file( $backup_id );
				}
				return self::err( 'goo1_mcp_verify_failed', 'The site failed to load without this file (' . $v['error']['message'] . '); it was put back.', 422, array( 'details' => array( 'path' => $rel, 'verify' => $v, 'restored' => ! is_wp_error( $undo ) ) ) );
			}
		}
		if ( $backup_id ) {
			Goo1_MCP_Backups::prune_file( $rel );
		}
		return $result;
	}

	/** Put the previous state back after a failed check. */
	private static function undo( $abs, $existed, $old, $perms, $created_dirs ) {
		if ( $existed ) {
			return self::put( $abs, $old, $perms );
		}
		if ( file_exists( $abs ) && ! @unlink( $abs ) ) { // phpcs:ignore
			return self::err( 'goo1_mcp_undo_failed', 'Could not remove the new file.', 500 );
		}
		self::invalidate( $abs );
		self::remove_dirs( $created_dirs );
		return true;
	}

	/** Remove folders this call created (deepest first, only if empty). */
	private static function remove_dirs( $dirs ) {
		foreach ( array_reverse( $dirs ) as $d ) {
			@rmdir( $d ); // phpcs:ignore
		}
	}

	private static function temp_name( $abs ) {
		return dirname( $abs ) . '/.goo1-mcp-tmp-' . wp_generate_password( 12, false );
	}

	/**
	 * Atomic write: temp file in the same folder, then rename over the target.
	 */
	public static function put( $abs, $content, $perms = 0644 ) {
		$tmp = self::temp_name( $abs );
		$n   = @file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore
		if ( strlen( $content ) !== $n ) {
			@unlink( $tmp ); // phpcs:ignore
			return self::err( 'goo1_mcp_write_failed', 'Could not write the file (permissions or disk space?).', 500 );
		}
		return self::finish( $tmp, $abs, $perms );
	}

	private static function put_from_file( $abs, $source, $perms ) {
		$tmp = self::temp_name( $abs );
		if ( ! @copy( $source, $tmp ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return self::err( 'goo1_mcp_write_failed', 'Could not write the file (permissions or disk space?).', 500 );
		}
		return self::finish( $tmp, $abs, $perms );
	}

	private static function finish( $tmp, $abs, $perms ) {
		@chmod( $tmp, $perms ? $perms : 0644 ); // phpcs:ignore
		if ( ! @rename( $tmp, $abs ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return self::err( 'goo1_mcp_write_failed', 'Could not replace the file (permissions?).', 500 );
		}
		self::invalidate( $abs );
		return true;
	}

	public static function invalidate( $abs ) {
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $abs, true ); // phpcs:ignore
		}
	}
}
