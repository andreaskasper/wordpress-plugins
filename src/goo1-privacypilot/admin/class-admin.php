<?php
/**
 * Admin UI: Settings → PrivacyPilot.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Admin {

	const SLUG = 'goo1-privacypilot';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_goo1_pp', array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . GOO1_PP_BASENAME, array( $this, 'action_links' ) );
	}

	public function menu() {
		add_options_page( 'PrivacyPilot', 'PrivacyPilot', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'goo1-privacypilot' ) . '</a>' );
		return $links;
	}

	public function assets( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'goo1-pp-admin', GOO1_PP_URL . 'admin/admin.css', array(), goo1_pp_asset_ver( 'admin/admin.css' ) );
		wp_enqueue_script( 'goo1-pp-admin', GOO1_PP_URL . 'admin/admin.js', array(), goo1_pp_asset_ver( 'admin/admin.js' ), true );
	}

	public static function url( $tab = 'status', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'options-general.php' ) );
	}

	private static function tabs() {
		$tabs = array(
			'status'        => __( 'Status', 'goo1-privacypilot' ),
			'questionnaire' => __( 'Questionnaire', 'goo1-privacypilot' ),
			'documents'     => __( 'Documents', 'goo1-privacypilot' ),
			'banner'        => __( 'Banner', 'goo1-privacypilot' ),
			'services'      => __( 'Services & scan', 'goo1-privacypilot' ),
			'requests'      => __( 'Requests', 'goo1-privacypilot' ),
			'consent'       => __( 'Consent log', 'goo1-privacypilot' ),
			'hub'           => __( 'Hub', 'goo1-privacypilot' ),
			'advanced'      => __( 'Advanced', 'goo1-privacypilot' ),
		);
		return $tabs;
	}

	/* ================================================================ *
	 * Notices
	 * ================================================================ */

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_pp  = $screen && 'settings_page_' . self::SLUG === $screen->id;

		if ( $on_pp && isset( $_GET['pp_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg   = sanitize_text_field( wp_unslash( $_GET['pp_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$class = isset( $_GET['pp_err'] ) ? 'notice-error' : 'notice-success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( $on_pp && ( $token = get_transient( 'goo1_pp_new_token_' . get_current_user_id() ) ) ) {
			delete_transient( 'goo1_pp_new_token_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'New client token – copy it now, it is shown only once:', 'goo1-privacypilot' ) . '</strong></p><p><code class="pp-token">' . esc_html( $token ) . '</code> <button type="button" class="button pp-copy" data-copy="' . esc_attr( $token ) . '">' . esc_html__( 'Copy', 'goo1-privacypilot' ) . '</button></p></div>';
		}

		$drafts = array_filter( Goo1_PP_Publisher::pending(), function ( $d ) { return 'draft' === $d['status']; } );
		if ( $drafts && 'client' !== Goo1_PP_Settings::get( 'mode' ) && ! $on_pp ) {
			echo '<div class="notice notice-warning"><p><strong>PrivacyPilot:</strong> ' . esc_html( sprintf( _n( '%d document version is waiting for your approval.', '%d document versions are waiting for your approval.', count( $drafts ), 'goo1-privacypilot' ), count( $drafts ) ) ) . ' <a href="' . esc_url( self::url( 'documents' ) ) . '">' . esc_html__( 'Review', 'goo1-privacypilot' ) . '</a></p></div>';
		}
		$new_q = Goo1_PP_Questionnaire::new_questions();
		if ( $new_q && ! $on_pp ) {
			echo '<div class="notice notice-info"><p><strong>PrivacyPilot:</strong> ' . esc_html( sprintf( _n( '%d new question in the questionnaire.', '%d new questions in the questionnaire.', count( $new_q ), 'goo1-privacypilot' ), count( $new_q ) ) ) . ' <a href="' . esc_url( self::url( 'questionnaire' ) ) . '">' . esc_html__( 'Answer now', 'goo1-privacypilot' ) . '</a></p></div>';
		}
	}

	/* ================================================================ *
	 * Actions
	 * ================================================================ */

	private static function form_open( $do, $class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
		echo '<input type="hidden" name="action" value="goo1_pp"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'goo1_pp_' . $do );
	}

	private static function done( $tab, $msg, $error = false, $extra = array() ) {
		$args = array_merge( array( 'pp_msg' => $msg ), $extra );
		if ( $error ) {
			$args['pp_err'] = 1;
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'goo1-privacypilot' ), 403 );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		check_admin_referer( 'goo1_pp_' . $do );
		$post = wp_unslash( $_POST );

		try {
			switch ( $do ) {
				case 'save_answers':
					$scope   = 'entity' === ( $post['scope'] ?? '' ) ? 'entity' : 'site';
					$answers = Goo1_PP_Questionnaire::sanitize_post( $scope, $_POST ); // values are unslashed inside.
					if ( 'entity' === $scope ) {
						$profile = sanitize_key( $post['profile'] ?? '' );
						if ( 'hub' === Goo1_PP_Settings::get( 'mode' ) && $profile ) {
							Goo1_PP_Hub::save_profile_answers( $profile, $answers );
						} elseif ( ! Goo1_PP_Questionnaire::save_entity_answers( $answers ) ) {
							self::done( 'questionnaire', __( 'Entity answers are managed by the hub on this site.', 'goo1-privacypilot' ), true );
						}
					} else {
						Goo1_PP_Questionnaire::save_site_answers( $answers );
					}
					Goo1_PP_Questionnaire::mark_seen();
					$s = Goo1_PP_Publisher::sync();
					self::done( 'questionnaire', sprintf( __( 'Answers saved. %d document draft(s) waiting for approval.', 'goo1-privacypilot' ), count( $s['drafts'] ?? array() ) ), false, isset( $profile ) && $profile ? array( 'profile' => $profile ) : array() );
					break;

				case 'approve':
					$s = Goo1_PP_Publisher::approve_current();
					self::done( 'documents', sprintf( __( 'Approved. Published: %s.', 'goo1-privacypilot' ), $s['published'] ? implode( ', ', $s['published'] ) : __( 'nothing new', 'goo1-privacypilot' ) ) );
					break;

				case 'publish_one':
					$id = absint( $post['version_id'] ?? 0 );
					Goo1_PP_Versions::publish( $id, 'published manually by ' . wp_get_current_user()->user_login );
					self::done( 'documents', __( 'Published.', 'goo1-privacypilot' ) );
					break;

				case 'reject_one':
					Goo1_PP_Versions::reject( absint( $post['version_id'] ?? 0 ) );
					self::done( 'documents', __( 'Draft rejected. It stays unpublished until its content changes.', 'goo1-privacypilot' ) );
					break;

				case 'sync':
					$s = Goo1_PP_Publisher::sync();
					self::done( sanitize_key( $post['back'] ?? 'status' ), sprintf( __( 'Documents rebuilt: %1$d unchanged, %2$d published, %3$d waiting for approval.', 'goo1-privacypilot' ), $s['unchanged'] ?? 0, count( $s['published'] ?? array() ), count( $s['drafts'] ?? array() ) ) );
					break;

				case 'save_documents':
					$langs = array_values( array_intersect( (array) ( $post['languages'] ?? array() ), array( 'en', 'de' ) ) );
					$docs  = array_values( array_intersect( (array) ( $post['documents'] ?? array() ), wp_list_pluck( Goo1_PP_Library::documents(), 'id' ) ) );
					$pages = array();
					foreach ( (array) ( $post['pages'] ?? array() ) as $doc => $by_lang ) {
						foreach ( (array) $by_lang as $lang => $pid ) {
							if ( absint( $pid ) ) {
								$pages[ sanitize_key( $doc ) ][ sanitize_key( $lang ) ] = absint( $pid );
							}
						}
					}
					$default = in_array( $post['default_language'] ?? '', $langs, true ) ? $post['default_language'] : ( $langs[0] ?? 'en' );
					Goo1_PP_Settings::update(
						array(
							'languages'        => $langs ? $langs : array( 'en' ),
							'default_language' => $default,
							'documents'        => $docs,
							'pages'            => $pages,
							'footer_links'     => ! empty( $post['footer_links'] ),
						)
					);
					Goo1_PP_Documents::sync_core_privacy_page();
					Goo1_PP_Publisher::sync();
					self::done( 'documents', __( 'Document settings saved.', 'goo1-privacypilot' ) );
					break;

				case 'create_pages':
					$ids = Goo1_PP_Documents::create_pages();
					Goo1_PP_Publisher::sync();
					self::done( 'documents', sprintf( __( '%d page(s) created.', 'goo1-privacypilot' ), count( $ids ) ) );
					break;

				case 'migrate':
					$pid  = absint( $post['post_id'] ?? 0 );
					$doc  = sanitize_key( $post['doc'] ?? 'privacy' );
					$lang = in_array( $post['lang'] ?? '', array( 'auto', 'en', 'de' ), true ) ? $post['lang'] : 'auto';
					$ok   = Goo1_PP_Migration::migrate( $pid, $doc, $lang );
					Goo1_PP_Publisher::sync();
					self::done( 'documents', $ok ? __( 'Termageddon embed replaced. The old content is kept as a revision.', 'goo1-privacypilot' ) : __( 'Nothing was replaced.', 'goo1-privacypilot' ), ! $ok );
					break;

				case 'save_banner':
					$hex = function ( $v, $d ) {
						$c = sanitize_hex_color( (string) $v );
						return $c ? $c : $d;
					};
					$rule = function ( $v ) {
						return in_array( $v, array( 'optin', 'optout', 'notice' ), true ) ? $v : 'optin';
					};
					Goo1_PP_Settings::update(
						array(
							'banner_enabled'    => ! empty( $post['banner_enabled'] ),
							'blocking_enabled'  => ! empty( $post['blocking_enabled'] ),
							'remove_other_cmps' => ! empty( $post['remove_other_cmps'] ),
							'exempt_admins'     => ! empty( $post['exempt_admins'] ),
							'geo_mode'          => in_array( $post['geo_mode'] ?? '', array( 'auto', 'strict' ), true ) ? $post['geo_mode'] : 'auto',
							'cloudflare'        => in_array( $post['cloudflare'] ?? '', array( 'auto', 'yes', 'no' ), true ) ? $post['cloudflare'] : 'auto',
							'rule_eu'           => $rule( $post['rule_eu'] ?? 'optin' ),
							'rule_us'           => $rule( $post['rule_us'] ?? 'optout' ),
							'rule_other'        => $rule( $post['rule_other'] ?? 'optin' ),
							'respect_gpc'       => ! empty( $post['respect_gpc'] ),
							'consent_mode'      => ! empty( $post['consent_mode'] ),
							'consent_days'      => max( 1, min( 395, absint( $post['consent_days'] ?? 365 ) ) ),
							'banner_position'   => in_array( $post['banner_position'] ?? '', array( 'bottom', 'center' ), true ) ? $post['banner_position'] : 'bottom',
							'color_primary'     => $hex( $post['color_primary'] ?? '', '#1f4fd1' ),
							'color_background'  => $hex( $post['color_background'] ?? '', '#ffffff' ),
							'color_text'        => $hex( $post['color_text'] ?? '', '#16181d' ),
						)
					);
					Goo1_PP_Publisher::sync();
					self::done( 'banner', __( 'Banner settings saved.', 'goo1-privacypilot' ) );
					break;

				case 'scan':
					$r = Goo1_PP_Scanner::run();
					self::done( 'services', sprintf( __( 'Scan finished: %1$d page(s), %2$d service(s) found.', 'goo1-privacypilot' ), count( $r['urls'] ), count( $r['found'] ) ) );
					break;

				case 'adopt_scan':
					$scan = Goo1_PP_Scanner::result();
					$site = Goo1_PP_Questionnaire::site_answers();
					$used = (array) ( Goo1_PP_Questionnaire::answers()['services.used'] ?? array() );
					$add  = array_values( array_diff( (array) ( $scan['undeclared'] ?? array() ), $used ) );
					$q    = Goo1_PP_Questionnaire::question( 'services.used' );
					if ( $q && 'site' === $q['scope'] ) {
						$site['services.used'] = array_values( array_unique( array_merge( $used, $add ) ) );
						Goo1_PP_Questionnaire::save_site_answers( $site );
					}
					Goo1_PP_Scanner::run();
					Goo1_PP_Publisher::sync();
					self::done( 'services', sprintf( __( '%d service(s) added to the questionnaire.', 'goo1-privacypilot' ), count( $add ) ) );
					break;

				case 'save_requests':
					Goo1_PP_Settings::update(
						array(
							'request_recipient'      => sanitize_email( $post['request_recipient'] ?? '' ),
							'request_confirmation'   => ! empty( $post['request_confirmation'] ),
							'request_retention_days' => max( 30, absint( $post['request_retention_days'] ?? 1095 ) ),
						)
					);
					self::done( 'requests', __( 'Saved.', 'goo1-privacypilot' ) );
					break;

				case 'update_request':
					Goo1_PP_Requests::update_request( absint( $post['request_id'] ?? 0 ), sanitize_key( $post['status'] ?? 'new' ), (string) ( $post['note'] ?? '' ) );
					self::done( 'requests', __( 'Request updated.', 'goo1-privacypilot' ) );
					break;

				case 'save_consent':
					Goo1_PP_Settings::update( array( 'log_retention_days' => max( 30, absint( $post['log_retention_days'] ?? 1095 ) ) ) );
					self::done( 'consent', __( 'Saved.', 'goo1-privacypilot' ) );
					break;

				case 'save_mode':
					$mode = in_array( $post['mode'] ?? '', array( 'standalone', 'hub', 'client' ), true ) ? $post['mode'] : 'standalone';
					$upd  = array( 'mode' => $mode );
					if ( 'client' === $mode ) {
						$upd['hub_url'] = esc_url_raw( trim( (string) ( $post['hub_url'] ?? '' ) ) );
						if ( ! empty( $post['hub_token'] ) ) {
							Goo1_PP_Hub::set_token( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $post['hub_token'] ) );
						}
					}
					if ( 'hub' === $mode && empty( Goo1_PP_Hub::profiles() ) ) {
						// Start the first profile with this site's current entity answers.
						$id = Goo1_PP_Hub::create_profile( get_bloginfo( 'name' ) );
						Goo1_PP_Hub::save_profile_answers( $id, (array) get_option( Goo1_PP_Questionnaire::ENTITY_OPTION, array() ) );
						$upd['hub_profile_id'] = $id;
					}
					Goo1_PP_Settings::update( $upd );
					if ( 'client' === $mode ) {
						$ok = Goo1_PP_Hub::sync();
						Goo1_PP_Publisher::sync();
						$c  = Goo1_PP_Hub::client_cache();
						self::done( 'hub', $ok ? __( 'Connected to the hub.', 'goo1-privacypilot' ) : sprintf( __( 'Saved, but the hub could not be reached: %s', 'goo1-privacypilot' ), $c['error'] ?? '' ), ! $ok );
					}
					Goo1_PP_Publisher::sync();
					self::done( 'hub', __( 'Mode saved.', 'goo1-privacypilot' ) );
					break;

				case 'hub_sync':
					$ok = Goo1_PP_Hub::sync();
					$s  = Goo1_PP_Publisher::sync();
					$c  = Goo1_PP_Hub::client_cache();
					self::done( 'hub', $ok ? sprintf( __( 'Synced. Published: %s', 'goo1-privacypilot' ), $s['published'] ? implode( ', ', $s['published'] ) : '-' ) : ( $c['error'] ?? 'error' ), ! $ok );
					break;

				case 'hub_profile_create':
					$id = Goo1_PP_Hub::create_profile( (string) ( $post['name'] ?? 'Profile' ) );
					self::done( 'hub', __( 'Profile created.', 'goo1-privacypilot' ), false, array( 'profile' => $id ) );
					break;

				case 'hub_profile_delete':
					$id = sanitize_key( $post['profile'] ?? '' );
					if ( $id === Goo1_PP_Settings::get( 'hub_profile_id' ) ) {
						self::done( 'hub', __( 'The profile used by this site cannot be deleted.', 'goo1-privacypilot' ), true );
					}
					Goo1_PP_Hub::delete_profile( $id );
					self::done( 'hub', __( 'Profile deleted.', 'goo1-privacypilot' ) );
					break;

				case 'hub_own_profile':
					Goo1_PP_Settings::update( array( 'hub_profile_id' => sanitize_key( $post['profile'] ?? '' ) ) );
					Goo1_PP_Publisher::sync();
					self::done( 'hub', __( 'Saved.', 'goo1-privacypilot' ) );
					break;

				case 'hub_approve':
					$id = sanitize_key( $post['profile'] ?? '' );
					Goo1_PP_Hub::approve_profile( $id );
					Goo1_PP_Publisher::sync();
					self::done( 'hub', __( 'Profile approved. Connected sites publish the new version at their next sync.', 'goo1-privacypilot' ) );
					break;

				case 'hub_client_create':
					$token = Goo1_PP_Hub::create_client( (string) ( $post['label'] ?? '' ), sanitize_key( $post['profile'] ?? '' ) );
					set_transient( 'goo1_pp_new_token_' . get_current_user_id(), $token, 300 );
					self::done( 'hub', __( 'Client created.', 'goo1-privacypilot' ) );
					break;

				case 'hub_client_revoke':
					Goo1_PP_Hub::revoke_client( sanitize_text_field( $post['client'] ?? '' ) );
					self::done( 'hub', __( 'Client revoked.', 'goo1-privacypilot' ) );
					break;

				case 'save_advanced':
					Goo1_PP_Settings::update(
						array(
							'feed_enabled'             => ! empty( $post['feed_enabled'] ),
							'feed_url'                 => esc_url_raw( (string) ( $post['feed_url'] ?? Goo1_PP_Settings::FEED_URL ) ),
							'auto_publish_deadline'    => ! empty( $post['auto_publish_deadline'] ),
							'delete_data_on_uninstall' => ! empty( $post['delete_data_on_uninstall'] ),
						)
					);
					Goo1_PP_Library::reset();
					self::done( 'advanced', __( 'Saved.', 'goo1-privacypilot' ) );
					break;

				case 'refresh_library':
					$ok    = Goo1_PP_Library::refresh();
					$state = Goo1_PP_Library::state();
					if ( $ok ) {
						Goo1_PP_Publisher::sync();
					}
					self::done( 'advanced', $ok ? sprintf( __( 'Library updated to %s.', 'goo1-privacypilot' ), Goo1_PP_Library::version() ) : ( $state['last_error'] ? $state['last_error'] : ( $state['last_result'] ?? '' ) ), ! $ok && ! empty( $state['last_error'] ) );
					break;

				case 'import':
					$json = json_decode( (string) ( $post['import_json'] ?? '' ), true );
					if ( ! is_array( $json ) ) {
						self::done( 'advanced', __( 'The import is not valid JSON.', 'goo1-privacypilot' ), true );
					}
					if ( isset( $json['entity'] ) && is_array( $json['entity'] ) ) {
						Goo1_PP_Questionnaire::save_entity_answers( self::clean_import( $json['entity'], 'entity' ) );
					}
					if ( isset( $json['site'] ) && is_array( $json['site'] ) ) {
						Goo1_PP_Questionnaire::save_site_answers( self::clean_import( $json['site'], 'site' ) );
					}
					Goo1_PP_Publisher::sync();
					self::done( 'advanced', __( 'Answers imported.', 'goo1-privacypilot' ) );
					break;
			}
		} catch ( \Throwable $e ) {
			self::done( 'status', 'Error: ' . $e->getMessage(), true );
		}
		self::done( 'status', __( 'Unknown action.', 'goo1-privacypilot' ), true );
	}

	/** Keep only known question ids, sanitize values. */
	private static function clean_import( array $answers, $scope ) {
		$fake = array();
		foreach ( Goo1_PP_Questionnaire::questions( $scope ) as $q ) {
			if ( array_key_exists( $q['id'], $answers ) ) {
				$v = $answers[ $q['id'] ];
				if ( 'bool' === $q['type'] ) {
					$v = $v ? '1' : '0';
				}
				$fake[ Goo1_PP_Questionnaire::field_name( $q['id'] ) ] = is_array( $v ) ? $v : wp_slash( (string) $v );
			}
		}
		if ( 'site' === $scope && isset( $answers['services.necessary'] ) && is_array( $answers['services.necessary'] ) ) {
			$fake[ Goo1_PP_Questionnaire::field_name( 'services.necessary' ) ] = $answers['services.necessary'];
		}
		$clean = Goo1_PP_Questionnaire::sanitize_post( $scope, $fake );
		return array_intersect_key( $clean, $answers );
	}

	/* ================================================================ *
	 * Render
	 * ================================================================ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'status';
		}
		echo '<div class="wrap pp-admin"><h1>PrivacyPilot <span class="pp-version">' . esc_html( GOO1_PP_VERSION ) . '</span></h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a href="' . esc_url( self::url( $key ) ) . '" class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="pp-tab">';
		call_user_func( array( $this, 'tab_' . $tab ) );
		echo '</div>';
		$this->footer();
		echo '</div>';
	}

	private function disclaimer() {
		echo '<div class="pp-disclaimer"><p>' . esc_html__( 'The clauses are checked and updated regularly, but PrivacyPilot is not legal advice. No liability is accepted for completeness or correctness. The site owner is responsible for the answers in the questionnaire and for the documents published on the site.', 'goo1-privacypilot' ) . '</p></div>';
	}

	private function footer() {
		echo '<div class="pp-footer">';
		$this->disclaimer();
		echo '<p class="pp-support">' . esc_html__( 'Do you like PrivacyPilot? It is free. Donations help to keep the clause library up to date and to pay for a legal review.', 'goo1-privacypilot' )
			. ' <a class="button" href="https://www.patreon.com/AndreasKasper" target="_blank" rel="noopener">' . esc_html__( 'Support on Patreon', 'goo1-privacypilot' ) . '</a></p>';
		echo '</div>';
	}

	private static function badge( $text, $kind = 'neutral' ) {
		return '<span class="pp-badge pp-badge-' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	/* ---------------------------------------------------------------- */

	private static function source_label( $source ) {
		$map = array(
			'bundled' => __( 'bundled', 'goo1-privacypilot' ),
			'feed'    => __( 'feed', 'goo1-privacypilot' ),
		);
		return $map[ $source ] ?? $source;
	}

	private static function mode_label( $mode ) {
		$map = array(
			'standalone' => __( 'standalone', 'goo1-privacypilot' ),
			'hub'        => __( 'hub', 'goo1-privacypilot' ),
			'client'     => __( 'client', 'goo1-privacypilot' ),
		);
		return $map[ $mode ] ?? $mode;
	}

	private function warnings() {
		foreach ( Goo1_PP_Questionnaire::warnings() as $w ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
		}
	}

	private function tab_status() {
		$lib    = Goo1_PP_Library::get();
		$open   = Goo1_PP_Questionnaire::open_questions();
		$drafts = array_filter( Goo1_PP_Publisher::pending(), function ( $d ) { return 'draft' === $d['status']; } );
		$scan   = Goo1_PP_Scanner::result();
		$mode   = Goo1_PP_Settings::get( 'mode' );

		echo '<div class="pp-cards">';
		echo '<div class="pp-card"><h3>' . esc_html__( 'Questionnaire', 'goo1-privacypilot' ) . '</h3><p>' . ( $open ? self::badge( sprintf( __( '%d open', 'goo1-privacypilot' ), count( $open ) ), 'warn' ) : self::badge( __( 'complete', 'goo1-privacypilot' ), 'ok' ) ) . '</p><p><a href="' . esc_url( self::url( 'questionnaire' ) ) . '">' . esc_html__( 'Open questionnaire', 'goo1-privacypilot' ) . '</a></p></div>';
		echo '<div class="pp-card"><h3>' . esc_html__( 'Approval', 'goo1-privacypilot' ) . '</h3><p>' . ( $drafts ? self::badge( sprintf( __( '%d waiting', 'goo1-privacypilot' ), count( $drafts ) ), 'warn' ) : self::badge( __( 'up to date', 'goo1-privacypilot' ), 'ok' ) ) . '</p><p><a href="' . esc_url( self::url( 'documents' ) ) . '">' . esc_html__( 'Documents', 'goo1-privacypilot' ) . '</a></p></div>';
		echo '<div class="pp-card"><h3>' . esc_html__( 'Banner', 'goo1-privacypilot' ) . '</h3><p>' . ( Goo1_PP_Settings::get( 'banner_enabled' ) ? self::badge( __( 'active', 'goo1-privacypilot' ), 'ok' ) : self::badge( __( 'off (shadow mode)', 'goo1-privacypilot' ), 'neutral' ) ) . ' ' . ( Goo1_PP_Settings::get( 'blocking_enabled' ) ? self::badge( __( 'blocking on', 'goo1-privacypilot' ), 'ok' ) : '' ) . '</p><p><a href="' . esc_url( self::url( 'banner' ) ) . '">' . esc_html__( 'Banner settings', 'goo1-privacypilot' ) . '</a></p></div>';
		echo '<div class="pp-card"><h3>' . esc_html__( 'Library', 'goo1-privacypilot' ) . '</h3><p><code>' . esc_html( $lib['version'] ) . '</code> ' . self::badge( self::source_label( $lib['source'] ), 'bundled' === $lib['source'] || 'feed' === $lib['source'] ? 'ok' : 'bad' ) . '</p><p>' . esc_html__( 'Mode:', 'goo1-privacypilot' ) . ' <strong>' . esc_html( self::mode_label( $mode ) ) . '</strong></p></div>';
		echo '<div class="pp-card"><h3>' . esc_html__( 'Requests', 'goo1-privacypilot' ) . '</h3><p>' . esc_html( sprintf( __( '%d open', 'goo1-privacypilot' ), Goo1_PP_Requests::open_count() ) ) . ( Goo1_PP_Requests::overdue_count() ? ' ' . self::badge( __( 'overdue', 'goo1-privacypilot' ), 'bad' ) : '' ) . '</p><p><a href="' . esc_url( self::url( 'requests' ) ) . '">' . esc_html__( 'Requests', 'goo1-privacypilot' ) . '</a></p></div>';
		echo '<div class="pp-card"><h3>' . esc_html__( 'Scan', 'goo1-privacypilot' ) . '</h3><p>' . ( empty( $scan['time'] ) ? esc_html__( 'not run yet', 'goo1-privacypilot' ) : esc_html( sprintf( __( '%1$d services, %2$s', 'goo1-privacypilot' ), count( (array) $scan['found'] ), wp_date( 'Y-m-d H:i', (int) $scan['time'] ) ) ) ) . ( ! empty( $scan['undeclared'] ) ? ' ' . self::badge( __( 'undeclared services', 'goo1-privacypilot' ), 'warn' ) : '' ) . '</p><p><a href="' . esc_url( self::url( 'services' ) ) . '">' . esc_html__( 'Services & scan', 'goo1-privacypilot' ) . '</a></p></div>';
		echo '</div>';

		$this->warnings();

		echo '<h2>' . esc_html__( 'Documents', 'goo1-privacypilot' ) . '</h2>';
		$this->documents_table( false );

		echo '<p>';
		self::form_open( 'sync', 'pp-inline' );
		echo '<input type="hidden" name="back" value="status"><button class="button">' . esc_html__( 'Rebuild documents now', 'goo1-privacypilot' ) . '</button></form> ';
		self::form_open( 'scan', 'pp-inline' );
		echo '<button class="button">' . esc_html__( 'Run scan', 'goo1-privacypilot' ) . '</button></form>';
		echo '</p>';

		$last = get_option( 'goo1_pp_last_sync', array() );
		if ( ! empty( $last['time'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Last rebuild: %s', 'goo1-privacypilot' ), wp_date( 'Y-m-d H:i', (int) $last['time'] ) ) ) . '</p>';
		}
	}

	private function documents_table( $with_actions ) {
		echo '<table class="widefat striped pp-docs"><thead><tr><th>' . esc_html__( 'Document', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Language', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Published', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Draft', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Page', 'goo1-privacypilot' ) . '</th>' . ( $with_actions ? '<th></th>' : '' ) . '</tr></thead><tbody>';
		$targets = Goo1_PP_Publisher::targets();
		if ( ! $targets ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No documents enabled.', 'goo1-privacypilot' ) . '</td></tr>';
		}
		$lang_ui = Goo1_PP_Questionnaire::admin_lang();
		foreach ( $targets as $t ) {
			list( $doc, $lang ) = $t;
			$def   = Goo1_PP_Library::document( $doc );
			$pub   = Goo1_PP_Versions::published( $doc, $lang );
			$draft = Goo1_PP_Versions::draft( $doc, $lang );
			$url   = Goo1_PP_Documents::url( $doc, $lang );
			echo '<tr><td><strong>' . esc_html( Goo1_PP_Library::t( $def['title'], $lang_ui ) ) . '</strong></td><td>' . esc_html( strtoupper( $lang ) ) . '</td>';
			echo '<td>' . ( $pub ? esc_html( 'v' . $pub['version_no'] . ' – ' . substr( (string) $pub['published_at'], 0, 10 ) ) : self::badge( __( 'none', 'goo1-privacypilot' ), 'bad' ) ) . '</td>';
			echo '<td>';
			if ( $draft ) {
				$m       = json_decode( (string) $draft['manifest'], true );
				$missing = (array) ( $m['missing'] ?? array() );
				echo self::badge( 'rejected' === $draft['status'] ? __( 'rejected', 'goo1-privacypilot' ) : __( 'waiting', 'goo1-privacypilot' ), 'rejected' === $draft['status'] ? 'neutral' : 'warn' ) . ' ' . esc_html( $draft['change_type'] );
				if ( $draft['effective_date'] ) {
					echo ' · ' . esc_html( sprintf( __( 'effective %s', 'goo1-privacypilot' ), $draft['effective_date'] ) );
				}
				if ( $missing ) {
					echo '<br><small class="pp-missing">' . esc_html( sprintf( __( 'Empty placeholders: %s', 'goo1-privacypilot' ), implode( ', ', $missing ) ) ) . '</small>';
				}
			} else {
				echo '–';
			}
			echo '</td><td>' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'view', 'goo1-privacypilot' ) . '</a>' : self::badge( __( 'no page', 'goo1-privacypilot' ), 'warn' ) ) . '</td>';
			if ( $with_actions ) {
				echo '<td>';
				if ( $draft ) {
					echo '<a href="#pp-preview-' . esc_attr( $doc . '-' . $lang ) . '" class="button button-small pp-toggle">' . esc_html__( 'Preview', 'goo1-privacypilot' ) . '</a> ';
					self::form_open( 'publish_one', 'pp-inline pp-confirm' );
					echo '<input type="hidden" name="version_id" value="' . (int) $draft['id'] . '"><button class="button button-small" data-confirm="' . esc_attr__( 'Publish this version without the regular approval?', 'goo1-privacypilot' ) . '">' . esc_html__( 'Publish now', 'goo1-privacypilot' ) . '</button></form> ';
					if ( 'draft' === $draft['status'] ) {
						self::form_open( 'reject_one', 'pp-inline' );
						echo '<input type="hidden" name="version_id" value="' . (int) $draft['id'] . '"><button class="button button-small button-link-delete">' . esc_html__( 'Reject', 'goo1-privacypilot' ) . '</button></form>';
					}
				}
				echo '</td>';
			}
			echo '</tr>';
			if ( $with_actions && $draft ) {
				echo '<tr class="pp-preview-row" id="pp-preview-' . esc_attr( $doc . '-' . $lang ) . '" hidden><td colspan="6">';
				echo '<div class="pp-preview-grid"><div><h4>' . esc_html__( 'New version', 'goo1-privacypilot' ) . '</h4>' . $this->clause_diff( $pub, $draft ) . '<div class="pp-preview">' . wp_kses( $draft['html'], Goo1_PP_Generator::allowed_html() ) . '</div></div>';
				echo '<div><h4>' . esc_html__( 'Published version', 'goo1-privacypilot' ) . '</h4><div class="pp-preview">' . ( $pub ? wp_kses( $pub['html'], Goo1_PP_Generator::allowed_html() ) : '<p>–</p>' ) . '</div></div></div>';
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	private function clause_diff( $pub, $draft ) {
		$old = $pub ? (array) ( json_decode( (string) $pub['manifest'], true )['clauses'] ?? array() ) : array();
		$new = (array) ( json_decode( (string) $draft['manifest'], true )['clauses'] ?? array() );
		$add = array_keys( array_diff_key( $new, $old ) );
		$del = array_keys( array_diff_key( $old, $new ) );
		$chg = array();
		foreach ( $new as $id => $sig ) {
			if ( isset( $old[ $id ] ) && $old[ $id ] !== $sig ) {
				$chg[] = $id;
			}
		}
		$out = '<ul class="pp-diff">';
		foreach ( $add as $id ) {
			$out .= '<li class="pp-add">+ ' . esc_html( $id ) . '</li>';
		}
		foreach ( $del as $id ) {
			$out .= '<li class="pp-del">− ' . esc_html( $id ) . '</li>';
		}
		foreach ( $chg as $id ) {
			$out .= '<li class="pp-chg">~ ' . esc_html( $id ) . '</li>';
		}
		if ( ! $add && ! $del && ! $chg ) {
			$out .= '<li>' . esc_html__( 'Same clauses; answers or placeholders changed.', 'goo1-privacypilot' ) . '</li>';
		}
		return $out . '</ul>';
	}

	/* ---------------------------------------------------------------- */

	private function tab_questionnaire() {
		$mode     = Goo1_PP_Settings::get( 'mode' );
		$lang     = Goo1_PP_Questionnaire::admin_lang();
		$profiles = Goo1_PP_Hub::profiles();
		$profile  = isset( $_GET['profile'] ) ? sanitize_key( $_GET['profile'] ) : (string) Goo1_PP_Settings::get( 'hub_profile_id', '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->disclaimer();
		$this->warnings();

		$new = wp_list_pluck( Goo1_PP_Questionnaire::new_questions(), 'id' );

		// Entity part.
		echo '<h2>' . esc_html__( 'Legal entity', 'goo1-privacypilot' ) . '</h2>';
		if ( 'client' === $mode ) {
			$cache = Goo1_PP_Hub::client_cache();
			echo '<p>' . esc_html( sprintf( __( 'These answers are managed on the hub %1$s (profile "%2$s").', 'goo1-privacypilot' ), Goo1_PP_Settings::get( 'hub_url' ), $cache['profile']['name'] ?? '?' ) ) . '</p>';
			$this->answers_readonly( 'entity', Goo1_PP_Questionnaire::entity_answers(), $lang );
		} else {
			$answers = Goo1_PP_Questionnaire::entity_answers();
			if ( 'hub' === $mode ) {
				echo '<form method="get" class="pp-profile-pick"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="questionnaire"><label>' . esc_html__( 'Profile:', 'goo1-privacypilot' ) . ' <select name="profile" onchange="this.form.submit()">';
				foreach ( $profiles as $p ) {
					echo '<option value="' . esc_attr( $p['id'] ) . '"' . selected( $p['id'], $profile, false ) . '>' . esc_html( $p['name'] ) . '</option>';
				}
				echo '</select></label></form>';
				$answers = isset( $profiles[ $profile ] ) ? (array) $profiles[ $profile ]['answers'] : array();
			}
			self::form_open( 'save_answers', 'pp-questions' );
			echo '<input type="hidden" name="scope" value="entity"><input type="hidden" name="profile" value="' . esc_attr( 'hub' === $mode ? $profile : '' ) . '">';
			$this->fields( 'entity', array_merge( $answers, Goo1_PP_Questionnaire::site_answers() ), $answers, $lang, $new );
			submit_button( __( 'Save entity answers', 'goo1-privacypilot' ) );
			echo '</form>';
		}

		// Site part.
		echo '<h2>' . esc_html__( 'This website', 'goo1-privacypilot' ) . '</h2>';
		self::form_open( 'save_answers', 'pp-questions' );
		echo '<input type="hidden" name="scope" value="site">';
		$this->fields( 'site', Goo1_PP_Questionnaire::answers(), Goo1_PP_Questionnaire::site_answers(), $lang, $new );
		submit_button( __( 'Save website answers', 'goo1-privacypilot' ) );
		echo '</form>';
	}

	private function answers_readonly( $scope, array $answers, $lang ) {
		echo '<table class="widefat striped"><tbody>';
		foreach ( Goo1_PP_Questionnaire::questions( $scope ) as $q ) {
			$v = $answers[ $q['id'] ] ?? '';
			if ( is_array( $v ) ) {
				$v = implode( ', ', $v );
			} elseif ( is_bool( $v ) ) {
				$v = $v ? '✓' : '–';
			}
			echo '<tr><th>' . esc_html( Goo1_PP_Library::t( $q['label'], $lang ) ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array $ctx_answers answers used to evaluate conditions
	 * @param array $values      values of this scope
	 */
	private function fields( $scope, array $ctx_answers, array $values, $lang, array $new ) {
		$defaults = array();
		foreach ( Goo1_PP_Library::questions() as $q ) {
			if ( isset( $q['default'] ) ) {
				$defaults[ $q['id'] ] = $q['default'];
			}
		}
		$ctx      = Goo1_PP_Questionnaire::nest( array_merge( $defaults, $ctx_answers ) );
		$sections = Goo1_PP_Library::ui( $lang )['sections'] ?? array();
		$scan     = Goo1_PP_Scanner::result();
		$current  = null;

		foreach ( Goo1_PP_Questionnaire::questions( $scope ) as $q ) {
			$name    = Goo1_PP_Questionnaire::field_name( $q['id'] );
			$value   = array_key_exists( $q['id'], $values ) ? $values[ $q['id'] ] : ( $q['default'] ?? null );
			$applies = Goo1_PP_Questionnaire::applies( $q, $ctx );

			if ( ! $applies ) {
				// Keep the stored value without showing the question.
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $v ) . '">';
					}
				} elseif ( 'bool' === $q['type'] ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . ( $value ? '1' : '0' ) . '">';
				} elseif ( null !== $value ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
				}
				continue;
			}

			if ( $q['section'] !== $current ) {
				if ( null !== $current ) {
					echo '</fieldset>';
				}
				$current = $q['section'];
				echo '<fieldset class="pp-section"><legend>' . esc_html( $sections[ $current ] ?? $current ) . '</legend>';
			}

			$label = Goo1_PP_Library::t( $q['label'], $lang );
			$help  = isset( $q['help'] ) ? Goo1_PP_Library::t( $q['help'], $lang ) : '';
			echo '<div class="pp-q pp-q-' . esc_attr( $q['type'] ) . '">';
			echo '<label class="pp-q-label" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . ( ! empty( $q['required'] ) ? ' <span class="pp-req">*</span>' : '' ) . ( in_array( $q['id'], $new, true ) ? ' ' . self::badge( __( 'new', 'goo1-privacypilot' ), 'warn' ) : '' ) . '</label>';
			echo '<div class="pp-q-input">';
			switch ( $q['type'] ) {
				case 'bool':
					echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html( Goo1_PP_Library::t( array( 'en' => 'Yes', 'de' => 'Ja' ), $lang ) ) . '</label> &nbsp; ';
					echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="0"' . checked( (bool) $value, false, false ) . '> ' . esc_html( Goo1_PP_Library::t( array( 'en' => 'No', 'de' => 'Nein' ), $lang ) ) . '</label>';
					break;
				case 'select':
					echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
					foreach ( (array) $q['options'] as $o ) {
						echo '<option value="' . esc_attr( $o['value'] ) . '"' . selected( (string) $value, (string) $o['value'], false ) . '>' . esc_html( Goo1_PP_Library::t( $o['label'], $lang ) ) . '</option>';
					}
					echo '</select>';
					break;
				case 'multi':
					foreach ( (array) $q['options'] as $o ) {
						echo '<label class="pp-check"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $o['value'] ) . '"' . checked( in_array( $o['value'], (array) $value, true ), true, false ) . '> ' . esc_html( Goo1_PP_Library::t( $o['label'], $lang ) ) . '</label>';
					}
					break;
				case 'services':
					$groups = array();
					foreach ( Goo1_PP_Library::services() as $svc ) {
						if ( 'cmp' === $svc['category'] ) {
							continue;
						}
						$groups[ $svc['group'] ?? 'other' ][] = $svc;
					}
					$glabels = Goo1_PP_Library::ui( $lang )['groups'] ?? array();
					echo '<div class="pp-services-grid">';
					foreach ( $groups as $g => $list ) {
						echo '<div class="pp-service-group"><strong>' . esc_html( $glabels[ $g ] ?? $g ) . '</strong>';
						foreach ( $list as $svc ) {
							$det = isset( $scan['found'][ $svc['id'] ] );
							echo '<label class="pp-check"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $svc['id'] ) . '"' . checked( in_array( $svc['id'], (array) $value, true ), true, false ) . '> ' . esc_html( $svc['name'] ) . ( $det ? ' ' . self::badge( __( 'detected', 'goo1-privacypilot' ), 'warn' ) : '' ) . '</label>';
						}
						echo '</div>';
					}
					echo '</div>';
					if ( 'services.used' === $q['id'] && 'site' === $q['scope'] ) {
						$this->render_necessary_override( (array) $value, $lang );
					}
					break;
				case 'textarea':
					echo '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				default:
					$type = in_array( $q['type'], array( 'email', 'url', 'number', 'date' ), true ) ? $q['type'] : 'text';
					echo '<input id="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" class="regular-text"' . ( isset( $q['placeholder'] ) ? ' placeholder="' . esc_attr( Goo1_PP_Library::t( $q['placeholder'], $lang ) ) . '"' : '' ) . '>';
			}
			if ( $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</div></div>';
		}
		if ( null !== $current ) {
			echo '</fieldset>';
		}
		echo '<p class="description">' . esc_html__( 'Questions that do not apply to your answers are hidden. Save to update the list.', 'goo1-privacypilot' ) . '</p>';
	}

	/**
	 * Checkboxes below the services question: selected services the site
	 * owner treats as strictly necessary on this site (no consent needed).
	 */
	private function render_necessary_override( array $used, $lang ) {
		$current = (array) ( Goo1_PP_Questionnaire::site_answers()['services.necessary'] ?? array() );
		$rows    = array();
		foreach ( Goo1_PP_Library::services() as $svc ) {
			$default = $svc['category_default'] ?? $svc['category'];
			if ( ! in_array( $svc['id'], $used, true ) || in_array( $default, array( 'necessary', 'cmp' ), true ) ) {
				continue;
			}
			$rows[] = '<label class="pp-check"><input type="checkbox" name="' . esc_attr( Goo1_PP_Questionnaire::field_name( 'services.necessary' ) ) . '[]" value="' . esc_attr( $svc['id'] ) . '"' . checked( in_array( $svc['id'], $current, true ), true, false ) . '> ' . esc_html( $svc['name'] ) . ' <small>(' . esc_html( $default ) . ')</small></label>';
		}
		if ( ! $rows ) {
			return;
		}
		echo '<details class="pp-necessary-override"' . ( $current ? ' open' : '' ) . '><summary>' . esc_html__( 'Strictly necessary on this site', 'goo1-privacypilot' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Selected services load without consent and are listed as necessary in the documents, e.g. reCAPTCHA when the forms cannot work without spam protection. Only choose this if the site really cannot work without the service; consent banners in the EU are checked for exactly this.', 'goo1-privacypilot' ) . '</p>';
		echo implode( '', $rows ) . '</details>';
	}

	/* ---------------------------------------------------------------- */

	private function tab_documents() {
		$mode   = Goo1_PP_Settings::get( 'mode' );
		$drafts = array_filter( Goo1_PP_Publisher::pending(), function ( $d ) { return 'draft' === $d['status']; } );

		if ( $drafts ) {
			echo '<div class="pp-approve-box">';
			if ( 'client' === $mode ) {
				$cache = Goo1_PP_Hub::client_cache();
				echo '<p>' . esc_html__( 'The legal entity part is approved on the hub. Approve here only the website-specific answers; the documents are published as soon as the hub approval matches.', 'goo1-privacypilot' ) . '</p>';
				echo '<p>' . esc_html( sprintf( __( 'Hub approval: %s', 'goo1-privacypilot' ), ! empty( $cache['approval']['approved_at'] ) ? wp_date( 'Y-m-d H:i', (int) $cache['approval']['approved_at'] ) . ' (library ' . $cache['approval']['library_version'] . ')' : __( 'none', 'goo1-privacypilot' ) ) ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Review the new versions below (Preview). Approving publishes every waiting document at once.', 'goo1-privacypilot' ) . '</p>';
			}
			$this->disclaimer();
			self::form_open( 'approve' );
			echo '<button class="button button-primary">' . esc_html( 'client' === $mode ? __( 'Approve website answers', 'goo1-privacypilot' ) : __( 'Approve and publish all', 'goo1-privacypilot' ) ) . '</button></form>';
			echo '</div>';
		}

		$this->documents_table( true );

		// Settings: languages, documents, pages.
		echo '<h2>' . esc_html__( 'Languages, documents and pages', 'goo1-privacypilot' ) . '</h2>';
		self::form_open( 'save_documents' );
		$langs   = Goo1_PP_Settings::languages();
		$docs    = Goo1_PP_Settings::documents();
		$lang_ui = Goo1_PP_Questionnaire::admin_lang();
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Languages', 'goo1-privacypilot' ) . '</th><td>';
		foreach ( array( 'en' => 'English', 'de' => 'Deutsch' ) as $code => $label ) {
			echo '<label class="pp-check"><input type="checkbox" name="languages[]" value="' . esc_attr( $code ) . '"' . checked( in_array( $code, $langs, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo ' &nbsp; ' . esc_html__( 'Default:', 'goo1-privacypilot' ) . ' <select name="default_language">';
		foreach ( array( 'en', 'de' ) as $code ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( Goo1_PP_Settings::get( 'default_language' ), $code, false ) . '>' . esc_html( strtoupper( $code ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Documents', 'goo1-privacypilot' ) . '</th><td>';
		foreach ( Goo1_PP_Library::documents() as $d ) {
			echo '<label class="pp-check"><input type="checkbox" name="documents[]" value="' . esc_attr( $d['id'] ) . '"' . checked( in_array( $d['id'], $docs, true ), true, false ) . '> ' . esc_html( Goo1_PP_Library::t( $d['title'], $lang_ui ) ) . ( ! empty( $d['languages'] ) ? ' <small>(' . esc_html( strtoupper( implode( ', ', $d['languages'] ) ) ) . ')</small>' : '' ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Pages', 'goo1-privacypilot' ) . '</th><td><table class="pp-pages">';
		foreach ( Goo1_PP_Publisher::targets() as $t ) {
			list( $doc, $lang ) = $t;
			$def = Goo1_PP_Library::document( $doc );
			echo '<tr><td>' . esc_html( Goo1_PP_Library::t( $def['title'], $lang_ui ) . ' (' . strtoupper( $lang ) . ')' ) . '</td><td>';
			wp_dropdown_pages(
				array(
					'name'              => 'pages[' . $doc . '][' . $lang . ']',
					'selected'          => Goo1_PP_Settings::page_id( $doc, $lang ),
					'show_option_none'  => '—',
					'option_none_value' => 0,
					'post_status'       => array( 'publish', 'draft', 'private' ),
				)
			);
			echo '</td></tr>';
		}
		echo '</table></td></tr>';
		echo '<tr><th>' . esc_html__( 'Footer links', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="footer_links" value="1"' . checked( Goo1_PP_Settings::get( 'footer_links' ), true, false ) . '> ' . esc_html__( 'Add links to all documents (and privacy settings) at the end of every page', 'goo1-privacypilot' ) . '</label><p class="description">' . esc_html__( 'Alternatively place the shortcode [privacypilot_links] in your footer or menu. A menu item with the CSS class "pp-open-settings" opens the privacy settings.', 'goo1-privacypilot' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save', 'goo1-privacypilot' ) );
		echo '</form>';

		self::form_open( 'create_pages' );
		echo '<p><button class="button">' . esc_html__( 'Create missing pages', 'goo1-privacypilot' ) . '</button> <span class="description">' . esc_html__( 'Creates a page with the document shortcode for every document/language without a page.', 'goo1-privacypilot' ) . '</span></p></form>';

		// Migration.
		$found = Goo1_PP_Migration::find();
		echo '<h2>' . esc_html__( 'Termageddon embeds', 'goo1-privacypilot' ) . '</h2>';
		if ( ! $found ) {
			echo '<p>' . esc_html__( 'No page embeds Termageddon policies.', 'goo1-privacypilot' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'These pages still load their text from Termageddon. Replacing keeps the page and its URL; WordPress stores the old content as a revision.', 'goo1-privacypilot' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Replace with', 'goo1-privacypilot' ) . '</th></tr></thead><tbody>';
			foreach ( $found as $f ) {
				echo '<tr><td><a href="' . esc_url( $f['url'] ) . '" target="_blank">' . esc_html( $f['title'] ) . '</a> <small>#' . (int) $f['id'] . ' ' . esc_html( $f['status'] ) . ( $f['elementor'] ? ' · Elementor' : '' ) . '</small></td><td>';
				self::form_open( 'migrate', 'pp-inline pp-confirm' );
				echo '<input type="hidden" name="post_id" value="' . (int) $f['id'] . '"><select name="doc">';
				foreach ( Goo1_PP_Library::documents() as $d ) {
					echo '<option value="' . esc_attr( $d['id'] ) . '"' . selected( $f['suggest'], $d['id'], false ) . '>' . esc_html( Goo1_PP_Library::t( $d['title'], $lang_ui ) ) . '</option>';
				}
				echo '</select> <select name="lang"><option value="auto">' . esc_html__( 'auto language', 'goo1-privacypilot' ) . '</option><option value="en">EN</option><option value="de">DE</option></select> ';
				echo '<button class="button" data-confirm="' . esc_attr__( 'Replace the Termageddon embed on this page?', 'goo1-privacypilot' ) . '">' . esc_html__( 'Replace', 'goo1-privacypilot' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* ---------------------------------------------------------------- */

	private function tab_banner() {
		$s = Goo1_PP_Settings::all();
		self::form_open( 'save_banner' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Consent banner', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="banner_enabled" value="1"' . checked( $s['banner_enabled'], true, false ) . '> ' . esc_html__( 'Show the consent banner', 'goo1-privacypilot' ) . '</label><p class="description">' . esc_html__( 'Leave this off while you prepare the site (shadow mode). Documents work without the banner.', 'goo1-privacypilot' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Blocking', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="blocking_enabled" value="1"' . checked( $s['blocking_enabled'], true, false ) . '> ' . esc_html__( 'Block known scripts and embeds until consent', 'goo1-privacypilot' ) . '</label><br><label><input type="checkbox" name="remove_other_cmps" value="1"' . checked( $s['remove_other_cmps'], true, false ) . '> ' . esc_html__( 'Remove other consent tools (e.g. Usercentrics from Termageddon)', 'goo1-privacypilot' ) . '</label><br><label><input type="checkbox" name="exempt_admins" value="1"' . checked( $s['exempt_admins'], true, false ) . '> ' . esc_html__( 'No banner and no blocking for logged-in administrators', 'goo1-privacypilot' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Rules by region', 'goo1-privacypilot' ) . '</th><td>';
		echo '<p><label>' . esc_html__( 'Mode', 'goo1-privacypilot' ) . ' <select name="geo_mode"><option value="auto"' . selected( $s['geo_mode'], 'auto', false ) . '>' . esc_html__( 'by country when it can be determined (Cloudflare), otherwise opt-in', 'goo1-privacypilot' ) . '</option><option value="strict"' . selected( $s['geo_mode'], 'strict', false ) . '>' . esc_html__( 'opt-in everywhere', 'goo1-privacypilot' ) . '</option></select></label></p>';
		echo '<p><label>Cloudflare <select name="cloudflare"><option value="auto"' . selected( $s['cloudflare'], 'auto', false ) . '>' . esc_html__( 'detect automatically', 'goo1-privacypilot' ) . '</option><option value="yes"' . selected( $s['cloudflare'], 'yes', false ) . '>' . esc_html__( 'site is behind Cloudflare', 'goo1-privacypilot' ) . '</option><option value="no"' . selected( $s['cloudflare'], 'no', false ) . '>' . esc_html__( 'not behind Cloudflare', 'goo1-privacypilot' ) . '</option></select></label></p>';
		$rules = array( 'optin' => __( 'Opt-in (nothing before consent)', 'goo1-privacypilot' ), 'optout' => __( 'Opt-out (notice, objection possible)', 'goo1-privacypilot' ) );
		foreach ( array( 'rule_eu' => __( 'EU / EEA / UK / Switzerland', 'goo1-privacypilot' ), 'rule_us' => __( 'USA', 'goo1-privacypilot' ), 'rule_other' => __( 'Other countries and unknown', 'goo1-privacypilot' ) ) as $key => $label ) {
			echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( $key ) . '">';
			foreach ( $rules as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $s[ $key ], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></label></p>';
		}
		echo '<p class="description">' . esc_html__( 'If the country cannot be determined, the rule for "other countries and unknown" applies. Keep it on opt-in.', 'goo1-privacypilot' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Signals', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="respect_gpc" value="1"' . checked( $s['respect_gpc'], true, false ) . '> ' . esc_html__( 'Respect Global Privacy Control (GPC) as opt-out of sale/sharing', 'goo1-privacypilot' ) . '</label><br><label><input type="checkbox" name="consent_mode" value="1"' . checked( $s['consent_mode'], true, false ) . '> ' . esc_html__( 'Google Consent Mode v2 (defaults: denied)', 'goo1-privacypilot' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Validity', 'goo1-privacypilot' ) . '</th><td><input type="number" name="consent_days" min="1" max="395" value="' . (int) $s['consent_days'] . '"> ' . esc_html__( 'days, then the banner asks again', 'goo1-privacypilot' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Look', 'goo1-privacypilot' ) . '</th><td><label>' . esc_html__( 'Position', 'goo1-privacypilot' ) . ' <select name="banner_position"><option value="bottom"' . selected( $s['banner_position'], 'bottom', false ) . '>' . esc_html__( 'bar at the bottom', 'goo1-privacypilot' ) . '</option><option value="center"' . selected( $s['banner_position'], 'center', false ) . '>' . esc_html__( 'dialog in the centre', 'goo1-privacypilot' ) . '</option></select></label><br>';
		foreach ( array( 'color_primary' => __( 'Accent', 'goo1-privacypilot' ), 'color_background' => __( 'Background', 'goo1-privacypilot' ), 'color_text' => __( 'Text', 'goo1-privacypilot' ) ) as $key => $label ) {
			echo '<label class="pp-color">' . esc_html( $label ) . ' <input type="color" name="' . esc_attr( $key ) . '" value="' . esc_attr( $s[ $key ] ) . '"></label> ';
		}
		echo '</td></tr></table>';
		submit_button( __( 'Save', 'goo1-privacypilot' ) );
		echo '</form>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( 'pp-audit', '1', home_url( '/' ) ) ) . '" target="_blank">' . esc_html__( 'Preview banner and run cookie audit', 'goo1-privacypilot' ) . '</a></p>';
	}

	/* ---------------------------------------------------------------- */

	private function tab_services() {
		$scan  = Goo1_PP_Scanner::result();
		$audit = get_option( 'goo1_pp_audit', array() );
		echo '<p>';
		self::form_open( 'scan', 'pp-inline' );
		echo '<button class="button button-primary">' . esc_html__( 'Run scan now', 'goo1-privacypilot' ) . '</button></form> ';
		echo '<a class="button" href="' . esc_url( add_query_arg( 'pp-audit', '1', home_url( '/' ) ) ) . '" target="_blank">' . esc_html__( 'Cookie audit in your browser', 'goo1-privacypilot' ) . '</a></p>';

		if ( ! empty( $scan['time'] ) ) {
			echo '<h2>' . esc_html( sprintf( __( 'Scan from %s', 'goo1-privacypilot' ), wp_date( 'Y-m-d H:i', (int) $scan['time'] ) ) ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Category', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Found via', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Declared', 'goo1-privacypilot' ) . '</th></tr></thead><tbody>';
			if ( empty( $scan['found'] ) ) {
				echo '<tr><td colspan="4">' . esc_html__( 'No known third-party services found.', 'goo1-privacypilot' ) . '</td></tr>';
			}
			foreach ( (array) $scan['found'] as $id => $f ) {
				$svc  = Goo1_PP_Library::service( $id );
				$decl = ! in_array( $id, (array) $scan['undeclared'], true );
				echo '<tr><td>' . esc_html( $svc ? $svc['name'] : $id ) . '</td><td>' . esc_html( $svc ? $svc['category'] : '' ) . '</td><td>' . esc_html( $f['via'] ?? '' ) . ( ! empty( $f['urls'] ) ? '<br><small>' . esc_html( implode( ', ', array_slice( $f['urls'], 0, 3 ) ) ) . '</small>' : '' ) . '</td><td>' . ( 'cmp' === ( $svc['category'] ?? '' ) ? self::badge( __( 'other consent tool', 'goo1-privacypilot' ), 'warn' ) : ( $decl ? self::badge( '✓', 'ok' ) : self::badge( __( 'not declared', 'goo1-privacypilot' ), 'warn' ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( ! empty( $scan['undeclared'] ) ) {
				self::form_open( 'adopt_scan' );
				echo '<p><button class="button">' . esc_html__( 'Add undeclared services to the questionnaire', 'goo1-privacypilot' ) . '</button></p></form>';
			}
			if ( ! empty( $scan['errors'] ) ) {
				echo '<p class="pp-missing">' . esc_html( implode( ' | ', $scan['errors'] ) ) . '</p>';
			}
			if ( ! empty( $scan['termageddon'] ) ) {
				echo '<p>' . self::badge( 'Termageddon', 'warn' ) . ' ' . esc_html( implode( ', ', $scan['termageddon'] ) ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Cookie audit', 'goo1-privacypilot' ) . '</h2>';
		if ( empty( $audit['time'] ) ) {
			echo '<p>' . esc_html__( 'Not run yet. Open the audit link above in a browser where you are logged in; the result appears here.', 'goo1-privacypilot' ) . '</p>';
		} else {
			echo '<p>' . esc_html( sprintf( __( '%1$s on %2$s', 'goo1-privacypilot' ), wp_date( 'Y-m-d H:i', (int) $audit['time'] ), $audit['url'] ) ) . '</p><table class="widefat striped"><thead><tr><th>Cookie</th><th>' . esc_html__( 'Category', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Service', 'goo1-privacypilot' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $audit['cookies'] as $c ) {
				echo '<tr><td><code>' . esc_html( $c['name'] ) . '</code></td><td>' . ( 'necessary' === $c['category'] ? esc_html( $c['category'] ) : self::badge( $c['category'], 'warn' ) ) . '</td><td>' . esc_html( $c['service'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( ! empty( $audit['storage'] ) ) {
				echo '<p>localStorage: <code>' . esc_html( implode( ', ', (array) $audit['storage'] ) ) . '</code></p>';
			}
		}

		echo '<h2>' . esc_html__( 'Service catalogue', 'goo1-privacypilot' ) . '</h2><p class="description">' . esc_html__( 'Maintained centrally with the clause library. Scanner and blocker use the same list.', 'goo1-privacypilot' ) . '</p><details><summary>' . esc_html( sprintf( __( '%d services', 'goo1-privacypilot' ), count( Goo1_PP_Library::services() ) ) ) . '</summary><ul class="pp-catalogue">';
		foreach ( Goo1_PP_Library::services() as $svc ) {
			echo '<li><strong>' . esc_html( $svc['name'] ) . '</strong> – ' . esc_html( $svc['provider'] . ' · ' . $svc['category'] ) . '</li>';
		}
		echo '</ul></details>';
	}

	/* ---------------------------------------------------------------- */

	private function tab_requests() {
		$s = Goo1_PP_Settings::all();
		self::form_open( 'save_requests' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Recipient', 'goo1-privacypilot' ) . '</th><td><input type="email" class="regular-text" name="request_recipient" value="' . esc_attr( $s['request_recipient'] ) . '"><p class="description">' . esc_html__( 'Receives every request from the form [privacypilot_request_form].', 'goo1-privacypilot' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Confirmation', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="request_confirmation" value="1"' . checked( $s['request_confirmation'], true, false ) . '> ' . esc_html__( 'Send a receipt to the requester', 'goo1-privacypilot' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Retention', 'goo1-privacypilot' ) . '</th><td><input type="number" min="30" name="request_retention_days" value="' . (int) $s['request_retention_days'] . '"> ' . esc_html__( 'days after closing', 'goo1-privacypilot' ) . '</td></tr></table>';
		submit_button( __( 'Save', 'goo1-privacypilot' ) );
		echo '</form>';

		$rows = Goo1_PP_Requests::list_requests();
		echo '<h2>' . esc_html__( 'Requests', 'goo1-privacypilot' ) . '</h2><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Received', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Type', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Requester', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Due', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Status', 'goo1-privacypilot' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No requests yet.', 'goo1-privacypilot' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$overdue = in_array( $r['status'], array( 'new', 'progress' ), true ) && $r['due_at'] < current_time( 'mysql', true );
			echo '<tr><td>' . (int) $r['id'] . '</td><td>' . esc_html( substr( $r['created_at'], 0, 16 ) ) . '</td><td>' . esc_html( $r['type'] ) . '<br><small>' . esc_html( strtoupper( $r['region'] ) ) . '</small></td><td>' . esc_html( $r['name'] ) . '<br><a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>' . ( $r['message'] ? '<br><small>' . esc_html( wp_trim_words( $r['message'], 30 ) ) . '</small>' : '' ) . '</td><td>' . ( $overdue ? self::badge( substr( $r['due_at'], 0, 10 ), 'bad' ) : esc_html( substr( (string) $r['due_at'], 0, 10 ) ) ) . '</td><td>';
			self::form_open( 'update_request', 'pp-request-update' );
			echo '<input type="hidden" name="request_id" value="' . (int) $r['id'] . '"><select name="status">';
			foreach ( Goo1_PP_Requests::STATUSES as $st ) {
				echo '<option value="' . esc_attr( $st ) . '"' . selected( $r['status'], $st, false ) . '>' . esc_html( $st ) . '</option>';
			}
			echo '</select><textarea name="note" rows="2" placeholder="' . esc_attr__( 'Internal note / reason', 'goo1-privacypilot' ) . '">' . esc_textarea( (string) $r['note'] ) . '</textarea><button class="button button-small">' . esc_html__( 'Update', 'goo1-privacypilot' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'GDPR/UK GDPR: answer within one month. US state laws: usually 45 days. If you reject a request, give reasons and explain how the person can appeal.', 'goo1-privacypilot' ) . '</p>';
	}

	/* ---------------------------------------------------------------- */

	private function tab_consent() {
		global $wpdb;
		$t     = $wpdb->prefix . 'goo1_pp_consent_log';
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$stats = $wpdb->get_results( $wpdb->prepare( "SELECT action, rule, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY action, rule ORDER BY n DESC", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<h2>' . esc_html__( 'Last 30 days', 'goo1-privacypilot' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Rule', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Count', 'goo1-privacypilot' ) . '</th></tr></thead><tbody>';
		if ( ! $stats ) {
			echo '<tr><td colspan="3">–</td></tr>';
		}
		foreach ( $stats as $s ) {
			echo '<tr><td>' . esc_html( $s['action'] ) . '</td><td>' . esc_html( $s['rule'] ) . '</td><td>' . (int) $s['n'] . '</td></tr>';
		}
		echo '</tbody></table>';

		$q = isset( $_GET['consent_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['consent_id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h2>' . esc_html__( 'Look up a consent', 'goo1-privacypilot' ) . '</h2><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="consent"><input type="text" name="consent_id" class="regular-text" placeholder="xxxxxxxx-xxxx-4xxx-xxxx-xxxxxxxxxxxx" value="' . esc_attr( $q ) . '"> <button class="button">' . esc_html__( 'Search', 'goo1-privacypilot' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'The consent id is shown to visitors in the privacy settings and stored in their pp_consent cookie. No IP addresses are stored.', 'goo1-privacypilot' ) . '</p>';
		if ( $q ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE consent_id = %s ORDER BY id DESC LIMIT 50", $q ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Action', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Categories', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Rule', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Country', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Versions', 'goo1-privacypilot' ) . '</th></tr></thead><tbody>';
			if ( ! $rows ) {
				echo '<tr><td colspan="6">' . esc_html__( 'Not found.', 'goo1-privacypilot' ) . '</td></tr>';
			}
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( $r['action'] ) . '</td><td>' . esc_html( $r['categories'] ) . ( $r['services'] ? '<br><small>' . esc_html( $r['services'] ) . '</small>' : '' ) . '</td><td>' . esc_html( $r['rule'] ) . '</td><td>' . esc_html( $r['country'] ) . '</td><td><small>' . esc_html( $r['banner_version'] . ' / ' . $r['library_version'] ) . '</small></td></tr>';
			}
			echo '</tbody></table>';
		}

		self::form_open( 'save_consent' );
		echo '<h2>' . esc_html__( 'Retention', 'goo1-privacypilot' ) . '</h2><p><input type="number" min="30" name="log_retention_days" value="' . (int) Goo1_PP_Settings::get( 'log_retention_days' ) . '"> ' . esc_html__( 'days', 'goo1-privacypilot' ) . ' <button class="button">' . esc_html__( 'Save', 'goo1-privacypilot' ) . '</button></p></form>';
	}

	/* ---------------------------------------------------------------- */

	private function tab_hub() {
		$mode = Goo1_PP_Settings::get( 'mode' );
		self::form_open( 'save_mode' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Mode', 'goo1-privacypilot' ) . '</th><td>';
		foreach ( array(
			'standalone' => __( 'Standalone – this site keeps its own answers', 'goo1-privacypilot' ),
			'hub'        => __( 'Hub – this site manages answers for several sites', 'goo1-privacypilot' ),
			'client'     => __( 'Client – legal entity answers come from a hub', 'goo1-privacypilot' ),
		) as $v => $l ) {
			echo '<label class="pp-radio"><input type="radio" name="mode" value="' . esc_attr( $v ) . '"' . checked( $mode, $v, false ) . '> ' . esc_html( $l ) . '</label><br>';
		}
		echo '</td></tr>';
		echo '<tr class="pp-client-only"><th>' . esc_html__( 'Hub URL', 'goo1-privacypilot' ) . '</th><td><input type="url" class="regular-text" name="hub_url" value="' . esc_attr( Goo1_PP_Settings::get( 'hub_url' ) ) . '" placeholder="https://johnlindo.com"></td></tr>';
		echo '<tr class="pp-client-only"><th>' . esc_html__( 'Client token', 'goo1-privacypilot' ) . '</th><td><input type="password" class="regular-text" name="hub_token" autocomplete="off" placeholder="' . esc_attr( Goo1_PP_Hub::has_token() ? __( '(stored – leave empty to keep)', 'goo1-privacypilot' ) : 'pph_…' ) . '"></td></tr>';
		echo '</table>';
		submit_button( __( 'Save mode', 'goo1-privacypilot' ) );
		echo '</form>';

		if ( 'client' === $mode ) {
			$c = Goo1_PP_Hub::client_cache();
			echo '<h2>' . esc_html__( 'Connection', 'goo1-privacypilot' ) . '</h2><table class="widefat striped"><tbody>';
			echo '<tr><th>' . esc_html__( 'Profile', 'goo1-privacypilot' ) . '</th><td>' . esc_html( $c['profile']['name'] ?? '–' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last sync', 'goo1-privacypilot' ) . '</th><td>' . esc_html( empty( $c['synced_at'] ) ? '–' : wp_date( 'Y-m-d H:i', (int) $c['synced_at'] ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Hub approval', 'goo1-privacypilot' ) . '</th><td>' . esc_html( empty( $c['approval']['approved_at'] ) ? '–' : wp_date( 'Y-m-d H:i', (int) $c['approval']['approved_at'] ) . ' · library ' . $c['approval']['library_version'] ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Hub library / this site', 'goo1-privacypilot' ) . '</th><td>' . esc_html( ( $c['library_version'] ?? '–' ) . ' / ' . Goo1_PP_Library::version() ) . '</td></tr>';
			if ( ! empty( $c['error'] ) ) {
				echo '<tr><th>' . esc_html__( 'Error', 'goo1-privacypilot' ) . '</th><td class="pp-missing">' . esc_html( $c['error'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			self::form_open( 'hub_sync' );
			echo '<p><button class="button">' . esc_html__( 'Sync now', 'goo1-privacypilot' ) . '</button></p></form>';
		}

		if ( 'hub' === $mode ) {
			$profiles = Goo1_PP_Hub::profiles();
			$clients  = Goo1_PP_Hub::clients();
			$lib      = Goo1_PP_Library::version();

			echo '<h2>' . esc_html__( 'Profiles (legal entities)', 'goo1-privacypilot' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Profile', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Approval', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Clients', 'goo1-privacypilot' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $profiles as $p ) {
				$hash  = Goo1_PP_Questionnaire::hash( (array) $p['answers'] );
				$appr  = $p['approval'];
				$fresh = $appr && $appr['library_version'] === $lib && $appr['entity_hash'] === $hash;
				$n     = count( array_filter( $clients, function ( $c ) use ( $p ) { return $c['profile'] === $p['id']; } ) );
				echo '<tr><td><strong>' . esc_html( $p['name'] ) . '</strong> <code>' . esc_html( $p['id'] ) . '</code>' . ( $p['id'] === Goo1_PP_Settings::get( 'hub_profile_id' ) ? ' ' . self::badge( __( 'this site', 'goo1-privacypilot' ), 'neutral' ) : '' ) . '<br><a href="' . esc_url( self::url( 'questionnaire', array( 'profile' => $p['id'] ) ) ) . '">' . esc_html__( 'Edit answers', 'goo1-privacypilot' ) . '</a></td>';
				echo '<td>' . ( $fresh ? self::badge( __( 'approved', 'goo1-privacypilot' ), 'ok' ) : self::badge( __( 'needs approval', 'goo1-privacypilot' ), 'warn' ) ) . ( $appr ? '<br><small>' . esc_html( wp_date( 'Y-m-d H:i', (int) $appr['approved_at'] ) . ' · ' . $appr['library_version'] ) . '</small>' : '' ) . '</td><td>' . (int) $n . '</td><td>';
				if ( ! $fresh ) {
					self::form_open( 'hub_approve', 'pp-inline pp-confirm' );
					echo '<input type="hidden" name="profile" value="' . esc_attr( $p['id'] ) . '"><button class="button button-primary button-small" data-confirm="' . esc_attr__( 'Approve the current answers of this profile for all connected sites?', 'goo1-privacypilot' ) . '">' . esc_html__( 'Approve', 'goo1-privacypilot' ) . '</button></form> ';
				}
				if ( $p['id'] !== Goo1_PP_Settings::get( 'hub_profile_id' ) ) {
					self::form_open( 'hub_own_profile', 'pp-inline' );
					echo '<input type="hidden" name="profile" value="' . esc_attr( $p['id'] ) . '"><button class="button button-small">' . esc_html__( 'Use for this site', 'goo1-privacypilot' ) . '</button></form> ';
					self::form_open( 'hub_profile_delete', 'pp-inline pp-confirm' );
					echo '<input type="hidden" name="profile" value="' . esc_attr( $p['id'] ) . '"><button class="button button-small button-link-delete" data-confirm="' . esc_attr__( 'Delete this profile?', 'goo1-privacypilot' ) . '">' . esc_html__( 'Delete', 'goo1-privacypilot' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			self::form_open( 'hub_profile_create' );
			echo '<p><input type="text" name="name" placeholder="' . esc_attr__( 'Legal entity name, e.g. JL Productions Inc.', 'goo1-privacypilot' ) . '" class="regular-text" required> <button class="button">' . esc_html__( 'Add profile', 'goo1-privacypilot' ) . '</button></p></form>';
			echo '<p class="description">' . esc_html__( 'The approval covers the legal entity answers at the current library version. After approving, connected sites publish at their next sync (daily, or "Sync now" on the client).', 'goo1-privacypilot' ) . '</p>';

			echo '<h2>' . esc_html__( 'Connected sites', 'goo1-privacypilot' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Profile', 'goo1-privacypilot' ) . '</th><th>' . esc_html__( 'Last seen', 'goo1-privacypilot' ) . '</th><th></th></tr></thead><tbody>';
			if ( ! $clients ) {
				echo '<tr><td colspan="4">–</td></tr>';
			}
			foreach ( $clients as $c ) {
				echo '<tr><td>' . esc_html( $c['label'] ) . ( $c['site_url'] ? '<br><small>' . esc_html( $c['site_url'] ) . ' ' . esc_html( $c['version'] ) . '</small>' : '' ) . '</td><td>' . esc_html( $profiles[ $c['profile'] ]['name'] ?? $c['profile'] ) . '</td><td>' . esc_html( $c['last_seen'] ? human_time_diff( (int) $c['last_seen'] ) . ' ' . __( 'ago', 'goo1-privacypilot' ) : '–' ) . '</td><td>';
				self::form_open( 'hub_client_revoke', 'pp-inline pp-confirm' );
				echo '<input type="hidden" name="client" value="' . esc_attr( $c['id'] ) . '"><button class="button button-small button-link-delete" data-confirm="' . esc_attr__( 'Revoke this token? The site stops receiving updates.', 'goo1-privacypilot' ) . '">' . esc_html__( 'Revoke', 'goo1-privacypilot' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
			if ( $profiles ) {
				self::form_open( 'hub_client_create' );
				echo '<p><input type="text" name="label" placeholder="' . esc_attr__( 'Site, e.g. libertyswing.com', 'goo1-privacypilot' ) . '" class="regular-text" required> <select name="profile">';
				foreach ( $profiles as $p ) {
					echo '<option value="' . esc_attr( $p['id'] ) . '">' . esc_html( $p['name'] ) . '</option>';
				}
				echo '</select> <button class="button">' . esc_html__( 'Create client token', 'goo1-privacypilot' ) . '</button></p></form>';
			}
		}
	}

	/* ---------------------------------------------------------------- */

	private function tab_advanced() {
		$s     = Goo1_PP_Settings::all();
		$lib   = Goo1_PP_Library::get();
		$state = Goo1_PP_Library::state();
		$bund  = Goo1_PP_Library::bundled();

		echo '<h2>' . esc_html__( 'Clause library', 'goo1-privacypilot' ) . '</h2><table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'In use', 'goo1-privacypilot' ) . '</th><td><code>' . esc_html( $lib['version'] ) . '</code> (' . esc_html( $lib['source'] ) . ')</td></tr>';
		echo '<tr><th>' . esc_html__( 'Bundled with the plugin', 'goo1-privacypilot' ) . '</th><td><code>' . esc_html( $bund['version'] ?? '–' ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Signing key', 'goo1-privacypilot' ) . '</th><td>' . ( Goo1_PP_Signature::has_keys() ? self::badge( __( 'configured', 'goo1-privacypilot' ), 'ok' ) : self::badge( __( 'not configured – feed updates disabled', 'goo1-privacypilot' ), 'warn' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Last feed check', 'goo1-privacypilot' ) . '</th><td>' . esc_html( empty( $state['last_check'] ) ? '–' : wp_date( 'Y-m-d H:i', (int) $state['last_check'] ) . ' · ' . ( $state['last_error'] ? $state['last_error'] : ( $state['last_result'] ?? '' ) ) ) . '</td></tr>';
		echo '</tbody></table>';
		self::form_open( 'refresh_library' );
		echo '<p><button class="button">' . esc_html__( 'Check feed now', 'goo1-privacypilot' ) . '</button></p></form>';

		self::form_open( 'save_advanced' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Feed', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="feed_enabled" value="1"' . checked( $s['feed_enabled'], true, false ) . '> ' . esc_html__( 'Fetch library updates daily (signed)', 'goo1-privacypilot' ) . '</label><br><input type="url" class="large-text" name="feed_url" value="' . esc_attr( $s['feed_url'] ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Deadlines', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="auto_publish_deadline" value="1"' . checked( $s['auto_publish_deadline'], true, false ) . '> ' . esc_html__( 'Publish a waiting version automatically on the effective date of a legal change, unless it was rejected', 'goo1-privacypilot' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Uninstall', 'goo1-privacypilot' ) . '</th><td><label><input type="checkbox" name="delete_data_on_uninstall" value="1"' . checked( $s['delete_data_on_uninstall'], true, false ) . '> ' . esc_html__( 'Delete all PrivacyPilot data when the plugin is deleted (including the consent log)', 'goo1-privacypilot' ) . '</label><p class="description">' . esc_html__( 'Off by default: the consent log is your proof of consent.', 'goo1-privacypilot' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save', 'goo1-privacypilot' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Export / import answers', 'goo1-privacypilot' ) . '</h2>';
		$export = wp_json_encode( array( 'entity' => Goo1_PP_Questionnaire::entity_answers(), 'site' => Goo1_PP_Questionnaire::site_answers(), 'library' => Goo1_PP_Library::version() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		echo '<textarea class="large-text code" rows="8" readonly>' . esc_textarea( $export ) . '</textarea>';
		self::form_open( 'import' );
		echo '<textarea class="large-text code" rows="6" name="import_json" placeholder="{&quot;entity&quot;: {…}, &quot;site&quot;: {…}}"></textarea>';
		echo '<p><button class="button pp-confirm-btn" data-confirm="' . esc_attr__( 'Overwrite the answers with the import?', 'goo1-privacypilot' ) . '">' . esc_html__( 'Import', 'goo1-privacypilot' ) . '</button></p></form>';
	}
}
