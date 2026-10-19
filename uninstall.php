<?php
/**
 * NBH Performance uninstall routine.
 *
 * Data is retained by default. Destructive removal requires both an explicit
 * wp-config.php opt-in and an administrator-selected uninstall policy.
 *
 * @package NBHPerformance
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Uninstalling a plugin must always detach its active drop-ins and scheduled
// workers, even when the owner chooses to retain generated data and settings.
// Otherwise WordPress may keep executing a retired object-cache/page-cache
// implementation after its owning plugin has been removed.
require_once __DIR__ . '/includes/autoload.php';

foreach ( \NBH\Performance\Modules\Core\DataOwnershipRegistry::cron_hooks() as $nbh_performance_uninstall_hook ) {
	wp_clear_scheduled_hook( $nbh_performance_uninstall_hook );
}

$nbh_performance_object_cache_owned = false;
foreach ( \NBH\Performance\Modules\Core\DataOwnershipRegistry::dropins() as $nbh_performance_uninstall_dropin => $nbh_performance_uninstall_marker ) {
	if ( ! is_file( $nbh_performance_uninstall_dropin ) || is_link( $nbh_performance_uninstall_dropin ) || ! is_readable( $nbh_performance_uninstall_dropin ) ) {
		continue;
	}
	// Only remove a drop-in that contains this plugin's unique ownership marker.
	$nbh_performance_uninstall_head = file_get_contents( $nbh_performance_uninstall_dropin, false, null, 0, 4096 );
	if ( false === $nbh_performance_uninstall_head || false === strpos( $nbh_performance_uninstall_head, $nbh_performance_uninstall_marker ) ) {
		continue;
	}
	if ( 'object-cache.php' === basename( $nbh_performance_uninstall_dropin ) ) {
		$nbh_performance_object_cache_owned = true;
	}
	wp_delete_file( $nbh_performance_uninstall_dropin );
}
if ( $nbh_performance_object_cache_owned ) {
	$nbh_performance_uninstall_object_config = trailingslashit( WP_CONTENT_DIR ) . 'nbh-performance-object-cache-config.php';
	if ( is_file( $nbh_performance_uninstall_object_config ) && ! is_link( $nbh_performance_uninstall_object_config ) ) {
		wp_delete_file( $nbh_performance_uninstall_object_config );
	}
}

// Revert only the narrowly-scoped server rules owned by this plugin.
nbh_performance_uninstall_remove_htaccess_block();

if ( ! defined( 'NBH_PERFORMANCE_ALLOW_UNINSTALL_DATA_PURGE' ) || true !== NBH_PERFORMANCE_ALLOW_UNINSTALL_DATA_PURGE ) {
	return;
}

// Capture policy before deleting any option. These values must survive the
// settings cleanup so the administrator's choices are respected.
$nbh_performance_uninstall_policy         = (string) get_option( 'nbh_performance_uninstall_data_policy', 'retain' );
$nbh_performance_uninstall_remove_cache   = (bool) get_option( 'nbh_performance_uninstall_remove_cache', true );
$nbh_performance_uninstall_remove_dropins = (bool) get_option( 'nbh_performance_uninstall_remove_dropin', true );

if ( ! in_array( $nbh_performance_uninstall_policy, array( 'retain', 'delete_settings', 'delete_all' ), true ) ) {
	$nbh_performance_uninstall_policy = 'retain';
}
if ( 'retain' === $nbh_performance_uninstall_policy ) {
	return;
}

require_once __DIR__ . '/includes/autoload.php';

use NBH\Performance\Modules\Core\DataOwnershipRegistry;
use NBH\Performance\Modules\ImageOptimization\ImageBackupManager;
use NBH\Performance\Modules\ImageOptimization\WebpAvifConverter;

/**
 * Remove a directory only when it exactly matches an approved NBH-owned root.
 *
 * @param string $directory Directory path.
 * @param array  $allowed   Approved roots.
 * @return void
 */
