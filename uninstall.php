<?php
/**
 * Removes the plugin's data when it is deleted: the change log, its caches,
 * and each site's uploads/redirects folder. Cloudflare rules stay in Cloudflare.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Deletes a folder and everything in it. */
function rfcf_uninstall_remove_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/** Cleans up the current site. */
function rfcf_uninstall_site() {
	global $wpdb;

	delete_option( 'rfcf_log' );
	delete_transient( 'rfcf_csv_' . get_current_blog_id() );
	// Cached Cloudflare lookups have hashed names, so remove them by prefix.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rfcf\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rfcf\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$uploads = wp_upload_dir( null, false );
	rfcf_uninstall_remove_dir( untrailingslashit( $uploads['basedir'] ) . '/redirects' );
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $rfcf_site_id ) {
		switch_to_blog( $rfcf_site_id );
		rfcf_uninstall_site();
		restore_current_blog();
	}
} else {
	rfcf_uninstall_site();
}
