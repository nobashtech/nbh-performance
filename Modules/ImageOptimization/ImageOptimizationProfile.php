<?php
/** Resolves safe image quality profiles into format-specific settings. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageOptimizationProfile {
	public function current() {
		$profile  = sanitize_key( (string) get_option( 'nbh_performance_image_optimization_profile', 'balanced' ) );
		$profiles = array(
			'lossless'   => array(
				'jpeg'           => 92,
				'png'            => 100,
				'webp'           => 92,
				'avif'           => 82,
				'minimum_saving' => 0.005,
			),
			'balanced'   => array(
				'jpeg'           => 82,
				'png'            => 88,
				'webp'           => 82,
				'avif'           => 70,
				'minimum_saving' => 0.01,
			),
			'aggressive' => array(
				'jpeg'           => 72,
				'png'            => 78,
				'webp'           => 72,
				'avif'           => 58,
				'minimum_saving' => 0.01,
			),
			'custom'     => array(
				'jpeg'           => absint( get_option( 'nbh_performance_jpeg_quality', 82 ) ),
				'png'            => absint( get_option( 'nbh_performance_png_quality', 88 ) ),
				'webp'           => absint( get_option( 'nbh_performance_webp_quality', 82 ) ),
				'avif'           => absint( get_option( 'nbh_performance_avif_quality', 70 ) ),
				'minimum_saving' => max( 0, min( 0.5, (float) get_option( 'nbh_performance_image_minimum_saving_percent', 1 ) / 100 ) ),
			),
		);
		return isset( $profiles[ $profile ] ) ? array_merge( array( 'name' => $profile ), $profiles[ $profile ] ) : array_merge( array( 'name' => 'balanced' ), $profiles['balanced'] );
	}

	public function quality( $format ) {
		$profile = $this->current();
		$key     = sanitize_key( str_replace( 'image/', '', (string) $format ) );
		return max( 30, min( 100, isset( $profile[ $key ] ) ? absint( $profile[ $key ] ) : 82 ) );
	}
}
