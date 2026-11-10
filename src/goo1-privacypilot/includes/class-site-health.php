<?php
/**
 * Site Health integration: tests (Tools → Site Health → Status) and a
 * "PrivacyPilot" section in the Info tab.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Site_Health {

	public static function boot() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'info' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'async_route' ) );
	}

	public static function tests( $tests ) {
		$direct = array(
			'documents'   => 'test_documents',
			'privacy_page'=> 'test_core_privacy_page',
			'approval'    => 'test_approval',
			'questions'   => 'test_questions',
			'impressum'   => 'test_impressum',
			'requests'    => 'test_requests',
			'hub'         => 'test_hub',
			'library'     => 'test_library',
			'audit'       => 'test_audit',
			'termageddon' => 'test_termageddon',
		);
		foreach ( $direct as $key => $method ) {
			$tests['direct'][ 'goo1_pp_' . $key ] = array(
				'label' => 'PrivacyPilot',
				'test'  => array( __CLASS__, $method ),
			);
		}
		$tests['async']['goo1_pp_scan'] = array(
			'label'             => 'PrivacyPilot scan',
			'test'              => rest_url( 'goo1-privacypilot/v1/health/scan' ),
			'has_rest'          => true,
			'async_direct_test' => array( __CLASS__, 'test_scan' ),
		);
		return $tests;
	}

	public static function async_route() {
		register_rest_route(
			'goo1-privacypilot/v1',
			'/health/scan',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return Goo1_PP_Site_Health::test_scan();
				},
				'permission_callback' => function () {
					return current_user_can( 'view_site_health_checks' );
				},
			)
		);
	}

	private static function result( $key, $status, $label, $description, $action = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status, // good | recommended | critical.
			'badge'       => array( 'label' => __( 'Privacy', 'goo1-privacypilot' ), 'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ) ),
			'description' => '<p>' . $description . '</p>',
			'actions'     => $action ? '<p><a href="' . esc_url( admin_url( 'options-general.php?page=goo1-privacypilot&tab=' . $action ) ) . '">' . esc_html__( 'Open PrivacyPilot', 'goo1-privacypilot' ) . '</a></p>' : '',
			'test'        => 'goo1_pp_' . $key,
		);
	}

	public static function test_documents() {
		$missing = array();
		foreach ( Goo1_PP_Publisher::targets() as $t ) {
			if ( ! Goo1_PP_Versions::published( $t[0], $t[1] ) || ! Goo1_PP_Documents::url( $t[0], $t[1] ) ) {
				$missing[] = $t[0] . ' (' . $t[1] . ')';
			}
		}
		if ( ! $missing ) {
			return self::result( 'documents', 'good', __( 'All privacy documents are published', 'goo1-privacypilot' ), esc_html__( 'Every document configured in PrivacyPilot has a published version and a public page.', 'goo1-privacypilot' ) );
		}
		$privacy_missing = (bool) array_filter( $missing, function ( $m ) { return 0 === strpos( $m, 'privacy' ); } );
		return self::result(
			'documents',
			$privacy_missing ? 'critical' : 'recommended',
			__( 'Some privacy documents are not published', 'goo1-privacypilot' ),
			esc_html( sprintf( __( 'Not published or without a page: %s.', 'goo1-privacypilot' ), implode( ', ', $missing ) ) ),
			'documents'
		);
	}

	public static function test_core_privacy_page() {
		$core = (int) get_option( 'wp_page_for_privacy_policy' );
		$ours = array();
		foreach ( Goo1_PP_Settings::languages() as $lang ) {
			$ours[] = Goo1_PP_Settings::page_id( 'privacy', $lang );
		}
		if ( $core && in_array( $core, $ours, true ) ) {
			return self::result( 'privacy_page', 'good', __( 'WordPress uses the PrivacyPilot privacy page', 'goo1-privacypilot' ), esc_html__( 'The privacy page set under Settings → Privacy is the PrivacyPilot document.', 'goo1-privacypilot' ) );
		}
		return self::result( 'privacy_page', 'recommended', __( 'WordPress privacy page is not the PrivacyPilot document', 'goo1-privacypilot' ), esc_html__( 'Settings → Privacy points to a different page. Login and registration forms link to that page.', 'goo1-privacypilot' ), 'documents' );
	}

	public static function test_approval() {
		$drafts = array_filter( Goo1_PP_Publisher::pending(), function ( $d ) { return 'draft' === $d['status']; } );
		if ( ! $drafts ) {
			return self::result( 'approval', 'good', __( 'No document versions waiting for approval', 'goo1-privacypilot' ), esc_html__( 'The published documents match the current clause library and answers.', 'goo1-privacypilot' ) );
		}
		$list = implode( ', ', array_map( function ( $d ) { return $d['doc'] . ' (' . $d['lang'] . ')' . ( $d['effective_date'] ? ' – ' . $d['effective_date'] : '' ); }, $drafts ) );
		return self::result( 'approval', 'recommended', __( 'New document versions are waiting for approval', 'goo1-privacypilot' ), esc_html( sprintf( __( 'Waiting: %s.', 'goo1-privacypilot' ), $list ) ), 'documents' );
	}

	public static function test_questions() {
		$open = Goo1_PP_Questionnaire::open_questions();
		$warn = Goo1_PP_Questionnaire::warnings();
		if ( ! $open && $warn ) {
			return self::result( 'questions', 'recommended', __( 'Some PrivacyPilot answers contradict each other', 'goo1-privacypilot' ), esc_html( implode( ' ', $warn ) ), 'questionnaire' );
		}
		if ( ! $open ) {
			return self::result( 'questions', 'good', __( 'The PrivacyPilot questionnaire is complete', 'goo1-privacypilot' ), esc_html__( 'All required questions are answered.', 'goo1-privacypilot' ) );
		}
		$lang = Goo1_PP_Questionnaire::admin_lang();
		$list = implode( ', ', array_slice( array_map( function ( $q ) use ( $lang ) { return Goo1_PP_Library::t( $q['label'], $lang ); }, $open ), 0, 8 ) );
		return self::result( 'questions', 'recommended', sprintf( _n( '%d question in the PrivacyPilot questionnaire is open', '%d questions in the PrivacyPilot questionnaire are open', count( $open ), 'goo1-privacypilot' ), count( $open ) ), esc_html( $list ), 'questionnaire' );
	}

	public static function test_impressum() {
		$ctx = Goo1_PP_Questionnaire::context();
		$de  = Goo1_PP_Conditions::evaluate( "entity.country == 'DE' or audience.de", $ctx );
		if ( ! $de ) {
			return self::result( 'impressum', 'good', __( 'No imprint required by PrivacyPilot settings', 'goo1-privacypilot' ), esc_html__( 'The site is not operated from Germany according to the questionnaire.', 'goo1-privacypilot' ) );
		}
		if ( in_array( 'impressum', Goo1_PP_Settings::documents(), true ) && Goo1_PP_Versions::published( 'impressum', 'de' ) && Goo1_PP_Documents::url( 'impressum', 'de' ) ) {
			return self::result( 'impressum', 'good', __( 'Imprint (Impressum) is published', 'goo1-privacypilot' ), esc_html__( 'German law (§ 5 DDG) requires an imprint; it is published.', 'goo1-privacypilot' ) );
		}
		return self::result( 'impressum', 'critical', __( 'Imprint (Impressum) is missing', 'goo1-privacypilot' ), esc_html__( 'The site is operated from Germany. § 5 DDG requires an easily reachable imprint. Enable the document "Impressum" for German.', 'goo1-privacypilot' ), 'documents' );
	}

	public static function test_requests() {
		$to = (string) Goo1_PP_Settings::get( 'request_recipient' );
		if ( ! is_email( $to ) ) {
			return self::result( 'requests', 'recommended', __( 'No recipient for privacy requests', 'goo1-privacypilot' ), esc_html__( 'Set an e-mail address that receives privacy requests from the form.', 'goo1-privacypilot' ), 'requests' );
		}
		$overdue = Goo1_PP_Requests::overdue_count();
		if ( $overdue ) {
			return self::result( 'requests', 'critical', __( 'Privacy requests are overdue', 'goo1-privacypilot' ), esc_html( sprintf( _n( '%d request is past its answer deadline.', '%d requests are past their answer deadline.', $overdue, 'goo1-privacypilot' ), $overdue ) ), 'requests' );
		}
		return self::result( 'requests', 'good', __( 'Privacy requests are handled', 'goo1-privacypilot' ), esc_html( sprintf( __( 'Requests go to %s. No request is overdue.', 'goo1-privacypilot' ), $to ) ) );
	}

	public static function test_hub() {
		if ( 'client' !== Goo1_PP_Settings::get( 'mode' ) ) {
			return self::result( 'hub', 'good', __( 'PrivacyPilot hub connection', 'goo1-privacypilot' ), esc_html__( 'This site is not a hub client.', 'goo1-privacypilot' ) );
		}
		$cache = Goo1_PP_Hub::client_cache();
		$age   = time() - (int) ( $cache['synced_at'] ?? 0 );
		if ( ! empty( $cache['synced_at'] ) && $age < 7 * DAY_IN_SECONDS ) {
			return self::result( 'hub', 'good', __( 'PrivacyPilot hub is in sync', 'goo1-privacypilot' ), esc_html( sprintf( __( 'Last sync with the hub: %s ago.', 'goo1-privacypilot' ), human_time_diff( time() - $age ) ) ) );
		}
		return self::result( 'hub', 'recommended', __( 'PrivacyPilot cannot reach its hub', 'goo1-privacypilot' ), esc_html( sprintf( __( 'Last successful sync: %1$s. Error: %2$s', 'goo1-privacypilot' ), empty( $cache['synced_at'] ) ? __( 'never', 'goo1-privacypilot' ) : wp_date( 'Y-m-d H:i', (int) $cache['synced_at'] ), $cache['error'] ?? '-' ) ), 'hub' );
	}

	public static function test_library() {
		$lib   = Goo1_PP_Library::get();
		$state = Goo1_PP_Library::state();
		if ( 'none' === $lib['source'] ) {
			return self::result( 'library', 'critical', __( 'PrivacyPilot clause library missing', 'goo1-privacypilot' ), esc_html__( 'Neither a bundled nor a downloaded clause library is available. Reinstall the plugin.', 'goo1-privacypilot' ), 'advanced' );
		}
		$unreviewed = 0;
		foreach ( (array) $lib['clauses'] as $c ) {
			if ( empty( $c['reviewed_at'] ) ) {
				$unreviewed++;
			}
		}
		$desc = sprintf( __( 'Library %1$s (%2$s). %3$d clauses without a legal review note.', 'goo1-privacypilot' ), $lib['version'], $lib['source'], $unreviewed );
		if ( ! empty( $state['last_error'] ) && Goo1_PP_Signature::has_keys() ) {
			return self::result( 'library', 'recommended', __( 'PrivacyPilot library feed has a problem', 'goo1-privacypilot' ), esc_html( $desc . ' ' . sprintf( __( 'Last feed error: %s', 'goo1-privacypilot' ), $state['last_error'] ) ), 'advanced' );
		}
		return self::result( 'library', 'good', __( 'PrivacyPilot clause library is available', 'goo1-privacypilot' ), esc_html( $desc ) );
	}

	public static function test_audit() {
		$audit = get_option( 'goo1_pp_audit', array() );
		if ( ! is_array( $audit ) || empty( $audit['time'] ) ) {
			return self::result( 'audit', 'recommended', __( 'No cookie audit yet', 'goo1-privacypilot' ), esc_html__( 'Run the browser audit once (PrivacyPilot → Services & scan) to check which cookies are set before consent.', 'goo1-privacypilot' ), 'services' );
		}
		if ( ! empty( $audit['problems'] ) && Goo1_PP_Settings::get( 'banner_enabled' ) ) {
			$names = implode( ', ', array_slice( wp_list_pluck( $audit['problems'], 'name' ), 0, 10 ) );
			return self::result( 'audit', 'critical', __( 'Cookies are set before consent', 'goo1-privacypilot' ), esc_html( sprintf( __( 'The last audit (%1$s) found cookies before consent: %2$s', 'goo1-privacypilot' ), wp_date( 'Y-m-d', (int) $audit['time'] ), $names ) ), 'services' );
		}
		return self::result( 'audit', 'good', __( 'No cookies before consent', 'goo1-privacypilot' ), esc_html( sprintf( __( 'Last audit: %s.', 'goo1-privacypilot' ), wp_date( 'Y-m-d', (int) $audit['time'] ) ) ) );
	}

	public static function test_termageddon() {
		$left = Goo1_PP_Migration::find();
		if ( ! $left ) {
			return self::result( 'termageddon', 'good', __( 'No Termageddon embeds left', 'goo1-privacypilot' ), esc_html__( 'No page embeds Termageddon policies.', 'goo1-privacypilot' ) );
		}
		return self::result( 'termageddon', 'recommended', __( 'Termageddon embeds are still in use', 'goo1-privacypilot' ), esc_html( sprintf( __( '%d page(s) still load policies from Termageddon. Replace them under PrivacyPilot → Documents.', 'goo1-privacypilot' ), count( $left ) ) ), 'documents' );
	}

	public static function test_scan() {
		$scan = Goo1_PP_Scanner::result();
		if ( empty( $scan['time'] ) || time() - (int) $scan['time'] > 7 * DAY_IN_SECONDS ) {
			$scan = Goo1_PP_Scanner::run();
		}
		$issues = array();
		if ( ! empty( $scan['undeclared'] ) ) {
			$issues[] = sprintf( __( 'services found but not declared in the questionnaire: %s', 'goo1-privacypilot' ), implode( ', ', $scan['undeclared'] ) );
		}
		if ( ! empty( $scan['conflicts'] ) && Goo1_PP_Settings::get( 'banner_enabled' ) && ! Goo1_PP_Settings::get( 'remove_other_cmps' ) ) {
			$issues[] = sprintf( __( 'another consent tool is active: %s', 'goo1-privacypilot' ), implode( ', ', $scan['conflicts'] ) );
		}
		if ( $issues ) {
			return self::result( 'scan', 'recommended', __( 'PrivacyPilot scan found differences', 'goo1-privacypilot' ), esc_html( ucfirst( implode( '; ', $issues ) ) . '.' ), 'services' );
		}
		return self::result( 'scan', 'good', __( 'PrivacyPilot scan matches the questionnaire', 'goo1-privacypilot' ), esc_html( sprintf( __( 'Scanned %1$d page(s) on %2$s.', 'goo1-privacypilot' ), count( (array) $scan['urls'] ), wp_date( 'Y-m-d H:i', (int) $scan['time'] ) ) ) );
	}

	public static function info( $info ) {
		$lib   = Goo1_PP_Library::get();
		$scan  = Goo1_PP_Scanner::result();
		$cache = Goo1_PP_Hub::client_cache();
		$pub   = array();
		foreach ( Goo1_PP_Publisher::targets() as $t ) {
			$p     = Goo1_PP_Versions::published( $t[0], $t[1] );
			$pub[] = $t[0] . '/' . $t[1] . ': ' . ( $p ? 'v' . $p['version_no'] . ' ' . substr( $p['published_at'], 0, 10 ) : '-' );
		}
		$info['goo1-privacypilot'] = array(
			'label'  => 'PrivacyPilot',
			'fields' => array(
				'version'    => array( 'label' => 'Plugin version', 'value' => GOO1_PP_VERSION ),
				'library'    => array( 'label' => 'Clause library', 'value' => $lib['version'] . ' (' . $lib['source'] . ')' ),
				'feed'       => array( 'label' => 'Feed signing key configured', 'value' => Goo1_PP_Signature::has_keys() ? 'yes' : 'no' ),
				'mode'       => array( 'label' => 'Mode', 'value' => Goo1_PP_Settings::get( 'mode' ) . ( 'client' === Goo1_PP_Settings::get( 'mode' ) ? ' → ' . Goo1_PP_Settings::get( 'hub_url' ) : '' ) ),
				'hub_sync'   => array( 'label' => 'Last hub sync', 'value' => empty( $cache['synced_at'] ) ? '-' : wp_date( 'Y-m-d H:i', (int) $cache['synced_at'] ) ),
				'languages'  => array( 'label' => 'Languages', 'value' => implode( ', ', Goo1_PP_Settings::languages() ) ),
				'documents'  => array( 'label' => 'Published documents', 'value' => implode( '; ', $pub ) ),
				'banner'     => array( 'label' => 'Banner / blocking', 'value' => ( Goo1_PP_Settings::get( 'banner_enabled' ) ? 'on' : 'off' ) . ' / ' . ( Goo1_PP_Settings::get( 'blocking_enabled' ) ? 'on' : 'off' ) ),
				'geo'        => array( 'label' => 'Geo mode', 'value' => Goo1_PP_Settings::get( 'geo_mode' ) . ', cloudflare: ' . Goo1_PP_Settings::get( 'cloudflare' ) ),
				'services'   => array( 'label' => 'Detected services', 'value' => empty( $scan['found'] ) ? '-' : implode( ', ', array_keys( $scan['found'] ) ) ),
				'scan'       => array( 'label' => 'Last scan', 'value' => empty( $scan['time'] ) ? '-' : wp_date( 'Y-m-d H:i', (int) $scan['time'] ) ),
			),
		);
		return $info;
	}
}
