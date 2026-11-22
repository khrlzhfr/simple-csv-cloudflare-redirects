<?php
/**
 * Updates from GitHub Releases, for copies installed from GitHub.
 *
 * The WordPress.org build leaves this file and lib/ out; those copies update
 * through WordPress.org instead.
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib/plugin-update-checker/plugin-update-checker.php';

$rfcf_updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/khrlzhfr/simple-csv-cloudflare-redirects/',
	RFCF_FILE,
	'simple-csv-cloudflare-redirects'
);
$rfcf_updates->getVcsApi()->enableReleaseAssets( '/simple-csv-cloudflare-redirects\.zip$/' );
