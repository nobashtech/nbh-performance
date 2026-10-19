<?php
/**
 * WooCommerce cache exclusions (compat shim — WooCommerceGuard is authoritative).
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Compatibility\WooCommerce;

final class WooCommerceCacheExclusion {
	public function register() {
		// Kept for backward compatibility with older integrations that expect this class.
		// Runtime exclusions are owned by WooCommerceGuard (priority 5).
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'exclude_sensitive_routes' ), 10, 2 );
	}

	public function exclude_sensitive_routes( $should_cache, $request_uri ) {
		if ( ! $should_cache || ! function_exists( 'WC' ) ) {
			return $should_cache;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
			return false;
		}
		$uri      = strtolower( (string) $request_uri );
		$excluded = array(
			'wc-ajax=',
			'add-to-cart=',
			'remove_item=',
			'pay_for_order=',
			'/cart',
			'/checkout',
			'/my-account',
			'/order-pay',
			'/order-received',
			'/wp-json/wc/',
			'/wc-api/',
			'rest_route=/wc/',
		);
		foreach ( $excluded as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return false;
			}
		}
		return $should_cache;
	}
}
