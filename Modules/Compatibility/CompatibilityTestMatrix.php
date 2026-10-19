<?php
/** Automated production compatibility and runtime-readiness test matrix. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\CacheEngine\CacheIndex;
use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class CompatibilityTestMatrix implements Module {
	private $diagnosis;
	private $queue;

	public function __construct( ConflictDiagnosis $diagnosis, JobQueue $queue ) {
		$this->diagnosis = $diagnosis;
		$this->queue     = $queue;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'init', array( $this, 'schedule' ), 20 );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/compatibility/matrix',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/compatibility/matrix/run',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_run' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}
	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}
	public function status() {
		return rest_ensure_response( get_option( 'nbh_performance_compatibility_matrix', array() ) );
	}
	public function rest_run() {
		return rest_ensure_response( $this->run() );
	}
	public function schedule() {
		$last = absint( get_option( 'nbh_performance_compatibility_matrix_checked_at', 0 ) );
		if ( time() - $last > DAY_IN_SECONDS && ! get_transient( 'nbh_performance_compatibility_scan_scheduled' ) ) {
			set_transient( 'nbh_performance_compatibility_scan_scheduled', 1, HOUR_IN_SECONDS );
			$this->queue->enqueue(
				'compatibility_scan',
				array(),
				array(
					'priority'   => 5,
					'unique_key' => 'compatibility-scan|' . gmdate( 'Ymd' ),
					'timeout'    => 180,
				)
			);
		}
	}

	public function run() {
		global $wpdb;
		$tests             = array();
		$tests[]           = $this->test( 'php_version', version_compare( PHP_VERSION, '7.4', '>=' ), 'PHP ' . PHP_VERSION, 'PHP 7.4 or newer is required.' );
		$tests[]           = $this->test( 'wordpress_version', version_compare( get_bloginfo( 'version' ), '6.4', '>=' ), 'WordPress ' . get_bloginfo( 'version' ), 'WordPress 6.4 or newer is required.' );
		$tests[]           = $this->test( 'json_extension', function_exists( 'json_encode' ) && function_exists( 'json_decode' ), 'JSON support is available.', 'PHP JSON support is required.' );
		$tests[]           = $this->test( 'hash_extension', function_exists( 'hash_file' ) && in_array( 'sha256', hash_algos(), true ), 'SHA-256 hashing is available.', 'SHA-256 hashing support is required.' );
		$tests[]           = $this->test( 'sodium_extension', function_exists( 'sodium_crypto_sign_verify_detached' ), 'Ed25519 signature verification is available.', 'PHP Sodium is required for signed licensing and updates.' );
		$rollback_required = (bool) get_option( 'nbh_performance_update_auto_rollback_enabled', 0 );
		$tests[]           = $this->test( 'zip_support', class_exists( 'ZipArchive' ), 'ZIP inspection and rollback support is available.', 'ZipArchive is unavailable; verified automatic rollback cannot run.', $rollback_required ? 'critical' : 'warning' );

		$db_version = method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live schema/worker health is being verified; cached state could hide a broken or expired worker.
		$tests[] = $this->test( 'database_connection', '1' === (string) $wpdb->get_var( 'SELECT 1' ), 'Database connectivity is healthy.', 'WordPress cannot execute a database health query.' );
		$tests[] = $this->test( 'database_version', '' === $db_version || version_compare( preg_replace( '/[^0-9.].*/', '', $db_version ), '5.7', '>=' ), '' === $db_version ? 'Database version was not reported.' : 'Database ' . $db_version, 'MySQL 5.7/MariaDB equivalent or newer is recommended.', 'warning' );
		$tests[] = $this->table_test( JobStore::table_name(), array( 'id', 'status', 'timeout_seconds', 'heartbeat_at', 'lease_token', 'available_at' ), 'job_queue_table' );
		$tests[] = $this->table_test( CacheIndex::table_name(), array( 'id', 'cache_key', 'url', 'file_path' ), 'cache_index_table' );

		$page_cache_enabled = (bool) get_option( 'nbh_performance_cache_enabled', 0 );
		$tests[]            = $this->test( 'wp_cache_constant', ! $page_cache_enabled || ( defined( 'WP_CACHE' ) && WP_CACHE ), $page_cache_enabled ? 'WP_CACHE is enabled.' : 'Page caching is disabled.', 'WP_CACHE is not enabled in wp-config.php.' );
		$tests[]            = $this->test( 'cache_directory', $this->directory_writable( WP_CONTENT_DIR . '/cache/nbh-performance' ), 'NBH Performance cache directory is writable.', 'The cache directory cannot be created or written.' );
		$tests[]            = $this->atomic_filesystem_test();
		$tests[]            = $this->disk_test();
		$tests[]            = $this->dropin_test( 'advanced-cache.php', 'NBH_PERFORMANCE_DROPIN', ! $page_cache_enabled );
		$tests[]            = $this->dropin_test( 'object-cache.php', 'NBH_PERFORMANCE_OBJECT_CACHE_DROPIN', true );
		$tests[]            = $this->test( 'permalinks', '' !== (string) get_option( 'permalink_structure', '' ), 'Pretty permalinks are enabled.', 'Plain permalinks reduce cache and preload reliability.', 'warning' );

		$cron_ok = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || $this->external_cron_is_expected();
		$tests[] = $this->test( 'cron_delivery', $cron_ok, 'Background scheduling delivery is configured.', 'WP-Cron is disabled. Configure an external cron or WP-CLI worker.', 'warning' );
		$tests[] = $this->test( 'worker_schedule', false !== wp_next_scheduled( 'nbh_performance_job_worker_tick' ), 'The background worker schedule exists.', 'The background worker event is not scheduled.', 'warning' );
		$tests[] = $this->test( 'watchdog_schedule', false !== wp_next_scheduled( 'nbh_performance_runtime_watchdog_tick' ), 'The runtime watchdog schedule exists.', 'The runtime watchdog event is not scheduled.', 'warning' );

		$tests[] = $this->loopback_test();
		$tests[] = $this->rest_test();
		$tests[] = $this->multisite_test();
		$tests[] = $this->woocommerce_test();
		$issues  = $this->diagnosis->diagnose();
		$tests[] = $this->test( 'optimizer_ownership', empty( $issues ), empty( $issues ) ? 'No conflicting optimizer ownership was detected.' : count( $issues ) . ' compatibility issue(s) detected.', 'Review the detected optimizer overlap.', empty( $issues ) ? 'ok' : 'warning' );

		$failed   = count(
			array_filter(
				$tests,
				static function ( $test ) {
					return empty( $test['passed'] ) && 'critical' === $test['severity'];
				}
			)
		);
		$warnings = count(
			array_filter(
				$tests,
				static function ( $test ) {
					return empty( $test['passed'] ) && 'warning' === $test['severity'];
				}
			)
		);
		$report   = array(
			'passed'            => 0 === $failed,
			'critical_failures' => $failed,
			'warnings'          => $warnings,
			'tests'             => $tests,
			'checked_at'        => time(),
			'build'             => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
			'site_id'           => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
		);
		update_option( 'nbh_performance_compatibility_matrix', $report, false );
		update_option( 'nbh_performance_compatibility_matrix_checked_at', time(), false );
		return $report;
	}

	private function test( $id, $passed, $success, $failure, $severity = 'critical', array $details = array() ) {
		return array_merge(
			array(
				'id'       => sanitize_key( $id ),
				'passed'   => (bool) $passed,
				'severity' => $passed ? 'ok' : $severity,
				'message'  => $passed ? $success : $failure,
			),
			$details
		);
	}

	private function table_test( $table, array $columns, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema/worker health is being verified; cached state could hide a broken or expired worker.
		$exists = $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			return $this->test( $id, false, '', 'Required database table is missing.' );
		}
		$present = array_map(
			static function ( $row ) {
				return isset( $row['Field'] ) ? (string) $row['Field'] : '';
			},
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema/worker health is being verified; cached state could hide a broken or expired worker.
			(array) $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$missing = array_values( array_diff( $columns, $present ) );
		return $this->test( $id, empty( $missing ), 'Required database table schema is current.', 'Database table schema is incomplete.', 'critical', array( 'missing_columns' => $missing ) );
	}

	private function directory_writable( $path ) {
		return ( is_dir( $path ) || wp_mkdir_p( $path ) ) && \NBH\Performance\Modules\Core\Filesystem::is_writable( $path );
	}

	private function atomic_filesystem_test() {
		$directory = WP_CONTENT_DIR . '/cache/nbh-performance/.runtime-probe';
		if ( ! $this->directory_writable( $directory ) ) {
			return $this->test( 'atomic_filesystem', false, '', 'The cache filesystem is not writable.' );
		}
		$source  = trailingslashit( $directory ) . 'source-' . wp_generate_uuid4();
		$target  = trailingslashit( $directory ) . 'target-' . wp_generate_uuid4();
		$payload = wp_generate_uuid4();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = \NBH\Performance\Modules\Core\Filesystem::put_contents( $source, $payload );
		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- The destination is an owned WP_CONTENT_DIR/cache/nbh-performance runtime/probe/lock file, not a plugin source directory.
		$renamed = $written && @rename( $source, $target );
		$valid   = $renamed && is_file( $target ) && hash_equals( hash( 'sha256', $payload ), (string) hash_file( 'sha256', $target ) );
		if ( is_file( $source ) ) {
			wp_delete_file( $source );
		}
		if ( is_file( $target ) ) {
			wp_delete_file( $target );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::rmdir( $directory );
		return $this->test( 'atomic_filesystem', $valid, 'Atomic cache-file replacement works.', 'The filesystem cannot safely perform atomic cache-file replacement.' );
	}

	private function disk_test() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$free    = @disk_free_space( WP_CONTENT_DIR );
		$reserve = max( 64, absint( get_option( 'nbh_performance_cache_min_free_disk_mb', 128 ) ) ) * MB_IN_BYTES;
		$passed  = false === $free || (float) $free > (float) $reserve;
		return $this->test(
			'disk_reserve',
			$passed,
			false === $free ? 'Free disk space could not be measured.' : 'Disk reserve is available.',
			'Free disk space is below the configured emergency reserve.',
			'critical',
			array(
				'free_bytes'     => false === $free ? null : (int) $free,
				'required_bytes' => $reserve,
			)
		);
	}

	private function dropin_test( $file, $marker, $optional ) {
		$path = WP_CONTENT_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return $this->test( sanitize_key( $file ), $optional, $optional ? $file . ' is not enabled.' : '', $file . ' is missing.', $optional ? 'ok' : 'critical' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head  = file_get_contents( $path, false, null, 0, 4096 );
		$owned = false !== $head && false !== strpos( $head, $marker );
		return $this->test( sanitize_key( $file ), $owned, $file . ' is owned by NBH Performance.', $file . ' is owned by another plugin or host.', $optional ? 'warning' : 'critical' );
	}

	private function loopback_test() {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array( 'X-NBH-Reliability-Probe' => wp_generate_uuid4() ),
				'user-agent'  => 'NBH Performance-Compatibility/' . NBH_PERFORMANCE_VERSION,
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		return $this->test( 'loopback', $code >= 200 && $code < 500, 'Frontend HTTPS loopback requests work.', 'The site cannot complete a verified frontend loopback request.', 'warning', array( 'status_code' => $code ) );
	}

	private function rest_test() {
		$url   = get_rest_url( null, 'nbh-performance/v1/health' );
		$valid = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return $this->test( 'rest_api', $valid && function_exists( 'rest_get_server' ), 'The WordPress REST runtime is available.', 'The WordPress REST runtime is unavailable.', 'critical', array( 'url_host' => (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}

	private function multisite_test() {
		if ( ! is_multisite() ) {
			return $this->test( 'multisite_context', true, 'Single-site context detected.', '', 'ok' );
		}
		$blog_id    = get_current_blog_id();
		$prefix_ok  = false !== strpos( JobStore::table_name(), $GLOBALS['wpdb']->prefix );
		$cache_safe = ! get_option( 'nbh_performance_cache_enabled', 0 );
		$passed     = $blog_id > 0 && $prefix_ok && $cache_safe;
		return $this->test(
			'multisite_context',
			$passed,
			'Multisite blog-scoped storage is active and page caching is safely disabled.',
			'Multisite page caching uses a single-origin early runtime and is therefore fail-closed in this build.',
			'critical',
			array(
				'blog_id'                => $blog_id,
				'page_cache_fail_closed' => $cache_safe,
			)
		);
	}

	private function woocommerce_test() {
		if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_VERSION' ) ) {
			return $this->test( 'woocommerce', true, 'WooCommerce is not active.', '', 'ok' );
		}
		$runtime          = apply_filters(
			'nbh_performance_runtime_config',
			array(
				'bypass_paths'   => array(),
				'bypass_cookies' => array(),
			)
		);
		$paths            = array_map( 'strtolower', isset( $runtime['bypass_paths'] ) ? (array) $runtime['bypass_paths'] : array() );
		$cookies          = array_map( 'strtolower', isset( $runtime['bypass_cookies'] ) ? (array) $runtime['bypass_cookies'] : array() );
		$required_paths   = array( '/checkout', '/cart', '/my-account', '/wp-json/wc/store/' );
		$required_cookies = array( 'woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_' );
		$missing_paths    = array();
		foreach ( $required_paths as $required ) {
			$found = false;
			foreach ( $paths as $path ) {
				if ( false !== strpos( rtrim( $path, '/' ), rtrim( $required, '/' ) ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing_paths[] = $required;
			}
		}
		$missing_cookies = array_values( array_diff( $required_cookies, $cookies ) );
		$passed          = empty( $missing_paths ) && empty( $missing_cookies );
		return $this->test(
			'woocommerce',
			$passed,
			'WooCommerce cart, checkout, Store API and session safeguards are active.',
			'WooCommerce dynamic-route safeguards are incomplete.',
			'critical',
			array(
				'missing_paths'   => $missing_paths,
				'missing_cookies' => $missing_cookies,
			)
		);
	}

	private function external_cron_is_expected() {
		return (bool) get_option( 'nbh_performance_external_cron_configured', 0 );
	}
}
