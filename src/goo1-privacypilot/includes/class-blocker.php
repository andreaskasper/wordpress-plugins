<?php
/**
 * Blocks known third-party scripts and embeds until consent.
 *
 * The page HTML is rewritten in an output buffer:
 *  - <script> of a known service  → type="text/plain" data-pp-category=… (the
 *    banner script re-inserts it after consent)
 *  - <iframe> of a known embed     → placeholder with a "load" button
 *  - Elementor video widgets       → settings parked in data-pp-settings
 *  - other consent tools (cmp)     → removed, if the setting asks for it
 *
 * Works with page caches: the cache stores the rewritten HTML, and the
 * decision which visitor may run what happens in the browser.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Blocker {

	/** @var array|null compiled service patterns */
	private static $patterns = null;

	public static function boot() {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 0 );
	}

	public static function enabled() {
		return Goo1_PP_Settings::get( 'banner_enabled' ) && Goo1_PP_Settings::get( 'blocking_enabled' );
	}

	public static function start() {
		if ( ! self::enabled() || is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( Goo1_PP_Settings::get( 'exempt_admins' ) && current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return; // page builders' editors must see the real embeds.
		}
		ob_start( array( __CLASS__, 'process' ) );
	}

	/** Pattern list from the service catalogue. */
	public static function patterns() {
		if ( null === self::$patterns ) {
			self::$patterns = array();
			foreach ( Goo1_PP_Library::services() as $svc ) {
				$p = isset( $svc['patterns'] ) ? (array) $svc['patterns'] : array();
				self::$patterns[] = array(
					'id'       => $svc['id'],
					'category' => $svc['category'],
					'script'   => (array) ( $p['script'] ?? array() ),
					'inline'   => (array) ( $p['inline'] ?? array() ),
					'iframe'   => (array) ( $p['iframe'] ?? array() ),
				);
			}
		}
		return self::$patterns;
	}

	private static function match( $kind, $subject ) {
		if ( '' === $subject ) {
			return null;
		}
		foreach ( self::patterns() as $p ) {
			foreach ( $p[ $kind ] as $re ) {
				if ( @preg_match( '~' . str_replace( '~', '\~', $re ) . '~i', $subject ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return $p;
				}
			}
		}
		return null;
	}

	public static function identify_script( $src, $inline ) {
		$hit = self::match( 'script', (string) $src );
		if ( ! $hit && '' !== trim( (string) $inline ) ) {
			$hit = self::match( 'inline', (string) $inline );
		}
		return $hit;
	}

	public static function identify_iframe( $src ) {
		return self::match( 'iframe', (string) $src );
	}

	private static function attr( $attrs, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', ' ' . $attrs, $m ) ) {
			return html_entity_decode( $m[2] !== '' ? $m[2] : ( isset( $m[3] ) && $m[3] !== '' ? $m[3] : ( $m[4] ?? '' ) ) );
		}
		return null;
	}

	private static function remove_attr( $attrs, $name ) {
		return preg_replace( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs );
	}

	public static function process( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) || strlen( $html ) > 8 * 1024 * 1024 ) {
			return $html;
		}
		try {
			$remove_cmps = (bool) Goo1_PP_Settings::get( 'remove_other_cmps' );

			$html = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $m ) use ( $remove_cmps ) {
					$attrs  = $m[1];
					$inline = $m[2];
					if ( preg_match( '/\sdata-pp-(category|skip)\b/i', $attrs ) ) {
						return $m[0];
					}
					$id   = (string) self::attr( $attrs, 'id' );
					$type = strtolower( trim( (string) self::attr( $attrs, 'type' ) ) );
					if ( 0 === strpos( $id, 'goo1-pp-' ) ) {
						return $m[0];
					}
					if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
						return $m[0]; // JSON-LD, templates, already blocked …
					}
					$svc = self::identify_script( (string) self::attr( $attrs, 'src' ), $inline );
					if ( ! $svc || 'necessary' === $svc['category'] ) {
						return $m[0];
					}
					if ( 'cmp' === $svc['category'] ) {
						return $remove_cmps ? '<!-- PrivacyPilot: removed ' . esc_html( $svc['id'] ) . ' -->' : $m[0];
					}
					$attrs = self::remove_attr( $attrs, 'type' );
					$extra = ' type="text/plain" data-pp-category="' . esc_attr( $svc['category'] ) . '" data-pp-service="' . esc_attr( $svc['id'] ) . '"';
					if ( 'module' === $type ) {
						$extra .= ' data-pp-type="module"';
					}
					return '<script' . $extra . $attrs . '>' . $inline . '</script>';
				},
				$html
			);

			$html = preg_replace_callback(
				'#<iframe\b([^>]*)>(.*?)</iframe>#is',
				function ( $m ) {
					if ( preg_match( '/\sdata-pp-skip\b/i', $m[1] ) ) {
						return $m[0];
					}
					$src = (string) self::attr( $m[1], 'src' );
					if ( '' === $src ) {
						$src = (string) self::attr( $m[1], 'data-src' );
					}
					$svc = self::identify_iframe( $src );
					if ( ! $svc || 'necessary' === $svc['category'] ) {
						return $m[0];
					}
					$w = (int) self::attr( $m[1], 'width' );
					$h = (int) self::attr( $m[1], 'height' );
					return self::placeholder( $m[0], $svc['id'], $svc['category'], $src, $w, $h );
				},
				$html
			);

			// Elementor video widget: its frontend handler builds the iframe
			// from data-settings. Park the settings until consent.
			$html = preg_replace_callback(
				'#<div\b([^>]*\belementor-widget-video\b[^>]*)>#i',
				function ( $m ) use ( &$html ) {
					$attrs    = $m[1][0];
					$after    = substr( $html, $m[0][1] + strlen( $m[0][0] ), 4000 );
					$next     = stripos( $after, 'elementor-widget ' );
					$inner    = false === $next ? $after : substr( $after, 0, $next );
					// Vimeo/Dailymotion widgets render the iframe on the server;
					// it already got its own placeholder, so no second overlay.
					$has_ph   = false !== strpos( $inner, 'class="pp-embed"' );
					$settings = self::attr( $attrs, 'data-settings' );
					if ( null === $settings || false !== stripos( $attrs, 'data-pp-settings' ) ) {
						return $m[0][0];
					}
					$svc_id = '';
					if ( preg_match( '/youtube|youtu\.be/i', $settings ) ) {
						$svc_id = 'youtube';
					} elseif ( preg_match( '/vimeo/i', $settings ) ) {
						$svc_id = 'vimeo';
					} elseif ( preg_match( '/dailymotion/i', $settings ) ) {
						$svc_id = 'dailymotion';
					}
					$svc = $svc_id ? Goo1_PP_Library::service( $svc_id ) : null;
					if ( ! $svc ) {
						return $m[0][0];
					}
					$attrs = self::remove_attr( $attrs, 'data-settings' );
					$attrs = preg_replace( '/\sclass\s*=\s*"/i', ' class="pp-elementor-video ', $attrs, 1 );
					$open  = '<div' . $attrs . ' data-pp-settings="' . esc_attr( $settings ) . '" data-pp-service="' . esc_attr( $svc['id'] ) . '" data-pp-category="' . esc_attr( $svc['category'] ) . '">';
					return $has_ph ? $open : $open . self::overlay( $svc['id'], $svc['category'] );
				},
				$html,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		} catch ( \Throwable $e ) {
			return $html;
		}
		return $html;
	}

	private static function overlay( $service_id, $category, $src = '' ) {
		$lang = Goo1_PP_Documents::current_lang();
		$ui   = Goo1_PP_Library::ui( $lang );
		$t    = $ui['placeholder'] ?? array();
		$svc  = $service_id ? Goo1_PP_Library::service( $service_id ) : null;
		$name = $svc ? $svc['name'] : ( $ui['categories'][ $category ]['label'] ?? $category );
		$text = sprintf( $t['text'] ?? 'This content is provided by %s. Loading it sends data to the provider.', $name );
		$out  = '<div class="pp-embed-inner"><p class="pp-embed-text">' . esc_html( $text ) . '</p>'
			. '<p class="pp-embed-actions"><button type="button" class="pp-embed-load">' . esc_html( $t['load'] ?? 'Load content' ) . '</button>'
			. ' <label class="pp-embed-always"><input type="checkbox" class="pp-embed-remember"> ' . esc_html( sprintf( $t['always'] ?? 'Always allow %s', $name ) ) . '</label></p>';
		if ( $src && preg_match( '#^https?://#', $src ) ) {
			$out .= '<p class="pp-embed-link"><a href="' . esc_url( $src ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $t['open'] ?? 'Open on the provider\'s site' ) . '</a></p>';
		}
		if ( $svc && ! empty( $svc['privacy_url'] ) ) {
			$out .= '<p class="pp-embed-privacy"><a href="' . esc_url( $svc['privacy_url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $t['privacy'] ?? 'Provider privacy policy' ) . '</a></p>';
		}
		return '<div class="pp-embed-overlay">' . $out . '</div></div>';
	}

	/** Placeholder that holds the original markup (base64) until consent. */
	public static function placeholder( $original, $service_id, $category, $src = '', $w = 0, $h = 0 ) {
		$style = '';
		if ( $w > 0 && $h > 0 ) {
			$style = ' style="aspect-ratio:' . (int) $w . '/' . (int) $h . ';max-width:' . (int) $w . 'px"';
		}
		return '<div class="pp-embed" data-pp-service="' . esc_attr( $service_id ) . '" data-pp-category="' . esc_attr( $category ) . '" data-pp-html="' . esc_attr( base64_encode( $original ) ) . '"' . $style . '>'
			. self::overlay( $service_id, $category, $src )
			. '</div>';
	}
}
