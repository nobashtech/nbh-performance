<?php
/**
 * Converts privacy-safe real-user metrics into explainable optimization signals.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\PerformanceIntelligence\UnifiedMetricsService;

final class RumInsightEngine {
	/** @var UnifiedMetricsService */
	private $metrics;

	public function __construct( UnifiedMetricsService $metrics ) {
		$this->metrics = $metrics;
	}

	/**
	 * Build a stable insight contract for plans and reports.
	 *
	 * @return array<string,mixed>
	 */
	public function analyze() {
		$summary  = $this->metrics->summary( 28 );
		$rum      = isset( $summary['rum'] ) && is_array( $summary['rum'] ) ? $summary['rum'] : array();
		$samples  = isset( $rum['samples'] ) ? absint( $rum['samples'] ) : 0;
		$overall  = isset( $rum['overall'] ) && is_array( $rum['overall'] ) ? $rum['overall'] : array();
		$devices  = isset( $rum['devices'] ) && is_array( $rum['devices'] ) ? $rum['devices'] : array();
		$insights = array();

		$this->append_threshold_insight( $insights, 'lcp', $overall, 2500, __( 'Largest Contentful Paint needs attention', 'nbh-performance' ), __( 'Prioritize the real LCP image, fonts and render-blocking CSS.', 'nbh-performance' ), 'render_path' );
		$this->append_threshold_insight( $insights, 'inp', $overall, 200, __( 'Interaction latency needs attention', 'nbh-performance' ), __( 'Reduce main-thread JavaScript and delay non-critical third-party work.', 'nbh-performance' ), 'script_execution' );
		$this->append_threshold_insight( $insights, 'cls', $overall, 0.1, __( 'Layout stability needs attention', 'nbh-performance' ), __( 'Reserve media dimensions and protect font and dynamic component layout.', 'nbh-performance' ), 'layout_stability' );
		$this->append_threshold_insight( $insights, 'ttfb', $overall, 800, __( 'Server response time needs attention', 'nbh-performance' ), __( 'Warm page cache and review object cache or hosting bottlenecks.', 'nbh-performance' ), 'server_response' );

		$mobile  = isset( $devices['mobile'] ) && is_array( $devices['mobile'] ) ? $devices['mobile'] : array();
		$desktop = isset( $devices['desktop'] ) && is_array( $devices['desktop'] ) ? $devices['desktop'] : array();
		if ( is_numeric( isset( $mobile['p75_lcp'] ) ? $mobile['p75_lcp'] : null ) && is_numeric( isset( $desktop['p75_lcp'] ) ? $desktop['p75_lcp'] : null ) ) {
			$gap = (float) $mobile['p75_lcp'] - (float) $desktop['p75_lcp'];
			if ( $gap >= 750 ) {
				$insights[] = array(
					'id'       => 'mobile-lcp-gap',
					'severity' => 'warning',
					'title'    => __( 'Mobile visitors are substantially slower', 'nbh-performance' ),
					'reason'   => __( 'Mobile p75 LCP trails desktop by at least 750 ms.', 'nbh-performance' ),
					'action'   => 'mobile_render_path',
					'evidence' => array( 'gap_ms' => round( $gap, 2 ) ),
				);
			}
		}

		return array(
			'source'      => 'rum',
			'window_days' => 28,
			'samples'     => $samples,
			'confidence'  => $this->confidence( $samples ),
			'overall'     => $overall,
			'devices'     => $devices,
			'insights'    => $insights,
			'privacy'     => isset( $summary['privacy'] ) ? sanitize_text_field( $summary['privacy'] ) : '',
		);
	}

	private function append_threshold_insight( array &$insights, $metric, array $overall, $threshold, $title, $reason, $action ) {
		$key = 'p75_' . $metric;
		if ( ! isset( $overall[ $key ] ) || ! is_numeric( $overall[ $key ] ) || (float) $overall[ $key ] <= (float) $threshold ) {
			return;
		}
		$insights[] = array(
			'id'       => 'poor-' . sanitize_key( $metric ),
			'severity' => 'warning',
			'title'    => $title,
			'reason'   => $reason,
			'action'   => sanitize_key( $action ),
			'evidence' => array(
				$key        => (float) $overall[ $key ],
				'threshold' => (float) $threshold,
			),
		);
	}

	private function confidence( $samples ) {
		$samples = absint( $samples );
		if ( $samples >= 500 ) {
			return 'high';
		}
		if ( $samples >= 100 ) {
			return 'medium';
		}
		return 'low';
	}
}
