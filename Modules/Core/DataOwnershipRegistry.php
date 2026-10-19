<?php
/**
 * Canonical registry of data and filesystem artifacts owned by NBH Performance.
 *
 * Activation, diagnostics, retention and uninstall routines should consume this
 * registry instead of maintaining independent incomplete lists.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

final class DataOwnershipRegistry {
	/** @return array<int,string> */
	public static function table_suffixes() {
		return array(
			'nbh_performance_rum',
			'nbh_performance_rum_rollups',
			'nbh_performance_jobs',
			'nbh_performance_priority_elements',
			'nbh_performance_lab_results',
			'nbh_performance_cache_index',
			'nbh_performance_cache_tags',
			'nbh_browser_jobs',
		);
	}

	/** @return array<int,string> */
	public static function cron_hooks() {
		return array(
			'nbh_performance_preload_tick',
			'nbh_performance_preload_worker',
			'nbh_performance_warm_home',
			'nbh_performance_cleanup_tick',
			'nbh_performance_sitemap_discovery_tick',
			'nbh_performance_critical_css_tick',
			'nbh_performance_browser_jobs_prune',
			'nbh_performance_analytics_proxy_refresh',
			'nbh_performance_google_fonts_refresh',
			'nbh_performance_retire_download_cache',
			'nbh_performance_database_cleanup_tick',
			'nbh_performance_rum_cleanup_tick',
			'nbh_performance_rum_rollup_hourly',
			'nbh_performance_rum_rollup_daily',
			'nbh_performance_intelligence_daily',
			'nbh_performance_intelligence_impact_tick',
			'nbh_performance_job_worker_tick',
			'nbh_performance_job_prune_tick',
			'nbh_performance_job_worker_kick',
			'nbh_performance_priority_elements_prune',
			'nbh_performance_performance_lab_schedule',
			'nbh_performance_performance_lab_prune',
			'nbh_performance_autopilot_evaluate',
			'nbh_performance_server_rules_reconcile',
			'nbh_performance_daily_maintenance',
			'nbh_performance_network_activation_batch',
			'nbh_performance_runtime_watchdog_tick',
			'nbh_performance_post_update_validation_fallback',
			'nbh_performance_restore_latest_update',
			'nbh_performance_cleanup_rollback_work',
		);
	}

	/** @return array<int,string> */
	public static function shared_directories() {
		$paths   = array(
			trailingslashit( WP_CONTENT_DIR ) . 'cache/nbh-performance',
			trailingslashit( WP_CONTENT_DIR ) . 'nbh-performance-private',
			self::private_storage_root(),
		);
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		if ( ! empty( $uploads['basedir'] ) ) {
			$paths[] = trailingslashit( $uploads['basedir'] ) . 'nbh-performance-originals';
		}
		return array_values( array_unique( array_map( 'wp_normalize_path', $paths ) ) );
	}

	/**
	 * Return the non-publicly-guessable artifact vault used for database backups.
	 *
	 * @return string
	 */
	public static function private_storage_root() {
		$salt  = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH );
		$token = substr( hash_hmac( 'sha256', 'nbh-performance-private-v2|' . ABSPATH, (string) $salt ), 0, 20 );
		return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . '.nbh-performance-private-' . $token );
	}

	/** @return array<string,string> File path => ownership marker. */
	public static function dropins() {
		return array(
			trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php' => 'NBH_PERFORMANCE_DROPIN',
			trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php'   => 'NBH_PERFORMANCE_OBJECT_CACHE_DROPIN',
		);
	}

	/** @return array<int,string> */
	public static function loose_files() {
		$files = array(
			trailingslashit( WP_CONTENT_DIR ) . 'nbh-performance-object-cache-config.php',
		);
		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $config ) {
			$files[] = $config . '.nbh-performance-backup';
		}
		return array_values( array_unique( array_map( 'wp_normalize_path', $files ) ) );
	}

	/** @return string */
	public static function option_prefix() {
		return 'nbh_performance_';
	}

	/** @return string */
	public static function postmeta_prefix() {
		return '_nbh_performance_';
	}
}