function nbh_performance_uninstall_remove_directory( $nbh_performance_directory, array $nbh_performance_allowed ) {
	$nbh_performance_path  = untrailingslashit( wp_normalize_path( $nbh_performance_directory ) );
	$nbh_performance_roots = array_map(
		static function ( $nbh_performance_root ) {
			return untrailingslashit( wp_normalize_path( $nbh_performance_root ) );
		},
		$nbh_performance_allowed
	);
	if ( ! in_array( $nbh_performance_path, $nbh_performance_roots, true ) || ! is_dir( $nbh_performance_path ) ) {
		return;
	}

	$nbh_performance_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $nbh_performance_path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $nbh_performance_items as $nbh_performance_item ) {
		$nbh_performance_item_path = wp_normalize_path( $nbh_performance_item->getPathname() );
		if ( 0 !== strpos( trailingslashit( $nbh_performance_item_path ), trailingslashit( $nbh_performance_path ) ) ) {
			continue;
		}
		if ( $nbh_performance_item->isLink() ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@\NBH\Performance\Modules\Core\Filesystem::unlink( $nbh_performance_item_path );
		} elseif ( $nbh_performance_item->isDir() ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@\NBH\Performance\Modules\Core\Filesystem::rmdir( $nbh_performance_item_path );
		} else {
			wp_delete_file( $nbh_performance_item_path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@\NBH\Performance\Modules\Core\Filesystem::rmdir( $nbh_performance_path );
}

/** Remove only NBH Performance's bounded block from the root .htaccess file. */
function nbh_performance_uninstall_remove_htaccess_block() {
	$nbh_performance_path = trailingslashit( ABSPATH ) . '.htaccess';
	if ( ! is_readable( $nbh_performance_path ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $nbh_performance_path ) ) {
		return;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$nbh_performance_before = file_get_contents( $nbh_performance_path );
	if ( false === $nbh_performance_before ) {
		return;
	}
	$nbh_performance_begin   = preg_quote( '# BEGIN NBH Performance', '/' );
	$nbh_performance_end     = preg_quote( '# END NBH Performance', '/' );
	$nbh_performance_pattern = '/^[ \t]*' . $nbh_performance_begin . '[^\r\n]*\R.*?^[ \t]*' . $nbh_performance_end . '[^\r\n]*(?:\R|$)/ms';
	if ( 1 !== preg_match_all( $nbh_performance_pattern, $nbh_performance_before ) ) {
		return;
	}
	$nbh_performance_after = preg_replace( $nbh_performance_pattern, '', $nbh_performance_before, 1 );
	if ( ! is_string( $nbh_performance_after ) || $nbh_performance_after === $nbh_performance_before ) {
		return;
	}
	$nbh_performance_temp = wp_tempnam( 'nbh-performance-uninstall-htaccess', dirname( $nbh_performance_path ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( ! $nbh_performance_temp || false === file_put_contents( $nbh_performance_temp, $nbh_performance_after, LOCK_EX ) ) {
		return;
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@\NBH\Performance\Modules\Core\Filesystem::chmod( $nbh_performance_temp, fileperms( $nbh_performance_path ) & 0777 );
	// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Restore only the explicitly owned wp-config.php backup during opted-in uninstall; this is an existing site configuration artifact, not generated user content.
	if ( ! @rename( $nbh_performance_temp, $nbh_performance_path ) ) {
		wp_delete_file( $nbh_performance_temp );
	}
}

/** Restore source images and remove generated image artifacts before metadata deletion. */
function nbh_performance_uninstall_restore_images() {
	global $wpdb;
	$nbh_performance_meta_keys    = array( '_nbh_performance_image_backup_v1', '_nbh_performance_image_variants_v2' );
	$nbh_performance_placeholders = implode( ',', array_fill( 0, count( $nbh_performance_meta_keys ), '%s' ) );
	$nbh_performance_sql          = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$nbh_performance_placeholders})";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$nbh_performance_attachment_ids = $wpdb->get_col( $wpdb->prepare( $nbh_performance_sql, $nbh_performance_meta_keys ) );
	$nbh_performance_backups        = new ImageBackupManager();
	$nbh_performance_variants       = new WebpAvifConverter();
	foreach ( array_map( 'absint', (array) $nbh_performance_attachment_ids ) as $nbh_performance_attachment_id ) {
		if ( $nbh_performance_backups->has_backup( $nbh_performance_attachment_id ) ) {
			$nbh_performance_backups->restore( $nbh_performance_attachment_id );
			$nbh_performance_backups->delete_backup( $nbh_performance_attachment_id );
		}
		$nbh_performance_variants->delete_attachment_variants( $nbh_performance_attachment_id );
	}
}

/** Delete NBH-owned database data for the currently selected site. */
function nbh_performance_uninstall_clean_current_site( $nbh_performance_delete_all ) {
	global $wpdb;

	foreach ( DataOwnershipRegistry::cron_hooks() as $nbh_performance_hook ) {
		wp_clear_scheduled_hook( $nbh_performance_hook );
	}

	if ( $nbh_performance_delete_all ) {
		nbh_performance_uninstall_restore_images();
		$nbh_performance_uploads = wp_get_upload_dir();
		if ( ! empty( $nbh_performance_uploads['basedir'] ) ) {
			$nbh_performance_image_root = trailingslashit( $nbh_performance_uploads['basedir'] ) . 'nbh-performance-originals';
			nbh_performance_uninstall_remove_directory( $nbh_performance_image_root, array( $nbh_performance_image_root ) );
		}
		foreach ( DataOwnershipRegistry::table_suffixes() as $nbh_performance_suffix ) {
			$nbh_performance_table = $wpdb->prefix . $nbh_performance_suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Explicitly opted-in uninstall removes only registry-allowlisted owned tables; this mutation has no cacheable result.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $nbh_performance_table ) );
		}
		$nbh_performance_meta_like = $wpdb->esc_like( DataOwnershipRegistry::postmeta_prefix() ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $nbh_performance_meta_like ) );
	}

	$nbh_performance_option_like    = $wpdb->esc_like( DataOwnershipRegistry::option_prefix() ) . '%';
	$nbh_performance_transient_like = $wpdb->esc_like( '_transient_' . DataOwnershipRegistry::option_prefix() ) . '%';
	$nbh_performance_timeout_like   = $wpdb->esc_like( '_transient_timeout_' . DataOwnershipRegistry::option_prefix() ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $nbh_performance_option_like, $nbh_performance_transient_like, $nbh_performance_timeout_like ) );
}

