<?php
/**
 * Measurable before-and-after performance reports.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\PerformanceIntelligence\UnifiedMetricsService;

final class PerformanceReportService {
	/** @var UnifiedMetricsService */
	private $metrics;
	/** @var RumInsightEngine */
	private $rum_insights;

	public function __construct( UnifiedMetricsService $metrics, RumInsightEngine $rum_insights ) {
		$this->metrics      = $metrics;
		$this->rum_insights = $rum_insights;
	}

	/** Create a report for a Smart Optimize run. */
	public function report( array $run = array() ) {
		$before       = isset( $run['before'] ) && is_array( $run['before'] ) ? $run['before'] : array();
		$after        = isset( $run['after'] ) && is_array( $run['after'] ) ? $run['after'] : $this->metrics->snapshot();
		$rum_insights = $this->rum_insights->analyze();
		$delta        = $this->delta( $before, $after );
		return array(
			'generated_at'  => time(),
			'run_id'        => isset( $run['id'] ) ? sanitize_text_field( $run['id'] ) : '',
			'mode'          => isset( $run['mode'] ) ? sanitize_key( $run['mode'] ) : '',
			'status'        => isset( $run['status'] ) ? sanitize_key( $run['status'] ) : 'not_started',
			'before'        => $before,
			'after'         => $after,
			'delta'         => $delta,
			'summary'       => $this->human_summary( $run, $delta ),
			'cache'         => (array) get_option( 'nbh_performance_cache_stats', array() ),
			'image_savings' => $this->image_savings(),
			'validation'    => isset( $run['validation'] ) && is_array( $run['validation'] ) ? $run['validation'] : (array) get_option( 'nbh_performance_last_staging_validation', array() ),
			'rollbacks'     => array_slice( (array) get_option( 'nbh_performance_automatic_rollback_history', array() ), 0, 10 ),
			'rum_insights'  => $rum_insights,
			'lab_results'   => isset( $run['lab_results'] ) ? array_values( (array) $run['lab_results'] ) : array(),
			'evidence'      => array(
				'baseline_available' => ! empty( $before ),
				'lab_pages'          => isset( $after['lab']['pages'] ) ? absint( $after['lab']['pages'] ) : 0,
				'rum_samples'        => absint( isset( $rum_insights['samples'] ) ? $rum_insights['samples'] : 0 ),
				'lab_results'        => isset( $run['lab_results'] ) ? count( (array) $run['lab_results'] ) : 0,
			),
		);
	}

	private function human_summary( array $run, array $delta ) {
		$bits = array();
		if ( isset( $run['updated_features'] ) ) {
			$bits[] = sprintf( /* translators: %d: feature count */ _n( '%d feature applied', '%d features applied', count( (array) $run['updated_features'] ), 'nbh-performance' ), count( (array) $run['updated_features'] ) );
		}
		if ( isset( $delta['health'] ) && is_numeric( $delta['health'] ) && 0 !== (int) $delta['health'] ) {
			$bits[] = sprintf( /* translators: %s: signed health delta */ __( 'Health %s', 'nbh-performance' ), ( (int) $delta['health'] > 0 ? '+' : '' ) . (int) $delta['health'] );
		}
		if ( isset( $delta['lab']['lcp'] ) && is_numeric( $delta['lab']['lcp'] ) ) {
			$bits[] = sprintf( /* translators: %s: LCP delta ms */ __( 'LCP %sms', 'nbh-performance' ), ( (float) $delta['lab']['lcp'] > 0 ? '+' : '' ) . $delta['lab']['lcp'] );
		}
		if ( isset( $delta['lab']['ttfb'] ) && is_numeric( $delta['lab']['ttfb'] ) ) {
			$bits[] = sprintf( /* translators: %s: TTFB delta ms */ __( 'TTFB %sms', 'nbh-performance' ), ( (float) $delta['lab']['ttfb'] > 0 ? '+' : '' ) . $delta['lab']['ttfb'] );
		}
		return array(
			'headline' => ! empty( $bits ) ? implode( ' · ', $bits ) : __( 'Impact evidence is collecting.', 'nbh-performance' ),
			'mode'     => isset( $run['mode'] ) ? sanitize_key( $run['mode'] ) : '',
			'status'   => isset( $run['status'] ) ? sanitize_key( $run['status'] ) : 'not_started',
		);
	}

	private function image_savings() {
		global $wpdb;
		if ( ! isset( $wpdb->postmeta ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)),0) FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_nbh_performance_image_source_bytes_saved' ) ) );
	}

	private function delta( array $before, array $after ) {
		$output = array(
			'health' => null,
			'lab'    => array(),
			'rum'    => array(),
		);
		if ( isset( $before['health'], $after['health'] ) ) {
			$output['health'] = (int) $after['health'] - (int) $before['health'];
		}
		foreach ( array( 'performance_score', 'lcp', 'cls', 'tbt', 'ttfb' ) as $metric ) {
			$old                      = isset( $before['lab'][ $metric ] ) ? $before['lab'][ $metric ] : null;
			$new_value                = isset( $after['lab'][ $metric ] ) ? $after['lab'][ $metric ] : null;
			$output['lab'][ $metric ] = is_numeric( $old ) && is_numeric( $new_value ) ? round( (float) $new_value - (float) $old, 'cls' === $metric ? 4 : 2 ) : null;
		}
		foreach ( array( 'p75_lcp', 'p75_inp', 'p75_cls', 'p75_ttfb' ) as $metric ) {
			$old                      = isset( $before['rum'][ $metric ] ) ? $before['rum'][ $metric ] : null;
			$new_value                = isset( $after['rum'][ $metric ] ) ? $after['rum'][ $metric ] : null;
			$precision                = false !== strpos( $metric, 'cls' ) ? 4 : 2;
			$output['rum'][ $metric ] = is_numeric( $old ) && is_numeric( $new_value ) ? round( (float) $new_value - (float) $old, $precision ) : null;
		}
		return $output;
	}
}
