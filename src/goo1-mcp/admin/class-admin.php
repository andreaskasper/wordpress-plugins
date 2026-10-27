<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_notices', array( $this, 'legacy_key_notice' ) );
	}

	/**
	 * Copy-to-clipboard button next to a value (e.g. the MCP URL).
	 */
	public static function copy_button( $text ) {
		static $printed = false;
		if ( ! $printed ) {
			$printed = true;
			?>
			<script>
			function goo1McpCopy(btn){
				var t=btn.getAttribute('data-copy'),done=function(){var o=btn.textContent;btn.textContent='<?php echo esc_js( __( 'Copied ✓', 'goo1-mcp' ) ); ?>';setTimeout(function(){btn.textContent=o;},1500);};
				if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done);return;}
				var ta=document.createElement('textarea');ta.value=t;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(ta);
			}
			</script>
			<?php
		}
		printf(
			' <button type="button" class="button button-small" data-copy="%1$s" onclick="goo1McpCopy(this)">%2$s</button>',
			esc_attr( $text ),
			esc_html__( 'Copy', 'goo1-mcp' )
		);
	}

	/**
	 * Select box for one optional grant: none / 60 min / 24 h / permanent.
	 */
	public static function grant_select( $name, $current = null ) {
		$state = Goo1_MCP_Permissions::describe_grant( $current );
		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="keep">' . esc_html( sprintf( __( 'Keep (%s)', 'goo1-mcp' ), $state ) ) . '</option>';
		echo '<option value="">' . esc_html__( 'None', 'goo1-mcp' ) . '</option>';
		echo '<option value="60">' . esc_html__( '60 minutes', 'goo1-mcp' ) . '</option>';
		echo '<option value="1440">' . esc_html__( '24 hours', 'goo1-mcp' ) . '</option>';
		echo '<option value="perm">' . esc_html__( 'Permanent', 'goo1-mcp' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Merge a submitted grant form (with "keep" entries) over existing grants.
	 */
	public static function grants_from_post( $posted, $existing ) {
		$existing = Goo1_MCP_Permissions::sanitize_grants( $existing );
		$choices  = array();
		foreach ( Goo1_MCP_Permissions::GRANTS as $g ) {
			$c = isset( $posted[ $g ] ) ? sanitize_text_field( wp_unslash( $posted[ $g ] ) ) : 'keep';
			if ( 'keep' === $c ) {
				continue;
			}
			$choices[ $g ] = $c;
		}
		$new = Goo1_MCP_Permissions::grants_from_form( $choices );
		foreach ( Goo1_MCP_Permissions::GRANTS as $g ) {
			if ( ! array_key_exists( $g, $choices ) ) {
				$new[ $g ] = $existing[ $g ];
			}
		}
		return $new;
	}

	/**
	 * Administrators a key can run as.
	 */
	public static function admin_users() {
		return get_users( array( 'role__in' => array( 'administrator' ), 'fields' => array( 'ID', 'user_login', 'display_name' ), 'orderby' => 'login' ) );
	}

	/**
	 * Notice for API keys created before 1.6 that run without a user.
	 */
	public function legacy_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'goo1-mcp' ) ) {
			return;
		}
		foreach ( (array) get_option( 'goo1_mcp_api_keys', array() ) as $k ) {
			if ( empty( $k['user_id'] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Some API keys do not run as a WordPress user yet (created before 1.6). They keep working, but WordPress filters HTML (kses) and new content has no author. Choose "Runs as" for them under Claude Bridge → API Keys.', 'goo1-mcp' ) . '</p></div>';
				return;
			}
		}
	}

	public function add_menu() {
		add_menu_page(
			'goo1 Claude Bridge',
			'Claude Bridge',
			'manage_options',
			'goo1-mcp',
			array( $this, 'render_keys_page' ),
			'dashicons-rest-api',
			80
		);

		add_submenu_page(
			'goo1-mcp',
			'API Keys',
			'API Keys',
			'manage_options',
			'goo1-mcp',
			array( $this, 'render_keys_page' )
		);

		add_submenu_page(
			'goo1-mcp',
			'Connector (OAuth)',
			'Connector (OAuth)',
			'manage_options',
			'goo1-mcp-oauth',
			array( $this, 'render_oauth_page' )
		);

		add_submenu_page(
			'goo1-mcp',
			'Audit Log',
			'Audit Log',
			'manage_options',
			'goo1-mcp-audit',
			array( $this, 'render_audit_page' )
		);

		add_submenu_page(
			'goo1-mcp',
			'Settings',
			'Settings',
			'manage_options',
			'goo1-mcp-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Handle form submissions (create/revoke keys, update settings, purge log).
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Create key.
		if ( isset( $_POST['goo1_mcp_create_key'] ) && check_admin_referer( 'goo1_mcp_create_key' ) ) {
			$label      = sanitize_text_field( wp_unslash( $_POST['key_label'] ?? '' ) );
			$scope      = sanitize_text_field( wp_unslash( $_POST['key_scope'] ?? 'read' ) );
			$db_write   = ! empty( $_POST['key_db_write'] );
			$rate_limit = (int) ( $_POST['key_rate_limit'] ?? 60 );
			$user_id    = (int) ( $_POST['key_user_id'] ?? get_current_user_id() );
			if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
				$user_id = get_current_user_id();
			}
			$grants = array();
			if ( Goo1_MCP_Permissions::grants_enabled() && isset( $_POST['key_grant'] ) && is_array( $_POST['key_grant'] ) ) {
				$grants = Goo1_MCP_Permissions::grants_from_form( array_map( 'sanitize_text_field', wp_unslash( $_POST['key_grant'] ) ) );
			}

			if ( empty( $label ) ) {
				add_settings_error( 'goo1_mcp', 'empty_label', 'Key label is required.' );
				return;
			}

			$result = Goo1_MCP_Auth::instance()->create_key( $label, $scope, $db_write, $rate_limit, $user_id, $grants );

			set_transient( 'goo1_mcp_new_key', $result['raw_key'], 60 );
			add_settings_error( 'goo1_mcp', 'key_created', 'API key created. Copy it now — it will not be shown again.', 'success' );
		}

		// Edit key (runs-as user, grants).
		if ( isset( $_POST['goo1_mcp_edit_key'] ) && check_admin_referer( 'goo1_mcp_edit_key' ) ) {
			$key_hash = sanitize_text_field( wp_unslash( $_POST['key_hash'] ?? '' ) );
			$changes  = array();
			$uid      = (int) ( $_POST['key_user_id'] ?? 0 );
			if ( $uid && user_can( $uid, 'manage_options' ) ) {
				$changes['user_id'] = $uid;
			}
			foreach ( (array) get_option( 'goo1_mcp_api_keys', array() ) as $k ) {
				if ( $k['key_hash'] === $key_hash ) {
					$changes['grants'] = self::grants_from_post( isset( $_POST['key_grant'] ) ? (array) $_POST['key_grant'] : array(), isset( $k['grants'] ) ? $k['grants'] : array() );
				}
			}
			if ( Goo1_MCP_Auth::instance()->update_key( $key_hash, $changes ) ) {
				add_settings_error( 'goo1_mcp', 'key_updated', 'API key updated.', 'success' );
			}
		}

		// Edit OAuth connection grants.
		if ( isset( $_POST['goo1_mcp_edit_connection'] ) && check_admin_referer( 'goo1_mcp_edit_connection' ) ) {
			$family = sanitize_text_field( wp_unslash( $_POST['family'] ?? '' ) );
			foreach ( Goo1_MCP_OAuth::instance()->connections() as $c ) {
				if ( $c['family'] === $family ) {
					$grants = self::grants_from_post( isset( $_POST['conn_grant'] ) ? (array) $_POST['conn_grant'] : array(), $c['grants'] );
					Goo1_MCP_OAuth::instance()->update_connection_grants( $family, $grants );
					add_settings_error( 'goo1_mcp', 'conn_updated', 'Connection updated.', 'success' );
				}
			}
		}

		// Revoke OAuth connection.
		if ( isset( $_POST['goo1_mcp_revoke_connection'] ) && check_admin_referer( 'goo1_mcp_edit_connection' ) ) {
			Goo1_MCP_OAuth::instance()->revoke_family( sanitize_text_field( wp_unslash( $_POST['family'] ?? '' ) ) );
			add_settings_error( 'goo1_mcp', 'conn_revoked', 'Connection revoked.', 'success' );
		}

		// Revoke key.
		if ( isset( $_POST['goo1_mcp_revoke_key'] ) && check_admin_referer( 'goo1_mcp_revoke_key' ) ) {
			$key_hash = sanitize_text_field( wp_unslash( $_POST['key_hash'] ?? '' ) );
			if ( Goo1_MCP_Auth::instance()->revoke_key( $key_hash ) ) {
				add_settings_error( 'goo1_mcp', 'key_revoked', 'API key revoked.', 'success' );
			}
		}

		// Update settings.
		if ( isset( $_POST['goo1_mcp_save_settings'] ) && check_admin_referer( 'goo1_mcp_settings' ) ) {
			$scope = sanitize_text_field( wp_unslash( $_POST['oauth_default_scope'] ?? 'read' ) );
			if ( ! in_array( $scope, array( 'read', 'full', 'full_dbwrite' ), true ) ) {
				$scope = 'read';
			}
			$settings = array_merge( (array) get_option( 'goo1_mcp_settings', array() ), array(
				'default_rate_limit'       => max( 1, (int) ( $_POST['default_rate_limit'] ?? 60 ) ),
				'audit_log_retention_days' => max( 1, (int) ( $_POST['audit_log_retention_days'] ?? 30 ) ),
				'db_write_enabled'         => ! empty( $_POST['db_write_enabled'] ),
				'grants_enabled'           => ! empty( $_POST['grants_enabled'] ),
				'oauth_enabled'            => ! empty( $_POST['oauth_enabled'] ),
				'oauth_dcr_enabled'        => ! empty( $_POST['oauth_dcr_enabled'] ),
				'oauth_token_ttl'          => max( 60, (int) ( $_POST['oauth_token_ttl'] ?? 3600 ) ),
				'oauth_refresh_ttl_days'   => max( 1, min( 365, (int) ( $_POST['oauth_refresh_ttl_days'] ?? 90 ) ) ),
				'oauth_default_scope'      => $scope,
			) );
			update_option( 'goo1_mcp_settings', $settings );
			add_settings_error( 'goo1_mcp', 'settings_saved', 'Settings saved.', 'success' );
		}

		// Revoke OAuth client.
		if ( isset( $_POST['goo1_mcp_revoke_client'] ) && check_admin_referer( 'goo1_mcp_revoke_client' ) ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
			if ( Goo1_MCP_OAuth::instance()->delete_client( $client_id ) ) {
				add_settings_error( 'goo1_mcp', 'client_revoked', 'OAuth client and its tokens revoked.', 'success' );
			}
		}

		// Revoke a single OAuth access token.
		if ( isset( $_POST['goo1_mcp_revoke_token'] ) && check_admin_referer( 'goo1_mcp_revoke_token' ) ) {
			$hash = sanitize_text_field( wp_unslash( $_POST['token_hash'] ?? '' ) );
			if ( $hash ) {
				Goo1_MCP_OAuth::instance()->revoke_token( $hash );
				add_settings_error( 'goo1_mcp', 'token_revoked', 'Access token revoked.', 'success' );
			}
		}

		// Purge audit log.
		if ( isset( $_POST['goo1_mcp_purge_log'] ) && check_admin_referer( 'goo1_mcp_purge_log' ) ) {
			$settings = get_option( 'goo1_mcp_settings', array() );
			$days     = $settings['audit_log_retention_days'] ?? 30;
			$deleted  = Goo1_MCP_Audit_Log::instance()->purge( $days );
			add_settings_error( 'goo1_mcp', 'log_purged', sprintf( '%d audit log entries purged.', $deleted ), 'success' );
		}
	}

	public function render_keys_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/api-keys.php';
	}

	public function render_oauth_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/oauth-clients.php';
	}

	public function render_audit_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/audit-log.php';
	}

	public function render_settings_page() {
		require_once GOO1_MCP_PLUGIN_DIR . 'admin/views/settings.php';
	}
}

Goo1_MCP_Admin::instance();
