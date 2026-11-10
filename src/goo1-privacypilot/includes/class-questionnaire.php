<?php
/**
 * Questionnaire: question definitions come from the library, answers are
 * split into entity answers (legal entity, shared via the hub) and site
 * answers (this website only).
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Questionnaire {

	const ENTITY_OPTION = 'goo1_pp_answers_entity';
	const SITE_OPTION   = 'goo1_pp_answers_site';

	public static function questions( $scope = null ) {
		$out = array();
		foreach ( Goo1_PP_Library::questions() as $q ) {
			if ( null === $scope || $q['scope'] === $scope ) {
				$out[] = $q;
			}
		}
		return $out;
	}

	public static function question( $id ) {
		foreach ( Goo1_PP_Library::questions() as $q ) {
			if ( $q['id'] === $id ) {
				return $q;
			}
		}
		return null;
	}

	/** Entity answers, wherever they live for the current mode. */
	public static function entity_answers() {
		$mode = Goo1_PP_Settings::get( 'mode', 'standalone' );
		if ( 'client' === $mode ) {
			$cache = Goo1_PP_Hub::client_cache();
			return isset( $cache['profile']['answers'] ) && is_array( $cache['profile']['answers'] ) ? $cache['profile']['answers'] : array();
		}
		if ( 'hub' === $mode ) {
			$profile = Goo1_PP_Hub::own_profile();
			return $profile ? (array) $profile['answers'] : array();
		}
		$a = get_option( self::ENTITY_OPTION, array() );
		return is_array( $a ) ? $a : array();
	}

	public static function site_answers() {
		$a = get_option( self::SITE_OPTION, array() );
		return is_array( $a ) ? $a : array();
	}

	public static function save_entity_answers( array $answers ) {
		$mode = Goo1_PP_Settings::get( 'mode', 'standalone' );
		if ( 'client' === $mode ) {
			return false; // managed by the hub.
		}
		if ( 'hub' === $mode ) {
			return Goo1_PP_Hub::save_profile_answers( Goo1_PP_Settings::get( 'hub_profile_id', '' ), $answers );
		}
		update_option( self::ENTITY_OPTION, $answers, false );
		return true;
	}

	public static function save_site_answers( array $answers ) {
		update_option( self::SITE_OPTION, $answers, false );
		return true;
	}

	/** Flat answers (entity first, site answers override). */
	public static function answers() {
		return array_merge( self::entity_answers(), self::site_answers() );
	}

	public static function hash( array $answers ) {
		ksort( $answers );
		return hash( 'sha256', wp_json_encode( $answers ) );
	}

	public static function entity_hash() {
		return self::hash( self::entity_answers() );
	}

	public static function site_hash() {
		return self::hash( self::site_answers() );
	}

	/** Turn {"entity.name": "X"} into ['entity' => ['name' => 'X']]. */
	public static function nest( array $flat ) {
		$out = array();
		foreach ( $flat as $key => $value ) {
			$parts = explode( '.', (string) $key );
			$node  = &$out;
			foreach ( $parts as $i => $part ) {
				if ( $i === count( $parts ) - 1 ) {
					$node[ $part ] = $value;
				} else {
					if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
						$node[ $part ] = array();
					}
					$node = &$node[ $part ];
				}
			}
			unset( $node );
		}
		return $out;
	}

	/** Context for conditions: answers plus defaults of unanswered questions. */
	public static function context() {
		$flat = array();
		foreach ( Goo1_PP_Library::questions() as $q ) {
			if ( isset( $q['default'] ) ) {
				$flat[ $q['id'] ] = $q['default'];
			}
		}
		$flat = array_merge( $flat, self::answers() );
		$ctx  = self::nest( $flat );

		// Convenience: languages and settings that clauses may depend on.
		$ctx['pp'] = array(
			'banner'     => (bool) Goo1_PP_Settings::get( 'banner_enabled' ),
			// GPC and the US opt-out model only exist while the banner runs.
			'gpc'        => Goo1_PP_Settings::get( 'banner_enabled' ) && Goo1_PP_Settings::get( 'respect_gpc' ),
			'us_optout'  => Goo1_PP_Settings::get( 'banner_enabled' ) && 'optout' === Goo1_PP_Settings::get( 'rule_us', 'optout' ),
			'languages'  => Goo1_PP_Settings::languages(),
			'documents'  => Goo1_PP_Settings::documents(),
			'consent_days' => (int) Goo1_PP_Settings::get( 'consent_days', 365 ),
		);
		return $ctx;
	}

	public static function applies( array $q, array $ctx ) {
		return empty( $q['when'] ) || Goo1_PP_Conditions::evaluate( $q['when'], $ctx );
	}

	/** Required, applicable questions without an answer. */
	/**
	 * Answers that contradict each other or the services in use. The site
	 * owner decides, but a policy saying "we do not share data for ads"
	 * next to a Meta Pixel is a typical finding of regulators.
	 *
	 * @return string[] Messages in English (admin text domain).
	 */
	public static function warnings() {
		$ctx      = self::context();
		$used     = (array) ( $ctx['services']['used'] ?? array() );
		$by_group = array();
		foreach ( $used as $id ) {
			$svc = Goo1_PP_Library::service( $id );
			if ( $svc ) {
				$by_group[ $svc['group'] ?? '' ][] = $svc['name'];
			}
		}
		$out = array();
		// Newsletter tools are in the marketing category too, but are not ad sharing.
		if ( ! empty( $by_group['marketing'] ) && empty( $ctx['data']['shares_ads'] ) ) {
			$names = $by_group['marketing'];
			/* translators: %s: service names */
			$out[] = sprintf( __( 'You use advertising services (%s) but answered that you do not share personal information for targeted advertising. Under US state privacy laws tracking pixels usually count as "sharing", so the documents disclose it anyway. Please correct the answer under "Handling of data".', 'goo1-privacypilot' ), implode( ', ', array_unique( $names ) ) );
		}
		if ( ! empty( $ctx['site']['tickets'] ) && empty( $ctx['site']['ticketing_provider'] ) && empty( $by_group['registration'] ) && empty( $by_group['shop'] ) ) {
			$out[] = __( 'Visitors can register or buy tickets, but no registration or ticketing system is named. The privacy policy cannot say who processes registrations.', 'goo1-privacypilot' );
		}
		if ( ! empty( $ctx['site']['newsletter'] ) && empty( $by_group['newsletter'] ) ) {
			$out[] = __( 'You send a newsletter but no newsletter service is selected under "Services and tools".', 'goo1-privacypilot' );
		}
		if ( ( ! empty( $by_group['payments'] ) || ! empty( $by_group['shop'] ) ) && empty( $ctx['site']['tickets'] ) ) {
			$out[] = __( 'Payment or shop services are selected, but the question about registrations and ticket sales is answered with "no".', 'goo1-privacypilot' );
		}
		return $out;
	}

	public static function open_questions( $scope = null ) {
		$ctx     = self::context();
		$answers = self::answers();
		$open    = array();
		foreach ( self::questions( $scope ) as $q ) {
			if ( empty( $q['required'] ) || ! self::applies( $q, $ctx ) ) {
				continue;
			}
			$v = $answers[ $q['id'] ] ?? null;
			if ( null === $v || '' === $v || ( is_array( $v ) && ! $v && 'services' !== $q['type'] && 'multi' !== $q['type'] ) ) {
				$open[] = $q;
			}
		}
		return $open;
	}

	/** Questions added in a library version newer than the last save. */
	public static function new_questions() {
		$seen = (string) get_option( 'goo1_pp_questions_seen_version', '' );
		if ( '' === $seen ) {
			return array();
		}
		$out = array();
		foreach ( Goo1_PP_Library::questions() as $q ) {
			if ( ! empty( $q['since'] ) && version_compare( $q['since'], $seen, '>' ) ) {
				$out[] = $q;
			}
		}
		return $out;
	}

	public static function mark_seen() {
		update_option( 'goo1_pp_questions_seen_version', Goo1_PP_Library::version(), false );
	}

	/** Sanitize posted values for a scope. */
	public static function sanitize_post( $scope, array $post ) {
		$out = array();
		foreach ( self::questions( $scope ) as $q ) {
			$field = self::field_name( $q['id'] );
			$raw   = $post[ $field ] ?? null;
			switch ( $q['type'] ) {
				case 'bool':
					$out[ $q['id'] ] = ( '1' === (string) $raw || 'yes' === $raw );
					break;
				case 'multi':
				case 'services':
					$vals = is_array( $raw ) ? array_map( 'sanitize_key', $raw ) : array();
					if ( 'multi' === $q['type'] && ! empty( $q['options'] ) ) {
						$allowed = wp_list_pluck( $q['options'], 'value' );
						$vals    = array_values( array_intersect( $vals, $allowed ) );
					}
					if ( 'services' === $q['type'] ) {
						$known = wp_list_pluck( Goo1_PP_Library::services(), 'id' );
						$vals  = array_values( array_intersect( $vals, $known ) );
					}
					$out[ $q['id'] ] = $vals;
					break;
				case 'select':
					$val     = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
					$allowed = wp_list_pluck( (array) ( $q['options'] ?? array() ), 'value' );
					$out[ $q['id'] ] = in_array( $val, $allowed, true ) ? $val : '';
					break;
				case 'email':
					$out[ $q['id'] ] = is_string( $raw ) ? sanitize_email( wp_unslash( $raw ) ) : '';
					break;
				case 'url':
					$out[ $q['id'] ] = is_string( $raw ) ? esc_url_raw( wp_unslash( $raw ) ) : '';
					break;
				case 'number':
					$out[ $q['id'] ] = is_numeric( $raw ) ? 0 + $raw : '';
					break;
				case 'textarea':
					$out[ $q['id'] ] = is_string( $raw ) ? str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( wp_unslash( $raw ) ) ) : '';
					break;
				default:
					$out[ $q['id'] ] = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
			}
		}
		return $out;
	}

	public static function field_name( $id ) {
		return 'q_' . str_replace( array( '.', '-' ), array( '__', '_' ), $id );
	}

	/** Admin language for question labels. */
	public static function admin_lang() {
		return 0 === strpos( get_user_locale(), 'de' ) ? 'de' : 'en';
	}
}
