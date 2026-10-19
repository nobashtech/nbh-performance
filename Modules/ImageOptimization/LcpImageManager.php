<?php
/** Promotes a conservative first content image as an LCP candidate. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Core\Module;

final class LcpImageManager implements Module {
	private $promoted = false;
	public function register() {
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'attributes' ), 5, 3 );
	}
	public function attributes( $attr, $attachment, $size ) {
		unset( $attachment, $size );
		if ( $this->promoted || is_admin() || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_lcp_image_optimization_enabled', 1 ) ) {
			return $attr;
		}
		$class = isset( $attr['class'] ) ? (string) $attr['class'] : '';
		if ( preg_match( '/(?:avatar|logo|icon|emoji|skip-lcp|no-lcp)/i', $class ) ) {
			return $attr;
		}
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
		$attr['decoding']      = 'async';
		$this->promoted        = true;
		return $attr;
	}
}