$nbh_performance_delete_all = 'delete_all' === $nbh_performance_uninstall_policy;
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$nbh_performance_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( (array) $nbh_performance_site_ids as $nbh_performance_site_id ) {
		switch_to_blog( absint( $nbh_performance_site_id ) );
		nbh_performance_uninstall_clean_current_site( $nbh_performance_delete_all );
		restore_current_blog();
	}
} else {
	nbh_performance_uninstall_clean_current_site( $nbh_performance_delete_all );
}

// Network options and site transients are stored in sitemeta.
if ( is_multisite() ) {
	global $wpdb;
	$nbh_performance_site_option_like    = $wpdb->esc_like( DataOwnershipRegistry::option_prefix() ) . '%';
	$nbh_performance_site_transient_like = $wpdb->esc_like( '_site_transient_' . DataOwnershipRegistry::option_prefix() ) . '%';
	$nbh_performance_site_timeout_like   = $wpdb->esc_like( '_site_transient_timeout_' . DataOwnershipRegistry::option_prefix() ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s", $nbh_performance_site_option_like, $nbh_performance_site_transient_like, $nbh_performance_site_timeout_like ) );
}

if ( $nbh_performance_delete_all ) {
	$nbh_performance_owned_roots = DataOwnershipRegistry::shared_directories();
	foreach ( $nbh_performance_owned_roots as $nbh_performance_root ) {
		$nbh_performance_is_cache = untrailingslashit( wp_normalize_path( $nbh_performance_root ) ) === untrailingslashit( wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'cache/nbh-performance' ) );
		if ( ! $nbh_performance_is_cache || $nbh_performance_uninstall_remove_cache ) {
			nbh_performance_uninstall_remove_directory( $nbh_performance_root, $nbh_performance_owned_roots );
		}
	}

	if ( $nbh_performance_uninstall_remove_dropins ) {
		foreach ( DataOwnershipRegistry::dropins() as $nbh_performance_dropin => $nbh_performance_marker ) {
			if ( ! is_readable( $nbh_performance_dropin ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$nbh_performance_head = file_get_contents( $nbh_performance_dropin, false, null, 0, 4096 );
			if ( false !== $nbh_performance_head && false !== strpos( $nbh_performance_head, $nbh_performance_marker ) ) {
				wp_delete_file( $nbh_performance_dropin );
			}
		}
		$nbh_performance_config = trailingslashit( WP_CONTENT_DIR ) . 'nbh-performance-object-cache-config.php';
		if ( is_file( $nbh_performance_config ) ) {
			wp_delete_file( $nbh_performance_config );
		}
	}

	// wp-config backups contain secrets and are always removed with delete_all,
	// even when the administrator elects to preserve active drop-ins.
	foreach ( DataOwnershipRegistry::loose_files() as $nbh_performance_file ) {
		if ( '.nbh-performance-backup' === substr( $nbh_performance_file, -strlen( '.nbh-performance-backup' ) ) && is_file( $nbh_performance_file ) ) {
			wp_delete_file( $nbh_performance_file );
		}
	}

	nbh_performance_uninstall_remove_htaccess_block();
}
