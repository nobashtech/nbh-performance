<?php
/** Dynamic selector safelist for unused CSS removal. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class SelectorSafelist {
	public function all() {
		$defaults = array(
			'html',
			'body',
			':root',
			':focus',
			':focus-visible',
			':hover',
			':active',
			':visited',
			':checked',
			':disabled',
			':target',
			':has(',
			':is(',
			':where(',
			'.admin-bar',
			'.logged-in',
			'.current-menu-item',
			'.current_page_item',
			'.menu-item-has-children',
			'.sub-menu',
			'.screen-reader-text',
			'.elementor-',
			'.e-',
			'.brxe-',
			'.bricks-',
			'.et_pb_',
			'.fl-',
			'.oxy-',
			'.ct-',
			'.breakdance-',
			'.vc_',
			'.wpb_',
			'.fusion-',
			'.thrv_',
			'.wp-block-',
			'.wp-container-',
			'.has-',
			'.is-layout-',
			'.alignwide',
			'.alignfull',
			'.woocommerce',
			'.wc-',
			'.cart',
			'.checkout',
			'.product',
			'.swiper-',
			'.slick-',
			'.owl-',
			'.splide-',
			'.flickity-',
			'.pswp',
			'.mfp-',
			'.modal',
			'.lightbox',
			'.dialog',
			'.tooltip',
			'.popover',
			'.is-active',
			'.active',
			'.open',
			'.show',
			'.hidden',
			'.visible',
			'.loading',
			'.loaded',
			'.error',
			'.success',
			'.selected',
			'.expanded',
			'.collapsed',
			'[aria-',
			'[data-',
			'[role=',
			'[class*=',
			'[class^=',
			'@font-face',
			'@keyframes',
			'@supports',
			'@media',
			'@container',
			'@layer',
		);
		$items    = (array) apply_filters( 'nbh_performance_unused_css_safelist', $defaults );
		return array_values( array_unique( array_filter( array_map( 'strval', $items ) ) ) );
	}

	public function protects( $selector ) {
		foreach ( $this->all() as $token ) {
			if ( 0 === strpos( $token, 'regex:' ) ) {
				$pattern = substr( $token, 6 );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Administrator-supplied regex validation may warn for invalid syntax; a failed match is treated as invalid, never as authorization.
				if ( $pattern && false !== @preg_match( $pattern, '' ) && preg_match( $pattern, (string) $selector ) ) {
					return true;
				}
			} elseif ( false !== stripos( (string) $selector, $token ) ) {
				return true;
			}
		}
		return false;
	}
}
