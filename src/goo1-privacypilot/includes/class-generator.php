<?php
/**
 * Assembles a document from the clauses whose conditions match the answers,
 * converts Markdown and fills placeholders.
 *
 * Placeholders:
 *   {{entity.name}}          answer (arrays are joined with commas)
 *   {{site.url}}             computed values (see context())
 *   {{url.privacy}}          URL of another PrivacyPilot document
 *   {{link.cookies}}         link to another document (title as text)
 *   {{#services_table:analytics}}  table of the selected services in a group
 *   {{#services_list:payments}}    list of the selected services in a group
 *   {{#cookie_table}}        all cookies of the selected services
 *   {{#request_form}}        privacy request form (rendered on display)
 *   {{#settings_link}}       "privacy settings" button (rendered on display)
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Generator {

	/** @var array */
	private $ctx;

	/** @var array */
	private $flat;

	/** @var string */
	private $lang;

	/** @var array */
	private $missing = array();

	public function __construct( $lang ) {
		$this->lang = $lang;
		$this->ctx  = self::context( $lang );
		$this->flat = self::flatten( $this->ctx );
	}

	/** Full condition/placeholder context for a language. */
	public static function context( $lang ) {
		$ctx = Goo1_PP_Questionnaire::context();

		$home = home_url( '/' );
		$ctx['site']           = isset( $ctx['site'] ) && is_array( $ctx['site'] ) ? $ctx['site'] : array();
		$ctx['site']['url']    = $home;
		$ctx['site']['domain'] = wp_parse_url( $home, PHP_URL_HOST );
		if ( empty( $ctx['site']['name'] ) ) {
			$ctx['site']['name'] = get_bloginfo( 'name' );
		}
		$ctx['lang'] = $lang;

		// Selected services and their groups/categories.
		$selected     = (array) Goo1_PP_Conditions::lookup( $ctx, 'services.used' );
		$ctx['svc']   = array();
		$ctx['group'] = array();
		$ctx['cat']   = array();
		foreach ( Goo1_PP_Library::services() as $svc ) {
			if ( in_array( $svc['id'], $selected, true ) ) {
				$ctx['svc'][ $svc['id'] ] = true;
				if ( ! empty( $svc['group'] ) ) {
					$ctx['group'][ $svc['group'] ] = true;
				}
				$ctx['cat'][ $svc['category'] ] = true;
			}
		}

		// Document URLs.
		$ctx['url'] = array();
		foreach ( Goo1_PP_Library::documents() as $doc ) {
			$ctx['url'][ $doc['id'] ] = Goo1_PP_Documents::url( $doc['id'], $lang );
		}
		$ctx['doc'] = array_fill_keys( Goo1_PP_Settings::documents(), true );

		$ctx['date'] = array( 'today' => wp_date( 'Y-m-d' ) );
		return $ctx;
	}

	public static function flatten( array $arr, $prefix = '' ) {
		$out = array();
		foreach ( $arr as $k => $v ) {
			$key = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) && $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
				$out += self::flatten( $v, $key );
			} else {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}

	/**
	 * @return array html, manifest (clause id => hash@version), changes
	 *               (clause id => change type), effective (dates), missing.
	 */
	public function render( $doc ) {
		$this->missing = array();
		$parts         = array();
		$manifest      = array();
		$changes       = array();
		$effective     = array();

		foreach ( Goo1_PP_Library::clauses( $doc, $this->lang ) as $clause ) {
			if ( ! Goo1_PP_Conditions::evaluate( $clause['when'], $this->ctx ) ) {
				continue;
			}
			$html = Goo1_PP_Markdown::to_html( $clause['body'], 0 );
			$html = $this->fill( $html );
			if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === strpos( $html, 'pp-marker' ) && false === strpos( $html, '<table' ) ) {
				continue;
			}
			$parts[]                     = '<section class="pp-clause" data-pp-clause="' . esc_attr( $clause['id'] ) . '">' . $html . '</section>';
			$manifest[ $clause['id'] ]   = $clause['hash'] . '@' . $clause['version'];
			$changes[ $clause['id'] ]    = $clause['change'];
			if ( ! empty( $clause['effective'] ) ) {
				$effective[ $clause['id'] ] = $clause['effective'];
			}
		}

		$html = wp_kses( implode( "\n", $parts ), self::allowed_html() );
		return array(
			'html'      => $html,
			'hash'      => hash( 'sha256', $html ),
			'manifest'  => $manifest,
			'changes'   => $changes,
			'effective' => $effective,
			'missing'   => array_values( array_unique( $this->missing ) ),
		);
	}

	public static function allowed_html() {
		$allowed            = wp_kses_allowed_html( 'post' );
		$allowed['section'] = array( 'class' => true, 'data-pp-clause' => true, 'id' => true );
		$allowed['div']     = array( 'class' => true, 'id' => true );
		$allowed['table']   = array( 'class' => true );
		$allowed['a']       = array( 'href' => true, 'rel' => true, 'target' => true, 'class' => true );
		foreach ( array( 'h2', 'h3', 'h4', 'h5' ) as $h ) {
			$allowed[ $h ] = array( 'id' => true );
		}
		return $allowed;
	}

	private function fill( $html ) {
		// Block helpers first (they stand alone in the markup).
		$html = preg_replace_callback(
			'/\{\{#\s*([a-z_]+)(?::([a-z0-9_\-]+))?\s*\}\}/i',
			function ( $m ) {
				return $this->block( strtolower( $m[1] ), isset( $m[2] ) ? $m[2] : '' );
			},
			$html
		);

		return preg_replace_callback(
			'/\{\{\s*([a-z0-9_.\-]+)\s*\}\}(\.?)/i',
			function ( $m ) {
				$value = $this->value( $m[1] );
				// "JL Productions Inc." at the end of a sentence: no double period.
				if ( '.' === $m[2] && '.' === substr( wp_strip_all_tags( $value ), -1 ) ) {
					return $value;
				}
				return $value . $m[2];
			},
			$html
		);
	}

	private function value( $key ) {
		if ( 0 === strpos( $key, 'url.' ) ) {
			$url = $this->ctx['url'][ substr( $key, 4 ) ] ?? '';
			if ( '' === $url ) {
				$this->missing[] = $key;
			}
			return esc_url( $url );
		}
		if ( 0 === strpos( $key, 'link.' ) ) {
			$doc = substr( $key, 5 );
			$url = $this->ctx['url'][ $doc ] ?? '';
			$def = Goo1_PP_Library::document( $doc );
			$txt = $def ? Goo1_PP_Library::t( $def['title'], $this->lang ) : $doc;
			if ( '' === $url ) {
				$this->missing[] = $key;
				return esc_html( $txt );
			}
			return '<a href="' . esc_url( $url ) . '">' . esc_html( $txt ) . '</a>';
		}
		$v = array_key_exists( $key, $this->flat ) ? $this->flat[ $key ] : Goo1_PP_Conditions::lookup( $this->ctx, $key );
		if ( is_array( $v ) ) {
			$v = implode( ', ', array_map( array( $this, 'label_for' ), array_fill( 0, count( $v ), $key ), $v ) );
		} elseif ( is_bool( $v ) ) {
			$v = $v ? Goo1_PP_Library::t( array( 'en' => 'yes', 'de' => 'ja' ), $this->lang ) : Goo1_PP_Library::t( array( 'en' => 'no', 'de' => 'nein' ), $this->lang );
		} else {
			$v = $this->label_for( $key, $v );
		}
		if ( null === $v || '' === (string) $v ) {
			$this->missing[] = $key;
			return '';
		}
		return nl2br( esc_html( (string) $v ) );
	}

	/** Map option values of select/multi questions to their label. */
	private function label_for( $key, $value ) {
		$q = Goo1_PP_Questionnaire::question( $key );
		if ( $q && ! empty( $q['options'] ) ) {
			foreach ( $q['options'] as $opt ) {
				if ( (string) $opt['value'] === (string) $value ) {
					return Goo1_PP_Library::t( $opt['label'], $this->lang );
				}
			}
		}
		if ( $q && 'services' === $q['type'] ) {
			$svc = Goo1_PP_Library::service( (string) $value );
			return $svc ? $svc['name'] : $value;
		}
		return $value;
	}

	private function selected_services( $group ) {
		$out = array();
		foreach ( Goo1_PP_Library::services() as $svc ) {
			if ( empty( $this->ctx['svc'][ $svc['id'] ] ) ) {
				continue;
			}
			$svc_group = $svc['group'] ?? '';
			// Newsletter tools sit in the marketing consent category but have
			// their own section; they are not advertising services.
			$by_cat = $svc['category'] === $group && ! ( 'marketing' === $group && 'newsletter' === $svc_group );
			if ( 'all' === $group || $svc_group === $group || $by_cat ) {
				$out[] = $svc;
			}
		}
		return $out;
	}

	private function block( $name, $arg ) {
		$ui = Goo1_PP_Library::ui( $this->lang );
		switch ( $name ) {
			case 'services_table':
				$list = $this->selected_services( $arg ? $arg : 'all' );
				if ( ! $list ) {
					return '';
				}
				$h    = $ui['table'] ?? array();
				$html = '<div class="pp-table-wrap"><table class="pp-table pp-services"><thead><tr><th>' . esc_html( $h['service'] ?? 'Service' ) . '</th><th>' . esc_html( $h['provider'] ?? 'Provider' ) . '</th><th>' . esc_html( $h['purpose'] ?? 'Purpose' ) . '</th><th>' . esc_html( $h['more'] ?? 'Privacy policy' ) . '</th></tr></thead><tbody>';
				foreach ( $list as $svc ) {
					$html .= '<tr><td>' . esc_html( $svc['name'] ) . '</td><td>' . esc_html( $svc['provider'] ) . '</td><td>' . esc_html( Goo1_PP_Library::t( $svc['purpose'] ?? '', $this->lang ) ) . '</td><td>'
						. ( ! empty( $svc['privacy_url'] ) ? '<a href="' . esc_url( $svc['privacy_url'] ) . '" rel="noopener" target="_blank">' . esc_html( wp_parse_url( $svc['privacy_url'], PHP_URL_HOST ) ) . '</a>' : '' )
						. '</td></tr>';
				}
				return $html . '</tbody></table></div>';

			case 'services_list':
				$list = $this->selected_services( $arg ? $arg : 'all' );
				if ( ! $list ) {
					return '';
				}
				$html = '<ul class="pp-services-list">';
				foreach ( $list as $svc ) {
					$html .= '<li><strong>' . esc_html( $svc['name'] ) . '</strong> (' . esc_html( $svc['provider'] ) . ')'
						. ( ! empty( $svc['purpose'] ) ? ': ' . esc_html( Goo1_PP_Library::t( $svc['purpose'], $this->lang ) ) : '' )
						. ( ! empty( $svc['privacy_url'] ) ? ' – <a href="' . esc_url( $svc['privacy_url'] ) . '" rel="noopener" target="_blank">' . esc_html( $ui['table']['more'] ?? 'Privacy policy' ) . '</a>' : '' )
						. '</li>';
				}
				return $html . '</ul>';

			case 'cookie_table':
				$rows = array(
					array(
						'name'     => 'pp_consent',
						'service'  => get_bloginfo( 'name' ),
						'purpose'  => Goo1_PP_Library::t( $ui['own_cookie_purpose'] ?? array( 'en' => 'Stores your privacy choices.' ), $this->lang ),
						'duration' => sprintf( Goo1_PP_Library::t( $ui['days'] ?? array( 'en' => '%d days' ), $this->lang ), (int) Goo1_PP_Settings::get( 'consent_days', 365 ) ),
						'category' => 'necessary',
					),
				);
				foreach ( $this->selected_services( 'all' ) as $svc ) {
					foreach ( (array) ( $svc['cookies'] ?? array() ) as $c ) {
						$rows[] = array(
							'name'     => $c['name'],
							'service'  => $svc['name'],
							'purpose'  => Goo1_PP_Library::t( $c['purpose'] ?? ( $svc['purpose'] ?? '' ), $this->lang ),
							'duration' => Goo1_PP_Library::t( $c['duration'] ?? '', $this->lang ),
							'category' => $svc['category'],
						);
					}
				}
				$h    = $ui['table'] ?? array();
				$cats = $ui['categories'] ?? array();
				$html = '<div class="pp-table-wrap"><table class="pp-table pp-cookies"><thead><tr><th>' . esc_html( $h['cookie'] ?? 'Cookie' ) . '</th><th>' . esc_html( $h['service'] ?? 'Service' ) . '</th><th>' . esc_html( $h['purpose'] ?? 'Purpose' ) . '</th><th>' . esc_html( $h['duration'] ?? 'Duration' ) . '</th><th>' . esc_html( $h['category'] ?? 'Category' ) . '</th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$cat   = isset( $cats[ $r['category'] ]['label'] ) ? $cats[ $r['category'] ]['label'] : $r['category'];
					$html .= '<tr><td><code>' . esc_html( $r['name'] ) . '</code></td><td>' . esc_html( $r['service'] ) . '</td><td>' . esc_html( $r['purpose'] ) . '</td><td>' . esc_html( $r['duration'] ) . '</td><td>' . esc_html( $cat ) . '</td></tr>';
				}
				return $html . '</tbody></table></div>';

			case 'request_form':
				return '<div class="pp-marker" id="pp-marker-request_form"></div>';

			case 'settings_link':
				return '<div class="pp-marker" id="pp-marker-settings_link"></div>';
		}
		return '';
	}
}
