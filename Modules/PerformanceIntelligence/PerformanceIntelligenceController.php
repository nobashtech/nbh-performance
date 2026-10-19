<?php
/**
 * Scheduled performance intelligence, unified analytics, and REST controls.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\PerformanceIntelligence;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use WP_REST_Request;
use WP_REST_Server;

final class PerformanceIntelligenceController implements Module {
	private $storage;
	private $metrics;
	private $regressions;
	private $queue;
	private $impacts;

	public function __construct( LabTestStorage $storage, UnifiedMetricsService $metrics, RegressionDetector $regressions, JobQueue $queue, PerformanceImpactTracker $impacts ) {
		$this->storage     = $storage;
		$this->metrics     = $metrics;
		$this->regressions = $regressions;
		$this->queue       = $queue;
		$this->impacts     = $impacts;
	}

	public function register() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule' ), 5 );
		add_action( 'nbh_performance_performance_lab_schedule', array( $this, 'scheduled_run' ) );
		add_action( 'nbh_performance_performance_lab_prune', array( $this->storage, 'prune' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'nbh_performance_intelligence_recommendations', array( $this, 'recommendations' ) );
	}

	public function cron_schedules( $schedules ) {
		$schedules['nbh_performance_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'nbh-performance' ),
		);
		return $schedules;
	}

	public function schedule() {
		$enabled   = (bool) get_option( 'nbh_performance_lab_testing_enabled', 0 );
		$frequency = sanitize_key( get_option( 'nbh_performance_lab_test_frequency', 'weekly' ) );
		$frequency = in_array( $frequency, array( 'daily', 'weekly' ), true ) ? $frequency : 'weekly';
		$hook      = 'nbh_performance_performance_lab_schedule';
		$scheduled = wp_next_scheduled( $hook );
		$current   = get_option( 'nbh_performance_lab_scheduled_frequency', '' );
		if ( ! $enabled ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( $hook );
			}
			delete_option( 'nbh_performance_lab_scheduled_frequency' );
			return;
		}
		if ( $scheduled && $current !== $frequency ) {
			wp_clear_scheduled_hook( $hook );
			$scheduled = false;
		}
		if ( ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily' === $frequency ? 'daily' : 'nbh_performance_weekly', $hook );
			update_option( 'nbh_performance_lab_scheduled_frequency', $frequency, false );
		}
		if ( ! wp_next_scheduled( 'nbh_performance_performance_lab_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'nbh_performance_performance_lab_prune' );
		}
	}

	public function scheduled_run() {
		if ( get_option( 'nbh_performance_lab_testing_enabled', 0 ) ) {
			$this->enqueue_suite();
		}
	}

	public function routes() {
		$permission = static function ( $request ) {
			return current_user_can( 'manage_options' ) && ( 'GET' === $request->get_method() || \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request ) );
		};
		foreach ( array(
			'local-check' => 'local_check',
			'monitoring'  => 'monitoring',
		) as $route => $callback ) {
			nbh_performance_register_rest_route(
				'nbh-performance/v1',
				'/performance-intelligence/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $permission,
				)
			);
		}
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance-intelligence/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance-intelligence/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'history' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance-intelligence/run',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'run_now' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance-intelligence/baseline',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'baseline' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance-intelligence/alerts/acknowledge',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'acknowledge' ),
				'permission_callback' => $permission,
			)
		);
	}

	public function summary( WP_REST_Request $request ) {
		$requested_days_1               = $request->get_param( 'days' );
		$summary                        = $this->metrics->summary( $requested_days_1 ? $requested_days_1 : 28 );
		$summary['local_check']         = get_option( LocalPerformanceCheck::OPTION, null );
		$summary['lab']['availability'] = LabTestClient::availability();
		$summary['lab']['jobs']         = $this->lab_jobs();
		$summary['collection']          = array(
			'enabled'     => (bool) get_option( 'nbh_performance_rum_enabled', 0 ),
			'safe_mode'   => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
			'sample_rate' => max( 1, min( 100, (float) get_option( 'nbh_performance_rum_sample_rate', 10 ) ) ),
			'home_url'    => home_url( '/' ),
		);
		return rest_ensure_response( $summary );
	}

	/** Run a bounded same-site diagnostic, without Google or a browser engine. */
	public function local_check() {
		$result = ( new LocalPerformanceCheck() )->run();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Explicit monitoring choice; keep Safe Mode and sampling preferences intact. */
	public function monitoring( WP_REST_Request $request ) {
		$enabled = $request->get_param( 'enabled' );
		if ( ! is_bool( $enabled ) ) {
			return new \WP_Error( 'nbh_performance_monitoring_value', __( 'Choose whether visitor monitoring is enabled.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$before = (bool) get_option( 'nbh_performance_rum_enabled', 0 );
		if ( $before === $enabled ) {
			return rest_ensure_response( array( 'enabled' => $before ) );
		}
		( new \NBH\Performance\Modules\Hub\FeatureToggleManager() )->update( array( 'nbh_performance_rum_enabled' => $enabled ) );
		if ( $before !== $enabled ) {
			do_action( 'nbh_performance_request_full_purge', 'monitoring_changed' );
		}
		return rest_ensure_response( array( 'enabled' => (bool) get_option( 'nbh_performance_rum_enabled', 0 ) ) );
	}

	/** Return only lab jobs, including retry and failure messages. */
	private function lab_jobs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin queue status must reflect asynchronous worker changes immediately.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id,status,attempts,max_attempts,last_error,updated_at FROM %i WHERE type = %s ORDER BY id DESC LIMIT 20', \NBH\Performance\Modules\Jobs\JobStore::table_name(), 'performance_lab_test' ), ARRAY_A );
	}

	public function history( WP_REST_Request $request ) {
		$requested_limit_2 = $request->get_param( 'limit' );
		return rest_ensure_response(
			array(
				'lab'     => $this->storage->history( $requested_limit_2 ? $requested_limit_2 : 100 ),
				'alerts'  => $this->regressions->alerts( 100 ),
				'impacts' => $this->impacts->history(),
			)
		);
	}

	public function run_now( WP_REST_Request $request ) {
		$availability = LabTestClient::availability();
		if ( ! $availability['available'] ) {
			return new \WP_Error( 'nbh_performance_lab_unavailable', $availability['reason'], array( 'status' => 400 ) );
		}
		$urls       = $request->get_param( 'urls' );
		$urls       = is_array( $urls ) && $urls ? $this->sanitize_urls( $urls ) : self::configured_urls();
		$strategies = $request->get_param( 'strategies' );
		$strategies = is_array( $strategies ) && $strategies ? array_values( array_intersect( array( 'mobile', 'desktop' ), array_map( 'sanitize_key', array_filter( $strategies, 'is_string' ) ) ) ) : self::configured_strategies();
		$queued     = $this->enqueue_suite( $urls, $strategies );
		if ( ! $queued ) {
			return new \WP_Error( 'nbh_performance_lab_not_queued', __( 'No tests could be queued. Check the public page URLs, device selection and background queue.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'queued'     => $queued,
				'urls'       => $urls,
				'strategies' => $strategies,
			)
		);
	}

	public function baseline( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'result_id' ) );
		if ( ! $id || ! $this->storage->set_baseline( $id ) ) {
			return new \WP_Error( 'nbh_performance_baseline_failed', __( 'Select a successful lab result to use as the baseline.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'baseline'  => true,
				'result_id' => $id,
			)
		);
	}

	public function acknowledge( WP_REST_Request $request ) {
		$id = sanitize_text_field( $request->get_param( 'alert_id' ) );
		return rest_ensure_response( array( 'acknowledged' => $this->regressions->acknowledge( $id ) ) );
	}

	/** Enqueue bounded same-origin performance tests. */
	public function enqueue_suite( $urls = null, $strategies = null ) {
		$availability = LabTestClient::availability();
		if ( ! $availability['available'] ) {
			return array();
		}
		$urls       = is_array( $urls ) ? $this->sanitize_urls( $urls ) : self::configured_urls();
		$strategies = is_array( $strategies ) ? $strategies : self::configured_strategies();
		$queued     = array();
		$bucket     = gmdate( 'YmdH' );
		foreach ( array_slice( $urls, 0, 10 ) as $url ) {
			foreach ( array_intersect( array( 'mobile', 'desktop' ), $strategies ) as $strategy ) {
				$id = $this->queue->enqueue(
					'performance_lab_test',
					array(
						'url'      => $url,
						'strategy' => $strategy,
					),
					array(
						'priority'     => 25,
						'max_attempts' => 3,
						'unique_key'   => 'lab-' . $bucket . '-' . md5( $url . $strategy ),
					)
				);
				if ( $id ) {
					$queued[] = $id;
				}
			}
		}
		do_action( 'nbh_performance_performance_lab_tests_queued', $queued, $urls, $strategies );
		return $queued;
	}

	/** Selected pages with homepage fallback. */
	public static function configured_urls() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_lab_test_urls', '' ) );
		$urls  = array_filter( array_map( 'esc_url_raw', (array) $lines ) );
		if ( empty( $urls ) ) {
			$urls = array( home_url( '/' ) );
		}
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$urls      = array_values(
			array_unique(
				array_filter(
					$urls,
					static function ( $url ) use ( $home_host ) {
						return wp_http_validate_url( $url ) && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $home_host;
					}
				)
			)
		);
		return array_slice( $urls, 0, 10 );
	}

	public static function configured_strategies() {
		$value = sanitize_key( get_option( 'nbh_performance_lab_test_strategies', 'both' ) );
		if ( 'mobile' === $value || 'desktop' === $value ) {
			return array( $value );
		}
		return array( 'mobile', 'desktop' );
	}

	public function recommendations( $items ) {
		$items   = is_array( $items ) ? $items : array();
		$summary = $this->metrics->summary( 28 );
		$lab     = isset( $summary['lab']['averages'] ) ? $summary['lab']['averages'] : array();
		if ( isset( $lab['lcp'] ) && is_numeric( $lab['lcp'] ) && $lab['lcp'] > 2500 ) {
			$items[] = array(
				'id'       => 'lab-lcp',
				'priority' => 94,
				'severity' => 'critical',
				'title'    => __( 'Improve laboratory LCP', 'nbh-performance' ),
				/* translators: %d: measured time in milliseconds. */
				'reason'   => sprintf( __( 'Average lab LCP is %d ms across monitored pages.', 'nbh-performance' ), round( $lab['lcp'] ) ),
				'action'   => 'enable_render_path',
				'evidence' => array( 'lab_lcp' => $lab['lcp'] ),
			);
		}
		if ( isset( $lab['tbt'] ) && is_numeric( $lab['tbt'] ) && $lab['tbt'] > 300 ) {
			$items[] = array(
				'id'       => 'lab-tbt',
				'priority' => 89,
				'severity' => 'warning',
				'title'    => __( 'Reduce laboratory main-thread blocking', 'nbh-performance' ),
				/* translators: %d: measured time in milliseconds. */
				'reason'   => sprintf( __( 'Average Total Blocking Time is %d ms.', 'nbh-performance' ), round( $lab['tbt'] ) ),
				'action'   => 'enable_safe_script_delay',
				'evidence' => array( 'lab_tbt' => $lab['tbt'] ),
			);
		}
		foreach ( array_slice( $summary['alerts'], 0, 3 ) as $alert ) {
			$items[] = array(
				'id'       => 'regression-' . sanitize_key( $alert['id'] ),
				'priority' => 'critical' === $alert['severity'] ? 100 : 92,
				'severity' => $alert['severity'],
				'title'    => __( 'Review a detected performance regression', 'nbh-performance' ),
				/* translators: 1: comma-separated performance metric names, 2: device testing strategy. */
				'reason'   => sprintf( __( '%1$s regressed on %2$s.', 'nbh-performance' ), strtoupper( implode( ', ', wp_list_pluck( $alert['issues'], 'metric' ) ) ), $alert['strategy'] ),
				'action'   => null,
				'evidence' => $alert,
			);
		}
		return $items;
	}

	private function sanitize_urls( array $urls ) {
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$output    = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$url = esc_url_raw( $url );
			if ( wp_http_validate_url( $url ) && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $home_host ) {
				$output[] = $url;
			}
		}
		return array_slice( array_values( array_unique( $output ) ), 0, 10 );
	}
}
