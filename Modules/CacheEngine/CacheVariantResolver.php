<?php
/**
 * Resolve deterministic, bounded public page-cache variants.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheVariantResolver {
	/** Resolve variants for the current public request. */
	public function current() {
		$variants = array();

		if ( (bool) get_option( 'nbh_performance_mobile_cache_enabled', false ) ) {
			$variants['device'] = wp_is_mobile() ? 'mobile' : 'desktop';
		}

		if ( (bool) get_option( 'nbh_performance_language_cache_variant', false ) ) {
			$value = $this->first_cookie_value( $this->language_cookie_names() );
			if ( '' !== $value ) {
				$variants['language'] = $this->bounded_value( $value, 20 );
			}
		}

		if ( (bool) get_option( 'nbh_performance_woocommerce_currency_variant', false ) ) {
			$value = $this->first_cookie_value( $this->currency_cookie_names() );
			if ( '' !== $value ) {
				$variants['currency'] = $this->bounded_value( $value, 12 );
			}
		}

		foreach ( $this->custom_vary_cookie_names() as $cookie ) {
			if ( isset( $_COOKIE[ $cookie ] ) && is_scalar( $_COOKIE[ $cookie ] ) ) {
				$key              = 'cookie_' . substr( sanitize_key( $cookie ), 0, 40 );
				$variants[ $key ] = substr( hash( 'sha256', \NBH\Performance\Modules\Security\RequestInput::cookie_bytes( $cookie ) ), 0, 16 );
			}
		}

		ksort( $variants );
		return $variants;
	}

	private function first_cookie_value( array $names ) {
		foreach ( $names as $name ) {
			if ( isset( $_COOKIE[ $name ] ) && is_scalar( $_COOKIE[ $name ] ) ) {
				$value = \NBH\Performance\Modules\Security\RequestInput::cookie_bytes( $name );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	private function language_cookie_names() {
		$defaults = array( 'wp-wpml_current_language', 'pll_language', 'trp_language', 'weglot_language' );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $defaults, $this->lines( 'nbh_performance_language_variant_cookies' ) ) ) ) ) );
	}

	private function currency_cookie_names() {
		$defaults = array( 'woocommerce_currency', 'aelia_cs_selected_currency', 'wmc_current_currency', 'woocs_current_currency' );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $defaults, $this->lines( 'nbh_performance_woocommerce_currency_cookies' ) ) ) ) ) );
	}

	private function custom_vary_cookie_names() {
		return array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', $this->lines( 'nbh_performance_cache_vary_cookies' ) ) ) ) ), 0, 10 );
	}

	private function bounded_value( $value, $length ) {
		$value = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $value ) );
		$value = trim( $value, '-_' );
		return substr( $value, 0, $length );
	}

	private function lines( $option ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( $option, '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}
}
