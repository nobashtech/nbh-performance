<?php
/** Runtime reliability watchdog and graceful-degradation policy. @package NBHPerformance */
namespace NBH\Performance\Modules\Reliability;

use NBH\Performance\Modules\CacheEngine\CacheIndex;
use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use NBH\Performance\Modules\Jobs\JobStore;

final class RuntimeReliabilityManager implements Module {
	private $queue;

	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	public function register() {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- A lightweight five-minute watchdog is required to recover missed workers promptly; heavier checks are separately throttled.
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule' ), 3 );
		add_action( 'init', array( $this, 'lightweight_check' ), 4 );
		add_action( 'nbh_performance_runtime_watchdog_tick', array( $this, 'watchdog' ) );
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 30, 2 );
		add_action( 'admin_init', array( $this, 'recover_missed_worker' ), 90 );
	}

	public function cron_schedules( $schedules ) {
		$schedules['nbh_performance_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'nbh-performance' ),
		);
		return $schedules;
	}

	public function schedule() {
		if ( ! wp_next_scheduled( 'nbh_performance_runtime_watchdog_tick' ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'nbh_performance_every_five_minutes', 'nbh_performance_runtime_watchdog_tick' );
		}
	}

	/** Run only inexpensive checks during normal requests. */
	public function lightweight_check() {
		$report = get_option( 'nbh_performance_runtime_reliability', array() );
		$last   = is_array( $report ) && isset( $report['checked_at'] ) ? absint( $report['checked_at'] ) : 0;
		if ( time() - $last <= 15 * MINUTE_IN_SECONDS || get_transient( 'nbh_performance_runtime_check_lock' ) ) {
			return;
		}
		set_transient( 'nbh_performance_runtime_check_lock', 1, MINUTE_IN_SECONDS );
		try {
			$this->assess( false );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	public function watchdog() {
		$this->ensure_required_schedules();
		$this->recover_missed_worker();
		$this->assess( true );
		update_option( 'nbh_performance_runtime_watchdog_last_run', time(), false );
	}

	/** Queue post-update validation only for this plugin. */
	public function after_update( $upgrader, $options ) {
		unset( $upgrader );
		if ( ! $this->is_nbh_update( $options ) ) {
			return;
		}
		$delay           = max( 10, min( 300, absint( get_option( 'nbh_performance_update_validation_delay', 30 ) ) ) );
		$installed_build = $this->installed_build_id();
		$payload         = array(
			'build'          => $installed_build,
			'previous_build' => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
			'updated_at'     => time(),
		);
		$job_id          = 0;
		try {
			$job_id = $this->queue->enqueue(
				'post_update_validation',
				$payload,
				array(
					'priority'     => 100,
					'delay'        => $delay,
					'max_attempts' => 4,
					'timeout'      => 300,
					'unique_key'   => 'post-update-validation|' . ( '' !== $installed_build ? $installed_build : NBH_PERFORMANCE_VERSION ),
				)
			);
		} catch ( \Throwable $error ) {
			$job_id = 0;
		}
		if ( ! $job_id && ! wp_next_scheduled( 'nbh_performance_post_update_validation_fallback', array( $payload ) ) ) {
			wp_schedule_single_event( time() + $delay, 'nbh_performance_post_update_validation_fallback', array( $payload ) );
		}
	}

	/** Assess runtime safety and fail closed for early page-cache serving. */
	public function assess( $deep = false ) {
		global $wpdb, $wp_version;
		$checks = array();
		$this->add( $checks, 'php_version', version_compare( PHP_VERSION, '7.4', '>=' ), true, PHP_VERSION, 'PHP 7.4 or newer is required.' );
		$this->add( $checks, 'wordpress_version', version_compare( (string) $wp_version, '6.4', '>=' ), true, (string) $wp_version, 'WordPress 6.4 or newer is required.' );
		$home       = home_url( '/' );
		$home_valid = in_array( strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) && '' !== (string) wp_parse_url( $home, PHP_URL_HOST );
		$this->add( $checks, 'site_origin', $home_valid, true, $home_valid ? (string) wp_parse_url( $home, PHP_URL_HOST ) : 'invalid', 'A valid WordPress home URL is required.' );

		$db_ok = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live schema/worker health is being verified; cached state could hide a broken or expired worker.
			$db_ok = '1' === (string) $wpdb->get_var( 'SELECT 1' );
		} catch ( \Throwable $error ) {
			$db_ok = false;
		}
		$this->add( $checks, 'database_connection', $db_ok, true, $db_ok ? 'connected' : 'unavailable', 'Restore database connectivity before enabling optimization.' );

		$cache_root  = WP_CONTENT_DIR . '/cache/nbh-performance';
		$cache_ready = is_dir( $cache_root ) || wp_mkdir_p( $cache_root );
		$cache_ready = $cache_ready && \NBH\Performance\Modules\Core\Filesystem::is_writable( $cache_root );
		$this->add( $checks, 'cache_storage', $cache_ready, true, $cache_ready ? 'writable' : 'unwritable', 'Make the NBH Performance cache directory writable.' );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;
		$reserve = max( 64, absint( get_option( 'nbh_performance_cache_min_free_disk_mb', 128 ) ) ) * MB_IN_BYTES;
		$disk_ok = false === $free || (float) $free > (float) $reserve;
		$this->add( $checks, 'disk_reserve', $disk_ok, true, false === $free ? 'unknown' : (string) (int) $free, 'Free disk space is below the configured emergency reserve.' );

		$memory    = $this->memory_limit_bytes();
		$memory_ok = PHP_INT_MAX === $memory || $memory >= 64 * MB_IN_BYTES;
		$this->add( $checks, 'memory_limit', $memory_ok, true, PHP_INT_MAX === $memory ? 'unlimited' : (string) $memory, 'At least 64 MB of PHP memory is required for reliable background processing.' );

		if ( $db_ok ) {
			$job_table   = JobStore::table_name();
			$cache_table = CacheIndex::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema/worker health is being verified; cached state could hide a broken or expired worker.
			$job_exists = $job_table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $job_table ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema/worker health is being verified; cached state could hide a broken or expired worker.
			$cache_exists = $cache_table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $cache_table ) ) );
			$this->add( $checks, 'job_table', $job_exists, true, $job_exists ? 'available' : 'missing', 'Repair the background-job table.' );
			$this->add( $checks, 'cache_index_table', $cache_exists, true, $cache_exists ? 'available' : 'missing', 'Repair the page-cache index table.' );
		}

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$external      = (bool) get_option( 'nbh_performance_external_cron_configured', 0 );
		$cron_ok       = ! $cron_disabled || $external;
		$this->add( $checks, 'cron_delivery', $cron_ok, false, $cron_disabled ? ( $external ? 'external' : 'disabled' ) : 'wp-cron', 'Configure external cron or WP-CLI when WP-Cron is disabled.' );

		if ( $deep && $home_valid ) {
			$response    = wp_remote_get(
				$home,
				array(
					'timeout'     => 8,
					'redirection' => 2,
					'sslverify'   => true,
					'headers'     => array( 'X-NBH-Reliability-Probe' => wp_generate_uuid4() ),
					'user-agent'  => 'NBH Performance Runtime Watchdog/' . NBH_PERFORMANCE_VERSION,
				)
			);
			$loopback_ok = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) > 0 && wp_remote_retrieve_response_code( $response ) < 500;
			$this->add( $checks, 'frontend_loopback', $loopback_ok, false, $loopback_ok ? (string) wp_remote_retrieve_response_code( $response ) : 'failed', 'Allow same-site HTTPS loopback requests for validation and preloading.' );
		}

		$multisite_cache_safe = ! is_multisite() || ! get_option( 'nbh_performance_cache_enabled', 0 );
		$this->add( $checks, 'multisite_page_cache_isolation', $multisite_cache_safe, true, $multisite_cache_safe ? 'isolated' : 'unsupported-single-origin-runtime', 'Page caching is fail-closed on multisite until a network-aware early runtime is enabled.' );

		if ( get_option( 'nbh_performance_cache_enabled', 0 ) ) {
			$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$owned = is_readable( $dropin ) && false !== strpos( (string) file_get_contents( $dropin, false, null, 0, 2048 ), 'NBH_PERFORMANCE_DROPIN' );
			$this->add( $checks, 'page_cache_dropin', $owned, true, $owned ? 'owned' : 'missing-or-foreign', 'Repair or safely transfer ownership of advanced-cache.php.' );
			$this->add( $checks, 'wp_cache_constant', defined( 'WP_CACHE' ) && WP_CACHE, true, defined( 'WP_CACHE' ) && WP_CACHE ? 'enabled' : 'disabled', 'Enable WP_CACHE in wp-config.php.' );
		}

		$critical = array_values(
			array_filter(
				$checks,
				static function ( $check ) {
					return empty( $check['passed'] ) && ! empty( $check['critical'] );
				}
			)
		);
		$report   = array(
			'reliable'     => empty( $critical ),
			'degraded'     => ! empty( $critical ),
			'checked_at'   => time(),
			'deep'         => (bool) $deep,
			'build'        => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
			'checks'       => $checks,
			'critical_ids' => array_values(
				array_map(
					static function ( $check ) {
						return $check['id'];
					},
					$critical
				)
			),
		);
		update_option( 'nbh_performance_runtime_reliability', $report, false );
		$this->persist_degraded_state( $report );
		do_action( 'nbh_performance_runtime_reliability_checked', $report );
		return $report;
	}

	/** Kick the queue after a missed cron interval without opening a public endpoint. */
	public function recover_missed_worker() {
		if ( get_option( 'nbh_performance_job_queue_paused', 0 ) ) {
			return;
		}
		try {
			$stats = ( new JobStore() )->stats();
		} catch ( \Throwable $error ) {
			return;
		}
		if ( empty( $stats['pending'] ) ) {
			return;
		}
		$last = max( absint( $stats['last_run'] ), absint( $stats['last_heartbeat'] ) );
		if ( $last && time() - $last < 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( ! wp_next_scheduled( 'nbh_performance_job_worker_kick' ) ) {
			wp_schedule_single_event( time() + 1, 'nbh_performance_job_worker_kick' );
		}
		if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron( time() );
		}
	}

	public static function is_degraded() {
		$state = get_option( 'nbh_performance_runtime_degraded', array() );
		return is_array( $state ) && ! empty( $state['active'] );
	}

	private function ensure_required_schedules() {
		if ( ! wp_next_scheduled( 'nbh_performance_runtime_watchdog_tick' ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'nbh_performance_every_five_minutes', 'nbh_performance_runtime_watchdog_tick' );
		}
		if ( ! wp_next_scheduled( 'nbh_performance_job_worker_tick' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nbh_performance_every_minute', 'nbh_performance_job_worker_tick' );
		}
		if ( ! wp_next_scheduled( 'nbh_performance_job_prune_tick' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nbh_performance_job_prune_tick' );
		}
	}

	private function persist_degraded_state( array $report ) {
		$current = get_option( 'nbh_performance_runtime_degraded', array() );
		$current = is_array( $current ) ? $current : array();
		if ( empty( $report['degraded'] ) ) {
			update_option(
				'nbh_performance_runtime_degraded',
				array(
					'active'          => false,
					'recovered_at'    => time(),
					'last_checked_at' => time(),
					'reasons'         => array(),
				),
				false
			);
			return;
		}
		update_option(
			'nbh_performance_runtime_degraded',
			array(
				'active'          => true,
				'entered_at'      => ! empty( $current['active'] ) && ! empty( $current['entered_at'] ) ? absint( $current['entered_at'] ) : time(),
				'last_checked_at' => time(),
				'reasons'         => isset( $report['critical_ids'] ) ? array_values( $report['critical_ids'] ) : array(),
			),
			false
		);
	}

	private function add( array &$checks, $id, $passed, $critical, $value, $recommendation ) {
		$checks[] = array(
			'id'             => sanitize_key( $id ),
			'passed'         => (bool) $passed,
			'critical'       => (bool) $critical,
			'value'          => sanitize_text_field( (string) $value ),
			'recommendation' => sanitize_text_field( (string) $recommendation ),
		);
	}

	private function memory_limit_bytes() {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '-1' === $value || '' === $value ) {
			return PHP_INT_MAX;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (int) $value;
		if ( 'g' === $unit ) {
			return $number * 1024 * 1024 * 1024;
		}
		if ( 'm' === $unit ) {
			return $number * 1024 * 1024;
		}
		if ( 'k' === $unit ) {
			return $number * 1024;
		}
		return $number;
	}


	private function installed_build_id() {
		$file = defined( 'NBH_PERFORMANCE_PATH' ) ? trailingslashit( NBH_PERFORMANCE_PATH ) . 'nbh-performance.php' : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head = '' !== $file && is_readable( $file ) ? file_get_contents( $file, false, null, 0, 8192 ) : false;
		if ( is_string( $head ) && preg_match( "/NBH_PERFORMANCE_BUILD'\s*,\s*'([^']+)'/", $head, $matches ) ) {
			return sanitize_text_field( $matches[1] );
		}
		return defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '';
	}

	private function is_nbh_update( $options ) {
		if ( ! is_array( $options ) || ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return false;
		}
		if ( isset( $options['plugin'] ) ) {
			return NBH_PERFORMANCE_BASENAME === $options['plugin'];
		}
		return isset( $options['plugins'] ) && is_array( $options['plugins'] ) && in_array( NBH_PERFORMANCE_BASENAME, $options['plugins'], true );
	}
}
