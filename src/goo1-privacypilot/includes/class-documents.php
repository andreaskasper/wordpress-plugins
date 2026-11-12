<?php
/**
 * Front end output of documents: shortcodes, block, pages, links.
 *
 * Shortcodes:
 *   [privacypilot doc="privacy" lang="auto" archive="1" show_credit="1"]
 *   [privacypilot_links]                  links to all documents + settings
 *   [privacypilot_settings_link text=""]  opens the consent settings
 *   [privacypilot_request_form]           privacy rights request form
 *   [privacypilot_embed service="youtube"]...[/privacypilot_embed]
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Documents {

	public static function boot() {
		add_shortcode( 'privacypilot', array( __CLASS__, 'shortcode_document' ) );
		add_shortcode( 'privacypilot_links', array( __CLASS__, 'shortcode_links' ) );
		add_shortcode( 'privacypilot_settings_link', array( __CLASS__, 'shortcode_settings_link' ) );
		add_shortcode( 'privacypilot_embed', array( __CLASS__, 'shortcode_embed' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_links' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	public static function styles() {
		wp_register_style( 'goo1-pp-documents', GOO1_PP_URL . 'public/documents.css', array(), goo1_pp_asset_ver( 'public/documents.css' ) );
		if ( is_singular() && self::page_has_document( get_queried_object_id() ) ) {
			wp_enqueue_style( 'goo1-pp-documents' );
		}
	}

	private static function page_has_document( $post_id ) {
		foreach ( (array) Goo1_PP_Settings::get( 'pages', array() ) as $langs ) {
			if ( in_array( (int) $post_id, array_map( 'intval', (array) $langs ), true ) ) {
				return true;
			}
		}
		$post = get_post( $post_id );
		return $post && has_shortcode( $post->post_content, 'privacypilot' );
	}

	/** Visitor language: multilingual plugin → locale → default. */
	public static function current_lang() {
		$langs = Goo1_PP_Settings::languages();
		$lang  = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language( 'slug' );
		} elseif ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$lang = (string) ICL_LANGUAGE_CODE;
		} else {
			$lang = substr( determine_locale(), 0, 2 );
		}
		if ( in_array( $lang, $langs, true ) ) {
			return $lang;
		}
		$default = (string) Goo1_PP_Settings::get( 'default_language', 'en' );
		return in_array( $default, $langs, true ) ? $default : $langs[0];
	}

	public static function url( $doc, $lang ) {
		$id = Goo1_PP_Settings::page_id( $doc, $lang );
		if ( ! $id ) {
			foreach ( Goo1_PP_Settings::languages() as $other ) {
				$id = Goo1_PP_Settings::page_id( $doc, $other );
				if ( $id ) {
					break;
				}
			}
		}
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		return (string) get_permalink( $id );
	}

	public static function shortcode_document( $atts ) {
		$atts = shortcode_atts(
			array(
				'doc'     => 'privacy',
				'lang'    => 'auto',
				'archive' => '1',
				'meta'    => '1',
				'show_credit' => '1',
			),
			$atts,
			'privacypilot'
		);
		$doc  = sanitize_key( $atts['doc'] );
		$lang = 'auto' === $atts['lang'] ? self::current_lang() : sanitize_key( $atts['lang'] );
		$credit = ! in_array( strtolower( trim( (string) $atts['show_credit'] ) ), array( '0', 'false', 'no', 'off' ), true );
		return self::render( $doc, $lang, '1' === (string) $atts['archive'], '1' === (string) $atts['meta'], $credit );
	}

	public static function render( $doc, $lang, $with_archive = true, $with_meta = true, $with_credit = true ) {
		$ui      = Goo1_PP_Library::ui( $lang );
		$version = null;
		$is_old  = false;

		// Viewing an archived version: ?pp_version=<id>.
		if ( $with_archive && isset( $_GET['pp_version'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$row = Goo1_PP_Versions::get( absint( $_GET['pp_version'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $row && $row['doc'] === $doc && $row['lang'] === $lang && in_array( $row['status'], array( 'published', 'superseded' ), true ) ) {
				$version = $row;
				$is_old  = 'superseded' === $row['status'];
			}
		}
		if ( ! $version ) {
			$version = Goo1_PP_Versions::published( $doc, $lang );
		}
		if ( ! $version ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="pp-doc pp-doc-missing"><p><strong>PrivacyPilot:</strong> ' . esc_html__( 'No published version of this document yet. Complete the questionnaire and approve the document under Settings → PrivacyPilot.', 'goo1-privacypilot' ) . '</p></div>';
			}
			return '<div class="pp-doc pp-doc-missing"><p>' . esc_html( $ui['doc_unavailable'] ?? 'This document is currently being updated.' ) . '</p></div>';
		}

		$html = (string) $version['html'];
		$html = str_replace( '<div class="pp-marker" id="pp-marker-request_form"></div>', Goo1_PP_Requests::form_html( $lang ), $html );
		$html = str_replace( '<div class="pp-marker" id="pp-marker-settings_link"></div>', self::settings_button( $lang ), $html );

		$out = '<div class="pp-doc pp-doc-' . esc_attr( $doc ) . '" lang="' . esc_attr( $lang ) . '">';
		if ( $is_old ) {
			$out .= '<p class="pp-doc-archived">' . esc_html( $ui['archived_notice'] ?? 'You are viewing an earlier version of this document.' ) . ' <a href="' . esc_url( remove_query_arg( 'pp_version' ) ) . '">' . esc_html( $ui['current_version'] ?? 'Current version' ) . '</a></p>';
		}
		if ( $with_meta ) {
			$date = mysql2date( 'en' === $lang ? 'F j, Y' : 'j. F Y', get_date_from_gmt( $version['published_at'] ), true );
			$out .= '<p class="pp-doc-meta">' . esc_html( sprintf( $ui['last_updated'] ?? 'Last updated: %s', $date ) ) . ' · ' . esc_html( sprintf( $ui['version'] ?? 'Version %d', (int) $version['version_no'] ) ) . '</p>';
		}
		$out .= $html;
		if ( $with_archive ) {
			$hist = Goo1_PP_Versions::history( $doc, $lang, 20 );
			if ( count( $hist ) > 1 ) {
				$out .= '<details class="pp-doc-history"><summary>' . esc_html( $ui['previous_versions'] ?? 'Previous versions' ) . '</summary><ul>';
				foreach ( $hist as $h ) {
					$label = sprintf( $ui['version'] ?? 'Version %d', (int) $h['version_no'] ) . ' – ' . mysql2date( 'Y-m-d', get_date_from_gmt( $h['published_at'] ) );
					$out  .= '<li><a href="' . esc_url( add_query_arg( 'pp_version', (int) $h['id'] ) ) . '" rel="nofollow">' . esc_html( $label ) . '</a></li>';
				}
				$out .= '</ul></details>';
			}
		}
		return $out . ( $with_credit ? self::credit( $lang ) : '' ) . '</div>';
	}

	/**
	 * Small "Generated by PrivacyPilot" line below a document. Added at
	 * render time, so it is not part of the stored version and its hash.
	 * Turn it off per document with [privacypilot ... show_credit="0"] or for
	 * the whole site with add_filter( 'goo1_pp_show_credit', '__return_false' ).
	 */
	public static function credit( $lang ) {
		if ( ! apply_filters( 'goo1_pp_show_credit', true, $lang ) ) {
			return '';
		}
		$ui   = Goo1_PP_Library::ui( $lang );
		$text = $ui['credit'] ?? ( 0 === strpos( (string) $lang, 'de' ) ? 'Erstellt mit PrivacyPilot' : 'Generated by PrivacyPilot' );
		return '<p class="pp-doc-credit"><a href="https://github.com/andreaskasper/wordpress-plugins" target="_blank" rel="noopener">' . esc_html( $text ) . '</a></p>';
	}

	public static function settings_button( $lang, $text = '' ) {
		$ui   = Goo1_PP_Library::ui( $lang );
		$text = $text ? $text : ( $ui['settings_link'] ?? 'Privacy settings' );
		return '<button type="button" class="pp-open-settings pp-button-link" data-pp-open="settings">' . esc_html( $text ) . '</button>';
	}

	public static function shortcode_settings_link( $atts ) {
		$atts = shortcode_atts( array( 'text' => '' ), $atts, 'privacypilot_settings_link' );
		return self::settings_button( self::current_lang(), sanitize_text_field( $atts['text'] ) );
	}

	public static function links_html() {
		$lang  = self::current_lang();
		$items = array();
		foreach ( Goo1_PP_Settings::documents() as $doc ) {
			$url = self::url( $doc, $lang );
			$def = Goo1_PP_Library::document( $doc );
			if ( $url && $def ) {
				$items[] = '<a href="' . esc_url( $url ) . '">' . esc_html( Goo1_PP_Library::t( $def['short_title'] ?? $def['title'], $lang ) ) . '</a>';
			}
		}
		if ( Goo1_PP_Settings::get( 'banner_enabled' ) ) {
			$items[] = self::settings_button( $lang );
		}
		return $items ? '<nav class="pp-links" aria-label="' . esc_attr__( 'Legal', 'goo1-privacypilot' ) . '">' . implode( ' <span aria-hidden="true">·</span> ', $items ) . '</nav>' : '';
	}

	public static function shortcode_links() {
		wp_enqueue_style( 'goo1-pp-documents' );
		return self::links_html();
	}

	public static function footer_links() {
		if ( ! Goo1_PP_Settings::get( 'footer_links' ) || is_admin() ) {
			return;
		}
		wp_enqueue_style( 'goo1-pp-documents' );
		echo '<div class="pp-footer-links">' . self::links_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Wrap arbitrary third-party markup: only rendered after consent for the
	 * given service (or category).
	 */
	public static function shortcode_embed( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'service' => '', 'category' => 'media' ), $atts, 'privacypilot_embed' );
		$svc  = $atts['service'] ? Goo1_PP_Library::service( sanitize_key( $atts['service'] ) ) : null;
		$cat  = $svc ? $svc['category'] : sanitize_key( $atts['category'] );
		$html = do_shortcode( (string) $content );
		if ( ! Goo1_PP_Settings::get( 'banner_enabled' ) || 'necessary' === $cat ) {
			return $html;
		}
		return Goo1_PP_Blocker::placeholder( $html, $svc ? $svc['id'] : '', $cat, '' );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'goo1-pp-block',
			GOO1_PP_URL . 'admin/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			goo1_pp_asset_ver( 'admin/block.js' ),
			true
		);
		$docs = array();
		foreach ( Goo1_PP_Library::documents() as $d ) {
			$docs[] = array( 'value' => $d['id'], 'label' => Goo1_PP_Library::t( $d['title'], Goo1_PP_Questionnaire::admin_lang() ) );
		}
		wp_localize_script( 'goo1-pp-block', 'goo1PpBlock', array( 'documents' => $docs ) );
		register_block_type(
			'goo1/privacypilot-document',
			array(
				'api_version'     => 2,
				'editor_script'   => 'goo1-pp-block',
				'attributes'      => array(
					'doc'  => array( 'type' => 'string', 'default' => 'privacy' ),
					'lang' => array( 'type' => 'string', 'default' => 'auto' ),
				),
				'render_callback' => function ( $attrs ) {
					wp_enqueue_style( 'goo1-pp-documents' );
					$lang = ( empty( $attrs['lang'] ) || 'auto' === $attrs['lang'] ) ? Goo1_PP_Documents::current_lang() : sanitize_key( $attrs['lang'] );
					return Goo1_PP_Documents::render( sanitize_key( $attrs['doc'] ?? 'privacy' ), $lang );
				},
			)
		);
	}

	/** Create missing pages for all documents/languages. Returns created ids. */
	public static function create_pages() {
		$created = array();
		foreach ( Goo1_PP_Publisher::targets() as $t ) {
			list( $doc, $lang ) = $t;
			$existing = Goo1_PP_Settings::page_id( $doc, $lang );
			if ( $existing && get_post( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}
			$def   = Goo1_PP_Library::document( $doc );
			$title = Goo1_PP_Library::t( $def['title'], $lang );
			$slug  = Goo1_PP_Library::t( $def['slug'] ?? $doc, $lang );
			if ( count( Goo1_PP_Settings::languages() ) > 1 && get_page_by_path( $slug ) ) {
				$slug .= '-' . $lang;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => '<!-- wp:shortcode -->[privacypilot doc="' . $doc . '" lang="' . $lang . '"]<!-- /wp:shortcode -->',
					'comment_status' => 'closed',
					'ping_status'  => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				Goo1_PP_Settings::set_page_id( $doc, $lang, $id );
				$created[] = $id;
			}
		}
		self::sync_core_privacy_page();
		return $created;
	}

	/** Point WordPress' own privacy page setting at our privacy document. */
	public static function sync_core_privacy_page() {
		$id = Goo1_PP_Settings::page_id( 'privacy', (string) Goo1_PP_Settings::get( 'default_language', 'en' ) );
		if ( ! $id ) {
			foreach ( Goo1_PP_Settings::languages() as $lang ) {
				$id = Goo1_PP_Settings::page_id( 'privacy', $lang );
				if ( $id ) {
					break;
				}
			}
		}
		if ( $id && (int) get_option( 'wp_page_for_privacy_policy' ) !== $id ) {
			update_option( 'wp_page_for_privacy_policy', $id );
		}
	}
}
