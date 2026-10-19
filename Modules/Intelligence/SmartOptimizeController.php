<?php
/**
 * Smart Optimize orchestration and REST API.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\AdvancedSettingsManager;
use NBH\Performance\Modules\Hub\ConfigurationTransactionManager;
use NBH\Performance\Modules\Hub\FeatureToggleManager;
use NBH\Performance\Modules\Hub\SafeModeManager;
use NBH\Performance\Modules\Jobs\JobQueue;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class SmartOptimizeController implements Module {
	/** @var SmartOptimizePlanBuilder */
	private $plans;
	/** @var FeatureToggleManager */
	private $toggles;
	/** @var AdvancedSettingsManager */
	private $settings;
	/** @var ConfigurationTransactionManager */
	private $transactions;
	/** @var SafeModeManager */
	private $safe_mode;
	/** @var JobQueue */
	private $queue;
	/** @var PerformanceReportService */
	private $reports;
	/** @var AutomaticRollbackManager */
	private $rollback;

	public function __construct( SmartOptimizePlanBuilder $plans, FeatureToggleManager $toggles, AdvancedSettingsManager $settings, ConfigurationTransactionManager $transactions, SafeModeManager $safe_mode, JobQueue $queue, PerformanceReportService $reports, AutomaticRollbackManager $rollback ) {
		$this->plans        = $plans;
		$this->toggles      = $toggles;
		$this->settings     = $settings;
		$this->transactions = $transactions;
		$this->safe_mode    = $safe_mode;
		$this->queue        = $queue;
		$this->reports      = $reports;
		$this->rollback     = $rollback;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_staging_validation_completed', array( $this, 'validation_completed' ) );
		add_action( 'nbh_performance_configuration_transaction_rolled_back', array( $this, 'transaction_rolled_back' ), 10, 2 );
		add_action( 'nbh_performance_lab_test_completed', array( $this, 'lab_test_completed' ), 10, 3 );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/smart-optimize/plan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_plan' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/smart-optimize/start',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_start' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/smart-optimize/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/smart-optimize/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_report' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/smart-optimize/rollback',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_rollback' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}

	public function rest_plan( WP_REST_Request $request ) {
		return rest_ensure_response( $this->plans->build( (string) $request->get_param( 'mode' ) ) );
	}

	public function rest_start( WP_REST_Request $request ) {
		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
		$plan = $this->plans->build( $mode );
		if ( empty( $plan['eligible'] ) ) {
			return new \WP_Error(
				'nbh_performance_smart_optimize_blocked',
				__( 'Smart Optimize is blocked by the current environment.', 'nbh-performance' ),
				array(
					'status' => 409,
					'plan'   => $plan,
				)
			);
		}
		$running = get_option( 'nbh_performance_smart_optimize_last_run', array() );
		if ( is_array( $running ) && in_array( isset( $running['status'] ) ? $running['status'] : '', array( 'applying', 'validating', 'measuring' ), true ) && time() - absint( isset( $running['started_at'] ) ? $running['started_at'] : 0 ) < 15 * MINUTE_IN_SECONDS ) {
			return new \WP_Error(
				'nbh_performance_smart_optimize_running',
				__( 'A Smart Optimize run is already in progress.', 'nbh-performance' ),
				array(
					'status' => 409,
					'run'    => $running,
				)
			);
		}

		$run_id  = wp_generate_uuid4();
		$options = array_merge( array_keys( $plan['features'] ), array_keys( $plan['settings'] ) );
		$tx_id   = $this->transactions->begin( 'smart_optimize', $options );
		$steps   = $this->mark_steps(
			$plan['steps'],
			array(
				'baseline'  => 'complete',
				'configure' => 'running',
			)
		);
		$run     = array(
			'id'             => $run_id,
			'mode'           => $plan['mode'],
			'status'         => 'applying',
			'started_at'     => time(),
			'transaction_id' => $tx_id,
			'before'         => $plan['before'],
			'plan'           => array(
				'site_type'     => $plan['site_type'],
				'hosting_tier'  => $plan['hosting_tier'],
				'warnings'      => $plan['warnings'],
				'blockers'      => $plan['blockers'],
				'steps'         => $steps,
				'feature_count' => count( $plan['features'] ),
				'setting_count' => count( $plan['settings'] ),
			),
			'proof'          => array(
				'lab_queued'     => false,
				'measure_forced' => true,
			),
		);
		update_option( 'nbh_performance_smart_optimize_last_run', $run, false );

		$updated_features = $this->toggles->update( $plan['features'] );
		$updated_settings = $this->settings->update( $plan['settings'] );
		$this->safe_mode->disable( false );
		$this->transactions->finish( 'smart_optimize', array_merge( $updated_features, $updated_settings ) );

		$run['plan']['steps']    = $this->mark_steps(
			$steps,
			array(
				'configure' => 'complete',
				'purge'     => 'running',
			)
		);
		$run['status']           = 'validating';
		$run['applied_at']       = time();
		$run['updated_features'] = array_keys( $updated_features );
		$run['updated_settings'] = array_keys( $updated_settings );
		update_option( 'nbh_performance_smart_optimize_last_run', $run, false );

		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'all'       => true,
				'warm_urls' => array( home_url( '/' ) ),
				'reason'    => 'smart_optimize',
			),
			array(
				'priority'   => 100,
				'unique_key' => 'smart-optimize-purge|' . $run_id,
			)
		);
		if ( ! empty( $plan['browser_engine'] ) && ( ! empty( $updated_features['nbh_performance_critical_css_enabled'] ) || ! empty( $updated_features['nbh_performance_unused_css_enabled'] ) ) ) {
			foreach ( array( 'desktop', 'mobile' ) as $variant ) {
				$this->queue->enqueue(
					'critical_css_generate',
					array(
						'url'     => home_url( '/' ),
						'variant' => $variant,
						'reason'  => 'smart-optimize',
					),
					array(
						'delay'        => 20,
						'priority'     => 80,
						'max_attempts' => 4,
						'unique_key'   => 'smart-visual|' . $run_id . '|' . $variant,
					)
				);
			}
		}

		// Soft-launch proof: always queue homepage lab tests for this run (even if scheduled lab is off).
		$lab_queued = 0;
		foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
			$job = $this->queue->enqueue(
				'performance_lab_test',
				array(
					'url'       => home_url( '/' ),
					'strategy'  => $strategy,
					'change_id' => $run_id,
					'reason'    => 'smart_optimize_proof',
				),
				array(
					'delay'        => 90,
					'priority'     => 45,
					'max_attempts' => 3,
					'unique_key'   => 'smart-lab|' . $run_id . '|' . $strategy,
				)
			);
			if ( $job ) {
				++$lab_queued;
			}
		}

		$run['plan']['steps']       = $this->mark_steps(
			$run['plan']['steps'],
			array(
				'purge'    => 'complete',
				'validate' => 'running',
				'protect'  => 'running',
			)
		);
		$run['proof']['lab_queued'] = $lab_queued > 0;
		$run['proof']['lab_jobs']   = $lab_queued;
		update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
		update_option( 'nbh_performance_auto_performance_mode', $plan['mode'], false );
		update_option( 'nbh_performance_auto_performance_applied_at', time(), false );
		do_action( 'nbh_performance_smart_optimize_started', $run, $plan );
		return rest_ensure_response(
			array(
				'started'  => true,
				'run'      => $run,
				'plan'     => $plan,
				'rollback' => $this->rollback->status(),
			)
		);
	}

	public function rest_status() {
		return rest_ensure_response( $this->status() );
	}

	public function rest_report() {
		return rest_ensure_response( $this->reports->report( $this->run() ) );
	}

	public function rest_rollback() {
		$run = $this->run();
		if ( empty( $run['transaction_id'] ) ) {
			return new \WP_Error( 'nbh_performance_smart_optimize_missing', __( 'No Smart Optimize transaction is available.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		$success = $this->transactions->rollback( $run['transaction_id'], 'manual_smart_optimize' );
		return rest_ensure_response(
			array(
				'success' => $success,
				'run'     => $this->run(),
			)
		);
	}

	public function status() {
		$run    = $this->hydrate_run( $this->run() );
		$report = $this->reports->report( $run );
		return array(
			'run'      => $run,
			'rollback' => $this->rollback->status(),
			'report'   => $report,
			'proof'    => $this->proof_summary( $run, $report ),
		);
	}

	public function validation_completed( $report ) {
		$run = $this->run();
		if ( empty( $run ) || ! in_array( isset( $run['status'] ) ? $run['status'] : '', array( 'applying', 'validating', 'measuring' ), true ) ) {
			return;
		}
		$run['validation'] = is_array( $report ) ? $report : array();
		$passed            = ! empty( $report['passed'] );
		$rollback_needed   = ! empty( $report['rollback_recommended'] );
		if ( $rollback_needed ) {
			$run['status']        = 'needs_rollback';
			$run['plan']['steps'] = $this->mark_steps(
				isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(),
				array(
					'validate' => 'failed',
					'measure'  => 'skipped',
					'protect'  => 'running',
				)
			);
		} elseif ( $passed ) {
			$run['status']        = 'measuring';
			$run['plan']['steps'] = $this->mark_steps(
				isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(),
				array(
					'validate' => 'complete',
					'measure'  => 'running',
					'protect'  => 'running',
				)
			);
			$run                  = $this->capture_after( $run );
			// Complete when validation passes; lab results enrich proof asynchronously.
			if ( empty( $run['proof']['lab_queued'] ) ) {
				$run = $this->finalize_complete( $run );
			} else {
				// Soft-complete so Hub shows a win while lab jobs finish.
				$run = $this->finalize_complete( $run, true );
			}
		} else {
			$run['status']        = 'needs_review';
			$run['plan']['steps'] = $this->mark_steps(
				isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(),
				array(
					'validate' => 'failed',
					'measure'  => 'skipped',
				)
			);
		}
		update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
	}

	public function lab_test_completed( $result_id, $result, $alert ) {
		$run = $this->run();
		if ( empty( $run['id'] ) || ! is_array( $result ) || ( isset( $result['change_id'] ) ? (string) $result['change_id'] : '' ) !== (string) $run['id'] ) {
			return;
		}
		if ( ! isset( $run['lab_results'] ) || ! is_array( $run['lab_results'] ) ) {
			$run['lab_results'] = array();
		}
		$run['lab_results'][] = array(
			'result_id'   => absint( $result_id ),
			'strategy'    => isset( $result['strategy'] ) ? sanitize_key( $result['strategy'] ) : '',
			'captured_at' => time(),
			'regression'  => is_array( $alert ) ? $alert : array(),
			'score'       => isset( $result['performance_score'] ) ? absint( $result['performance_score'] ) : ( isset( $result['score'] ) ? absint( $result['score'] ) : 0 ),
			'lcp'         => isset( $result['lcp'] ) ? round( (float) $result['lcp'], 2 ) : null,
			'ttfb'        => isset( $result['ttfb'] ) ? round( (float) $result['ttfb'], 2 ) : null,
		);
		$run['lab_results']   = array_slice( $run['lab_results'], -10 );
		$run                  = $this->capture_after( $run );
		$run['plan']['steps'] = $this->mark_steps(
			isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(),
			array(
				'measure' => 'complete',
				'protect' => 'complete',
			)
		);
		if ( in_array( isset( $run['status'] ) ? $run['status'] : '', array( 'measuring', 'validating', 'complete' ), true ) ) {
			$run = $this->finalize_complete( $run );
		}
		update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
	}

	public function transaction_rolled_back( $transaction_id, $reason ) {
		$run = $this->run();
		if ( empty( $run['transaction_id'] ) || $transaction_id !== $run['transaction_id'] ) {
			return;
		}
		$run['status']          = 'rolled_back';
		$run['rolled_back_at']  = time();
		$run['rollback_reason'] = sanitize_key( $reason );
		$run['plan']['steps']   = $this->mark_steps( isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(), array( 'protect' => 'complete' ) );
		update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
	}

	private function hydrate_run( array $run ) {
		if ( empty( $run ) ) {
			return $run;
		}
		if ( ! empty( $run['transaction_id'] ) ) {
			foreach ( $this->transactions->summaries() as $transaction ) {
				if ( $run['transaction_id'] === $transaction['id'] ) {
					$run['transaction'] = $transaction;
					if ( 'validated' === $transaction['status'] && in_array( isset( $run['status'] ) ? $run['status'] : '', array( 'validating', 'measuring' ), true ) ) {
						$run                  = $this->capture_after( $run );
						$run['plan']['steps'] = $this->mark_steps(
							isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(),
							array(
								'validate' => 'complete',
								'measure'  => empty( $run['proof']['lab_queued'] ) ? 'complete' : 'running',
								'protect'  => 'running',
							)
						);
						$run                  = $this->finalize_complete( $run, ! empty( $run['proof']['lab_queued'] ) );
						update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
					}
					break;
				}
			}
		}
		// Time-box measuring → complete so Hub never stalls forever waiting on lab API.
		if ( 'measuring' === ( isset( $run['status'] ) ? $run['status'] : '' ) && time() - absint( isset( $run['applied_at'] ) ? $run['applied_at'] : $run['started_at'] ) > 8 * MINUTE_IN_SECONDS ) {
			$run                  = $this->capture_after( $run );
			$run['plan']['steps'] = $this->mark_steps(
				isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(),
				array(
					'measure' => ! empty( $run['lab_results'] ) ? 'complete' : 'deferred',
					'protect' => 'complete',
				)
			);
			$run                  = $this->finalize_complete( $run );
			update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
		}
		return $run;
	}

	private function finalize_complete( array $run, $soft = false ) {
		$run['status'] = 'complete';
		if ( empty( $run['completed_at'] ) ) {
			$run['completed_at'] = time();
		}
		$run   = $this->capture_after( $run );
		$steps = array( 'protect' => 'complete' );
		if ( ! $soft ) {
			$steps['measure'] = ! empty( $run['lab_results'] ) ? 'complete' : ( ! empty( $run['proof']['lab_queued'] ) ? 'deferred' : 'complete' );
		}
		$run['plan']['steps'] = $this->mark_steps( isset( $run['plan']['steps'] ) ? $run['plan']['steps'] : array(), $steps );
		$report               = $this->reports->report( $run );
		$run['proof_summary'] = $this->proof_summary( $run, $report );
		update_option(
			'nbh_performance_soft_launch_first_win',
			array(
				'at'     => time(),
				'mode'   => isset( $run['mode'] ) ? $run['mode'] : '',
				'run_id' => isset( $run['id'] ) ? $run['id'] : '',
				'proof'  => $run['proof_summary'],
			),
			false
		);
		return $run;
	}

	private function capture_after( array $run ) {
		$report = $this->reports->report( $run );
		if ( ! empty( $report['after'] ) && is_array( $report['after'] ) ) {
			$run['after'] = $report['after'];
		}
		return $run;
	}

	private function proof_summary( array $run, array $report ) {
		$delta   = isset( $report['delta'] ) && is_array( $report['delta'] ) ? $report['delta'] : array();
		$summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		return array(
			'status'             => isset( $run['status'] ) ? sanitize_key( $run['status'] ) : 'not_started',
			'mode'               => isset( $run['mode'] ) ? sanitize_key( $run['mode'] ) : '',
			'headline'           => isset( $summary['headline'] ) ? sanitize_text_field( (string) $summary['headline'] ) : '',
			'features_applied'   => isset( $run['updated_features'] ) ? count( (array) $run['updated_features'] ) : 0,
			'settings_applied'   => isset( $run['updated_settings'] ) ? count( (array) $run['updated_settings'] ) : 0,
			'health_delta'       => isset( $delta['health'] ) && is_numeric( $delta['health'] ) ? (int) $delta['health'] : null,
			'lcp_delta'          => isset( $delta['lab']['lcp'] ) && is_numeric( $delta['lab']['lcp'] ) ? (float) $delta['lab']['lcp'] : null,
			'ttfb_delta'         => isset( $delta['lab']['ttfb'] ) && is_numeric( $delta['lab']['ttfb'] ) ? (float) $delta['lab']['ttfb'] : null,
			'score_delta'        => isset( $delta['lab']['performance_score'] ) && is_numeric( $delta['lab']['performance_score'] ) ? (float) $delta['lab']['performance_score'] : null,
			'image_savings'      => isset( $report['image_savings'] ) ? absint( $report['image_savings'] ) : 0,
			'lab_results'        => isset( $run['lab_results'] ) ? count( (array) $run['lab_results'] ) : 0,
			'baseline_available' => ! empty( $report['evidence']['baseline_available'] ),
			'validation_passed'  => ! empty( $run['validation']['passed'] ) || ( 'complete' === ( isset( $run['status'] ) ? $run['status'] : '' ) ),
			'completed_at'       => isset( $run['completed_at'] ) ? absint( $run['completed_at'] ) : 0,
		);
	}

	private function mark_steps( $steps, array $map ) {
		$output = array();
		foreach ( (array) $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$id = isset( $step['id'] ) ? sanitize_key( $step['id'] ) : '';
			if ( $id && isset( $map[ $id ] ) ) {
				$step['status'] = sanitize_key( $map[ $id ] );
			}
			$output[] = $step;
		}
		return $output;
	}

	private function run() {
		$run = get_option( 'nbh_performance_smart_optimize_last_run', array() );
		return is_array( $run ) ? $run : array();
	}
}
