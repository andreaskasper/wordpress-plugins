<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Plugins_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/plugins', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_plugins' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>.+)/activate', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'activate_plugin' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>.+)/deactivate', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'deactivate_plugin' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/themes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_themes' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/themes/(?P<slug>[a-zA-Z0-9_-]+)/activate', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'activate_theme' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/plugins/install', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'install_plugin' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>.+)/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_plugin' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>.+)/delete', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_plugin' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/themes/install', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'install_theme' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/themes/(?P<slug>[a-zA-Z0-9_.-]+)/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_theme' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/themes/(?P<slug>[a-zA-Z0-9_.-]+)/delete', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_theme' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );

		register_rest_route( self::NAMESPACE, '/updates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_updates' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/rewrite-rules', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_rewrite_rules' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );

		register_rest_route( self::NAMESPACE, '/rewrite-rules/flush', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'flush_rewrite_rules' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
	}

	public function list_plugins( $request ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$output         = array();

		foreach ( $all_plugins as $file => $data ) {
			$output[] = array(
				'file'        => $file,
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'description' => $data['Description'],
				'author'      => $data['Author'],
				'author_uri'  => $data['AuthorURI'],
				'plugin_uri'  => $data['PluginURI'],
				'active'      => in_array( $file, $active_plugins, true ),
				'network'     => is_plugin_active_for_network( $file ),
			);
		}

		return $this->success( $output );
	}

	public function activate_plugin( $request ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slug = sanitize_text_field( $request['slug'] );
		$file = $this->find_plugin_file( $slug );

		if ( ! $file ) {
			return $this->error( 'not_found', 'Plugin not found.', 404 );
		}

		$guard  = Goo1_MCP_Guard::snapshot();
		$result = activate_plugin( $file );
		if ( Goo1_MCP_Guard::restore_if_changed( $guard ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'guard_restored' );
		}
		if ( is_wp_error( $result ) ) {
			return $this->error( 'activation_failed', $result->get_error_message() );
		}

		return $this->success( array( 'activated' => true, 'plugin' => $file ) );
	}

	public function deactivate_plugin( $request ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slug = sanitize_text_field( $request['slug'] );
		$file = $this->find_plugin_file( $slug );

		if ( ! $file ) {
			return $this->error( 'not_found', 'Plugin not found.', 404 );
		}

		if ( GOO1_MCP_PLUGIN_BASENAME === $file ) {
			return $this->error( 'self_deactivate', 'The bridge cannot deactivate itself.', 403 );
		}
		deactivate_plugins( $file );

		return $this->success( array( 'deactivated' => true, 'plugin' => $file ) );
	}

	public function list_themes( $request ) {
		$themes       = wp_get_themes();
		$active_theme = get_stylesheet();
		$output       = array();

		foreach ( $themes as $slug => $theme ) {
			$output[] = array(
				'slug'        => $slug,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => $theme->get( 'Description' ),
				'author'      => $theme->get( 'Author' ),
				'template'    => $theme->get( 'Template' ),
				'active'      => $slug === $active_theme,
				'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			);
		}

		return $this->success( $output );
	}

	public function activate_theme( $request ) {
		$slug   = sanitize_text_field( $request['slug'] );
		$themes = wp_get_themes();

		if ( ! isset( $themes[ $slug ] ) ) {
			return $this->error( 'not_found', 'Theme not found.', 404 );
		}

		switch_theme( $slug );

		return $this->success( array( 'activated' => true, 'theme' => $slug ) );
	}

	public function list_rewrite_rules( $request ) {
		global $wp_rewrite;

		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( ! $rules ) {
			$rules = array();
		}

		$output = array();
		foreach ( $rules as $pattern => $rewrite ) {
			$output[] = array(
				'pattern' => $pattern,
				'rewrite' => $rewrite,
			);
		}

		return $this->success( array(
			'rules'     => $output,
			'total'     => count( $output ),
			'structure' => get_option( 'permalink_structure' ),
		) );
	}

	public function flush_rewrite_rules( $request ) {
		flush_rewrite_rules();

		return $this->success( array( 'flushed' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Install / update / delete                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Load the upgrader stack and make sure WordPress can write files
	 * without asking for FTP credentials.
	 *
	 * @return true|WP_REST_Response
	 */
	private function prepare_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'direct' !== get_filesystem_method() ) {
			return $this->error(
				'filesystem_not_direct',
				'WordPress cannot write files directly on this server (FS method "' . get_filesystem_method() . '"). Install/update needs FS_METHOD direct or a writable wp-content.',
				500
			);
		}
		if ( ! WP_Filesystem() ) {
			return $this->error( 'filesystem_failed', 'Could not initialise the WordPress filesystem.', 500 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		return true;
	}

	/**
	 * Run an upgrader action with a silent skin and collect its messages.
	 *
	 * @param string   $class    Plugin_Upgrader or Theme_Upgrader.
	 * @param callable $callback function( $upgrader ) returning the result.
	 * @return array( result, messages, errors )
	 */
	private function run_upgrader( $class, $callback ) {
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new $class( $skin );
		$guard    = Goo1_MCP_Guard::snapshot();
		ob_start();
		$result = call_user_func( $callback, $upgrader );
		ob_end_clean();
		if ( Goo1_MCP_Guard::restore_if_changed( $guard ) ) {
			Goo1_MCP_Audit_Log::add_flag( 'guard_restored' );
		}

		$errors = array();
		if ( is_wp_error( $result ) ) {
			$errors = $result->get_error_messages();
		}
		if ( $skin->get_errors()->has_errors() ) {
			$errors = array_merge( $errors, $skin->get_errors()->get_error_messages() );
		}
		return array(
			'result'   => $result,
			'upgrader' => $upgrader,
			'messages' => array_values( array_filter( array_map( function ( $m ) { return html_entity_decode( wp_strip_all_tags( $m ), ENT_QUOTES, 'UTF-8' ); }, (array) $skin->get_upgrade_messages() ) ) ),
			'errors'   => array_values( array_unique( $errors ) ),
		);
	}

	/**
	 * Install a plugin from wordpress.org (slug) or any ZIP URL.
	 * Params: slug | url, activate (bool), overwrite (bool, reinstall/downgrade).
	 */
	public function install_plugin( $request ) {
		$ready = $this->prepare_upgrader();
		if ( true !== $ready ) {
			return $ready;
		}
		$slug      = sanitize_key( (string) $request->get_param( 'slug' ) );
		$url       = esc_url_raw( (string) $request->get_param( 'url' ) );
		$activate  = $this->truthy( $request->get_param( 'activate' ) );
		$overwrite = $this->truthy( $request->get_param( 'overwrite' ) );

		if ( ! $slug && ! $url ) {
			return $this->error( 'missing_source', 'Provide `slug` (wordpress.org) or `url` (ZIP).' );
		}

		$package = $url;
		$version = null;
		if ( $slug ) {
			$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $this->error( 'plugin_not_found', 'wordpress.org: ' . $api->get_error_message(), 404 );
			}
			$package = $api->download_link;
			$version = $api->version;
			if ( ! $overwrite && $this->find_plugin_file( $slug ) ) {
				return $this->error( 'already_installed', 'Plugin is already installed. Use the update tool, or pass overwrite=true to reinstall.', 409 );
			}
		}

		// Never let an install replace the bridge itself (use the update tool).
		$own_dir    = dirname( GOO1_MCP_PLUGIN_BASENAME );
		$self_guard = function ( $source ) use ( $own_dir ) {
			if ( is_string( $source ) && basename( untrailingslashit( $source ) ) === $own_dir ) {
				return new WP_Error( 'self_overwrite', 'This package would replace Claude Bridge itself; use wp_update_plugin for the bridge.' );
			}
			return $source;
		};
		add_filter( 'upgrader_source_selection', $self_guard, 1 );
		$run = $this->run_upgrader( 'Plugin_Upgrader', function ( $u ) use ( $package, $overwrite ) {
			return $u->install( $package, array( 'overwrite_package' => $overwrite ) );
		} );
		remove_filter( 'upgrader_source_selection', $self_guard, 1 );
		if ( true !== $run['result'] || $run['errors'] ) {
			return $this->error( 'install_failed', implode( ' | ', $run['errors'] ? $run['errors'] : array( 'Installation failed.' ) ) );
		}

		wp_clean_plugins_cache();
		$file = $run['upgrader']->plugin_info();
		if ( ! $file && $slug ) {
			$file = $this->find_plugin_file( $slug );
		}

		$out = array(
			'installed' => true,
			'plugin'    => $file,
			'version'   => $file ? $this->plugin_version( $file ) : $version,
			'source'    => $slug ? 'wordpress.org' : $url,
			'activated' => false,
			'messages'  => $run['messages'],
		);

		if ( $activate && $file ) {
			$guard = Goo1_MCP_Guard::snapshot();
			$res   = activate_plugin( $file );
			if ( Goo1_MCP_Guard::restore_if_changed( $guard ) ) {
				Goo1_MCP_Audit_Log::add_flag( 'guard_restored' );
			}
			if ( is_wp_error( $res ) ) {
				$out['activation_error'] = $res->get_error_message();
			} else {
				$out['activated'] = true;
			}
		}

		return $this->success( $out, 201 );
	}

	/**
	 * Update an installed plugin to the latest available version.
	 */
	public function update_plugin( $request ) {
		$ready = $this->prepare_upgrader();
		if ( true !== $ready ) {
			return $ready;
		}
		$file = $this->find_plugin_file( sanitize_text_field( $request['slug'] ) );
		if ( ! $file ) {
			return $this->error( 'not_found', 'Plugin not found.', 404 );
		}
		$before = $this->plugin_version( $file );
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( empty( $updates->response[ $file ] ) ) {
			return $this->success( array( 'updated' => false, 'plugin' => $file, 'version' => $before, 'message' => 'No update available.' ) );
		}
		$was_active = is_plugin_active( $file );

		$run = $this->run_upgrader( 'Plugin_Upgrader', function ( $u ) use ( $file ) {
			return $u->upgrade( $file );
		} );
		if ( true !== $run['result'] || $run['errors'] ) {
			return $this->error( 'update_failed', implode( ' | ', $run['errors'] ? $run['errors'] : array( 'Update failed.' ) ) );
		}
		wp_clean_plugins_cache();
		if ( $was_active && ! is_plugin_active( $file ) ) {
			$guard = Goo1_MCP_Guard::snapshot();
			activate_plugin( $file, '', false, true );
			if ( Goo1_MCP_Guard::restore_if_changed( $guard ) ) {
				Goo1_MCP_Audit_Log::add_flag( 'guard_restored' );
			}
		}

		return $this->success( array(
			'updated'      => true,
			'plugin'       => $file,
			'from_version' => $before,
			'to_version'   => $this->plugin_version( $file ),
			'active'       => is_plugin_active( $file ),
			'messages'     => $run['messages'],
		) );
	}

	/**
	 * Delete an inactive plugin (files are removed).
	 */
	public function delete_plugin( $request ) {
		$ready = $this->prepare_upgrader();
		if ( true !== $ready ) {
			return $ready;
		}
		$file = $this->find_plugin_file( sanitize_text_field( $request['slug'] ) );
		if ( ! $file ) {
			return $this->error( 'not_found', 'Plugin not found.', 404 );
		}
		if ( GOO1_MCP_PLUGIN_BASENAME === $file ) {
			return $this->error( 'self_delete', 'The bridge cannot delete itself.', 403 );
		}
		if ( is_plugin_active( $file ) ) {
			return $this->error( 'plugin_active', 'Deactivate the plugin first.', 409 );
		}
		$res = delete_plugins( array( $file ) );
		if ( is_wp_error( $res ) || ! $res ) {
			return $this->error( 'delete_failed', is_wp_error( $res ) ? $res->get_error_message() : 'Could not delete plugin.' );
		}
		return $this->success( array( 'deleted' => true, 'plugin' => $file ) );
	}

	public function install_theme( $request ) {
		$ready = $this->prepare_upgrader();
		if ( true !== $ready ) {
			return $ready;
		}
		$slug      = sanitize_key( (string) $request->get_param( 'slug' ) );
		$url       = esc_url_raw( (string) $request->get_param( 'url' ) );
		$overwrite = $this->truthy( $request->get_param( 'overwrite' ) );
		if ( ! $slug && ! $url ) {
			return $this->error( 'missing_source', 'Provide `slug` (wordpress.org) or `url` (ZIP).' );
		}
		$package = $url;
		if ( $slug ) {
			$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $this->error( 'theme_not_found', 'wordpress.org: ' . $api->get_error_message(), 404 );
			}
			$package = $api->download_link;
			if ( ! $overwrite && wp_get_theme( $slug )->exists() ) {
				return $this->error( 'already_installed', 'Theme is already installed. Use the update tool, or pass overwrite=true.', 409 );
			}
		}
		$run = $this->run_upgrader( 'Theme_Upgrader', function ( $u ) use ( $package, $overwrite ) {
			return $u->install( $package, array( 'overwrite_package' => $overwrite ) );
		} );
		if ( true !== $run['result'] || $run['errors'] ) {
			return $this->error( 'install_failed', implode( ' | ', $run['errors'] ? $run['errors'] : array( 'Installation failed.' ) ) );
		}
		return $this->success( array( 'installed' => true, 'theme' => $slug ? $slug : null, 'source' => $slug ? 'wordpress.org' : $url, 'messages' => $run['messages'] ), 201 );
	}

	public function update_theme( $request ) {
		$ready = $this->prepare_upgrader();
		if ( true !== $ready ) {
			return $ready;
		}
		$slug  = sanitize_text_field( $request['slug'] );
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return $this->error( 'not_found', 'Theme not found.', 404 );
		}
		$before = $theme->get( 'Version' );
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( empty( $updates->response[ $slug ] ) ) {
			return $this->success( array( 'updated' => false, 'theme' => $slug, 'version' => $before, 'message' => 'No update available.' ) );
		}
		$run = $this->run_upgrader( 'Theme_Upgrader', function ( $u ) use ( $slug ) {
			return $u->upgrade( $slug );
		} );
		if ( true !== $run['result'] || $run['errors'] ) {
			return $this->error( 'update_failed', implode( ' | ', $run['errors'] ? $run['errors'] : array( 'Update failed.' ) ) );
		}
		wp_clean_themes_cache();
		return $this->success( array(
			'updated'      => true,
			'theme'        => $slug,
			'from_version' => $before,
			'to_version'   => wp_get_theme( $slug )->get( 'Version' ),
			'messages'     => $run['messages'],
		) );
	}

	public function delete_theme( $request ) {
		$ready = $this->prepare_upgrader();
		if ( true !== $ready ) {
			return $ready;
		}
		$slug = sanitize_text_field( $request['slug'] );
		if ( ! wp_get_theme( $slug )->exists() ) {
			return $this->error( 'not_found', 'Theme not found.', 404 );
		}
		if ( get_stylesheet() === $slug || get_template() === $slug ) {
			return $this->error( 'theme_active', 'The active theme (or its parent) cannot be deleted.', 409 );
		}
		$res = delete_theme( $slug );
		if ( is_wp_error( $res ) || ! $res ) {
			return $this->error( 'delete_failed', is_wp_error( $res ) ? $res->get_error_message() : 'Could not delete theme.' );
		}
		return $this->success( array( 'deleted' => true, 'theme' => $slug ) );
	}

	/**
	 * Available core, plugin, theme and translation updates.
	 * refresh=true forces a fresh check against wordpress.org.
	 */
	public function list_updates( $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( $this->truthy( $request->get_param( 'refresh' ) ) ) {
			wp_version_check( array(), true );
			wp_update_plugins();
			wp_update_themes();
		}

		$plugins = array();
		$up      = get_site_transient( 'update_plugins' );
		$all     = get_plugins();
		if ( $up && ! empty( $up->response ) ) {
			foreach ( $up->response as $file => $info ) {
				$plugins[] = array(
					'plugin'      => $file,
					'name'        => isset( $all[ $file ] ) ? $all[ $file ]['Name'] : $file,
					'current'     => isset( $all[ $file ] ) ? $all[ $file ]['Version'] : null,
					'new_version' => isset( $info->new_version ) ? $info->new_version : null,
					'active'      => is_plugin_active( $file ),
					'requires_php'=> isset( $info->requires_php ) ? $info->requires_php : null,
				);
			}
		}

		$themes = array();
		$ut     = get_site_transient( 'update_themes' );
		if ( $ut && ! empty( $ut->response ) ) {
			foreach ( $ut->response as $slug => $info ) {
				$t        = wp_get_theme( $slug );
				$themes[] = array(
					'theme'       => $slug,
					'name'        => $t->get( 'Name' ),
					'current'     => $t->get( 'Version' ),
					'new_version' => isset( $info['new_version'] ) ? $info['new_version'] : null,
					'active'      => get_stylesheet() === $slug,
				);
			}
		}

		$core   = array();
		$uc     = get_site_transient( 'update_core' );
		if ( $uc && ! empty( $uc->updates ) ) {
			foreach ( $uc->updates as $u ) {
				if ( isset( $u->response ) && 'latest' !== $u->response ) {
					$core[] = array( 'type' => $u->response, 'version' => $u->current, 'php_version' => isset( $u->php_version ) ? $u->php_version : null );
				}
			}
		}

		$translations = 0;
		foreach ( array( $up, $ut, $uc ) as $t ) {
			if ( $t && ! empty( $t->translations ) ) {
				$translations += count( $t->translations );
			}
		}

		return $this->success( array(
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'core'                => $core,
			'plugins'             => $plugins,
			'themes'              => $themes,
			'translation_updates' => $translations,
			'last_checked'        => $up && isset( $up->last_checked ) ? gmdate( 'c', (int) $up->last_checked ) : null,
		) );
	}

	private function plugin_version( $file ) {
		$all = get_plugins();
		return isset( $all[ $file ] ) ? $all[ $file ]['Version'] : null;
	}

	private function truthy( $v ) {
		return true === $v || 1 === $v || in_array( strtolower( (string) $v ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Find plugin file by slug (directory name or full path).
	 */
	private function find_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = get_plugins();

		// Direct match.
		if ( isset( $all[ $slug ] ) ) {
			return $slug;
		}

		// Match by directory name.
		foreach ( array_keys( $all ) as $file ) {
			$dir = dirname( $file );
			if ( $dir === $slug ) {
				return $file;
			}
		}

		return null;
	}
}
