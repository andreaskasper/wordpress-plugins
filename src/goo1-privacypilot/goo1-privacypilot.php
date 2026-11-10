<?php
/**
 * Plugin Name: PrivacyPilot
 * Plugin URI:  https://github.com/andreaskasper/wordpress-plugins
 * Description: Privacy policy, cookie policy, terms and imprint generated from a questionnaire and a centrally maintained clause library, plus a consent banner with script blocking, Google Consent Mode v2, GPC support, a request form for privacy rights and Site Health checks. US and EU/UK, English and German.
 * Version:     0.9.261109
 * Author:      Andreas Kasper <andreas.kasper@goo1.de>
 * Author URI:  https://goo1.de
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: goo1-privacypilot
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GOO1_PP_VERSION', '0.9.261109' );
define( 'GOO1_PP_FILE', __FILE__ );
define( 'GOO1_PP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOO1_PP_URL', plugin_dir_url( __FILE__ ) );
define( 'GOO1_PP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Asset version: plugin version plus file time, so a CDN (Cloudflare caches
 * .js/.css by URL) never serves an old file after an update.
 */
function goo1_pp_asset_ver( $rel ) {
	$file = GOO1_PP_DIR . $rel;
	return GOO1_PP_VERSION . '.' . ( is_readable( $file ) ? filemtime( $file ) : 0 );
}
define( 'GOO1_PP_DB_VERSION', 1 );

require_once GOO1_PP_DIR . 'includes/class-settings.php';
require_once GOO1_PP_DIR . 'includes/class-activator.php';
require_once GOO1_PP_DIR . 'includes/class-signature.php';
require_once GOO1_PP_DIR . 'includes/class-library.php';
require_once GOO1_PP_DIR . 'includes/class-conditions.php';
require_once GOO1_PP_DIR . 'includes/class-markdown.php';
require_once GOO1_PP_DIR . 'includes/class-questionnaire.php';
require_once GOO1_PP_DIR . 'includes/class-hub.php';
require_once GOO1_PP_DIR . 'includes/class-generator.php';
require_once GOO1_PP_DIR . 'includes/class-versions.php';
require_once GOO1_PP_DIR . 'includes/class-publisher.php';
require_once GOO1_PP_DIR . 'includes/class-documents.php';
require_once GOO1_PP_DIR . 'includes/class-consent.php';
require_once GOO1_PP_DIR . 'includes/class-blocker.php';
require_once GOO1_PP_DIR . 'includes/class-scanner.php';
require_once GOO1_PP_DIR . 'includes/class-requests.php';
require_once GOO1_PP_DIR . 'includes/class-migration.php';
require_once GOO1_PP_DIR . 'includes/class-site-health.php';
require_once GOO1_PP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Goo1_PP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Goo1_PP_Activator', 'deactivate' ) );

Goo1_PP_Plugin::instance()->boot();

if ( is_admin() ) {
	require_once GOO1_PP_DIR . 'admin/class-admin.php';
	Goo1_PP_Admin::instance()->boot();
}

// Auto-updater (GitHub-hosted, Plugin Update Checker v5). The library is
// vendored into the release ZIP by the workflow and is not part of the source
// tree, so every step is guarded: a missing library must never break the site.
// v5 is namespaced and therefore does not collide with the v4 copies that
// other goo1 plugins (goo1-omni, goo1-mcp) bring along.
$goo1_pp_puc = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
if ( is_readable( $goo1_pp_puc ) ) {
	require_once $goo1_pp_puc;
}
if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://raw.githubusercontent.com/andreaskasper/wordpress-plugins/main/distmeta/updater/goo1-privacypilot.json',
		__FILE__,
		'goo1-privacypilot'
	);
}
