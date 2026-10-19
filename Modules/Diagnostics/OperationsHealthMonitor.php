<?php
/** Aggregated operational health for cache, queue, browser API, object cache and storage. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\DataOwnershipRegistry;
use NBH\Performance\Modules\Jobs\JobStore;

final class OperationsHealthMonitor {
	public function snapshot() {
		$components = array(
			'runtime'      => $this->runtime(),
			'page_cache'   => $this->page_cache(),
			'job_queue'    => $this->job_queue(),
			'browser_api'  => $this->browser_api(),
			'object_cache' => $this->object_cache(),
			'storage'      => $this->storage(),
			'updates'      => $this->updates(),
			'wordpress'    => $this->wordpress(),
		);
		$rank       = array(
			'healthy'  => 0,
			'warning'  => 1,
			'critical' => 2,
		);
		$overall    = 'healthy';
		foreach ( $components as $component ) {
			$status = isset( $component['status'] ) ? $component['status'] : 'warning';
			if ( isset( $rank[ $status ] ) && $rank[ $status ] > $rank[ $overall ] ) {
				$overall = $status;
			}
		}
		return array(
			'status'     => $overall,
			'checked_at' => time(),
			'components' => $components,
		);
	}

	private function runtime() {
		$report  = get_option( 'nbh_performance_runtime_reliability', array() );
		$state   = get_option( 'nbh_performance_runtime_degraded', array() );
		$report  = is_array( $report ) ? $report : array();
		$state   = is_array( $state ) ? $state : array();
		$checked = isset( $report['checked_at'] ) ? absint( $report['checked_at'] ) : 0;
		$status  = ! empty( $state['active'] ) ? 'critical' : ( ! $checked || time() - $checked > 30 * MINUTE_IN_SECONDS ? 'warning' : 'healthy' );
		return array(
			'status'            => $status,
			'degraded'          => ! empty( $state['active'] ),
			'reasons'           => isset( $state['reasons'] ) ? array_values( (array) $state['reasons'] ) : array(),
			'checked_at'        => $checked,
			'watchdog_last_run' => absint( get_option( 'nbh_performance_runtime_watchdog_last_run', 0 ) ),
			'build'             => isset( $report['build'] ) ? sanitize_text_field( $report['build'] ) : '',
		);
	}

	private function page_cache() {
		$enabled = (bool) get_option( 'nbh_performance_cache_enabled', 0 );
		$safe    = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$dropin  = WP_CONTENT_DIR . '/advanced-cache.php';

		$owned  = ( new \NBH\Performance\Modules\CacheEngine\DropInInstaller() )->is_ours( $dropin );
		$status = ! $enabled ? 'warning' : ( ( $safe || ! $owned || ! ( defined( 'WP_CACHE' ) && WP_CACHE ) ) ? 'warning' : 'healthy' );
		return array(
			'status'       => $status,
			'enabled'      => $enabled,
			'safe_mode'    => $safe,
			'dropin_owned' => $owned,
			'wp_cache'     => defined( 'WP_CACHE' ) && WP_CACHE,
			'stats'        => (array) get_option( 'nbh_performance_cache_stats', array() ),
		);
	}

	private function job_queue() {
		try {
			$stats = ( new JobStore() )->stats();
		} catch ( \Throwable $e ) {
			return array(
				'status'  => 'critical',
				'message' => 'Queue status unavailable.',
			);
		}
		$last_run       = isset( $stats['last_run'] ) ? absint( $stats['last_run'] ) : 0;
		$last_heartbeat = isset( $stats['last_heartbeat'] ) ? absint( $stats['last_heartbeat'] ) : 0;
		$last_activity  = max( $last_run, $last_heartbeat );
		$pending        = isset( $stats['pending'] ) ? absint( $stats['pending'] ) : 0;
		$running        = isset( $stats['running'] ) ? absint( $stats['running'] ) : 0;
		$status         = 'healthy';
		$message        = '';
		if ( ! empty( $stats['paused'] ) ) {
			$status  = 'warning';
			$message = 'The background queue is paused.';
		} elseif ( $running > 0 && ( ! $last_heartbeat || time() - $last_heartbeat > 15 * MINUTE_IN_SECONDS ) ) {
			$status  = 'critical';
			$message = 'A running job has no recent worker heartbeat.';
		} elseif ( $pending > 0 && ( ! $last_activity || time() - $last_activity > 15 * MINUTE_IN_SECONDS ) ) {
			$status  = 'critical';
			$message = 'Pending jobs are not being processed.';
		} elseif ( ! empty( $stats['failed'] ) || get_option( 'nbh_performance_job_worker_last_fatal', array() ) ) {
			$status  = 'warning';
			$message = 'One or more background jobs require review.';
		}
		return array_merge(
			array(
				'status'     => $status,
				'message'    => $message,
				'last_fatal' => $this->sanitize_record( (array) get_option( 'nbh_performance_job_worker_last_fatal', array() ) ),
			),
			$stats
		);
	}

	private function browser_api() {
		$enabled    = (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ) || (bool) get_option( 'nbh_performance_unused_css_enabled', 0 );
		$endpoint   = '';
		$last       = (array) get_option( 'nbh_performance_browser_api_health', array() );
		$configured = ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) );
		$status     = ! $enabled ? 'healthy' : ( ! $configured ? 'critical' : ( isset( $last['ok'] ) && ! $last['ok'] ? 'warning' : 'healthy' ) );
		return array(
			'status'        => $status,
			'required'      => $enabled,
			'configured'    => $configured,
			'endpoint_host' => (string) wp_parse_url( $endpoint, PHP_URL_HOST ),
			'last_check'    => $this->sanitize_record( $last ),
		);
	}

	private function object_cache() {
		$enabled = (bool) get_option( 'nbh_performance_object_cache_enabled', 0 );
		$metrics = (array) get_option( 'nbh_performance_object_cache_metrics', array() );
		$circuit = (array) get_option( 'nbh_performance_object_cache_circuit_breaker', array() );
		$status  = ! $enabled ? 'healthy' : ( ! empty( $circuit['open'] ) ? 'critical' : ( ! empty( $metrics['backend_errors'] ) ? 'warning' : 'healthy' ) );
		return array(
			'status'       => $status,
			'enabled'      => $enabled,
			'external'     => wp_using_ext_object_cache(),
			'backend'      => sanitize_key( (string) get_option( 'nbh_performance_object_cache_driver', 'redis' ) ),
			'metrics'      => $this->sanitize_record( $metrics ),
			'circuit_open' => ! empty( $circuit['open'] ),
		);
	}

	private function storage() {
		$paths    = array( WP_CONTENT_DIR . '/cache/nbh-performance', DataOwnershipRegistry::private_storage_root() );
		$checks   = array();
		$critical = false;
		foreach ( $paths as $path ) {
			$exists   = is_dir( $path );
			$writable = $exists && \NBH\Performance\Modules\Core\Filesystem::is_writable( $path );
			$checks[] = array(
				'path'     => wp_normalize_path( str_replace( ABSPATH, '[ABSPATH]/', $path ) ),
				'exists'   => $exists,
				'writable' => $writable,
			);
			if ( $exists && ! $writable ) {
				$critical = true;
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$free   = @disk_free_space( WP_CONTENT_DIR );
		$status = $critical ? 'critical' : ( false !== $free && $free < 512 * MB_IN_BYTES ? 'warning' : 'healthy' );
		return array(
			'status'     => $status,
			'free_bytes' => false === $free ? null : (int) $free,
			'paths'      => $checks,
		);
	}

	private function updates() {
		$validation = (array) get_option( 'nbh_performance_last_update_validation', array() );
		$rollback   = (array) get_option( 'nbh_performance_last_update_rollback', array() );
		$status     = 'healthy';
		if ( ! empty( $rollback ) && isset( $rollback['success'] ) && ! $rollback['success'] ) {
			$status = 'critical';
		} elseif ( ! empty( $validation ) && isset( $validation['passed'] ) && ! $validation['passed'] ) {
			$status = 'warning';
		}
			return array(
				'status'        => $status,
				'auto_rollback' => (bool) get_option( 'nbh_performance_update_auto_rollback_enabled', 0 ),
				'validation'    => $this->sanitize_record( $validation ),
				'rollback'      => $this->sanitize_record( $rollback ),
			);
	}

	private function wordpress() {
		global $wp_version;
		return array(
			'status'        => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? 'warning' : 'healthy',
			'version'       => (string) $wp_version,
			'php'           => PHP_VERSION,
			'multisite'     => is_multisite(),
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
		);
	}

	private function sanitize_record( array $record ) {
		$clean = array();
		foreach ( array_slice( $record, 0, 30, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( preg_match( '/token|secret|password|key|cookie|authorization/i', $key ) ) {
				$clean[ $key ] = '[redacted]';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}
		return $clean;
	}
}
