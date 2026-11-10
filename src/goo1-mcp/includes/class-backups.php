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
			label varchar(191) NOT NULL DEFAULT '',
			payload longtext,
			sha256 char(64) NOT NULL DEFAULT '',
			size_bytes int(10) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY object (object_type, object_id, id)
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
}
