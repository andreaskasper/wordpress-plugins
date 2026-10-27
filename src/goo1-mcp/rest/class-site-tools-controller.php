<?php
/**
 * Site-wide helpers: cache purge across common caching plugins and a
 * one-call site overview for audits.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_MCP_Site_Tools_Controller extends Goo1_MCP_Base_Controller {

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/cache/purge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'purge' ),
			'permission_callback' => $this->get_permission_callback( 'full' ),
		) );
		register_rest_route( self::NAMESPACE, '/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'overview' ),
			'permission_callback' => $this->get_permission_callback( 'read' ),
		) );
	}

	/**
	 * Purge page caches, Elementor CSS and/or the object cache.
	 * scope: all (default) | page | elementor | object; url: purge one URL only (where supported).
	 */
	public function purge( $request ) {
		$scope = (string) ( $request->get_param( 'scope' ) ?: 'all' );
		$url   = esc_url_raw( (string) $request->get_param( 'url' ) );
		$done  = array();
		$page  = in_array( $scope, array( 'all', 'page' ), true );

		if ( $page ) {
			if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core' ) ) {
				if ( $url ) {
					do_action( 'litespeed_purge_url', $url );
				} else {
					do_action( 'litespeed_purge_all' );
				}
				$done[] = 'litespeed';
			}
			if ( function_exists( 'rocket_clean_domain' ) ) {
				if ( $url && function_exists( 'rocket_clean_files' ) ) {
					rocket_clean_files( array( $url ) );
				} else {
					rocket_clean_domain();
				}
				$done[] = 'wp-rocket';
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				if ( $url && function_exists( 'w3tc_flush_url' ) ) {
					w3tc_flush_url( $url );
				} else {
					w3tc_flush_all();
				}
				$done[] = 'w3-total-cache';
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
				$done[] = 'wp-super-cache';
			}
			if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
				$done[] = 'wp-fastest-cache';
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
				$done[] = 'sg-optimizer';
			}
			if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
				autoptimizeCache::clearall();
				$done[] = 'autoptimize';
			}
			if ( has_action( 'breeze_clear_all_cache' ) ) {
				do_action( 'breeze_clear_all_cache' );
				$done[] = 'breeze';
			}
			if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
				do_action( 'cache_enabler_clear_complete_cache' );
				$done[] = 'cache-enabler';
			}
			if ( has_action( 'wphb_clear_page_cache' ) ) {
				do_action( 'wphb_clear_page_cache' );
				$done[] = 'hummingbird';
			}
			if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
				do_action( 'rt_nginx_helper_purge_all' );
				$done[] = 'nginx-helper';
			}
			if ( class_exists( 'WpeCommon' ) ) {
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					WpeCommon::purge_memcached();
				}
				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					WpeCommon::purge_varnish_cache();
				}
				$done[] = 'wp-engine';
			}
		}

		if ( in_array( $scope, array( 'all', 'elementor' ), true ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				$done[] = 'elementor-css';
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( in_array( $scope, array( 'all', 'object' ), true ) ) {
			wp_cache_flush();
			$done[] = wp_using_ext_object_cache() ? 'object-cache (persistent)' : 'object-cache (runtime)';
		}

		return $this->success( array( 'scope' => $scope, 'url' => $url ? $url : null, 'purged' => $done ) );
	}

	/**
	 * Audit snapshot in one call.
	 */
	public function overview( $request ) {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$pv      = function ( $file ) use ( $plugins, $active ) {
			return isset( $plugins[ $file ] ) ? array( 'version' => $plugins[ $file ]['Version'], 'active' => in_array( $file, $active, true ) ) : null;
		};

		$up   = get_site_transient( 'update_plugins' );
		$ut   = get_site_transient( 'update_themes' );
		$uc   = get_site_transient( 'update_core' );
		$core = null;
		if ( $uc && ! empty( $uc->updates ) ) {
			foreach ( $uc->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core = $u->current;
					break;
				}
			}
		}

		$overdue = 0;
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( $ts < time() - 600 ) {
				$overdue += count( $hooks );
			}
		}

		$php_eol = array( '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31' );
		$php_mm  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

		$risky = array();
		foreach ( array( 'wp-file-manager/file_folder_manager.php' => 'WP File Manager', 'file-manager-advanced/file_manager_advanced.php' => 'File Manager Advanced', 'wp-phpmyadmin-extension/index.php' => 'phpMyAdmin extension' ) as $f => $n ) {
			if ( in_array( $f, $active, true ) ) {
				$risky[] = $n;
			}
		}

		$caches = array();
		foreach ( array( 'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache', 'wp-rocket/wp-rocket.php' => 'WP Rocket', 'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache', 'wp-super-cache/wp-cache.php' => 'WP Super Cache', 'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache', 'autoptimize/autoptimize.php' => 'Autoptimize', 'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer', 'redis-cache/redis-cache.php' => 'Redis Object Cache' ) as $f => $n ) {
			if ( in_array( $f, $active, true ) ) {
				$caches[] = $n;
			}
		}

		$keys   = (array) get_option( 'goo1_mcp_api_keys', array() );
		$legacy = count( array_filter( $keys, function ( $k ) { return empty( $k['user_id'] ); } ) );

		$updraft = null;
		if ( class_exists( 'UpdraftPlus' ) && class_exists( 'UpdraftPlus_Options' ) ) {
			$svc  = UpdraftPlus_Options::get_updraft_option( 'updraft_service', array() );
			$last = UpdraftPlus_Options::get_updraft_option( 'updraft_last_backup', array() );
			$updraft = array(
				'services'       => array_values( array_filter( (array) $svc ) ),
				'files_schedule' => UpdraftPlus_Options::get_updraft_option( 'updraft_interval', 'manual' ),
				'db_schedule'    => UpdraftPlus_Options::get_updraft_option( 'updraft_interval_database', 'manual' ),
				'last_backup'    => ! empty( $last['backup_time'] ) ? gmdate( 'c', (int) $last['backup_time'] ) : null,
				'last_success'   => isset( $last['success'] ) ? (bool) $last['success'] : null,
			);
		}

		$disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false; // phpcs:ignore

		return $this->success( array(
			'site'        => array(
				'home'            => home_url(),
				'https'           => 0 === strpos( home_url(), 'https://' ),
				'wordpress'       => get_bloginfo( 'version' ),
				'core_update'     => $core,
				'multisite'       => is_multisite(),
				'search_visible'  => (bool) get_option( 'blog_public' ),
				'permalinks'      => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain',
				'theme'           => get_stylesheet(),
				'timezone'        => wp_timezone_string(),
				'admin_count'     => count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ),
			),
			'php'         => array(
				'version'      => PHP_VERSION,
				'eol'          => isset( $php_eol[ $php_mm ] ) ? $php_eol[ $php_mm ] : null,
				'eol_passed'   => isset( $php_eol[ $php_mm ] ) && strtotime( $php_eol[ $php_mm ] ) < time(),
				'memory_limit' => ini_get( 'memory_limit' ),
				'wp_memory'    => WP_MEMORY_LIMIT,
			),
			'config'      => array(
				'WP_DEBUG'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_DISPLAY'    => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true,
				'DISALLOW_FILE_EDIT'  => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'DISABLE_WP_CRON'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'object_cache'        => (bool) wp_using_ext_object_cache(),
				'fs_method'           => function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : null,
			),
			'updates'     => array(
				'plugins' => $up && ! empty( $up->response ) ? count( $up->response ) : 0,
				'themes'  => $ut && ! empty( $ut->response ) ? count( $ut->response ) : 0,
				'checked' => $up && isset( $up->last_checked ) ? gmdate( 'c', (int) $up->last_checked ) : null,
			),
			'plugins'     => array(
				'installed'  => count( $plugins ),
				'active'     => count( $active ),
				'risky'      => $risky,
				'caching'    => $caches,
				'updraftplus'=> $pv( 'updraftplus/updraftplus.php' ),
				'worker'     => $pv( 'worker/init.php' ),
				'wordfence'  => $pv( 'wordfence/wordfence.php' ),
				'goo1_omni'  => $pv( 'goo1-omni/goo1-omni.php' ),
				'elementor'  => $pv( 'elementor/elementor.php' ),
				'woocommerce'=> $pv( 'woocommerce/woocommerce.php' ),
			),
			'backup'      => $updraft,
			'managewp'    => class_exists( 'Goo1_MCP_ManageWP_Controller' ) ? Goo1_MCP_ManageWP_Controller::worker_state() : null,
			'cron'        => array( 'overdue_hooks' => $overdue ),
			'database'    => array(
				'size_mb' => round( (float) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', DB_NAME ) ) / 1048576, 1 ),
				'prefix'  => $wpdb->prefix,
				'autoload_kb' => round( (float) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" ) / 1024 ),
			),
			'disk_free_gb'=> false !== $disk_free ? round( $disk_free / 1073741824, 1 ) : null,
			'bridge'      => array(
				'version'        => GOO1_MCP_VERSION,
				'api_keys'       => count( $keys ),
				'legacy_keys'    => $legacy,
				'connections'    => count( Goo1_MCP_OAuth::instance()->connections() ),
				'grants_enabled' => Goo1_MCP_Permissions::grants_enabled(),
				'run_as'         => wp_get_current_user()->user_login,
			),
		) );
	}
}
