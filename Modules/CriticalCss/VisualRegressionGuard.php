<?php
/**
 * Browser-supplied visual regression safety gate.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CriticalCss;

final class VisualRegressionGuard {
	/** Validate versioned visual and structural evidence. */
	public function passes( array $metrics ) {
		if ( ! get_option( 'nbh_performance_visual_regression_guard_enabled', 1 ) ) {
			return true;
		}

		$required = array( 'visual_difference', 'visible_node_delta', 'dom_node_delta', 'page_height_delta', 'viewport_width', 'viewport_height' );
		foreach ( $required as $key ) {
			if ( ! isset( $metrics[ $key ] ) || ! is_numeric( $metrics[ $key ] ) ) {
				return (bool) apply_filters( 'nbh_performance_visual_regression_missing_metrics_passes', false, $metrics );
			}
		}

		$width  = absint( $metrics['viewport_width'] );
		$height = absint( $metrics['viewport_height'] );
		if ( $width < 320 || $width > 3840 || $height < 320 || $height > 2160 ) {
			return false;
		}

		$threshold_percent  = max( 1, min( 25, absint( get_option( 'nbh_performance_visual_difference_threshold', 5 ) ) ) );
		$maximum_difference = (float) apply_filters( 'nbh_performance_visual_difference_threshold', $threshold_percent / 100, $metrics );
		if ( (float) $metrics['visual_difference'] < 0 || (float) $metrics['visual_difference'] > $maximum_difference ) {
			return false;
		}

		$missing_limit = max( 0, min( 100, absint( get_option( 'nbh_performance_visual_missing_nodes_limit', 0 ) ) ) );
		if ( isset( $metrics['missing_nodes'] ) && (int) $metrics['missing_nodes'] > $missing_limit ) {
			return false;
		}

		$visible_limit = max( 2, min( 100, absint( get_option( 'nbh_performance_visual_node_delta_limit', 10 ) ) ) );
		if ( abs( (int) $metrics['visible_node_delta'] ) > $visible_limit ) {
			return false;
		}

		$dom_limit = max( 5, min( 500, absint( get_option( 'nbh_performance_visual_dom_delta_limit', 50 ) ) ) );
		if ( abs( (int) $metrics['dom_node_delta'] ) > $dom_limit ) {
			return false;
		}

		$page_height_limit = max( 0.01, min( 0.50, absint( get_option( 'nbh_performance_visual_page_height_delta_percent', 10 ) ) / 100 ) );
		if ( abs( (float) $metrics['page_height_delta'] ) > $page_height_limit ) {
			return false;
		}

		foreach ( array( 'baseline_sha256', 'optimized_sha256' ) as $hash_key ) {
			if ( ! empty( $metrics[ $hash_key ] ) && ! preg_match( '/^[a-f0-9]{64}$/i', (string) $metrics[ $hash_key ] ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'nbh_performance_visual_regression_passes', true, $metrics );
	}
}
