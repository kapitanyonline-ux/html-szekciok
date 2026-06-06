<?php
/**
 * Plugin Name: HTML szekciók
 * Description: HTML snippet manager szekciókkal és shortcode-okkal
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * Text Domain: html-szekciok
 * Update URI: https://github.com/kapitanyonline-ux/html-szekciok
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HTML_SZEKCIOK_VERSION',     '1.2.0' );
define( 'HTML_SZEKCIOK_PATH',        plugin_dir_path( __FILE__ ) );
define( 'HTML_SZEKCIOK_URL',         plugin_dir_url( __FILE__ ) );
define( 'HTML_SZEKCIOK_IMPORTS_DIR', HTML_SZEKCIOK_PATH . 'imports/' );

require_once HTML_SZEKCIOK_PATH . 'includes/class-database.php';
require_once HTML_SZEKCIOK_PATH . 'includes/class-content-extractor.php';
require_once HTML_SZEKCIOK_PATH . 'includes/class-shortcodes.php';
require_once HTML_SZEKCIOK_PATH . 'includes/class-import-export.php';
require_once HTML_SZEKCIOK_PATH . 'admin/class-admin.php';

/**
 * GitHub-alapú automatikus frissítés (plugin-update-checker).
 * Publikus repónál nem kell token; privátnál tedd a wp-config.php-ba:
 *   define( 'KAPITANYONLINE_UX_GH_TOKEN', 'ghp_...' );
 */
require_once HTML_SZEKCIOK_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
$html_szekciok_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/kapitanyonline-ux/html-szekciok/',
	__FILE__,
	'html-szekciok'
);
$html_szekciok_update_checker->setBranch( 'main' );
if ( defined( 'KAPITANYONLINE_UX_GH_TOKEN' ) && KAPITANYONLINE_UX_GH_TOKEN ) {
	$html_szekciok_update_checker->setAuthentication( KAPITANYONLINE_UX_GH_TOKEN );
}

register_activation_hook( __FILE__, 'html_szekciok_activate' );

function html_szekciok_activate() {
	HTML_Szekciok_Database::create_tables();
	update_option( 'html_szekciok_db_version', HTML_SZEKCIOK_VERSION );

	if ( ! file_exists( HTML_SZEKCIOK_IMPORTS_DIR ) ) {
		wp_mkdir_p( HTML_SZEKCIOK_IMPORTS_DIR );
	}
	if ( ! file_exists( HTML_SZEKCIOK_IMPORTS_DIR . 'index.php' ) ) {
		file_put_contents( HTML_SZEKCIOK_IMPORTS_DIR . 'index.php', '<?php // Silence is golden' );
	}
	if ( ! file_exists( HTML_SZEKCIOK_IMPORTS_DIR . '.htaccess' ) ) {
		file_put_contents( HTML_SZEKCIOK_IMPORTS_DIR . '.htaccess', "Options -Indexes\n<Files \"*.json\">\nOrder deny,allow\nDeny from all\n</Files>" );
	}
}

/**
 * Automatikus DB séma update meglévő telepítéseknél (új tábla v1.2.0-ban).
 */
function html_szekciok_maybe_upgrade_db() {
	$installed = get_option( 'html_szekciok_db_version', '0.0.0' );
	if ( version_compare( $installed, HTML_SZEKCIOK_VERSION, '<' ) ) {
		HTML_Szekciok_Database::create_tables();
		update_option( 'html_szekciok_db_version', HTML_SZEKCIOK_VERSION );
	}
}
add_action( 'plugins_loaded', 'html_szekciok_maybe_upgrade_db', 5 );

function html_szekciok_generate_slug( $name ) {
	$name  = mb_strtolower( $name, 'UTF-8' );
	$chars = [
		'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o',
		'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', ' ' => '_',
	];
	$name = strtr( $name, $chars );
	$name = preg_replace( '/[^a-z0-9_]/', '', $name );
	$name = preg_replace( '/_+/', '_', $name );
	return trim( $name, '_' );
}

add_action( 'plugins_loaded', function () {
	HTML_Szekciok_Shortcodes::init();
	HTML_Szekciok_Admin::init();
} );
