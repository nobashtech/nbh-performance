<?php
/** @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ResponsiveImageGenerator {
	public function filter_sizes( $sizes ) {
		$sizes['nbh_performance_640']  = array(
			'width'  => 640,
			'height' => 0,
			'crop'   => false,
		);
		$sizes['nbh_performance_960']  = array(
			'width'  => 960,
			'height' => 0,
			'crop'   => false,
		);
		$sizes['nbh_performance_1280'] = array(
			'width'  => 1280,
			'height' => 0,
			'crop'   => false,
		);
		return $sizes;
	}

	public function filter_sizes_attribute( $sizes, $size ) {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_responsive_images_enabled', 0 ) ) {
			return $sizes;
		}
		if ( is_array( $size ) && ! empty( $size[0] ) ) {
			return '(max-width: ' . absint( $size[0] ) . 'px) 100vw, ' . absint( $size[0] ) . 'px';
		}
		return $sizes ? $sizes : '100vw';
	}
}
