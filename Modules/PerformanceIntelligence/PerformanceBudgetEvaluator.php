<?php
/** Core Web Vitals and lab performance budget evaluator. @package NBHPerformance */
namespace NBH\Performance\Modules\PerformanceIntelligence;

final class PerformanceBudgetEvaluator {
	public function budgets() {
		return array(
			'p75_lcp'           => (float) get_option( 'nbh_performance_budget_lcp', 2500 ),
			'p75_inp'           => (float) get_option( 'nbh_performance_budget_inp', 200 ),
			'p75_cls'           => (float) get_option( 'nbh_performance_budget_cls', 0.1 ),
			'p75_ttfb'          => (float) get_option( 'nbh_performance_budget_ttfb', 800 ),
			'performance_score' => (float) get_option( 'nbh_performance_budget_score', 90 ),
			'tbt'               => (float) get_option( 'nbh_performance_budget_tbt', 300 ),
		);
	}
	public function evaluate( array $summary ) {
		$b          = $this->budgets();
		$violations = array();
		$rum        = isset( $summary['rum']['overall'] ) ? $summary['rum']['overall'] : array();
		$lab        = isset( $summary['lab']['averages'] ) ? $summary['lab']['averages'] : array();
		foreach ( array( 'p75_lcp', 'p75_inp', 'p75_cls', 'p75_ttfb' ) as $m ) {
			if ( isset( $rum[ $m ] ) && is_numeric( $rum[ $m ] ) && (float) $rum[ $m ] > $b[ $m ] ) {
				$violations[] = $this->violation( $m, $rum[ $m ], $b[ $m ], 'rum' );
			}
		}
		if ( isset( $lab['performance_score'] ) && is_numeric( $lab['performance_score'] ) && (float) $lab['performance_score'] < $b['performance_score'] ) {
			$violations[] = $this->violation( 'performance_score', $lab['performance_score'], $b['performance_score'], 'lab', true );
		}
		if ( isset( $lab['tbt'] ) && is_numeric( $lab['tbt'] ) && (float) $lab['tbt'] > $b['tbt'] ) {
			$violations[] = $this->violation( 'tbt', $lab['tbt'], $b['tbt'], 'lab' );
		}
		$measured = array_filter( array_intersect_key( $rum, $b ) + array_intersect_key( $lab, $b ), 'is_numeric' );
		return array(
			'budgets'      => $b,
			'passed'       => $measured ? empty( $violations ) : null,
			'violations'   => $violations,
			'evaluated_at' => time(),
		);
	}
	private function violation( $metric, $actual, $budget, $source, $minimum = false ) {
		$delta = $minimum ? ( (float) $budget - (float) $actual ) : ( (float) $actual - (float) $budget );
		return array(
			'metric'   => $metric,
			'actual'   => (float) $actual,
			'budget'   => (float) $budget,
			'delta'    => round( $delta, 4 ),
			'source'   => $source,
			'severity' => $delta > ( $minimum ? 15 : ( $budget * .3 ) ) ? 'critical' : 'warning',
		);
	}
}
