<?php
/**
 * Document versions: drafts, the published version and the archive.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Versions {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'goo1_pp_versions';
	}

	public static function published( $doc, $lang ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE doc = %s AND lang = %s AND status = 'published' ORDER BY id DESC LIMIT 1", $doc, $lang ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function draft( $doc, $lang ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE doc = %s AND lang = %s AND status IN ('draft','rejected') ORDER BY id DESC LIMIT 1", $doc, $lang ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function history( $doc, $lang, $limit = 50 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, version_no, status, content_hash, library_version, change_type, created_at, published_at, note FROM {$t} WHERE doc = %s AND lang = %s AND status IN ('published','superseded') ORDER BY id DESC LIMIT %d", $doc, $lang, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function all_drafts() {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( "SELECT * FROM {$t} WHERE status IN ('draft','rejected') ORDER BY doc, lang", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Insert or replace the draft for doc/lang. */
	public static function save_draft( $doc, $lang, array $rendered, $change_type, array $hashes ) {
		global $wpdb;
		$t        = self::table();
		$existing = self::draft( $doc, $lang );
		$row      = array(
			'doc'             => $doc,
			'lang'            => $lang,
			'status'          => 'draft',
			'html'            => $rendered['html'],
			'manifest'        => wp_json_encode( array( 'clauses' => $rendered['manifest'], 'missing' => $rendered['missing'] ) ),
			'content_hash'    => $rendered['hash'],
			'library_version' => Goo1_PP_Library::version(),
			'entity_hash'     => $hashes['entity'],
			'site_hash'       => $hashes['site'],
			'change_type'     => $change_type,
			'effective_date'  => $rendered['effective'] ? min( $rendered['effective'] ) : null,
			'created_at'      => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			// A rejected draft stays rejected while its content is unchanged.
			if ( 'rejected' === $existing['status'] && $existing['content_hash'] === $rendered['hash'] ) {
				return (int) $existing['id'];
			}
			if ( 'draft' === $existing['status'] && $existing['content_hash'] === $rendered['hash'] && $existing['entity_hash'] === $hashes['entity'] && $existing['site_hash'] === $hashes['site'] ) {
				return (int) $existing['id'];
			}
			$wpdb->update( $t, $row, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete_draft( $doc, $lang ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE doc = %s AND lang = %s AND status IN ('draft','rejected')", $doc, $lang ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function publish( $id, $note = '' ) {
		global $wpdb;
		$t     = self::table();
		$draft = self::get( $id );
		if ( ! $draft || ! in_array( $draft['status'], array( 'draft', 'rejected' ), true ) ) {
			return false;
		}
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version_no) FROM {$t} WHERE doc = %s AND lang = %s", $draft['doc'], $draft['lang'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'superseded' WHERE doc = %s AND lang = %s AND status = 'published'", $draft['doc'], $draft['lang'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$t,
			array(
				'status'       => 'published',
				'version_no'   => $max + 1,
				'published_at' => current_time( 'mysql', true ),
				'published_by' => get_current_user_id(),
				'note'         => $note,
			),
			array( 'id' => (int) $id )
		);
		do_action( 'goo1_pp_document_published', $draft['doc'], $draft['lang'], (int) $id );
		return true;
	}

	public static function reject( $id ) {
		global $wpdb;
		return (bool) $wpdb->update( self::table(), array( 'status' => 'rejected' ), array( 'id' => (int) $id, 'status' => 'draft' ) );
	}
}
