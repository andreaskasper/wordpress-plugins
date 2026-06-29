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

		$result = activate_plugin( $file );
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
