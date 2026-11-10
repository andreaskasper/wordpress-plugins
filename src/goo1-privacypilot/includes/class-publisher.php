<?php
/**
 * Decides what happens with a newly rendered document:
 *  - unchanged          → nothing
 *  - editorial changes  → published automatically
 *  - material changes   → draft, waits for approval (local or via the hub);
 *                         published automatically on the effective date of a
 *                         clause if the deadline option is on and nobody
 *                         rejected it.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Publisher {

	const LOCAL_APPROVAL = 'goo1_pp_approval';
	const SITE_APPROVAL  = 'goo1_pp_site_approval';

	/** @var bool */
	private static $running = false;

	/** Documents this site publishes, per language, that exist in the library. */
	public static function targets() {
		$out = array();
		foreach ( Goo1_PP_Settings::documents() as $doc_id ) {
			$def = Goo1_PP_Library::document( $doc_id );
			if ( ! $def ) {
				continue;
			}
			foreach ( Goo1_PP_Settings::languages() as $lang ) {
				if ( ! empty( $def['languages'] ) && ! in_array( $lang, (array) $def['languages'], true ) ) {
					continue;
				}
				$out[] = array( $doc_id, $lang );
			}
		}
		return $out;
	}

	public static function current_hashes() {
		return array(
			'entity' => Goo1_PP_Questionnaire::entity_hash(),
			'site'   => Goo1_PP_Questionnaire::site_hash(),
		);
	}

	/**
	 * Render everything and apply the rules. Returns a summary.
	 */
	public static function sync() {
		if ( self::$running ) {
			return array();
		}
		self::$running = true;
		$summary       = array( 'unchanged' => 0, 'published' => array(), 'drafts' => array() );
		$hashes        = self::current_hashes();
		$generators    = array();

		try {
			foreach ( self::targets() as $t ) {
				list( $doc, $lang ) = $t;
				if ( ! isset( $generators[ $lang ] ) ) {
					$generators[ $lang ] = new Goo1_PP_Generator( $lang );
				}
				$rendered  = $generators[ $lang ]->render( $doc );
				$published = Goo1_PP_Versions::published( $doc, $lang );

				if ( $published && $published['content_hash'] === $rendered['hash'] ) {
					Goo1_PP_Versions::delete_draft( $doc, $lang );
					$summary['unchanged']++;
					continue;
				}

				$change = self::classify( $published, $rendered, $hashes );
				$id     = Goo1_PP_Versions::save_draft( $doc, $lang, $rendered, $change, $hashes );
				$draft  = Goo1_PP_Versions::get( $id );

				if ( 'editorial' === $change ) {
					Goo1_PP_Versions::publish( $id, 'editorial update (automatic)' );
					$summary['published'][] = "$doc/$lang";
					continue;
				}
				if ( 'rejected' !== $draft['status'] && self::is_approved( $draft ) ) {
					Goo1_PP_Versions::publish( $id, 'approved' );
					$summary['published'][] = "$doc/$lang";
					continue;
				}
				if ( 'rejected' !== $draft['status'] && self::deadline_reached( $draft ) ) {
					Goo1_PP_Versions::publish( $id, 'published automatically on the effective date ' . $draft['effective_date'] );
					$summary['published'][] = "$doc/$lang";
					continue;
				}
				$summary['drafts'][] = "$doc/$lang";
			}
		} finally {
			self::$running = false;
		}

		update_option( 'goo1_pp_last_sync', array( 'time' => time(), 'summary' => $summary ), false );
		update_option( 'goo1_pp_synced_library', Goo1_PP_Library::version(), false );
		self::maybe_notify();
		return $summary;
	}

	public static function classify( $published, array $rendered, array $hashes ) {
		if ( ! $published ) {
			return 'material';
		}
		if ( $published['entity_hash'] !== $hashes['entity'] || $published['site_hash'] !== $hashes['site'] ) {
			return 'material';
		}
		$old = json_decode( (string) $published['manifest'], true );
		$old = isset( $old['clauses'] ) ? (array) $old['clauses'] : array();
		$new = (array) $rendered['manifest'];
		if ( array_diff_key( $old, $new ) || array_diff_key( $new, $old ) ) {
			return 'material';
		}
		foreach ( $new as $id => $sig ) {
			if ( $old[ $id ] !== $sig && 'editorial' !== ( $rendered['changes'][ $id ] ?? 'material' ) ) {
				return 'material';
			}
		}
		// Same clauses, only editorial edits (or a different placeholder
		// rendering, e.g. a page URL changed).
		return 'editorial';
	}

	/** Is this draft covered by an approval? */
	public static function is_approved( array $draft ) {
		$mode = Goo1_PP_Settings::get( 'mode', 'standalone' );

		if ( 'standalone' === $mode ) {
			$a = get_option( self::LOCAL_APPROVAL, array() );
			return is_array( $a )
				&& ( $a['library_version'] ?? '' ) === $draft['library_version']
				&& ( $a['entity_hash'] ?? '' ) === $draft['entity_hash']
				&& ( $a['site_hash'] ?? '' ) === $draft['site_hash'];
		}

		// Hub and client: entity part approved on the hub, site part locally.
		$site = get_option( self::SITE_APPROVAL, array() );
		if ( ! is_array( $site ) || ( $site['site_hash'] ?? '' ) !== $draft['site_hash'] ) {
			return false;
		}
		if ( 'hub' === $mode ) {
			$profile  = Goo1_PP_Hub::own_profile();
			$approval = $profile ? $profile['approval'] : null;
		} else {
			$cache    = Goo1_PP_Hub::client_cache();
			$approval = $cache['approval'] ?? null;
			// The hub approved its own hash of the profile; make sure it is the
			// answer set this draft was built from.
			if ( ! is_array( $approval ) || ( $cache['profile']['entity_hash'] ?? '' ) !== ( $approval['entity_hash'] ?? '-' ) ) {
				return false;
			}
			return ( $approval['library_version'] ?? '' ) === $draft['library_version'];
		}
		return is_array( $approval )
			&& ( $approval['library_version'] ?? '' ) === $draft['library_version']
			&& ( $approval['entity_hash'] ?? '' ) === $draft['entity_hash'];
	}

	public static function deadline_reached( array $draft ) {
		if ( ! Goo1_PP_Settings::get( 'auto_publish_deadline', true ) || empty( $draft['effective_date'] ) ) {
			return false;
		}
		// Only for documents that were approved at least once before:
		// a first publication always needs a human.
		if ( ! Goo1_PP_Versions::published( $draft['doc'], $draft['lang'] ) ) {
			return false;
		}
		return $draft['effective_date'] <= wp_date( 'Y-m-d' );
	}

	/** Approve the current state (what the admin sees in the previews). */
	public static function approve_current() {
		$mode   = Goo1_PP_Settings::get( 'mode', 'standalone' );
		$hashes = self::current_hashes();
		if ( 'standalone' === $mode ) {
			update_option(
				self::LOCAL_APPROVAL,
				array(
					'library_version' => Goo1_PP_Library::version(),
					'entity_hash'     => $hashes['entity'],
					'site_hash'       => $hashes['site'],
					'approved_at'     => time(),
					'approved_by'     => get_current_user_id(),
				),
				false
			);
		} else {
			update_option(
				self::SITE_APPROVAL,
				array(
					'site_hash'   => $hashes['site'],
					'approved_at' => time(),
					'approved_by' => get_current_user_id(),
				),
				false
			);
			if ( 'hub' === $mode ) {
				Goo1_PP_Hub::approve_profile( (string) Goo1_PP_Settings::get( 'hub_profile_id', '' ) );
			}
		}
		return self::sync();
	}

	public static function pending() {
		return Goo1_PP_Versions::all_drafts();
	}

	private static function maybe_notify() {
		$drafts = array_filter(
			self::pending(),
			function ( $d ) {
				return 'draft' === $d['status'];
			}
		);
		if ( ! $drafts ) {
			delete_option( 'goo1_pp_notified' );
			return;
		}
		$key = md5( wp_json_encode( wp_list_pluck( $drafts, 'content_hash' ) ) );
		if ( get_option( 'goo1_pp_notified' ) === $key ) {
			return;
		}
		update_option( 'goo1_pp_notified', $key, false );
		$list = implode( "\n", array_map( function ( $d ) { return '- ' . $d['doc'] . ' (' . $d['lang'] . ')' . ( $d['effective_date'] ? ', effective ' . $d['effective_date'] : '' ); }, $drafts ) );
		$mode = Goo1_PP_Settings::get( 'mode', 'standalone' );
		$how  = 'client' === $mode
			? 'This site is connected to the hub ' . Goo1_PP_Settings::get( 'hub_url' ) . '. The new version is published as soon as it is approved there.'
			: 'Please review and approve it: ' . admin_url( 'options-general.php?page=goo1-privacypilot&tab=documents' );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] PrivacyPilot: new document version waiting for approval', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			"A new version of the following documents is ready:\n\n{$list}\n\n{$how}\n\nPrivacyPilot does not provide legal advice. The site owner is responsible for the answers in the questionnaire."
		);
	}
}
