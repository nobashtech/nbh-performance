<?php
/** @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Core\PatternMatcher;

final class LazyLoadManager {
	private $image_count = 0;

	public function filter_attributes( $attributes, $attachment, $size ) {
		unset( $size );
		if ( false === apply_filters( 'nbh_performance_should_optimize_feature', true, 'lazy_load' ) || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_lazy_load_enabled', 0 ) ) {
			return $attributes;
		}
		++$this->image_count;
		$is_lcp = 1 === $this->image_count || ( has_post_thumbnail() && get_post_thumbnail_id() === $attachment->ID );
		$is_lcp = (bool) apply_filters( 'nbh_performance_is_lcp_image', $is_lcp, $attachment->ID, $attributes );
		if ( $is_lcp ) {
			$attributes['loading']       = 'eager';
			$attributes['fetchpriority'] = 'high';
			$attributes['decoding']      = 'async';
			return $attributes;
		}
		if ( $this->is_sensitive_context( $attributes ) ) {
			return $attributes;
		}
		$attributes['loading']  = 'lazy';
		$attributes['decoding'] = 'async';
		return $attributes;
	}

	private function is_sensitive_context( $attributes ) {
		$class = isset( $attributes['class'] ) ? (string) $attributes['class'] : '';
		$src   = isset( $attributes['src'] ) ? (string) $attributes['src'] : '';
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return true;
		}
		if ( false !== strpos( $class, 'woocommerce-product-gallery__image' ) ) {
			return true;
		}
		$class_exclusions = $this->lines( 'nbh_performance_lazyload_excluded_classes' );
		if ( PatternMatcher::matches_any( $class, $class_exclusions ) ) {
			return true;
		}
		return PatternMatcher::matches_any( $src, $this->lines( 'nbh_performance_media_excluded_urls' ) );
	}

	private function lines( $option ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( $option, '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}
}
