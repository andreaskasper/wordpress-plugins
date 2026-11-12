<?php
/**
 * Content backups taken automatically before the bridge overwrites data
 * (currently Elementor documents). Stored in their own table so large JSON
 * blobs never bloat post meta (which WordPress loads per post).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Backups {

	const KEEP = 10;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'goo1_mcp_backups';
	}

	public static function schema_sql( $charset_collate ) {
		$t = self::table();
		return "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_key varchar(64) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			payload longtext,
			sha256 char(64) NOT NULL DEFAULT '',
			size_bytes int(10) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY object (object_type, object_id, id),
			KEY object_key (object_type, object_key, id)
		) $charset_collate;";
	}

	/**
	 * Store a backup and keep only the newest KEEP per object.
	 *
	 * @return int Backup id (0 on failure).
	 */
	public static function create( $type, $object_id, $payload, $label = '' ) {
		global $wpdb;
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return 0;
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'object_type' => $type,
				'object_id'   => (int) $object_id,
				'label'       => substr( (string) $label, 0, 191 ),
				'payload'     => $json,
				'sha256'      => hash( 'sha256', $json ),
				'size_bytes'  => strlen( $json ),
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		$keep_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d',
			$type, (int) $object_id, self::KEEP
		) );
		if ( count( $keep_ids ) >= self::KEEP ) {
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d AND id < %d',
				$type, (int) $object_id, (int) min( $keep_ids )
			) );
		}
		return $id;
	}

	public static function list_for( $type, $object_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT id, label, sha256, size_bytes, created_by, created_at FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC',
			$type, (int) $object_id
		), ARRAY_A );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['payload'] = json_decode( $row['payload'], true );
		return $row;
	}

	/* ------------------------------------------------------------------
	 * File backups (object_type "file", object_key = sha1 of the path)
	 * ---------------------------------------------------------------- */

	/** Largest file that is backed up (bigger ones need no_backup=true). */
	const FILE_MAX_BYTES = 5242880;

	/** File backups older than this are removed by the daily maintenance. */
	const FILE_MAX_DAYS = 30;

	public static function file_key( $rel ) {
		return sha1( (string) $rel );
	}

	/**
	 * Back up a file before it is changed. Content is stored base64-encoded,
	 * so binary and non-UTF-8 files survive the JSON payload. The column
	 * sha256 holds the keyed file hash the file tools report
	 * (Goo1_MCP_File_Access::hash()); the payload keeps the plain SHA-256 for
	 * the integrity check in get_file(). Older backups are not pruned here:
	 * the caller calls prune_file() once the change succeeded, or
	 * discard_file() when the file was put back (so failed attempts never
	 * push out real backups).
	 *
	 * @return int|WP_Error Backup id.
	 */
	public static function create_file( $rel, $abs, $reason = '' ) {
		global $wpdb;
		clearstatcache( true, $abs );
		$size = @filesize( $abs ); // phpcs:ignore
		if ( false === $size ) {
			return new WP_Error( 'goo1_mcp_backup_failed', 'Could not read the file for the backup; nothing was changed.' );
		}
		if ( $size > self::FILE_MAX_BYTES ) {
			return new WP_Error( 'goo1_mcp_backup_too_large', sprintf( 'The file is %s; backups are limited to %s. Pass no_backup=true to change it without a backup.', size_format( $size ), size_format( self::FILE_MAX_BYTES ) ), array( 'status' => 413 ) );
		}
		$content = @file_get_contents( $abs ); // phpcs:ignore
		if ( false === $content ) {
			return new WP_Error( 'goo1_mcp_backup_failed', 'Could not read the file for the backup; nothing was changed.' );
		}
		$sha     = hash( 'sha256', $content );
		$payload = wp_json_encode( array(
			'path'    => (string) $rel,
			'reason'  => (string) $reason,
			'size'    => strlen( $content ),
			'sha256'  => $sha,
			'perms'   => substr( sprintf( '%o', @fileperms( $abs ) ), -4 ), // phpcs:ignore
			'mtime'   => (int) @filemtime( $abs ), // phpcs:ignore
			'content' => base64_encode( $content ), // phpcs:ignore
		) );
		$ok = $wpdb->insert(
			self::table(),
			array(
				'object_type' => 'file',
				'object_id'   => 0,
				'object_key'  => self::file_key( $rel ),
				'label'       => function_exists( 'mb_strcut' ) ? mb_strcut( (string) $rel, 0, 191, 'UTF-8' ) : substr( (string) $rel, 0, 191 ),
				'payload'     => $payload,
				'sha256'      => Goo1_MCP_File_Access::hash( $content ),
				'size_bytes'  => strlen( $content ),
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'goo1_mcp_backup_failed', 'Could not store the backup (database error); nothing was changed.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Keep only the newest KEEP backups of a file (after a successful change).
	 */
	public static function prune_file( $rel ) {
		global $wpdb;
		$keep_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . " WHERE object_type = 'file' AND object_key = %s ORDER BY id DESC LIMIT %d",
			self::file_key( $rel ), self::KEEP
		) );
		if ( count( $keep_ids ) >= self::KEEP ) {
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE object_type = 'file' AND object_key = %s AND id < %d",
				self::file_key( $rel ), (int) min( $keep_ids )
			) );
		}
	}

	/**
	 * Remove a backup taken for a change that did not happen (the file is in
	 * exactly the backed-up state again).
	 */
	public static function discard_file( $id ) {
		global $wpdb;
		if ( $id ) {
			$wpdb->delete( self::table(), array( 'id' => (int) $id, 'object_type' => 'file' ), array( '%d', '%s' ) );
		}
	}

	/**
	 * Backups of one file, or the latest backups of all files.
	 *
	 * @return array[] id, path, sha256, size_bytes, created_by, created_at.
	 */
	public static function list_files( $rel = null, $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		if ( null !== $rel ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, label, sha256, size_bytes, created_by, created_at FROM ' . self::table() . " WHERE object_type = 'file' AND object_key = %s ORDER BY id DESC LIMIT %d",
				self::file_key( $rel ), $limit
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, label, sha256, size_bytes, created_by, created_at FROM ' . self::table() . " WHERE object_type = 'file' ORDER BY id DESC LIMIT %d",
				$limit
			), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One file backup with decoded content.
	 *
	 * @return array|null id, path, content, sha256, size, perms, mtime, created_at.
	 */
	public static function get_file( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE id = %d AND object_type = 'file'", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$p = json_decode( $row['payload'], true );
		if ( ! is_array( $p ) || ! isset( $p['path'], $p['content'] ) ) {
			return null;
		}
		$content = base64_decode( $p['content'], true ); // phpcs:ignore
		if ( false === $content || ! isset( $p['sha256'] ) || ! hash_equals( (string) $p['sha256'], hash( 'sha256', $content ) ) ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'path'       => (string) $p['path'],
			'content'    => $content,
			'sha256'     => Goo1_MCP_File_Access::hash( $content ),
			'size'       => strlen( $content ),
			'perms'      => isset( $p['perms'] ) ? (string) $p['perms'] : '',
			'mtime'      => isset( $p['mtime'] ) ? (int) $p['mtime'] : 0,
			'reason'     => isset( $p['reason'] ) ? (string) $p['reason'] : '',
			'created_by' => (int) $row['created_by'],
			'created_at' => $row['created_at'],
		);
	}

	/**
	 * Remove file backups older than FILE_MAX_DAYS (site time, like created_at).
	 */
	public static function purge_files( $days = self::FILE_MAX_DAYS ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - max( 1, (int) $days ) * DAY_IN_SECONDS );
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE object_type = 'file' AND created_at < %s", $cutoff ) );
	}
}
