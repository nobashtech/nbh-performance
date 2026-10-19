<?php
/** Production readiness scanner. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;
use NBH\Performance\Modules\PerformanceIntelligence\LabTestStorage;
use NBH\Performance\Modules\Core\PatternMatcher;

final class ProductionReadinessScanner {
	/** Run bounded, non-destructive checks. */
	public function scan() {
		$checks = array();
		$this->add( $checks, 'php_version', version_compare( PHP_VERSION, '7.4', '>=' ), PHP_VERSION, 'PHP 7.4 or newer is required.' );
		$this->add( $checks, 'https', is_ssl(), is_ssl() ? 'HTTPS enabled' : 'HTTPS not detected', 'Serve production traffic over HTTPS.' );
		$this->add( $checks, 'wp_cache', defined( 'WP_CACHE' ) && WP_CACHE, defined( 'WP_CACHE' ) && WP_CACHE ? 'Enabled' : 'Disabled', 'Enable WP_CACHE after staging validation.' );
		$cache_root = WP_CONTENT_DIR . '/cache/nbh-performance';
		$this->add( $checks, 'cache_directory', is_dir( $cache_root ) && \NBH\Performance\Modules\Core\Filesystem::is_writable( $cache_root ), $cache_root, 'Ensure the NBH Performance directory exists and is writable by PHP.' );
		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';

		$owned = ( new \NBH\Performance\Modules\CacheEngine\DropInInstaller() )->is_ours( $dropin );
		$this->add( $checks, 'dropin', $owned, $owned ? 'NBH Performance drop-in installed' : 'Missing or owned by another plugin', 'Reinstall the drop-in only after resolving cache-plugin conflicts.' );
		$this->add( $checks, 'safe_mode', ! (bool) get_option( 'nbh_performance_safe_mode', 1 ), (bool) get_option( 'nbh_performance_safe_mode', 1 ) ? 'Enabled' : 'Disabled', 'Keep Safe Mode enabled until staging tests pass; disable it for production optimization.' );
		$this->add( $checks, 'cron', ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron disabled' : 'WP-Cron available', 'When WP-Cron is disabled, configure a real system cron.' );
		$job_stats         = ( new JobStore() )->stats();
		$queue_paused      = ! empty( $job_stats['paused'] );
		$worker_configured = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || (bool) get_option( 'nbh_performance_external_cron_configured', 0 );
		$worker_available  = $worker_configured && ! $queue_paused;
		$this->add( $checks, 'background_worker', $worker_available, $queue_paused ? 'Queue paused' : ( $worker_configured ? 'Queue worker configured' : 'No reliable worker configured' ), $queue_paused ? 'Resume the NBH Performance queue before production traffic.' : 'Configure a real cron using wp nbh-performance jobs run --limit=10 when WP-Cron is disabled.' );
		$this->add( $checks, 'job_queue_paused', ! $queue_paused, $queue_paused ? 'Paused' : 'Running', 'Resume the queue from Tools or run wp nbh-performance jobs resume.' );
		$this->add( $checks, 'failed_jobs', empty( $job_stats['failed'] ), isset( $job_stats['failed'] ) ? (string) $job_stats['failed'] : '0', 'Review and retry failed NBH Performance background jobs.' );
		$this->add( $checks, 'debug_display', ! ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ), defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'Enabled' : 'Disabled', 'Disable public error display in production.' );
		$this->add( $checks, 'memory', $this->memory_bytes() >= 134217728, size_format( $this->memory_bytes() ), 'Use at least 128 MB PHP memory; 256 MB is recommended for image/CSS workers.' );
		$this->add( $checks, 'composer_minifier', class_exists( '\\NBH\Performance\\Vendor\\MatthiasMullie\\Minify\\CSS' ) && class_exists( '\\NBH\Performance\\Vendor\\MatthiasMullie\\Minify\\JS' ), class_exists( '\\NBH\Performance\\Vendor\\MatthiasMullie\\Minify\\CSS' ) ? 'Bundled and scoped' : 'Using internal fail-safe engine', 'The scoped production minifier is bundled in the release package.' );
		$this->add( $checks, 'css_parser', class_exists( '\\NBH\Performance\\Vendor\\Sabberworm\\CSS\\Parser' ), class_exists( '\\NBH\Performance\\Vendor\\Sabberworm\\CSS\\Parser' ) ? 'Bundled and scoped' : 'Unavailable', 'Restore the scoped Sabberworm CSS parser from the official release package.' );
		$browser_css_enabled = (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ) || (bool) get_option( 'nbh_performance_unused_css_enabled', 0 );
		$endpoint            = '';
		$generator_ready     = ! $browser_css_enabled || ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) );
		$this->add( $checks, 'browser_css_engine', $generator_ready, $browser_css_enabled ? ( $generator_ready ? 'Configured' : 'Not configured' ) : 'Not required', 'Enable PHP DOM and the bundled CSS parser for conservative background generation.' );
		$priority_schema = absint( get_option( 'nbh_performance_priority_elements_schema_version', 0 ) );
		$priority_ready  = ! get_option( 'nbh_performance_resource_priority_enabled', 0 ) || $priority_schema >= PriorityElementsStorage::SCHEMA_VERSION;
		$this->add( $checks, 'priority_elements_storage', $priority_ready, $priority_ready ? 'Ready' : 'Storage schema missing', 'Reactivate NBH Performance or run its schema upgrade to install browser Priority Elements storage.' );
		$visual_guard = ! get_option( 'nbh_performance_unused_css_enabled', 0 ) || (bool) get_option( 'nbh_performance_visual_regression_guard_enabled', 1 );
		$this->add( $checks, 'used_css_visual_guard', $visual_guard, $visual_guard ? 'Enabled or not required' : 'Disabled', 'Keep the browser visual regression guard enabled when Remove Unused CSS is active.' );
		$lab_enabled = (bool) get_option( 'nbh_performance_lab_testing_enabled', 0 );
		$lab_schema  = absint( get_option( 'nbh_performance_lab_results_schema_version', 0 ) );
		$this->add( $checks, 'performance_lab_storage', ! $lab_enabled || $lab_schema >= LabTestStorage::SCHEMA_VERSION, $lab_enabled ? ( $lab_schema >= LabTestStorage::SCHEMA_VERSION ? 'Ready' : 'Schema missing' ) : 'Not enabled', 'Run the NBH Performance schema upgrade before enabling scheduled Performance Insights tests.' );
		$lab_worker = ! $lab_enabled || $worker_available;
		$this->add( $checks, 'performance_lab_worker', $lab_worker, $lab_enabled ? ( $worker_available ? 'Reliable worker available' : 'Worker unavailable' ) : 'Not enabled', 'Scheduled lab testing requires the NBH Performance background worker or external WP-CLI cron.' );
		$edge_enabled = (bool) get_option( 'nbh_performance_edge_html_cache_enabled', 0 );
		$edge_url     = esc_url_raw( (string) get_option( 'nbh_performance_edge_purge_webhook_url', '' ) );
		$edge_ready   = ! $edge_enabled || ( 'https' === strtolower( (string) wp_parse_url( $edge_url, PHP_URL_SCHEME ) ) && strlen( (string) get_option( 'nbh_performance_edge_purge_secret', '' ) ) >= 32 );
		$this->add( $checks, 'enterprise_edge_webhook', $edge_ready, $edge_enabled ? ( $edge_ready ? 'Signed HTTPS webhook configured' : 'Webhook or secret missing' ) : 'Not enabled', 'Configure and test a signed HTTPS purge webhook before enabling full-page edge caching.' );
		$ai_write = (bool) get_option( 'nbh_performance_ai_gateway_write_enabled', 0 );
		$ai_scope = sanitize_key( (string) get_option( 'nbh_performance_ai_gateway_scope', 'read' ) );
		$ai_ready = ! $ai_write || ( 'read_write' === $ai_scope && (bool) get_option( 'nbh_performance_ai_gateway_token_hash', '' ) );
		$this->add( $checks, 'enterprise_ai_gateway', $ai_ready, $ai_write ? ( $ai_ready ? 'Write scope with approvals configured' : 'Write configuration incomplete' ) : 'Read-only or disabled', 'Keep the AI operations gateway read-only unless token rotation and administrator approval review are configured.' );
		$redis_mode  = sanitize_key( (string) get_option( 'nbh_performance_object_cache_redis_mode', 'standalone' ) );
		$redis_ready = 'sentinel' !== $redis_mode || class_exists( 'RedisSentinel' );
		$redis_ready = $redis_ready && ( 'cluster' !== $redis_mode || class_exists( 'RedisCluster' ) );
		$this->add( $checks, 'redis_high_availability', $redis_ready, ucfirst( $redis_mode ), 'Install a PhpRedis build that supports the selected Sentinel or Cluster topology, then run Test Connection.' );
		$smart = (array) get_option( 'nbh_performance_smart_optimize_last_run', array() );
		$this->add( $checks, 'smart_optimize_proof', in_array( isset( $smart['status'] ) ? (string) $smart['status'] : '', array( 'complete', 'needs_review' ), true ), isset( $smart['status'] ) ? (string) $smart['status'] : 'not_started', 'Run Smart Optimize once to capture Soft Launch before/after proof.' );
		$first_win = (array) get_option( 'nbh_performance_soft_launch_first_win', array() );
		$this->add( $checks, 'soft_launch_first_win', ! empty( $first_win['at'] ), ! empty( $first_win['at'] ) ? 'Captured' : 'Missing', 'Finish onboarding and one Smart Optimize run before invite Soft Launch.' );
		$checks = array_values( array_filter( $checks, array( $this, 'included_check' ) ) );
		$failed = array_filter(
			$checks,
			static function ( $check ) {
				return empty( $check['passed'] );
			}
		);
		return array(
			'ready'      => count( $failed ) === 0,
			'score'      => (int) round( 100 * ( count( $checks ) - count( $failed ) ) / max( 1, count( $checks ) ) ),
			'checked_at' => time(),
			'checks'     => array_values( $checks ),
		);
	}


	private function included_check( $check ) {
		$id = is_array( $check ) && isset( $check['id'] ) ? (string) $check['id'] : '';
		return ! PatternMatcher::matches_any( $id, $this->diagnostic_exclusions() );
	}

	private function diagnostic_exclusions() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_diagnostic_exclusions', '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	private function add( array &$checks, $id, $passed, $value, $recommendation ) {
		$checks[] = array(
			'id'             => sanitize_key( $id ),
			'passed'         => (bool) $passed,
			'value'          => sanitize_text_field( (string) $value ),
			'recommendation' => sanitize_text_field( (string) $recommendation ),
		);
	}

	private function memory_bytes() {
		$value = (string) ini_get( 'memory_limit' );
		if ( '-1' === $value ) {
			return PHP_INT_MAX;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (int) $value;
		if ( 'g' === $unit ) {
			return $number * 1073741824;
		}
		if ( 'm' === $unit ) {
			return $number * 1048576;
		}
		if ( 'k' === $unit ) {
			return $number * 1024;
		}
		return $number;
	}
}
