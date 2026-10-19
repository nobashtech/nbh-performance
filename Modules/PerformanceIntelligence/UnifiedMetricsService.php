<?php
/**
 * Unified laboratory and real-user performance reporting.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\PerformanceIntelligence;

use NBH\Performance\Modules\Rum\RumStorage;

final class UnifiedMetricsService {
	/** @var LabTestStorage */
	private $lab;
	/** @var RegressionDetector */
	private $regressions;
	/** @var \NBH\Performance\Modules\Rum\RumRollupStorage */
	private $rollups;
	/** @var PerformanceBudgetEvaluator */
	private $budgets;

	public function __construct( LabTestStorage $lab, RegressionDetector $regressions, \NBH\Performance\Modules\Rum\RumRollupStorage $rollups, PerformanceBudgetEvaluator $budgets ) {
		$this->lab         = $lab;
		$this->regressions = $regressions;
		$this->rollups     = $rollups;
		$this->budgets     = $budgets;
	}

	/** Build the dashboard summary. */
	public function summary( $days = 28 ) {
		$days                 = max( 1, min( 90, absint( $days ) ) );
		$latest               = $this->lab->latest( 30 );
		$rum                  = $this->rum_summary( $days );
		$alerts               = $this->regressions->alerts( 20 );
		$unacknowledged       = array_values(
			array_filter(
				$alerts,
				static function ( $alert ) {
					return empty( $alert['acknowledged'] );
				}
			)
		);
		$rollup_trend         = $this->rollups->history( $days, 'daily', 'overall', 'all' );
		$summary              = array(
			'generated_at' => time(),
			'days'         => $days,
			'lab'          => array(
				'latest'       => $latest,
				'averages'     => $this->lab_averages( $latest ),
				'last_test_at' => $this->latest_time( $latest ),
			),
			'rum'          => $rum,
			'alerts'       => $unacknowledged,
			'health'       => $this->health_score( $latest, $rum, $unacknowledged ),
			'schedule'     => array(
				'enabled'    => (bool) get_option( 'nbh_performance_lab_testing_enabled', 0 ),
				'frequency'  => sanitize_key( get_option( 'nbh_performance_lab_test_frequency', 'weekly' ) ),
				'next_run'   => wp_next_scheduled( 'nbh_performance_performance_lab_schedule' ),
				'strategies' => sanitize_key( get_option( 'nbh_performance_lab_test_strategies', 'both' ) ),
			),
			'rollups'      => array(
				'trend'                      => $rollup_trend,
				'minimum_confidence_samples' => absint( get_option( 'nbh_performance_autopilot_minimum_samples', 100 ) ),
			),
			'privacy'      => 'RUM stores hashed page identifiers and no IP address, cookie, user ID, or raw browsing history.',
		);
		$summary['budgets']   = $this->budgets->evaluate( $summary );
		$summary['autopilot'] = (array) get_option( 'nbh_performance_autopilot_run', array() );
		return $summary;
	}

	/** Snapshot suitable for before/after impact tracking. */
	public function snapshot() {
		$summary = $this->summary( 28 );
		return array(
			'captured_at' => time(),
			'health'      => $summary['health'],
			'lab'         => $summary['lab']['averages'],
			'rum'         => $summary['rum']['overall'],
		);
	}

	private function rum_summary( $days ) {
		global $wpdb;
		$table = RumStorage::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return array(
				'samples' => 0,
				'overall' => array(),
				'devices' => array(),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT device,lcp,inp,cls,ttfb,fcp FROM %i WHERE collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', $table, $days ),
			ARRAY_A
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$overall = array(
			'lcp'  => array(),
			'inp'  => array(),
			'cls'  => array(),
			'ttfb' => array(),
			'fcp'  => array(),
		);
		$devices = array();
		foreach ( (array) $rows as $row ) {
			$device = in_array( $row['device'], array( 'mobile', 'desktop', 'tablet' ), true ) ? $row['device'] : 'other';
			if ( ! isset( $devices[ $device ] ) ) {
				$devices[ $device ] = array(
					'lcp'  => array(),
					'inp'  => array(),
					'cls'  => array(),
					'ttfb' => array(),
					'fcp'  => array(),
				);
			}
			foreach ( array_keys( $overall ) as $metric ) {
				if ( null !== $row[ $metric ] && is_numeric( $row[ $metric ] ) ) {
					$overall[ $metric ][]            = (float) $row[ $metric ];
					$devices[ $device ][ $metric ][] = (float) $row[ $metric ];
				}
			}
		}
		$device_output = array();
		foreach ( $devices as $device => $metrics ) {
			$device_output[ $device ] = $this->percentiles( $metrics );
		}
		return array(
			'samples' => count( (array) $rows ),
			'overall' => $this->percentiles( $overall ),
			'devices' => $device_output,
		);
	}

	private function percentiles( array $metrics ) {
		$output = array();
		foreach ( $metrics as $metric => $values ) {
			if ( empty( $values ) ) {
				$output[ 'p75_' . $metric ] = null;
				continue;
			}
			sort( $values, SORT_NUMERIC );
			$index                      = max( 0, (int) ceil( 0.75 * count( $values ) ) - 1 );
			$output[ 'p75_' . $metric ] = round( $values[ $index ], 'cls' === $metric ? 4 : 2 );
		}
		return $output;
	}

	private function lab_averages( array $rows ) {
		$metrics = array( 'performance_score', 'lcp', 'cls', 'tbt', 'fcp', 'speed_index', 'ttfb' );
		$output  = array();
		foreach ( $metrics as $metric ) {
			$values = array();
			foreach ( $rows as $row ) {
				if ( isset( $row[ $metric ] ) && is_numeric( $row[ $metric ] ) ) {
					$values[] = (float) $row[ $metric ];
				}
			}
			$output[ $metric ] = $values ? round( array_sum( $values ) / count( $values ), 'cls' === $metric ? 4 : 2 ) : null;
		}
		$output['pages'] = count( $rows );
		return $output;
	}

	private function latest_time( array $rows ) {
		$latest = 0;
		foreach ( $rows as $row ) {
			$time   = isset( $row['captured_at'] ) ? strtotime( $row['captured_at'] . ' UTC' ) : 0;
			$latest = max( $latest, $time );
		}
		return $latest;
	}

	private function health_score( array $lab, array $rum, array $alerts ) {
		$score    = 100;
		$averages = $this->lab_averages( $lab );
		$overall  = isset( $rum['overall'] ) ? $rum['overall'] : array();
		$evidence = array( $averages['performance_score'], $averages['lcp'], $averages['tbt'] );
		foreach ( array( 'p75_lcp', 'p75_inp', 'p75_cls' ) as $metric ) {
			$evidence[] = isset( $overall[ $metric ] ) ? $overall[ $metric ] : null;
		}
		if ( ! array_filter( $evidence, 'is_numeric' ) ) {
			return null;
		}
		if ( isset( $averages['performance_score'] ) && is_numeric( $averages['performance_score'] ) && $averages['performance_score'] < 90 ) {
			$score -= min( 20, ( 90 - $averages['performance_score'] ) / 1.5 );
		}
		if ( isset( $averages['lcp'] ) && is_numeric( $averages['lcp'] ) && $averages['lcp'] > 2500 ) {
			$score -= min( 15, ( $averages['lcp'] - 2500 ) / 250 );
		}
		if ( isset( $averages['tbt'] ) && is_numeric( $averages['tbt'] ) && $averages['tbt'] > 300 ) {
			$score -= min( 10, ( $averages['tbt'] - 300 ) / 100 );
		}
		$overall = isset( $rum['overall'] ) ? $rum['overall'] : array();
		if ( isset( $overall['p75_lcp'] ) && is_numeric( $overall['p75_lcp'] ) && $overall['p75_lcp'] > 2500 ) {
			$score -= 8;
		}
		if ( isset( $overall['p75_inp'] ) && is_numeric( $overall['p75_inp'] ) && $overall['p75_inp'] > 200 ) {
			$score -= 8;
		}
		if ( isset( $overall['p75_cls'] ) && is_numeric( $overall['p75_cls'] ) && $overall['p75_cls'] > 0.1 ) {
			$score -= 8;
		}
		foreach ( $alerts as $alert ) {
			$score -= isset( $alert['severity'] ) && 'critical' === $alert['severity'] ? 8 : 4;
		}
		return max( 0, min( 100, (int) round( $score ) ) );
	}
}
