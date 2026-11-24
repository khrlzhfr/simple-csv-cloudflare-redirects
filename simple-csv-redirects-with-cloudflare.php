<?php
/**
 * Plugin Name:       khrlzhfr Simple CSV Redirects with Cloudflare
 * Plugin URI:        https://github.com/khrlzhfr/simple-csv-redirects-with-cloudflare
 * Description:       Manage redirects as CSV rules in WordPress, and optionally as Cloudflare Single Redirect rules, from Tools → Redirects.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Khairil Zhafri
 * Author URI:        https://khrlzh.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       khrlzhfr-simple-csv-redirects-with-cloudflare
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

// Another copy of this plugin is already running (for example one installed under its earlier folder name): stay inactive and say so on the Plugins screen.
if ( defined( 'RFCF_FILE' ) ) {
	add_action(
		'admin_notices',
		static function () {
			global $pagenow;
			if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: plugin name, 2: folder of the copy that is running */
						__( '%1$s is installed twice. The copy in the %2$s folder is running. Deactivate and delete the other copy; its settings and data are kept.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ),
						'khrlzhfr Simple CSV Redirects with Cloudflare',
						dirname( plugin_basename( RFCF_FILE ) )
					)
				)
			);
		}
	);
	return;
}

define( 'RFCF_FILE', __FILE__ );
define( 'RFCF_VERSION', '1.2.0' );

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
