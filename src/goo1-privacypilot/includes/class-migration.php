<?php
/**
 * Migration from Termageddon (policy embeds) to PrivacyPilot shortcodes.
 * Classic content and Elementor data are both handled; WordPress keeps the
 * old content as a revision.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Migration {

	const MARKERS = '(termageddon\.com|data-policy-key|Please wait while the policy is loaded)';

	/** Posts that still contain a Termageddon embed. */
	public static function find() {
		global $wpdb;
		$like = '%termageddon%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				 WHERE p.post_type NOT IN ('revision','nav_menu_item') AND p.post_status IN ('publish','draft','private','future')
				 AND ( p.post_content LIKE %s OR m.meta_value LIKE %s OR p.post_content LIKE %s )",
				$like,
				$like,
				'%data-policy-key%'
			)
		);
		$out = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$post  = get_post( $id );
			$out[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'url'       => get_permalink( $id ),
				'status'    => $post->post_status,
				'elementor' => 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ),
				'suggest'   => self::suggest_doc( $post ),
			);
		}
		return $out;
	}

	public static function suggest_doc( $post ) {
		$hay = strtolower( $post->post_name . ' ' . $post->post_title . ' ' . substr( wp_strip_all_tags( $post->post_content ), 0, 300 ) );
		if ( preg_match( '/cookie/', $hay ) ) {
			return 'cookies';
		}
		if ( preg_match( '/terms|conditions|tos|nutzungsbedingungen|agb/', $hay ) ) {
			return 'terms';
		}
		if ( preg_match( '/privacy|datenschutz/', $hay ) ) {
			return 'privacy';
		}
		if ( preg_match( '/impressum|imprint|legal notice/', $hay ) ) {
			return 'impressum';
		}
		return 'privacy';
	}

	private static function replace_in_html( $html, $shortcode ) {
		$count = 0;
		$out   = $html;
		// Termageddon embed div (any nesting-free content) and its loader script.
		$out = preg_replace( '#<div[^>]*data-policy-key[^>]*>.*?</div>#is', '%%PP%%', $out, -1, $c1 );
		$out = preg_replace( '#<script[^>]*termageddon[^>]*>\s*</script>#is', '', $out, -1, $c2 );
		// The bare fallback text/link variant.
		$out = preg_replace( '#(<p[^>]*>)?\s*Please wait while the policy is loaded\..*?termageddon\.com[^<]*</a>\.?\s*(</p>)?#is', '%%PP%%', $out, -1, $c3 );
		$count = $c1 + $c2 + $c3;
		if ( false === strpos( $out, '%%PP%%' ) ) {
			if ( ! $count && ! preg_match( '~' . self::MARKERS . '~i', $html ) ) {
				return null; // nothing to do.
			}
			$out .= "\n%%PP%%";
		}
		// Only one shortcode, at the first position.
		$pos = strpos( $out, '%%PP%%' );
		$out = substr( $out, 0, $pos ) . $shortcode . str_replace( '%%PP%%', '', substr( $out, $pos + 6 ) );
		return $out;
	}

	/** Replace the embed in one post. Returns true on change. */
	public static function migrate( $post_id, $doc, $lang = 'auto' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$doc       = sanitize_key( $doc );
		$shortcode = '[privacypilot doc="' . $doc . '" lang="' . esc_attr( $lang ) . '"]';
		$changed   = false;

		$new = self::replace_in_html( $post->post_content, $shortcode );
		if ( null !== $new && $new !== $post->post_content ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $new ) ) );
			$changed = true;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw ) && '' !== $raw && false !== stripos( $raw, 'termageddon' ) ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				$done = false;
				$walk = function ( &$elements ) use ( &$walk, $shortcode, &$done ) {
					foreach ( $elements as &$el ) {
						if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
							foreach ( array( 'html', 'editor', 'shortcode', 'text' ) as $field ) {
								if ( isset( $el['settings'][ $field ] ) && is_string( $el['settings'][ $field ] ) && preg_match( '~' . self::MARKERS . '~i', $el['settings'][ $field ] ) ) {
									$replaced = self::replace_in_html( $el['settings'][ $field ], $done ? '' : $shortcode );
									if ( null !== $replaced ) {
										if ( 'html' === $field && 'html' === ( $el['widgetType'] ?? '' ) ) {
											// The HTML widget prints raw markup without running
											// shortcodes; the shortcode widget does both.
											$el['widgetType'] = 'shortcode';
											$el['settings']   = array( 'shortcode' => $replaced );
										} else {
											$el['settings'][ $field ] = $replaced;
										}
										$done = true;
										break;
									}
								}
							}
						}
						if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
							$walk( $el['elements'] );
						}
					}
				};
				$walk( $data );
				if ( $done ) {
					update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
					delete_post_meta( $post_id, '_elementor_css' );
					delete_post_meta( $post_id, '_elementor_element_cache' );
					if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
					}
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			$pages = (array) Goo1_PP_Settings::get( 'pages', array() );
			$use   = 'auto' === $lang ? (string) Goo1_PP_Settings::get( 'default_language', 'en' ) : $lang;
			if ( empty( $pages[ $doc ][ $use ] ) ) {
				Goo1_PP_Settings::set_page_id( $doc, $use, $post_id );
			}
			clean_post_cache( $post_id );
		}
		return $changed;
	}
}
