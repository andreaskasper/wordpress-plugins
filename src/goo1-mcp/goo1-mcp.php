<?php
/**
 * Plugin Name: goo1 MCP WP Claude Bridge
 * Plugin URI:  https://github.com/andreaskasper/wordpress-plugins
 * Description: Exposes WordPress functionality via REST API for Claude AI integration. Manage posts, pages, users, options, database, and more.
 * Version:     1.3.260629
 * Author:      Andreas Kasper <andreas.kasper@goo1.de>
 * Author URI:  https://goo1.de
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: goo1-mcp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GOO1_MCP_VERSION', '1.3.260629' );
define( 'GOO1_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOO1_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GOO1_MCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Activation / Deactivation.
require_once GOO1_MCP_PLUGIN_DIR . 'includes/class-activator.php';
register_activation_hook( __FILE__, array( 'Goo1_MCP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Goo1_MCP_Deactivator', 'deactivate' ) );

// Core includes.
require_once GOO1_MCP_PLUGIN_DIR . 'includes/class-auth.php';
require_once GOO1_MCP_PLUGIN_DIR . 'includes/class-permissions.php';
require_once GOO1_MCP_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once GOO1_MCP_PLUGIN_DIR . 'includes/class-audit-log.php';
require_once GOO1_MCP_PLUGIN_DIR . 'includes/class-oauth.php';

// REST controllers.
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-base-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-posts-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-taxonomies-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-media-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-comments-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-menus-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-users-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-options-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-plugins-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-database-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-site-health-controller.php';
require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-mcp-controller.php';

// Conditionally load WooCommerce controller.
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WooCommerce' ) ) {
		require_once GOO1_MCP_PLUGIN_DIR . 'rest/class-woocommerce-controller.php';
	}
} );

// Admin.
if ( is_admin() ) {
	require_once GOO1_MCP_PLUGIN_DIR . 'admin/class-admin.php';
}

/**
 * OAuth front-controller: serve the discovery metadata and the
 * authorize / token / register endpoints (outside the REST stack).
 */
add_action( 'init', function () {
	Goo1_MCP_OAuth::instance()->maybe_handle_request();
}, 0 );

/**
 * Allow the MCP transport headers through the REST CORS layer (claude.ai web).
 */
add_filter( 'rest_allowed_cors_headers', function ( $headers ) {
	$headers[] = 'Mcp-Protocol-Version';
	$headers[] = 'Mcp-Session-Id';
	return $headers;
} );

/**
 * Register all REST routes.
 */
add_action( 'rest_api_init', function () {
	$controllers = array(
		'Goo1_MCP_Posts_Controller',
		'Goo1_MCP_Taxonomies_Controller',
		'Goo1_MCP_Media_Controller',
		'Goo1_MCP_Comments_Controller',
		'Goo1_MCP_Menus_Controller',
		'Goo1_MCP_Users_Controller',
		'Goo1_MCP_Options_Controller',
		'Goo1_MCP_Plugins_Controller',
		'Goo1_MCP_Database_Controller',
		'Goo1_MCP_Site_Health_Controller',
		'Goo1_MCP_MCP_Controller',
	);

	if ( class_exists( 'Goo1_MCP_WooCommerce_Controller' ) ) {
		$controllers[] = 'Goo1_MCP_WooCommerce_Controller';
	}

	foreach ( $controllers as $class ) {
		$controller = new $class();
		$controller->register_routes();
	}
} );

/**
 * Audit log on every authenticated response.
 */
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	$route = $request->get_route();
	if ( strpos( $route, '/goo1-mcp/v1' ) === 0 ) {
		// On 401, point clients at the OAuth metadata (RFC 9728 / MCP auth spec).
		if ( 401 === $response->get_status() ) {
			$response->header(
				'WWW-Authenticate',
				sprintf( 'Bearer resource_metadata="%s"', Goo1_MCP_OAuth::protected_resource_url() )
			);
		}

		$key_data = $request->get_param( '_goo1_mcp_key' );
		// Skip the /mcp envelope itself — each tools/call is dispatched
		// internally and audited under its real endpoint.
		if ( $key_data && substr( $route, -4 ) !== '/mcp' ) {
			Goo1_MCP_Audit_Log::instance()->log(
				$key_data['label'],
				$route,
				$request->get_method(),
				$request->get_params(),
				$response->get_status()
			);
		}
	}
	return $response;
}, 10, 3 );


//Auto UPDATER
if (!class_exists("Puc_v4_Factory")) {
	require_once(__DIR__."/plugin-update-checker/plugin-update-checker.php");
}
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    "https://raw.githubusercontent.com/andreaskasper/wordpress_omni/master/distmeta/updater/goo1-mcp.json",
    __FILE__, //Full path to the main plugin file or functions.php.
    'goo1-omni'
);
