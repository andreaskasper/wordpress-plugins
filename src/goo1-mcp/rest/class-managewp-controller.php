<?php
/**
 * ManageWP Worker helpers.
 *
 * The connection key is created by the Worker and has to be typed into the
 * ManageWP dashboard by a human — the bridge never returns it. It is shown
 * only on the wp-admin page "Claude Bridge → ManageWP" with a copy button.
 * Option and SQL reads mask it (blocked option name + value matching); a
 * full-scope token could still extract it deliberately (see class-redactor.php).
 * Worker internals: wiki note "ManageWP Worker: wie der Connection Key funktioniert".
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_ManageWP_Controller extends Goo1_MCP_Base_Controller {

	const WORKER_FILE = 'worker/init.php';

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/managewp/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
		register_rest_route( self::NAMESPACE, '/managewp/connection-link', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'connection_link' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/managewp/reset', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'reset' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	/**
	 * Everything about the Worker except the key itself.
	 */
	public static function worker_state() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins   = get_plugins();
		$installed = isset( $plugins[ self::WORKER_FILE ] );
		$active    = $installed && is_plugin_active( self::WORKER_FILE );
		$godaddy   = file_exists( WPMU_PLUGIN_DIR . '/gd-system-plugin.php' ) || defined( 'GD_SYSTEM_PLUGIN_DIR' );

		$keys  = get_option( 'mwp_communication_keys', array() );
		$sites = array();
		if ( is_array( $keys ) ) {
			foreach ( $keys as $site_id => $k ) {
				$used    = get_option( 'mwp_key_last_used_' . $site_id );
				$sites[] = array(
					'managewp_site_id' => (string) $site_id,
					'connected_since'  => ! empty( $k['added'] ) ? gmdate( 'c', (int) $k['added'] ) : null,
					'last_contact'     => $used ? gmdate( 'c', (int) $used ) : null,
				);
			}
		}
		$legacy_key = (bool) get_option( 'mwp_communication_key' );
		$pk_time    = (int) get_option( 'mwp_potential_key_time', 0 );

		return array(
			'installed'            => $installed,
			'active'               => $active,
			'version'              => $installed ? $plugins[ self::WORKER_FILE ]['Version'] : null,
			'godaddy_managed'      => $godaddy,
			'connected'            => ! empty( $sites ) || $legacy_key,
			'connections'          => $sites,
			'legacy_connection'    => $legacy_key,
			'public_key_set'       => (bool) get_option( '_worker_public_key' ),
			'pending_key_age_min'  => $pk_time ? (int) floor( ( time() - $pk_time ) / 60 ) : null,
			'object_cache'         => (bool) wp_using_ext_object_cache(),
			'site_url_for_managewp'=> home_url(),
		);
	}

	public function status( $request ) {
		$state = self::worker_state();
		$state['hints'] = array();
		if ( $state['godaddy_managed'] ) {
			$state['hints'][] = 'GoDaddy Managed WordPress connects to ManageWP through its own system plugin — do not install the Worker here.';
		}
		if ( ! $state['installed'] ) {
			$state['hints'][] = 'Install with wp_install_plugin slug=worker activate=true.';
		} elseif ( ! $state['active'] ) {
			$state['hints'][] = 'Activate with wp_activate_plugin slug=worker.';
		}
		return $this->success( $state );
	}

	/**
	 * Make sure a fresh connection key exists and return the wp-admin page
	 * where the owner copies it. The key itself is NOT part of the response.
	 */
	public function connection_link( $request ) {
		$state = self::worker_state();
		if ( ! $state['active'] ) {
			return $this->error( 'worker_inactive', 'The ManageWP Worker plugin is not active.', 409 );
		}
		if ( function_exists( 'mwp_get_potential_key' ) ) {
			mwp_get_potential_key(); // regenerates after 24 h.
		}
		return $this->success( array(
			'admin_page'    => admin_url( 'admin.php?page=goo1-mcp-managewp' ),
			'site_url'      => home_url(),
			'connected'     => $state['connected'],
			'instructions'  => array(
				'1. The owner opens admin_page (logged in as admin) and clicks "Copy key".',
				'2. In ManageWP: Add website → enter site_url exactly → "Use connection key instead" → paste the key.',
				'3. Afterwards check with managewp_status that connected=true.',
			),
		) );
	}

	/**
	 * Remove the Worker's connection data cleanly (also from the object
	 * cache — the Worker's own "clear data" bypasses it). The site then needs
	 * to be re-added in ManageWP with a new key.
	 */
	public function reset( $request ) {
		$c = $request->get_param( 'confirm' );
		if ( ! ( true === $c || 'true' === $c || '1' === $c || 1 === $c ) ) {
			return $this->error( 'confirm_required', 'This disconnects the site from ManageWP. Repeat with confirm=true.', 400 );
		}
		global $wpdb;
		$names = array( 'mwp_communication_keys', 'mwp_communication_key', 'mwp_potential_key', 'mwp_potential_key_time', '_worker_public_key', '_worker_nossl_key', 'mwp_key_last_used_any' );
		$like  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mwp_key_last_used_' ) . '%' ) );
		$names = array_unique( array_merge( $names, (array) $like ) );
		$deleted = array();
		foreach ( $names as $n ) {
			if ( false !== get_option( $n ) ) {
				$deleted[] = $n;
			}
			delete_option( $n );
			wp_cache_delete( $n, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( function_exists( 'mwp_get_potential_key' ) ) {
			mwp_get_potential_key();
		}
		return $this->success( array(
			'reset'         => true,
			'deleted'       => count( $deleted ),
			'next'          => 'Use managewp_connection_link and re-add the site in ManageWP ("Use connection key instead").',
			'state'         => self::worker_state(),
		) );
	}
}
