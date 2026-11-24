<?php
/**
 * Plugin Name:       Simple CSV Redirects with Cloudflare
 * Plugin URI:        https://github.com/khrlzhfr/simple-csv-redirects-with-cloudflare
 * Description:       Manage redirects as CSV rules in WordPress, and optionally as Cloudflare Single Redirect rules, from Tools → Redirects.
 * Version:           1.1.3
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Khairil Zhafri
 * Author URI:        https://khrlzh.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-csv-redirects-with-cloudflare
 *
 * Cloudflare Rules are optional and configured in wp-config.php (see the Setup tab):
 *
 *   define( 'RFCF_CONFIG', [
 *       'token'   => 'cfut_…',                               // defaults for every site
 *       'domains' => [ 'example.com' ],
 *       3         => [ 'roles' => [ 'administrator' ] ],     // multisite: per-site overrides
 *       5         => false,                                  // multisite: off for this site
 *   ] );
 */

defined( 'ABSPATH' ) || exit;

define( 'RFCF_FILE', __FILE__ );
define( 'RFCF_VERSION', '1.1.3' );

require_once __DIR__ . '/includes/class-rfcf-config.php';
require_once __DIR__ . '/includes/class-rfcf-csv.php';

// Copies installed from GitHub update from GitHub Releases; the WordPress.org build leaves this file out.
if ( file_exists( __DIR__ . '/includes/github-updater.php' ) ) {
	require_once __DIR__ . '/includes/github-updater.php';
}

RFCF_CSV::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-rfcf-api.php';
	require_once __DIR__ . '/includes/list-tables.php';
	require_once __DIR__ . '/includes/class-rfcf-admin.php';
	RFCF_Admin::init();
}
