<?php
/** Guarded, measurable, canary-first optimization autopilot. @package NBHPerformance */
namespace NBH\Performance\Modules\PerformanceIntelligence;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\ConfigurationTransactionManager;
use NBH\Performance\Modules\Validation\StagingValidator;
use WP_REST_Request;
use WP_REST_Server;
final class AutopilotManager implements Module {
	private $metrics;
	private $budgets;
	private $transactions;
	private $validator;
	public function __construct( UnifiedMetricsService $metrics, PerformanceBudgetEvaluator $budgets, ConfigurationTransactionManager $transactions, StagingValidator $validator ) {
		$this->metrics      = $metrics;
		$this->budgets      = $budgets;
		$this->transactions = $transactions;
		$this->validator    = $validator;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_autopilot_evaluate', array( $this, 'evaluate_active_run' ) );
	}
	public function routes() {
		$p = static function () {
				return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/autopilot/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $p,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/autopilot/start',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => $p,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/autopilot/evaluate',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'evaluate' ),
				'permission_callback' => $p,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/autopilot/cancel',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => $p,
			)
		);
	}
	public function status() {
		$run = (array) get_option( 'nbh_performance_autopilot_run', array() );
		return rest_ensure_response(
			array(
				'enabled'          => (bool) get_option( 'nbh_performance_autopilot_enabled', 0 ),
				'run'              => $run,
				'environment'      => $this->validator->is_staging() ? 'staging' : 'production',
				'production_guard' => (bool) get_option( 'nbh_performance_autopilot_production_enabled', 0 ),
			)
		);
	}
	public function start( WP_REST_Request $request ) {
		if ( ! $this->validator->is_staging() && ! get_option( 'nbh_performance_autopilot_production_enabled', 0 ) ) {
			return new \WP_Error( 'nbh_performance_autopilot_guard', __( 'Autopilot production changes are disabled. Validate on staging or explicitly enable guarded production canaries.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$existing = (array) get_option( 'nbh_performance_autopilot_run', array() );
		if ( $existing && in_array( isset( $existing['status'] ) ? $existing['status'] : '', array( 'canary', 'observing' ), true ) ) {
			return new \WP_Error( 'nbh_performance_autopilot_active', __( 'An Autopilot run is already active.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$requested_canary_percent_1  = $request->get_param( 'canary_percent' );
		$percent                     = max( 1, min( 25, absint( $requested_canary_percent_1 ? $requested_canary_percent_1 : 5 ) ) );
		$requested_observe_minutes_2 = $request->get_param( 'observe_minutes' );
		$baseline                    = $this->metrics->snapshot();
		$id                          = wp_generate_uuid4();
		$run                         = array(
			'id'             => $id,
			'status'         => 'canary',
			'canary_percent' => $percent,
			'baseline'       => $baseline,
			'started_at'     => time(),
			'observe_until'  => time() + max( 30, min( 1440, absint( $requested_observe_minutes_2 ? $requested_observe_minutes_2 : 180 ) ) ) * MINUTE_IN_SECONDS,
			'transaction_id' => '',
			'decision'       => 'pending',
		);
		update_option( 'nbh_performance_autopilot_run', $run, false );
		update_option( 'nbh_performance_autopilot_enabled', 1, false );
		wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, 'nbh_performance_autopilot_evaluate' );
		do_action( 'nbh_performance_autopilot_started', $run );
		return rest_ensure_response( $run );
	}
	public function evaluate() {
		return rest_ensure_response( $this->evaluate_active_run() );
	}
	public function evaluate_active_run() {
		$run = (array) get_option( 'nbh_performance_autopilot_run', array() );
		if ( empty( $run['id'] ) || ! in_array( isset( $run['status'] ) ? $run['status'] : '', array( 'canary', 'observing' ), true ) ) {
			return array(
				'evaluated' => false,
				'reason'    => 'no_active_run',
			);
		}
		$current             = $this->metrics->summary( 28 );
		$budget              = $this->budgets->evaluate( $current );
		$samples             = isset( $current['rum']['samples'] ) ? absint( $current['rum']['samples'] ) : 0;
		$minimum             = max( 30, absint( get_option( 'nbh_performance_autopilot_minimum_samples', 100 ) ) );
		$run['latest']       = $this->metrics->snapshot();
		$run['budget']       = $budget;
		$run['samples']      = $samples;
		$run['evaluated_at'] = time();
		if ( $samples < $minimum && time() < $run['observe_until'] ) {
			$run['status']   = 'observing';
			$run['decision'] = 'insufficient_samples';
			update_option( 'nbh_performance_autopilot_run', $run, false );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'nbh_performance_autopilot_evaluate' );
			return $run;
		}
		if ( null === $budget['passed'] ) {
			$run['status']   = 'blocked';
			$run['decision'] = 'insufficient_measurements';
		} elseif ( ! $budget['passed'] ) {
			$run['status']   = 'blocked';
			$run['decision'] = 'rollback_or_hold';
			do_action( 'nbh_performance_autopilot_regression', $run, $budget );
		} else {
			$run['status']   = 'approved';
			$run['decision'] = 'promote';
			do_action( 'nbh_performance_autopilot_approved', $run );
		}
			update_option( 'nbh_performance_autopilot_run', $run, false );
			return $run;
	}
	public function cancel() {
		$run                 = (array) get_option( 'nbh_performance_autopilot_run', array() );
		$run['status']       = 'cancelled';
		$run['cancelled_at'] = time();
		update_option( 'nbh_performance_autopilot_run', $run, false );
		return rest_ensure_response( $run );
	}
	public static function visitor_in_canary() {
		$run = (array) get_option( 'nbh_performance_autopilot_run', array() );
		if ( empty( $run['id'] ) || ! in_array( isset( $run['status'] ) ? $run['status'] : '', array( 'canary', 'observing' ), true ) ) {
			return false;
		}
		$seed = isset( $_COOKIE['nbh_perf_canary'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['nbh_perf_canary'] ) ) : '';
		if ( ! $seed ) {
			$seed = wp_generate_uuid4();
			if ( ! headers_sent() ) {
				setcookie( 'nbh_perf_canary', $seed, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			}
		}
		return ( hexdec( substr( hash( 'sha256', $seed . $run['id'] ), 0, 8 ) ) % 100 ) < absint( $run['canary_percent'] );
	}
}
