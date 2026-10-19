<?php
/** Local compatibility rules for JavaScript loading. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class ScriptCompatibilityRegistry {
	/** Scripts that must never be delayed in the current request context. */
	public function protected_rules() {
		$rules = array(
			'jquery',
			'jquery-core',
			'wp-hooks',
			'wp-i18n',
			'wp-element',
			'wp-polyfill',
			'nbh-performance-delay-loader',
			'recaptcha',
			'grecaptcha',
			'turnstile',
			'hcaptcha',
			'cookieyes',
			'complianz',
			'cookiebot',
			'onetrust',
		);
		if ( $this->commerce_context() ) {
			$rules = array_merge( $rules, array( 'woocommerce', 'wc-checkout', 'wc-cart-fragments', 'wc-add-to-cart', 'stripe', 'paypal', 'square', 'klarna', 'afterpay', 'payment' ) );
		}
		return array_values( array_unique( (array) apply_filters( 'nbh_performance_delay_compatibility_rules', $rules ) ) );
	}

	/** Third-party patterns suitable for interaction delay by default. */
	public function delay_patterns() {
		$patterns = array(
			'googletagmanager.com',
			'google-analytics.com',
			'gtag/js',
			'connect.facebook.net',
			'static.hotjar.com',
			'client.crisp.chat',
			'widget.intercom.io',
			'snap.licdn.com',
			'bat.bing.com',
			'clarity.ms',
			'tiktok.com/i18n/pixel',
			'static.ads-twitter.com',
			'doubleclick.net',
			'adsbygoogle.js',
		);
		return array_values( array_unique( (array) apply_filters( 'nbh_performance_delay_script_patterns', $patterns ) ) );
	}

	private function commerce_context() {
		return ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );
	}
}
