<?php
/** Asset exclusion rules. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class OptimizationExclusions {
	/** @param string $url Asset URL. @param string $type css|js. @return bool */
	public function is_excluded( $url, $type ) {
		$defaults = array(
			'/elementor/assets/lib/',
			'/elementor-pro/assets/lib/',
			'/woocommerce/assets/js/frontend/cart-fragments',
			'/wp-includes/js/jquery/jquery',
			'/wp-includes/js/dist/',
			'.min.css',
			'.min.js',
			'load-scripts.php',
			'load-styles.php',
		);
		$patterns = (array) apply_filters( 'nbh_performance_asset_exclusion_patterns', $defaults, $type );
		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && '' !== $pattern && false !== stripos( $url, $pattern ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'nbh_performance_exclude_asset', false, $url, $type );
	}
}
