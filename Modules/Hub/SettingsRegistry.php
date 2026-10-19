<?php
/**
 * Central settings contract and runtime-consumer audit.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\Core\Module;

final class SettingsRegistry implements Module {
	const REPORT_OPTION = 'nbh_performance_settings_contract_report';

	/** Register sanitizers and settings-contract audits. */
	public function register() {
		foreach ( $this->definitions() as $option => $definition ) {
			add_filter( 'sanitize_option_' . $option, array( $this, 'sanitize_option' ), 10, 3 );
		}
		add_action( 'admin_init', array( $this, 'audit' ), 100 );
		add_action( 'nbh_performance_advanced_settings_updated', array( $this, 'audit' ), 100 );
		add_action( 'nbh_performance_settings_schema_upgraded', array( $this, 'audit' ), 100 );
	}

	/** Critical settings that cross UI, runtime, drop-ins, or scheduled jobs. */
	public function definitions() {
		return array(
			'nbh_performance_cache_excluded_urls'          => $this->definition( 'lines', '', array( 'runtime_config', 'request_eligibility' ) ),
			'nbh_performance_cache_excluded_cookies'       => $this->definition( 'lines', '', array( 'runtime_config', 'request_eligibility' ) ),
			'nbh_performance_cache_ignored_query_args'     => $this->definition( 'lines', '', array( 'runtime_config', 'request_eligibility' ) ),
			'nbh_performance_cache_allowed_query_args'     => $this->definition( 'lines', '', array( 'runtime_config', 'request_eligibility', 'advanced_cache' ) ),
			'nbh_performance_cache_vary_cookies'           => $this->definition( 'lines', '', array( 'runtime_config', 'cache_variants', 'advanced_cache' ) ),
			'nbh_performance_language_variant_cookies'     => $this->definition( 'lines', '', array( 'runtime_config', 'cache_variants', 'advanced_cache' ) ),
			'nbh_performance_cache_stale_while_revalidate' => $this->definition( 'integer', 300, array( 'cache_metadata', 'advanced_cache' ), 0, 86400 ),
			'nbh_performance_cache_stale_if_error'         => $this->definition( 'integer', 86400, array( 'cache_metadata', 'advanced_cache' ), 0, 604800 ),
			'nbh_performance_cache_lock_timeout'           => $this->definition( 'integer', 45, array( 'advanced_cache' ), 10, 300 ),
			'nbh_performance_cache_max_html_mb'            => $this->definition( 'integer', 8, array( 'cache_writer' ), 1, 50 ),
			'nbh_performance_cache_min_html_bytes'         => $this->definition( 'integer', 256, array( 'cache_writer' ), 0, 65536 ),
			'nbh_performance_cache_min_free_disk_mb'       => $this->definition( 'integer', 128, array( 'cache_writer' ), 16, 10240 ),
			'nbh_performance_cache_gzip_enabled'           => $this->definition( 'boolean', 1, array( 'cache_writer', 'advanced_cache' ) ),
			'nbh_performance_cache_brotli_enabled'         => $this->definition( 'boolean', 1, array( 'cache_writer', 'advanced_cache' ) ),
			'nbh_performance_cache_verify_integrity'       => $this->definition( 'boolean', 0, array( 'advanced_cache' ) ),
			'nbh_performance_cache_404_enabled'            => $this->definition( 'boolean', 0, array( 'request_eligibility', 'cache_metadata', 'advanced_cache' ) ),
			'nbh_performance_cache_redirects_enabled'      => $this->definition( 'boolean', 0, array( 'request_eligibility', 'cache_metadata', 'advanced_cache' ) ),
			'nbh_performance_language_cache_variant'       => $this->definition( 'boolean', 0, array( 'cache_variants', 'advanced_cache' ) ),
			'nbh_performance_preload_request_timeout'      => $this->definition( 'integer', 12, array( 'preload_worker' ), 3, 30 ),
			'nbh_performance_preload_retry_limit'          => $this->definition( 'integer', 3, array( 'preload_queue' ), 1, 5 ),
			'nbh_performance_preload_mobile_variant'       => $this->definition( 'boolean', false, array( 'preload_queue' ) ),
			'nbh_performance_preload_slow_request_ms'      => $this->definition( 'integer', 5000, array( 'preload_worker' ), 1000, 30000 ),
			'nbh_performance_cdn_excluded_urls'            => $this->definition( 'lines', '', array( 'cdn_rewriter' ) ),
			'nbh_performance_database_cleanup_enabled'     => $this->definition( 'boolean', 0, array( 'database_scheduler' ) ),
			'nbh_performance_database_batch_size'          => $this->definition( 'integer', 250, array( 'database_cleanup' ), 25, 1000 ),
			'nbh_performance_database_excluded_tables'     => $this->definition( 'lines', '', array( 'database_cleanup' ) ),
			'nbh_performance_revision_retention_days'      => $this->definition( 'integer', 30, array( 'database_cleanup' ), 1, 3650 ),
			'nbh_performance_lazyload_excluded_classes'    => $this->definition( 'lines', '', array( 'lazy_load' ) ),
			'nbh_performance_media_excluded_urls'          => $this->definition( 'lines', '', array( 'media_delivery' ) ),
			'nbh_performance_lcp_image_exclusions'         => $this->definition( 'lines', '', array( 'resource_priority' ) ),
			'nbh_performance_font_excluded_families'       => $this->definition( 'lines', '', array( 'font_optimizer' ) ),
			'nbh_performance_font_display'                 => $this->definition( 'enum', 'swap', array( 'font_optimizer' ), null, null, array(), array( 'swap', 'optional', 'fallback', 'block' ) ),
			'nbh_performance_image_conversion_exclusions'  => $this->definition( 'lines', '', array( 'image_converter' ) ),
			'nbh_performance_diagnostic_exclusions'        => $this->definition( 'lines', '', array( 'readiness_scanner', 'support_report' ) ),
			'nbh_performance_rum_sample_rate'              => $this->definition( 'integer', 10, array( 'rum_beacon' ), 1, 100 ),
			'nbh_performance_script_manager_testing_mode'  => $this->definition( 'boolean', 1, array( 'script_manager' ) ),
			'nbh_performance_lazyload_eager_images'        => $this->definition( 'integer', 2, array( 'html_media_optimizer' ), 0, 10 ),
			'nbh_performance_video_preload'                => $this->definition( 'enum', 'metadata', array( 'html_media_optimizer' ), null, null, array(), array( 'none', 'metadata', 'auto' ) ),
			'nbh_performance_unused_css_max_reduction'     => $this->definition( 'integer', 80, array( 'coverage_validator' ), 5, 95 ),
			'nbh_performance_browser_css_ttl'              => $this->definition( 'integer', 2592000, array( 'critical_css_storage', 'used_css_storage' ), 86400, 7776000 ),
			'nbh_performance_jpeg_quality'                 => $this->definition( 'integer', 82, array( 'image_compression' ), 40, 100 ),
			'nbh_performance_png_quality'                  => $this->definition( 'integer', 88, array( 'image_compression' ), 40, 100 ),
			'nbh_performance_action_scheduler_retention_days' => $this->definition( 'integer', 30, array( 'database_cleanup' ), 7, 365 ),
			'nbh_performance_woocommerce_currency_cookies' => $this->definition( 'lines', '', array( 'woocommerce_performance' ) ),
			'nbh_performance_smart_rollback_enabled'       => $this->definition( 'boolean', 1, array( 'automatic_rollback' ) ),
			'nbh_performance_smart_rollback_production_enabled' => $this->definition( 'boolean', 0, array( 'automatic_rollback' ) ),
			'nbh_performance_smart_rollback_window_minutes' => $this->definition( 'integer', 360, array( 'automatic_rollback' ), 15, 1440 ),
		);
	}

	/** WordPress sanitize_option callback. */
	public function sanitize_option( $value, $option, $original_value ) {
		unset( $original_value );
		$definitions = $this->definitions();
		if ( ! isset( $definitions[ $option ] ) ) {
			return $value;
		}
		$definition = $definitions[ $option ];
		switch ( $definition['type'] ) {
			case 'boolean':
				return rest_sanitize_boolean( $value ) ? 1 : 0;
			case 'integer':
				$value = absint( $value );
				return max( $definition['min'], min( $definition['max'], $value ) );
			case 'enum':
				$value = sanitize_key( $value );
				return in_array( $value, $definition['allowed'], true ) ? $value : $definition['default'];
			case 'lines':
				return implode( "\n", $this->sanitize_lines( $value ) );
		}
		return $value;
	}


	/** Store a machine-readable settings contract report for diagnostics. */
	public function audit() {
		$report = array(
			'generated_at' => time(),
			'schema'       => defined( 'NBH_PERFORMANCE_SETTINGS_SCHEMA_VERSION' ) ? NBH_PERFORMANCE_SETTINGS_SCHEMA_VERSION : 0,
			'settings'     => array(),
		);
		foreach ( $this->definitions() as $option => $definition ) {
			$report['settings'][ $option ] = array(
				'configured' => null !== get_option( $option, null ),
				'consumers'  => $definition['consumers'],
				'aliases'    => $definition['aliases'],
			);
		}
		update_option( self::REPORT_OPTION, $report, false );
		return $report;
	}

	private function definition( $type, $default_value, array $consumers, $min = null, $max = null, array $aliases = array(), array $allowed = array() ) {
		return array(
			'type'      => $type,
			'default'   => $default_value,
			'consumers' => $consumers,
			'min'       => $min,
			'max'       => $max,
			'aliases'   => $aliases,
			'allowed'   => $allowed,
		);
	}

	private function sanitize_lines( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$lines = array_map( 'sanitize_text_field', (array) $lines );
		$lines = array_filter( array_map( 'trim', $lines ) );
		return array_slice( array_values( array_unique( $lines ) ), 0, 500 );
	}
}
