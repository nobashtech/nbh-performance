<?php
/**
 * Tracks before/after performance evidence around managed configuration changes.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\PerformanceIntelligence;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;

final class PerformanceImpactTracker implements Module {
	const OPTION = 'nbh_performance_performance_change_impacts';
	private $metrics;
	private $queue;
	private $pending = array();

	public function __construct( UnifiedMetricsService $metrics, JobQueue $queue ) {
		$this->metrics = $metrics;
		$this->queue   = $queue;
	}

	public function register() {
		add_action( 'nbh_performance_before_feature_toggles_updated', array( $this, 'before_features' ) );
		add_action( 'nbh_performance_feature_toggles_updated', array( $this, 'after_features' ) );
		add_action( 'nbh_performance_before_advanced_settings_updated', array( $this, 'before_settings' ) );
		add_action( 'nbh_performance_advanced_settings_updated', array( $this, 'after_settings' ) );
		add_action( 'nbh_performance_lab_test_completed', array( $this, 'lab_completed' ), 10, 3 );
	}

	public function before_features( $values ) {
		$this->begin( 'features', (array) $values );
	}
	public function after_features( $values ) {
		$this->finish_change( 'features', (array) $values );
	}
	public function before_settings( $values ) {
		$this->begin( 'settings', (array) $values );
	}
	public function after_settings( $values ) {
		$this->finish_change( 'settings', (array) $values );
	}

	private function begin( $kind, array $values ) {
		$this->pending[ $kind ] = array(
			'id'         => wp_generate_uuid4(),
			'kind'       => $kind,
			'requested'  => array_keys( $values ),
			'before'     => $this->metrics->snapshot(),
			'created_at' => time(),
		);
	}

	private function finish_change( $kind, array $updated ) {
		if ( empty( $this->pending[ $kind ] ) || empty( $updated ) ) {
			return;
		}
		$record = $this->pending[ $kind ];
		unset( $this->pending[ $kind ] );
		$availability                = LabTestClient::availability();
		$lab_enabled                 = (bool) get_option( 'nbh_performance_lab_testing_enabled', 0 ) && $availability['available'];
		$record['updated']           = array_keys( $updated );
		$record['status']            = $lab_enabled ? 'measuring' : 'waiting_for_lab';
		$record['after']             = null;
		$record['completed_results'] = 0;
		$record['expected_results']  = $lab_enabled ? count( PerformanceIntelligenceController::configured_urls() ) * count( PerformanceIntelligenceController::configured_strategies() ) : 0;
		$all                         = (array) get_option( self::OPTION, array() );
		$all[ $record['id'] ]        = $record;
		$all                         = array_slice( $all, -50, null, true );
		update_option( self::OPTION, $all, false );
		if ( $lab_enabled ) {
			$this->enqueue_change_tests( $record['id'] );
			if ( get_option( 'nbh_performance_rum_enabled', 0 ) ) {
				$this->queue->enqueue(
					'performance_impact_finalize',
					array( 'change_id' => $record['id'] ),
					array(
						'delay'        => DAY_IN_SECONDS,
						'priority'     => 15,
						'max_attempts' => 3,
						'unique_key'   => 'impact-finalize-' . $record['id'],
					)
				);
			}
		}
	}

	private function enqueue_change_tests( $change_id ) {
		$urls = PerformanceIntelligenceController::configured_urls();
		foreach ( $urls as $url ) {
			foreach ( PerformanceIntelligenceController::configured_strategies() as $strategy ) {
				$this->queue->enqueue(
					'performance_lab_test',
					array(
						'url'       => $url,
						'strategy'  => $strategy,
						'change_id' => $change_id,
					),
					array(
						'delay'        => 5 * MINUTE_IN_SECONDS,
						'priority'     => 35,
						'max_attempts' => 3,
						'unique_key'   => 'lab-change-' . $change_id . '-' . md5( $url . $strategy ),
					)
				);
			}
		}
	}

	public function lab_completed( $result_id, $result, $alert ) {
		$change_id = isset( $result['change_id'] ) ? sanitize_text_field( $result['change_id'] ) : '';
		if ( '' === $change_id ) {
			return;
		}
		$all = (array) get_option( self::OPTION, array() );
		if ( empty( $all[ $change_id ] ) ) {
			return;
		}
		$record                      = $all[ $change_id ];
		$record['completed_results'] = absint( isset( $record['completed_results'] ) ? $record['completed_results'] : 0 ) + 1;
		$record['last_result_id']    = absint( $result_id );
		if ( ! empty( $alert ) ) {
			$record['regressions'][] = $alert;
		}
		$expected = max( 1, absint( isset( $record['expected_results'] ) ? $record['expected_results'] : 1 ) );
		if ( $record['completed_results'] >= $expected ) {
			$record['after_lab'] = $this->metrics->snapshot();
			if ( get_option( 'nbh_performance_rum_enabled', 0 ) ) {
				$record['status'] = 'waiting_for_field_data';
				$record['delta']  = $this->delta( $record['before'], $record['after_lab'] );
			} else {
				$record['after']        = $record['after_lab'];
				$record['completed_at'] = time();
				$record['status']       = 'complete';
				$record['delta']        = $this->delta( $record['before'], $record['after'] );
			}
		}
		$all[ $change_id ] = $record;
		update_option( self::OPTION, $all, false );
	}


	/** Complete the field-data window after the configured delay. */
	public function finalize( $change_id ) {
		$change_id = sanitize_text_field( (string) $change_id );
		$all       = (array) get_option( self::OPTION, array() );
		if ( '' === $change_id || empty( $all[ $change_id ] ) ) {
			return new \WP_Error( 'nbh_performance_impact_missing', __( 'The performance impact record no longer exists.', 'nbh-performance' ) );
		}
		$record                 = $all[ $change_id ];
		$record['after']        = $this->metrics->snapshot();
		$record['completed_at'] = time();
		$record['status']       = 'complete';
		$record['delta']        = $this->delta( $record['before'], $record['after'] );
		$all[ $change_id ]      = $record;
		update_option( self::OPTION, $all, false );
		do_action( 'nbh_performance_performance_impact_completed', $record );
		return array(
			'completed' => true,
			'change_id' => $change_id,
			'delta'     => $record['delta'],
		);
	}

	public function history() {
		return array_reverse( array_values( (array) get_option( self::OPTION, array() ) ) );
	}

	private function delta( array $before, array $after ) {
		$output           = array();
		$output['health'] = isset( $before['health'], $after['health'] ) ? (int) $after['health'] - (int) $before['health'] : null;
		foreach ( array( 'performance_score', 'lcp', 'cls', 'tbt', 'ttfb' ) as $metric ) {
			$old                        = isset( $before['lab'][ $metric ] ) ? $before['lab'][ $metric ] : null;
			$new_value                  = isset( $after['lab'][ $metric ] ) ? $after['lab'][ $metric ] : null;
			$output[ 'lab_' . $metric ] = is_numeric( $old ) && is_numeric( $new_value ) ? round( (float) $new_value - (float) $old, 'cls' === $metric ? 4 : 2 ) : null;
		}
		foreach ( array( 'p75_lcp', 'p75_inp', 'p75_cls', 'p75_ttfb' ) as $metric ) {
			$old                        = isset( $before['rum'][ $metric ] ) ? $before['rum'][ $metric ] : null;
			$new_value                  = isset( $after['rum'][ $metric ] ) ? $after['rum'][ $metric ] : null;
			$precision                  = false !== strpos( $metric, 'cls' ) ? 4 : 2;
			$output[ 'rum_' . $metric ] = is_numeric( $old ) && is_numeric( $new_value ) ? round( (float) $new_value - (float) $old, $precision ) : null;
		}
		return $output;
	}
}
