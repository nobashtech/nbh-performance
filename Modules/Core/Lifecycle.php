<?php
/**
 * Activation and deactivation lifecycle.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

use NBH\Performance\Modules\CacheEngine\DropInInstaller;
use NBH\Performance\Modules\CacheEngine\CacheIndex;
use NBH\Performance\Modules\Rum\RumStorage;
use NBH\Performance\Modules\Rum\RumRollupStorage;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;
use NBH\Performance\Modules\PerformanceIntelligence\LabTestStorage;

final class Lifecycle {
	const SETTINGS_SCHEMA_VERSION = 2026071701;

	public static function maybe_upgrade() {
		FreeReleaseMigration::run();
		$installed        = (string) get_option( 'nbh_performance_version', '0.0.0' );
		$installed_schema = absint( get_option( 'nbh_performance_settings_schema_version', 0 ) );

		// Always merge missing defaults by schema. Existing values are never overwritten.
		self::ensure_persistent_defaults();
		if ( absint( get_option( 'nbh_performance_cache_index_schema_version', 0 ) ) < CacheIndex::SCHEMA_VERSION ) {
			CacheIndex::install();
		}

		// Onboarding: defaults seed completed=0. Do not auto-mark completed when the
		// option is missing — that broke explicit resets and re-activation testing.
		// Legacy sites can skip once from the Hub wizard.

		if ( absint( get_option( 'nbh_performance_job_schema_version', 0 ) ) < JobStore::SCHEMA_VERSION ) {
			JobStore::install();
		}
		if ( absint( get_option( 'nbh_performance_priority_elements_schema_version', 0 ) ) < PriorityElementsStorage::SCHEMA_VERSION ) {
			PriorityElementsStorage::install();
		}
		if ( absint( get_option( 'nbh_performance_lab_results_schema_version', 0 ) ) < LabTestStorage::SCHEMA_VERSION ) {
			LabTestStorage::install();
		}
		if ( absint( get_option( 'nbh_performance_rum_rollup_schema_version', 0 ) ) < RumRollupStorage::SCHEMA_VERSION ) {
			RumRollupStorage::install();
		}

		if ( $installed_schema < self::SETTINGS_SCHEMA_VERSION ) {
			update_option( 'nbh_performance_settings_schema_version', self::SETTINGS_SCHEMA_VERSION, false );
			do_action( 'nbh_performance_settings_schema_upgraded', $installed_schema, self::SETTINGS_SCHEMA_VERSION );
		}

		if ( version_compare( $installed, NBH_PERFORMANCE_VERSION, '>=' ) ) {
			return;
		}

		add_option( 'nbh_performance_rum_enabled', 0 );
		add_option( 'nbh_performance_rum_sample_rate', 10 );
		add_option( 'nbh_performance_rum_retention_days', 90 );
		add_option( 'nbh_performance_safe_mode_snapshot', array() );
		add_option( 'nbh_performance_last_readiness_scan', array() );
		add_option( 'nbh_performance_uninstall_data_policy', 'retain' );
		add_option( 'nbh_performance_uninstall_remove_cache', 1 );
		add_option( 'nbh_performance_uninstall_remove_dropin', 1 );
		add_option( 'nbh_performance_browser_cache_ttl', MONTH_IN_SECONDS );
		add_option( 'nbh_performance_server_rules_enabled', 0 );
		add_option( 'nbh_performance_server_rules_status', array() );
		add_option( 'nbh_performance_server_rules_checked_at', 0 );
		add_option( 'nbh_performance_job_worker_batch_size', 5 );
		add_option( 'nbh_performance_job_queue_paused', 0 );
		add_option( 'nbh_performance_job_worker_last_run', 0 );
		add_option( 'nbh_performance_job_worker_last_heartbeat', 0 );
		add_option( 'nbh_performance_job_worker_time_budget', 20 );
		add_option( 'nbh_performance_job_worker_memory_reserve_mb', 16 );
		add_option( 'nbh_performance_runtime_reliability', array() );
		add_option( 'nbh_performance_runtime_degraded', array() );
		add_option( 'nbh_performance_update_auto_rollback_enabled', 0 );
		add_option( 'nbh_performance_update_validation_delay', 30 );
		add_option( 'nbh_performance_staging_auto_validation', 1 );
		add_option( 'nbh_performance_auto_rollback_on_validation_failure', 1 );
		add_option( 'nbh_performance_allow_production_auto_rollback', 0 );
		add_option( 'nbh_performance_force_staging_validation', 0 );
		add_option( 'nbh_performance_external_cron_configured', 0 );
		add_option( 'nbh_performance_validation_urls', '' );
		add_option( 'nbh_performance_configuration_transactions', array() );
		add_option(
			'nbh_performance_per_page_runtime_rules',
			array(
				'excluded_paths'                => array(),
				'mobile_variant_disabled_paths' => array(),
				'ttl_overrides'                 => array(),
				'updated_at'                    => 0,
			)
		);
		add_option( 'nbh_performance_last_staging_validation', array() );
		add_option( 'nbh_performance_compatibility_matrix', array() );
		add_option( 'nbh_performance_compatibility_matrix_checked_at', 0 );
		RumStorage::install();
		RumRollupStorage::install();
		JobStore::install();
		PriorityElementsStorage::install();
		LabTestStorage::install();
		update_option( 'nbh_performance_version', NBH_PERFORMANCE_VERSION, false );
		do_action( 'nbh_performance_upgraded', $installed, NBH_PERFORMANCE_VERSION );
	}

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			update_site_option(
				'nbh_performance_network_activation_pending',
				array(
					'last_blog_id' => 0,
					'started_at'   => time(),
					'updated_at'   => time(),
				)
			);
			self::activate_site();
			self::schedule_network_activation_batch();
			return;
		}
		self::activate_site();
	}

	private static function activate_site() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( NBH_PERFORMANCE_BASENAME );
			wp_die( esc_html__( 'NBH Performance requires PHP 7.4 or newer.', 'nbh-performance' ) );
		}

		self::ensure_persistent_defaults();
		CacheIndex::install();

		$installer = new DropInInstaller();
		$installer->install();

		update_option( 'nbh_performance_version', NBH_PERFORMANCE_VERSION, false );
		update_option( 'nbh_performance_settings_schema_version', self::SETTINGS_SCHEMA_VERSION, false );
		add_option( 'nbh_performance_safe_mode', 1 );
		add_option( 'nbh_performance_cache_enabled', 0 );
		add_option( 'nbh_performance_cache_ttl', HOUR_IN_SECONDS );
		add_option( 'nbh_performance_cache_stale_while_revalidate', 300 );
		add_option( 'nbh_performance_cache_stale_if_error', DAY_IN_SECONDS );
		add_option( 'nbh_performance_cache_gzip_enabled', 1 );
		add_option( 'nbh_performance_cache_brotli_enabled', 1 );
		add_option( 'nbh_performance_cache_lock_timeout', 45 );
		add_option( 'nbh_performance_cache_verify_integrity', 0 );
		add_option( 'nbh_performance_cache_404_enabled', 0 );
		add_option( 'nbh_performance_cache_redirects_enabled', 0 );
		add_option( 'nbh_performance_cache_allowed_query_args', '' );
		add_option( 'nbh_performance_cache_vary_cookies', '' );
		add_option( 'nbh_performance_language_cache_variant', 0 );
		add_option( 'nbh_performance_language_variant_cookies', '' );
		add_option( 'nbh_performance_cache_max_html_mb', 8 );
		add_option( 'nbh_performance_cache_min_html_bytes', 256 );
		add_option( 'nbh_performance_cache_min_free_disk_mb', 128 );
		add_option( 'nbh_performance_mobile_cache_enabled', 0 );
		add_option( 'nbh_performance_logged_in_cache_enabled', 0 );
		add_option( 'nbh_performance_debug_headers', 0 );
		add_option( 'nbh_performance_preload_queue', array() );
		add_option( 'nbh_performance_sitemap_preload_enabled', 1 );
		add_option( 'nbh_performance_preload_batch_size', 3 );
		add_option( 'nbh_performance_preload_max_urls', 500 );
		add_option( 'nbh_performance_preload_request_timeout', 12 );
		add_option( 'nbh_performance_preload_retry_limit', 3 );
		add_option( 'nbh_performance_preload_excluded_urls', '' );
		add_option( 'nbh_performance_preload_paused', 0 );
		add_option( 'nbh_performance_preload_mobile_variant', 0 );
		add_option( 'nbh_performance_preload_slow_request_ms', 5000 );
		add_option( 'nbh_performance_preload_run', array() );
		add_option( 'nbh_performance_preload_history', array() );
		add_option( 'nbh_performance_preload_recent_results', array() );
		add_option( 'nbh_performance_resource_priority_enabled', 0 );
		add_option( 'nbh_performance_font_preload_enabled', 0 );
		add_option( 'nbh_performance_lcp_preload_limit', 1 );
		add_option( 'nbh_performance_font_preload_limit', 2 );
		add_option( 'nbh_performance_priority_preload_urls', '' );
		add_option( 'nbh_performance_font_preload_urls', '' );
		add_option(
			'nbh_performance_cache_stats',
			array(
				'files'      => 0,
				'bytes'      => 0,
				'updated_at' => 0,
			)
		);
		add_option( 'nbh_performance_css_minify_enabled', 0 );
		add_option( 'nbh_performance_js_minify_enabled', 0 );
		add_option( 'nbh_performance_combine_assets_enabled', 0 );
		add_option( 'nbh_performance_asset_failures', array() );
		add_option( 'nbh_performance_defer_js_enabled', 0 );
		add_option( 'nbh_performance_delay_js_enabled', 0 );
		add_option( 'nbh_performance_async_js_handles', array() );
		add_option( 'nbh_performance_delay_js_handles', array() );
		add_option( 'nbh_performance_critical_css_enabled', 0 );
		add_option( 'nbh_performance_async_css_enabled', 0 );
		add_option( 'nbh_performance_critical_css_index', array() );
		add_option( 'nbh_performance_critical_css_queue', array() );
		add_option( 'nbh_performance_unused_css_enabled', 0 );
		add_option( 'nbh_performance_priority_elements_sample_rate', 20 );
		add_option( 'nbh_performance_priority_elements_retention_days', 30 );
		add_option( 'nbh_performance_automatic_preconnect_enabled', 0 );
		add_option( 'nbh_performance_preconnect_limit', 3 );
		add_option( 'nbh_performance_visual_regression_guard_enabled', 1 );
		add_option( 'nbh_performance_visual_difference_threshold', 5 );
		add_option( 'nbh_performance_visual_missing_nodes_limit', 0 );
		add_option( 'nbh_performance_visual_node_delta_limit', 10 );
		add_option( 'nbh_performance_visual_regression_failures', array() );
		add_option( 'nbh_performance_browser_css_status', array() );
		add_option( 'nbh_performance_browser_css_ttl', 30 * DAY_IN_SECONDS );
		add_option( 'nbh_performance_image_conversion_enabled', 0 );
		add_option( 'nbh_performance_nextgen_image_serving_enabled', 0 );
		add_option( 'nbh_performance_generate_webp', 1 );
		add_option( 'nbh_performance_generate_avif', 1 );
		add_option( 'nbh_performance_convert_on_upload', 1 );
		add_option( 'nbh_performance_webp_quality', 82 );
		add_option( 'nbh_performance_avif_quality', 70 );
		add_option( 'nbh_performance_image_batch_size', 10 );
		add_option( 'nbh_performance_image_optimization_profile', 'balanced' );
		add_option( 'nbh_performance_image_minimum_saving_percent', 1 );
		add_option( 'nbh_performance_lcp_image_optimization_enabled', 1 );
		add_option( 'nbh_performance_image_batch_state_v2', array() );
		add_option( 'nbh_performance_image_max_source_mb', 25 );
		add_option( 'nbh_performance_image_conversion_exclusions', '' );
		add_option( 'nbh_performance_image_last_batch', array() );
		add_option( 'nbh_performance_lazy_load_enabled', 0 );
		add_option( 'nbh_performance_responsive_images_enabled', 0 );
		add_option( 'nbh_performance_youtube_facade_enabled', 0 );
		add_option( 'nbh_performance_youtube_autoload_after_consent', 0 );
		add_option( 'nbh_performance_chat_facade_enabled', 0 );
		add_option( 'nbh_performance_consent_mode_enabled', 0 );
		add_option( 'nbh_performance_database_cleanup_enabled', 0 );
		add_option( 'nbh_performance_database_last_cleanup', array() );
		add_option( 'nbh_performance_database_backups_v2', array() );
		add_option( 'nbh_performance_database_cleanup_journal_v2', array() );
		add_option( 'nbh_performance_database_backup_max_age', DAY_IN_SECONDS );
		add_option( 'nbh_performance_database_backup_excluded_tables', '' );
		add_option( 'nbh_performance_database_batch_size', 250 );
		add_option( 'nbh_performance_revision_retention_days', 30 );
		add_option( 'nbh_performance_action_scheduler_retention_days', 30 );
		add_option( 'nbh_performance_edge_provider', '' );
		add_option( 'nbh_performance_edge_auto_purge_enabled', 0 );
		add_option( 'nbh_performance_edge_cache_headers_enabled', 0 );
		add_option( 'nbh_performance_edge_cache_ttl', HOUR_IN_SECONDS );
		add_option( 'nbh_performance_cdn_rewrite_enabled', 0 );
		add_option( 'nbh_performance_cdn_base_url', '' );
		add_option( 'nbh_performance_resource_hints_enabled', 0 );
		add_option( 'nbh_performance_cloudflare_zone_id', '' );
		add_option( 'nbh_performance_cloudflare_api_token', '' );
		add_option( 'nbh_performance_bunny_pull_zone_id', 0 );
		add_option( 'nbh_performance_bunny_api_key', '' );
		add_option( 'nbh_performance_keycdn_zone_id', 0 );
		add_option( 'nbh_performance_keycdn_api_key', '' );
		add_option( 'nbh_performance_heartbeat_control_enabled', 0 );
		add_option( 'nbh_performance_heartbeat_frontend_mode', 'reduce' );
		add_option( 'nbh_performance_heartbeat_frontend_interval', 120 );
		add_option( 'nbh_performance_heartbeat_admin_mode', 'reduce' );
		add_option( 'nbh_performance_heartbeat_admin_interval', 60 );
		add_option( 'nbh_performance_heartbeat_editor_mode', 'reduce' );
		add_option( 'nbh_performance_heartbeat_editor_interval', 30 );
		add_option( 'nbh_performance_rum_enabled', 0 );
		add_option( 'nbh_performance_rum_sample_rate', 10 );
		add_option( 'nbh_performance_rum_retention_days', 90 );
		add_option( 'nbh_performance_safe_mode_snapshot', array() );
		add_option( 'nbh_performance_activated_at', time() );
		add_option( 'nbh_performance_last_readiness_scan', array() );
		add_option( 'nbh_performance_uninstall_data_policy', 'retain' );
		add_option( 'nbh_performance_uninstall_remove_cache', 1 );
		add_option( 'nbh_performance_uninstall_remove_dropin', 1 );

		RumStorage::install();
		RumRollupStorage::install();
		JobStore::install();
		PriorityElementsStorage::install();
		LabTestStorage::install();

		wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/pages' );
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/assets/css' );
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/assets/js' );
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/critical-css' );
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/third-party/analytics' );
		wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/third-party/fonts' );
	}


	/**
	 * Add every known persistent option without changing an existing value.
	 *
	 * This runs on activation and on every boot-time schema check so ZIP
	 * replacement, manual updates, and same-version development builds retain
	 * the previous configuration while receiving newly introduced settings.
	 *
	 * @return void
	 */
	private static function ensure_persistent_defaults() {
		$defaults = array(
			'nbh_performance_safe_mode'                    => 1,
			'nbh_performance_cache_enabled'                => 0,
			'nbh_performance_cache_ttl'                    => HOUR_IN_SECONDS,
			'nbh_performance_cache_stale_while_revalidate' => 300,
			'nbh_performance_cache_stale_if_error'         => DAY_IN_SECONDS,
			'nbh_performance_cache_gzip_enabled'           => 1,
			'nbh_performance_cache_brotli_enabled'         => 1,
			'nbh_performance_cache_lock_timeout'           => 45,
			'nbh_performance_cache_verify_integrity'       => 0,
			'nbh_performance_cache_404_enabled'            => 0,
			'nbh_performance_cache_redirects_enabled'      => 0,
			'nbh_performance_cache_allowed_query_args'     => '',
			'nbh_performance_cache_vary_cookies'           => '',
			'nbh_performance_language_cache_variant'       => 0,
			'nbh_performance_language_variant_cookies'     => '',
			'nbh_performance_cache_max_html_mb'            => 8,
			'nbh_performance_cache_min_html_bytes'         => 256,
			'nbh_performance_cache_min_free_disk_mb'       => 128,
			'nbh_performance_last_cache_maintenance'       => array(),
			'nbh_performance_mobile_cache_enabled'         => 0,
			'nbh_performance_logged_in_cache_enabled'      => 0,
			'nbh_performance_debug_headers'                => 0,
			'nbh_performance_cache_index'                  => array(),
			'nbh_performance_preload_queue'                => array(),
			'nbh_performance_sitemap_preload_enabled'      => 1,
			'nbh_performance_preload_batch_size'           => 3,
			'nbh_performance_preload_max_urls'             => 500,
			'nbh_performance_preload_request_timeout'      => 12,
			'nbh_performance_preload_retry_limit'          => 3,
			'nbh_performance_preload_excluded_urls'        => '',
			'nbh_performance_preload_paused'               => 0,
			'nbh_performance_preload_mobile_variant'       => 0,
			'nbh_performance_preload_slow_request_ms'      => 5000,
			'nbh_performance_preload_run'                  => array(),
			'nbh_performance_preload_history'              => array(),
			'nbh_performance_preload_recent_results'       => array(),
			'nbh_performance_resource_priority_enabled'    => 0,
			'nbh_performance_font_preload_enabled'         => 0,
			'nbh_performance_lcp_preload_limit'            => 1,
			'nbh_performance_font_preload_limit'           => 2,
			'nbh_performance_priority_preload_urls'        => '',
			'nbh_performance_font_preload_urls'            => '',
			'nbh_performance_cache_stats'                  => array(
				'files'      => 0,
				'bytes'      => 0,
				'updated_at' => 0,
			),
			'nbh_performance_css_minify_enabled'           => 0,
			'nbh_performance_js_minify_enabled'            => 0,
			'nbh_performance_combine_assets_enabled'       => 0,
			'nbh_performance_asset_failures'               => array(),
			'nbh_performance_defer_js_enabled'             => 0,
			'nbh_performance_delay_js_enabled'             => 0,
			'nbh_performance_async_js_handles'             => array(),
			'nbh_performance_delay_js_handles'             => array(),
			'nbh_performance_script_manager_enabled'       => 0,
			'nbh_performance_script_manager_testing_mode'  => 1,
			'nbh_performance_script_manager_rules'         => array(),
			'nbh_performance_script_manager_warnings'      => array(),
			'nbh_performance_delay_timeout'                => 8000,
			'nbh_performance_critical_css_enabled'         => 0,
			'nbh_performance_async_css_enabled'            => 0,
			'nbh_performance_critical_css_index'           => array(),
			'nbh_performance_critical_css_queue'           => array(),
			'nbh_performance_unused_css_enabled'           => 0,
			'nbh_performance_priority_elements_sample_rate' => 20,
			'nbh_performance_priority_elements_retention_days' => 30,
			'nbh_performance_automatic_preconnect_enabled' => 0,
			'nbh_performance_preconnect_limit'             => 3,
			'nbh_performance_visual_regression_guard_enabled' => 1,
			'nbh_performance_visual_difference_threshold'  => 5,
			'nbh_performance_browser_css_ttl'              => 30 * DAY_IN_SECONDS,
			'nbh_performance_visual_missing_nodes_limit'   => 0,
			'nbh_performance_visual_node_delta_limit'      => 10,
			'nbh_performance_unused_css_max_reduction'     => 80,
			'nbh_performance_visual_regression_failures'   => array(),
			'nbh_performance_browser_css_status'           => array(),
			'nbh_performance_lab_testing_enabled'          => 0,
			'nbh_performance_lab_test_frequency'           => 'weekly',
			'nbh_performance_lab_test_strategies'          => 'both',
			'nbh_performance_lab_test_urls'                => '',
			'nbh_performance_lab_retention_days'           => 180,
			'nbh_performance_regression_score_drop'        => 5,
			'nbh_performance_regression_lcp_percent'       => 12,
			'nbh_performance_regression_tbt_percent'       => 20,
			'nbh_performance_regression_ttfb_percent'      => 15,
			'nbh_performance_regression_cls_delta'         => 0.05,
			'nbh_performance_regression_email_alerts'      => 0,
			'nbh_performance_performance_regression_alerts' => array(),
			'nbh_performance_asset_inventory_enabled'      => 0,
			'nbh_performance_performance_budgets_enabled'  => 0,
			'nbh_performance_budget_script_count'          => 30,
			'nbh_performance_budget_style_count'           => 20,
			'nbh_performance_performance_change_impacts'   => array(),
			'nbh_performance_smart_optimize_last_run'      => array(),
			'nbh_performance_smart_rollback_enabled'       => 1,
			'nbh_performance_smart_rollback_production_enabled' => 0,
			'nbh_performance_smart_rollback_window_minutes' => 360,
			'nbh_performance_automatic_rollback_history'   => array(),
			'nbh_performance_image_conversion_enabled'     => 0,
			'nbh_performance_nextgen_image_serving_enabled' => 0,
			'nbh_performance_generate_webp'                => 1,
			'nbh_performance_generate_avif'                => 1,
			'nbh_performance_convert_on_upload'            => 1,
			'nbh_performance_webp_quality'                 => 82,
			'nbh_performance_avif_quality'                 => 70,
			'nbh_performance_image_batch_size'             => 10,
			'nbh_performance_image_optimization_profile'   => 'balanced',
			'nbh_performance_image_minimum_saving_percent' => 1,
			'nbh_performance_lcp_image_optimization_enabled' => 1,
			'nbh_performance_image_batch_state_v2'         => array(),
			'nbh_performance_image_max_source_mb'          => 25,
			'nbh_performance_compress_original_images'     => 0,
			'nbh_performance_jpeg_quality'                 => 82,
			'nbh_performance_png_quality'                  => 88,
			'nbh_performance_image_conversion_exclusions'  => '',
			'nbh_performance_image_last_batch'             => array(),
			'nbh_performance_lazy_load_enabled'            => 0,
			'nbh_performance_lazyload_eager_images'        => 2,
			'nbh_performance_lazyload_background_images'   => 0,
			'nbh_performance_video_preload'                => 'metadata',
			'nbh_performance_responsive_images_enabled'    => 0,
			'nbh_performance_youtube_facade_enabled'       => 0,
			'nbh_performance_youtube_autoload_after_consent' => 0,
			'nbh_performance_chat_facade_enabled'          => 0,
			'nbh_performance_consent_mode_enabled'         => 0,
			'nbh_performance_database_cleanup_enabled'     => 0,
			'nbh_performance_database_backup_max_age'      => DAY_IN_SECONDS,
			'nbh_performance_database_backup_excluded_tables' => '',
			'nbh_performance_database_batch_size'          => 250,
			'nbh_performance_revision_retention_days'      => 30,
			'nbh_performance_action_scheduler_retention_days' => 30,
			'nbh_performance_database_excluded_tables'     => '',
			'nbh_performance_database_last_cleanup'        => array(),
			'nbh_performance_edge_provider'                => '',
			'nbh_performance_edge_auto_purge_enabled'      => 0,
			'nbh_performance_edge_cache_headers_enabled'   => 0,
			'nbh_performance_edge_cache_ttl'               => HOUR_IN_SECONDS,
			'nbh_performance_cdn_rewrite_enabled'          => 0,
			'nbh_performance_cdn_base_url'                 => '',
			'nbh_performance_resource_hints_enabled'       => 0,
			'nbh_performance_cloudflare_zone_id'           => '',
			'nbh_performance_cloudflare_api_token'         => '',
			'nbh_performance_bunny_pull_zone_id'           => 0,
			'nbh_performance_bunny_api_key'                => '',
			'nbh_performance_keycdn_zone_id'               => 0,
			'nbh_performance_keycdn_api_key'               => '',
			'nbh_performance_disable_emojis'               => 0,
			'nbh_performance_disable_embeds'               => 0,
			'nbh_performance_disable_jquery_migrate'       => 0,
			'nbh_performance_disable_dashicons_guests'     => 0,
			'nbh_performance_remove_query_strings'         => 0,
			'nbh_performance_disable_xmlrpc'               => 0,
			'nbh_performance_remove_head_links'            => 0,
			'nbh_performance_disable_rest_api_links'       => 0,
			'nbh_performance_disable_rss_links'            => 0,
			'nbh_performance_remove_comment_author_urls'   => 0,
			'nbh_performance_disable_self_pingbacks'       => 0,
			'nbh_performance_disable_block_library_css'    => 0,
			'nbh_performance_disable_global_styles'        => 0,
			'nbh_performance_disable_woocommerce_cart_fragments' => 0,
			'nbh_performance_disable_woocommerce_scripts_non_shop' => 0,
			'nbh_performance_disable_password_strength_meter' => 0,
			'nbh_performance_woocommerce_currency_variant' => 0,
			'nbh_performance_woocommerce_currency_cookies' => '',
			'nbh_performance_heartbeat_control_enabled'    => 0,
			'nbh_performance_heartbeat_frontend_mode'      => 'reduce',
			'nbh_performance_heartbeat_frontend_interval'  => 120,
			'nbh_performance_heartbeat_admin_mode'         => 'reduce',
			'nbh_performance_heartbeat_admin_interval'     => 60,
			'nbh_performance_heartbeat_editor_mode'        => 'reduce',
			'nbh_performance_heartbeat_editor_interval'    => 30,
			'nbh_performance_rum_enabled'                  => 0,
			'nbh_performance_rum_sample_rate'              => 10,
			'nbh_performance_rum_retention_days'           => 90,
			'nbh_performance_safe_mode_snapshot'           => array(),
			'nbh_performance_last_readiness_scan'          => array(),
			'nbh_performance_uninstall_data_policy'        => 'retain',
			'nbh_performance_uninstall_remove_cache'       => 1,
			'nbh_performance_uninstall_remove_dropin'      => 1,
			'nbh_performance_browser_cache_ttl'            => MONTH_IN_SECONDS,
			'nbh_performance_server_rules_enabled'         => 0,
			'nbh_performance_server_rules_last_sync'       => 0,
			'nbh_performance_server_rules_signature'       => '',
			'nbh_performance_browser_cache_rules_enabled'  => 1,
			'nbh_performance_compression_rules_enabled'    => 1,
			'nbh_performance_static_cache_max_age'         => YEAR_IN_SECONDS,
			'nbh_performance_server_rules_backup'          => '',
			'nbh_performance_server_rules_status'          => array(),
			'nbh_performance_server_rules_checked_at'      => 0,
			'nbh_performance_onboarding_completed'         => 0,
			'nbh_performance_onboarding_profile'           => '',
			'nbh_performance_onboarding_completed_at'      => 0,
			'nbh_performance_job_worker_batch_size'        => 5,
			'nbh_performance_job_queue_paused'             => 0,
			'nbh_performance_job_worker_last_run'          => 0,
			'nbh_performance_job_worker_last_heartbeat'    => 0,
			'nbh_performance_job_worker_time_budget'       => 20,
			'nbh_performance_job_worker_memory_reserve_mb' => 16,
			'nbh_performance_job_worker_last_fatal'        => array(),
			'nbh_performance_runtime_reliability'          => array(),
			'nbh_performance_runtime_degraded'             => array(),
			'nbh_performance_runtime_watchdog_last_run'    => 0,
			'nbh_performance_update_auto_rollback_enabled' => 0,
			'nbh_performance_update_validation_delay'      => 30,
			'nbh_performance_last_update_validation'       => array(),
			'nbh_performance_last_update_rollback'         => array(),
			'nbh_performance_staging_auto_validation'      => 1,
			'nbh_performance_auto_rollback_on_validation_failure' => 1,
			'nbh_performance_allow_production_auto_rollback' => 0,
			'nbh_performance_force_staging_validation'     => 0,
			'nbh_performance_external_cron_configured'     => 0,
			'nbh_performance_validation_urls'              => '',
			'nbh_performance_configuration_transactions'   => array(),
			'nbh_performance_per_page_runtime_rules'       => array(
				'excluded_paths'                => array(),
				'mobile_variant_disabled_paths' => array(),
				'ttl_overrides'                 => array(),
				'updated_at'                    => 0,
			),
			'nbh_performance_last_staging_validation'      => array(),
			'nbh_performance_compatibility_matrix'         => array(),
			'nbh_performance_compatibility_matrix_checked_at' => 0,
			'nbh_performance_edge_html_cache_enabled'      => 0,
			'nbh_performance_edge_html_ttl'                => HOUR_IN_SECONDS,
			'nbh_performance_edge_stale_while_revalidate'  => 300,
			'nbh_performance_edge_stale_if_error'          => DAY_IN_SECONDS,
			'nbh_performance_edge_purge_webhook_url'       => '',
			'nbh_performance_edge_last_purge'              => array(),
			'nbh_performance_cache_tag_stats'              => array(),
			'nbh_performance_ai_gateway_token_hash'        => '',
			'nbh_performance_ai_gateway_scope'             => 'read',
			'nbh_performance_ai_gateway_write_enabled'     => 0,
			'nbh_performance_ai_gateway_approvals'         => array(),
			'nbh_performance_agency_reports_enabled'       => 1,
			'nbh_performance_agency_brand'                 => array(),
			'nbh_performance_agency_company'               => '',
			'nbh_performance_agency_report_title'          => 'WordPress Performance Report',
			'nbh_performance_agency_footer'                => 'Generated by NBH Performance',
			'nbh_performance_agency_logo_url'              => '',
			'nbh_performance_agency_profiles'              => array(),
			'nbh_performance_agency_active_profile'        => '',
			'nbh_performance_object_cache_redis_mode'      => 'standalone',
			'nbh_performance_object_cache_redis_nodes'     => array(),
			'nbh_performance_object_cache_sentinel_service' => '',
		);

		foreach ( $defaults as $option => $default_value ) {
			add_option( $option, $default_value, '', false );
		}

		if ( ! self::option_exists( 'nbh_performance_edge_purge_secret' ) ) {
			add_option( 'nbh_performance_edge_purge_secret', wp_generate_password( 64, false, false ), '', false );
		}
		if ( ! self::option_exists( 'nbh_performance_activated_at' ) ) {
			add_option( 'nbh_performance_activated_at', time(), '', false );
		}
	}

	/**
	 * Determine whether an option exists without confusing a stored false value.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function option_exists( $option ) {
		$sentinel = '__nbh_performance_option_missing__';
		return get_option( $option, $sentinel ) !== $sentinel;
	}

	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
			$site_ids = get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 0,
					'deleted'  => 0,
					'archived' => 0,
					'spam'     => 0,
				)
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( absint( $site_id ) );
				self::deactivate_site();
				restore_current_blog();
			}
			delete_site_option( 'nbh_performance_network_activation_pending' );
			return;
		}
		self::deactivate_site();
	}

	private static function deactivate_site() {
		self::remove_object_cache_dropin();
		foreach ( DataOwnershipRegistry::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		delete_option( 'nbh_performance_job_worker_lock' );
	}

	/** Register multisite initialization hooks while the plugin is loaded. */
	public static function register_runtime_hooks() {
		FreeReleaseMigration::register();
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_new_site' ), 30, 1 );
		add_action( 'nbh_performance_network_activation_batch', array( __CLASS__, 'process_network_activation_batch' ) );
	}

	/** Initialize tables and defaults for a newly created site when network-active. */
	public static function initialize_new_site( $new_site ) {
		if ( ! self::is_network_active() ) {
			return;
		}
		$site_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? absint( $new_site->blog_id ) : 0;
		if ( ! $site_id ) {
			return;
		}
		switch_to_blog( $site_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/** Process network activation in bounded batches to avoid request timeouts. */
	public static function process_network_activation_batch() {
		if ( ! is_multisite() || ! self::is_network_active() ) {
			return;
		}
		global $wpdb;
		$state = get_site_option( 'nbh_performance_network_activation_pending', array() );
		if ( ! is_array( $state ) ) {
			return;
		}
		$last = isset( $state['last_blog_id'] ) ? absint( $state['last_blog_id'] ) : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Activation processes the current multisite/schema state in batches; a cached cursor can skip sites.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id > %d AND deleted = 0 AND archived = 0 AND spam = 0 ORDER BY blog_id ASC LIMIT 25", $last ) );
		foreach ( (array) $ids as $site_id ) {
			$site_id = absint( $site_id );
			if ( ! $site_id ) {
				continue;
			}
			switch_to_blog( $site_id );
			try {
				self::activate_site();
			} finally {
				restore_current_blog();
			}
			$last = $site_id;
		}
		if ( count( (array) $ids ) < 25 ) {
			delete_site_option( 'nbh_performance_network_activation_pending' );
			return;
		}
		update_site_option(
			'nbh_performance_network_activation_pending',
			array(
				'last_blog_id' => $last,
				'started_at'   => isset( $state['started_at'] ) ? absint( $state['started_at'] ) : time(),
				'updated_at'   => time(),
			)
		);
		self::schedule_network_activation_batch();
	}

	private static function schedule_network_activation_batch() {
		if ( ! wp_next_scheduled( 'nbh_performance_network_activation_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'nbh_performance_network_activation_batch' );
		}
	}

	private static function is_network_active() {
		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ NBH_PERFORMANCE_BASENAME ] );
	}

	/** Remove only NBH Performance-owned persistent object-cache files. */
	private static function remove_object_cache_dropin() {
		$dropin = WP_CONTENT_DIR . '/object-cache.php';
		if ( is_readable( $dropin ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$head = file_get_contents( $dropin, false, null, 0, 4096 );
			if ( false !== $head && false !== strpos( $head, 'NBH_PERFORMANCE_OBJECT_CACHE_DROPIN' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				\NBH\Performance\Modules\Core\Filesystem::unlink( $dropin );
			}
		}
		$config = WP_CONTENT_DIR . '/nbh-performance-object-cache-config.php';
		if ( is_file( $config ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			\NBH\Performance\Modules\Core\Filesystem::unlink( $config );
		}
	}
}
