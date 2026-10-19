<?php
/**
 * Conservative Critical/Used CSS coverage validation.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CriticalCss;

final class CoverageValidator {
	/** @var VisualRegressionGuard */
	private $visual_guard;

	public function __construct( VisualRegressionGuard $visual_guard ) {
		$this->visual_guard = $visual_guard;
	}

	/** Validate reduction ratio, browser coverage, and visual evidence. */
	public function validate( $original, $optimized, array $metrics = array() ) {
		$original        = (string) $original;
		$optimized       = (string) $optimized;
		$optimized_bytes = strlen( $optimized );
		$original_bytes  = strlen( $original );
		if ( isset( $metrics['original_bytes'] ) && is_numeric( $metrics['original_bytes'] ) ) {
			$original_bytes = max( $original_bytes, absint( $metrics['original_bytes'] ) );
		}
		if ( isset( $metrics['used_bytes'] ) && is_numeric( $metrics['used_bytes'] ) ) {
			$declared_used = absint( $metrics['used_bytes'] );
			if ( abs( $declared_used - $optimized_bytes ) > max( 256, (int) round( $optimized_bytes * 0.02 ) ) ) {
				return false;
			}
		}

		if ( '' === trim( $optimized ) || $optimized_bytes > UsedCssStorage::MAX_BYTES || false !== stripos( $optimized, '</style' ) ) {
			return false;
		}
		if ( $original_bytes > 0 && $optimized_bytes > $original_bytes ) {
			return false;
		}

		$ratio      = $original_bytes > 0 ? $optimized_bytes / $original_bytes : 1;
		$configured = max( 5, min( 95, absint( get_option( 'nbh_performance_unused_css_max_reduction', 80 ) ) ) );
		$minimum    = (float) apply_filters( 'nbh_performance_unused_css_minimum_ratio', ( 100 - $configured ) / 100, $metrics );
		if ( $ratio < $minimum ) {
			return false;
		}
		if ( isset( $metrics['coverage'] ) && (float) $metrics['coverage'] < 0.90 ) {
			return false;
		}
		if ( isset( $metrics['layout_shift'] ) && (float) $metrics['layout_shift'] > 0.10 ) {
			return false;
		}
		if ( ! $this->visual_guard->passes( $metrics ) ) {
			return false;
		}
		return (bool) apply_filters( 'nbh_performance_validate_optimized_css', true, $original, $optimized, $metrics );
	}
}
