<?php
/**
 * Privacy rights requests (access, deletion, correction, opt-out of
 * sale/sharing, appeal …): public form, storage, e-mail, deadlines.
 *
 * Spam protection without third parties: honeypot, a signed timestamp
 * (works on cached pages, unlike nonces) and a per-IP rate limit.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Requests {

	const TYPES    = array( 'access', 'delete', 'correct', 'portability', 'optout', 'restrict', 'object', 'withdraw', 'appeal', 'other' );
	const STATUSES = array( 'new', 'progress', 'done', 'rejected' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'goo1_pp_requests';
	}

	public static function boot() {
		add_shortcode( 'privacypilot_request_form', array( __CLASS__, 'shortcode' ) );
	}

	public static function routes() {
		register_rest_route(
			'goo1-privacypilot/v1',
			'/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function shortcode() {
		return self::form_html( Goo1_PP_Documents::current_lang() );
	}

	private static function stamp() {
		$t = time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'pp-form|' . $t, wp_salt( 'nonce' ) ), 0, 20 );
	}

	private static function stamp_valid( $stamp ) {
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{20})$/', (string) $stamp, $m ) ) {
			return false;
		}
		$age = time() - (int) $m[1];
		// Cached pages can carry an old stamp: accept up to 30 days, but not
		// faster than a human can fill the form.
		if ( $age < 3 || $age > 30 * DAY_IN_SECONDS ) {
			return false;
		}
		return hash_equals( substr( hash_hmac( 'sha256', 'pp-form|' . $m[1], wp_salt( 'nonce' ) ), 0, 20 ), $m[2] );
	}

	public static function form_html( $lang ) {
		wp_enqueue_style( 'goo1-pp-documents' );
		wp_enqueue_script( 'goo1-pp-request', GOO1_PP_URL . 'public/request.js', array(), goo1_pp_asset_ver( 'public/request.js' ), true );
		$ui = Goo1_PP_Library::ui( $lang );
		$f  = $ui['form'] ?? array();
		$id = 'pp-req-' . wp_generate_password( 6, false );

		$types = '';
		foreach ( self::TYPES as $type ) {
			$label  = $f['types'][ $type ] ?? ucfirst( $type );
			$types .= '<option value="' . esc_attr( $type ) . '">' . esc_html( $label ) . '</option>';
		}
		$regions = '';
		foreach ( array( 'us', 'eu', 'uk', 'other' ) as $r ) {
			$regions .= '<option value="' . esc_attr( $r ) . '">' . esc_html( $f['regions'][ $r ] ?? strtoupper( $r ) ) . '</option>';
		}

		$h  = '<form class="pp-request-form" data-pp-rest="' . esc_url( rest_url( 'goo1-privacypilot/v1/request' ) ) . '" novalidate>';
		$h .= '<p><label for="' . $id . '-type">' . esc_html( $f['type'] ?? 'Your request' ) . '</label><select id="' . $id . '-type" name="type" required>' . $types . '</select></p>';
		$h .= '<p><label for="' . $id . '-name">' . esc_html( $f['name'] ?? 'Name' ) . '</label><input id="' . $id . '-name" name="name" type="text" autocomplete="name" required maxlength="190"></p>';
		$h .= '<p><label for="' . $id . '-email">' . esc_html( $f['email'] ?? 'E-mail address' ) . '</label><input id="' . $id . '-email" name="email" type="email" autocomplete="email" required maxlength="190"></p>';
		$h .= '<p><label for="' . $id . '-region">' . esc_html( $f['region'] ?? 'Where do you live?' ) . '</label><select id="' . $id . '-region" name="region">' . $regions . '</select></p>';
		$h .= '<p><label for="' . $id . '-msg">' . esc_html( $f['message'] ?? 'Details (optional)' ) . '</label><textarea id="' . $id . '-msg" name="message" rows="5" maxlength="5000"></textarea></p>';
		$h .= '<p class="pp-hp" aria-hidden="true"><label>Website<input name="website" type="text" tabindex="-1" autocomplete="off"></label></p>';
		$h .= '<input type="hidden" name="stamp" value="' . esc_attr( self::stamp() ) . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		$h .= '<p class="pp-request-consent"><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html( $f['confirm'] ?? 'I confirm that this request concerns my own personal data, or that I am authorised to act for the person concerned.' ) . '</label></p>';
		$h .= '<p><button type="submit" class="pp-button">' . esc_html( $f['submit'] ?? 'Send request' ) . '</button></p>';
		$h .= '<p class="pp-request-status" role="status" aria-live="polite" data-ok="' . esc_attr( $f['ok'] ?? 'Thank you. We have received your request and will answer by e-mail.' ) . '" data-error="' . esc_attr( $f['error'] ?? 'Your request could not be sent. Please try again later or contact us by e-mail.' ) . '"></p>';
		return $h . '</form>';
	}

	public static function rest_submit( WP_REST_Request $request ) {
		$p = $request->get_json_params();
		$p = is_array( $p ) ? $p : $request->get_body_params();

		if ( ! empty( $p['website'] ) || ! self::stamp_valid( $p['stamp'] ?? '' ) ) {
			// Pretend success to bots.
			return array( 'ok' => true );
		}
		if ( Goo1_PP_Consent::rate_limited( 'request', 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'pp_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		$type   = in_array( $p['type'] ?? '', self::TYPES, true ) ? $p['type'] : 'other';
		$name   = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$email  = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$region = in_array( $p['region'] ?? '', array( 'us', 'eu', 'uk', 'other' ), true ) ? $p['region'] : 'other';
		$lang   = in_array( $p['lang'] ?? '', array( 'en', 'de' ), true ) ? $p['lang'] : 'en';
		$msg    = sanitize_textarea_field( mb_substr( (string) ( $p['message'] ?? '' ), 0, 5000 ) );
		if ( '' === $name || ! is_email( $email ) || empty( $p['confirm'] ) ) {
			return new WP_Error( 'pp_invalid', 'Please fill in all required fields.', array( 'status' => 400 ) );
		}

		// GDPR/UK GDPR: one month; US state laws: 45 days (CCPA/CPRA and most others).
		$days = 'us' === $region ? 45 : 30;
		$now  = current_time( 'mysql', true );
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'due_at'     => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
				'type'       => $type,
				'status'     => 'new',
				'name'       => $name,
				'email'      => $email,
				'region'     => $region,
				'lang'       => $lang,
				'message'    => $msg,
			)
		);
		$id = (int) $wpdb->insert_id;

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$to   = Goo1_PP_Settings::get( 'request_recipient' ) ? Goo1_PP_Settings::get( 'request_recipient' ) : get_option( 'admin_email' );
		wp_mail(
			$to,
			sprintf( '[%s] Privacy request #%d: %s', $site, $id, $type ),
			"A privacy request was submitted on {$site}.\n\n"
			. "Request: #{$id}\nType: {$type}\nName: {$name}\nE-mail: {$email}\nRegion: {$region}\nAnswer due by: " . gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) . "\n\n"
			. "Details:\n{$msg}\n\n"
			. 'Manage: ' . admin_url( 'options-general.php?page=goo1-privacypilot&tab=requests' ) . "\n\n"
			. "Please verify the identity of the requester before you disclose or delete data.",
			array( 'Reply-To: ' . $name . ' <' . $email . '>' )
		);

		if ( Goo1_PP_Settings::get( 'request_confirmation', true ) ) {
			$ui = Goo1_PP_Library::ui( $lang );
			$f  = $ui['form'] ?? array();
			wp_mail(
				$email,
				sprintf( $f['mail_subject'] ?? '%s: we received your privacy request', $site ),
				sprintf( $f['mail_body'] ?? "Hello %1\$s,\n\nwe have received your request (reference #%2\$d) and will reply within %3\$d days. If we need to confirm your identity, we will contact you at this address.\n\n%4\$s", $name, $id, $days, $site )
			);
		}

		return array( 'ok' => true, 'id' => $id );
	}

	public static function list_requests( $status = '', $limit = 100 ) {
		global $wpdb;
		$t = self::table();
		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY FIELD(status,'new','progress','done','rejected'), id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function update_request( $id, $status, $note ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		return (bool) $wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'note'       => sanitize_textarea_field( $note ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('new','progress')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function overdue_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('new','progress') AND due_at < %s", current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function purge_old() {
		global $wpdb;
		$days = max( 30, (int) Goo1_PP_Settings::get( 'request_retention_days', 1095 ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status IN ('done','rejected') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
