<?php
/**
 * Connects dashboard settings to runtime decision hooks.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Core\PatternMatcher;

final class SettingsRuntimeBridge implements Module {
	public function register() {
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'cache_request' ), 20, 2 );
		add_filter( 'nbh_performance_runtime_config', array( $this, 'runtime_config' ) );
		add_filter( 'nbh_performance_ignored_query_parameters', array( $this, 'ignored_query_parameters' ), 20 );
		add_filter( 'nbh_performance_bypass_cookie_prefixes', array( $this, 'bypass_cookies' ), 20 );
		add_filter( 'nbh_performance_asset_exclusion_patterns', array( $this, 'asset_exclusions' ), 20, 2 );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'delay_exclusions' ), 20 );
		add_filter( 'nbh_performance_unused_css_safelist', array( $this, 'unused_css_safelist' ), 20 );
		add_filter( 'nbh_performance_dynamic_css_safelist', array( $this, 'dynamic_css_safelist' ), 20 );
		add_filter( 'nbh_performance_cdn_rewrite_extensions', array( $this, 'cdn_extensions' ), 20 );
		add_filter( 'nbh_performance_should_rewrite_cdn_url', array( $this, 'cdn_url_allowed' ), 20, 2 );
		add_filter( 'nbh_performance_edge_excluded_paths', array( $this, 'edge_exclusions' ), 20 );
		add_filter( 'nbh_performance_font_preload_urls', array( $this, 'font_preloads' ), 20 );
		add_filter( 'nbh_performance_preload_batch_size', array( $this, 'preload_batch_size' ), 20 );
		add_filter( 'nbh_performance_preload_request_timeout', array( $this, 'preload_timeout' ), 20 );
		add_filter( 'nbh_performance_image_quality', array( $this, 'image_quality' ), 20, 2 );
		add_action( 'update_option_nbh_performance_combine_mode', array( $this, 'sync_combine_mode' ), 10, 2 );
	}

	/** Keep legacy combine flag aligned with the Hub combine_mode setting. @param mixed $old Old. @param mixed $new_value New. */
	public function sync_combine_mode( $old, $new_value ) {
		unset( $old );
		$mode = sanitize_key( (string) $new_value );
		update_option( 'nbh_performance_combine_assets_enabled', in_array( $mode, array( 'auto', 'http1' ), true ) ? 1 : 0, false );
	}

	public function cache_request( $allowed, $uri ) {
		if ( ! $allowed ) {
			return false;
		}
		if ( PatternMatcher::matches_any( (string) $uri, $this->lines( 'nbh_performance_cache_excluded_urls' ) ) ) {
			return false;
		}
		return ! (bool) apply_filters( 'nbh_performance_exclude_url', false, (string) $uri );
	}

	public function runtime_config( array $config ) {
		$config['bypass_cookies']  = $this->merge( isset( $config['bypass_cookies'] ) ? (array) $config['bypass_cookies'] : array(), $this->lines( 'nbh_performance_cache_excluded_cookies' ) );
		$config['ignored_queries'] = $this->merge( isset( $config['ignored_queries'] ) ? (array) $config['ignored_queries'] : array(), $this->lines( 'nbh_performance_cache_ignored_query_args' ) );
		$config['excluded_paths']  = $this->merge( isset( $config['excluded_paths'] ) ? (array) $config['excluded_paths'] : array(), $this->lines( 'nbh_performance_cache_excluded_urls' ) );
		return $config;
	}

	public function ignored_query_parameters( array $parameters ) {
		return $this->merge( $parameters, array_map( 'sanitize_key', $this->lines( 'nbh_performance_cache_ignored_query_args' ) ) );
	}

	public function bypass_cookies( array $cookies ) {
		return $this->merge( $cookies, $this->lines( 'nbh_performance_cache_excluded_cookies' ) );
	}

	public function asset_exclusions( array $patterns, $type ) {
		$option = 'css' === $type ? 'nbh_performance_css_exclusions' : 'nbh_performance_js_exclusions';
		return $this->merge( $patterns, $this->lines( $option ) );
	}

	public function delay_exclusions( array $handles ) {
		return $this->merge( $handles, $this->lines( 'nbh_performance_delay_js_exclusions' ) );
	}

	public function unused_css_safelist( array $selectors ) {
		$selectors = $this->merge( $selectors, $this->lines( 'nbh_performance_unused_css_safelist' ) );
		return $this->merge( $selectors, (array) apply_filters( 'nbh_performance_dynamic_css_safelist', array() ) );
	}

	public function dynamic_css_safelist( array $selectors ) {
		return $this->merge( $selectors, $this->lines( 'nbh_performance_unused_css_safelist' ) );
	}

	public function cdn_extensions( array $extensions ) {
		$custom = array_map(
			static function ( $extension ) {
				return ltrim( sanitize_key( $extension ), '.' );
			},
			$this->lines( 'nbh_performance_cdn_included_extensions' )
		);
		return empty( $custom ) ? $extensions : array_values( array_filter( array_unique( $custom ) ) );
	}

	public function cdn_url_allowed( $allowed, $url ) {
		if ( ! $allowed ) {
			return false;
		}
		return ! PatternMatcher::matches_any( (string) $url, $this->lines( 'nbh_performance_cdn_excluded_urls' ) );
	}

	public function edge_exclusions( array $patterns ) {
		return $this->merge( $patterns, $this->lines( 'nbh_performance_edge_excluded_paths' ) );
	}

	public function font_preloads( array $urls ) {
		foreach ( $this->lines( 'nbh_performance_font_preload_urls' ) as $url ) {
			$url = esc_url_raw( $url );
			if ( $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
				$urls[] = $url;
			}
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, max( 0, min( 4, absint( get_option( 'nbh_performance_font_preload_limit', 2 ) ) ) ) );
	}

	public function preload_batch_size( $size ) {
		return max( 1, min( 20, absint( get_option( 'nbh_performance_preload_batch_size', $size ) ) ) );
	}

	public function preload_timeout( $timeout ) {
		return max( 3, min( 30, absint( get_option( 'nbh_performance_preload_request_timeout', $timeout ) ) ) );
	}

	public function image_quality( $quality, $mime ) {
		$option = false !== strpos( (string) $mime, 'avif' ) ? 'nbh_performance_avif_quality' : 'nbh_performance_webp_quality';
		return max( 30, min( 100, absint( get_option( $option, $quality ) ) ) );
	}

	private function lines( $option ) {
		$value = (string) get_option( $option, '' );
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	private function merge( array $left, array $right ) {
		return array_values(
			array_unique(
				array_filter(
					array_merge( $left, $right ),
					static function ( $value ) {
						return '' !== trim( (string) $value );
					}
				)
			)
		);
	}
}
