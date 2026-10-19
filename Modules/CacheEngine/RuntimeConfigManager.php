<?php
/**
 * Writes the fail-safe, WordPress-independent page-cache runtime configuration.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Reliability\RuntimeReliabilityManager;

final class RuntimeConfigManager implements Module {
	/** Runtime schema consumed by advanced-cache.php. */
	const CONFIG_VERSION = 8;

	/** Security-sensitive cookies that integrations may extend but never remove. */
	private const MANDATORY_BYPASS_COOKIES = array(
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'phpsessid',
	);

	/** State-changing or personalized query keys that must never enter page-cache keys. */
	private const MANDATORY_BYPASS_QUERIES = array(
		'preview',
		'preview_id',
		'preview_nonce',
		'customize_changeset_uuid',
		'customize_messenger_channel',
		'wc-ajax',
		'add-to-cart',
		'remove_item',
		'apply_coupon',
		'coupon_code',
		'order_again',
		'pay_for_order',
		'_wpnonce',
		'_wp_http_referer',
		'rest_route',
	);

	/** Register every setting that changes early cache serving. */
	public function register() {
		add_action( 'init', array( $this, 'write' ), 1 );

		$options = array(
			'nbh_performance_cache_enabled',
			'nbh_performance_safe_mode',
			'nbh_performance_runtime_degraded',
			'nbh_performance_cache_ttl',
			'nbh_performance_mobile_cache_enabled',
			'nbh_performance_cache_excluded_urls',
			'nbh_performance_cache_excluded_cookies',
			'nbh_performance_cache_ignored_query_args',
			'nbh_performance_cache_allowed_query_args',
			'nbh_performance_cache_vary_cookies',
			'nbh_performance_language_cache_variant',
			'nbh_performance_language_variant_cookies',
			'nbh_performance_woocommerce_currency_variant',
			'nbh_performance_woocommerce_currency_cookies',
			'nbh_performance_cache_stale_while_revalidate',
			'nbh_performance_cache_stale_if_error',
			'nbh_performance_cache_gzip_enabled',
			'nbh_performance_cache_brotli_enabled',
			'nbh_performance_cache_lock_timeout',
			'nbh_performance_cache_verify_integrity',
			'nbh_performance_cache_404_enabled',
			'nbh_performance_cache_redirects_enabled',
		);

		foreach ( $options as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'write' ) );
		}
	}

	/**
	 * Write runtime JSON atomically.
	 *
	 * Invariants are applied after extension filters. No integration may change
	 * the cache root, site origin or runtime schema consumed by the drop-in.
	 *
	 * @return bool
	 */
	public function write() {
		$root = wp_normalize_path( WP_CONTENT_DIR . '/cache/nbh-performance' );
		if ( ! wp_mkdir_p( $root . '/pages' ) ) {
			return false;
		}

		$home_url = home_url( '/' );
		$scheme   = strtolower( (string) wp_parse_url( $home_url, PHP_URL_SCHEME ) );
		$scheme   = in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : ( is_ssl() ? 'https' : 'http' );
		$port     = absint( wp_parse_url( $home_url, PHP_URL_PORT ) );
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}

		$config = array(
			'enabled'                => (bool) get_option( 'nbh_performance_cache_enabled', false ) && ! (bool) get_option( 'nbh_performance_safe_mode', true ) && ! RuntimeReliabilityManager::is_degraded() && ! is_multisite(),
			'cache_root'             => wp_normalize_path( $root . '/pages' ),
			'ttl'                    => max( 60, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_cache_ttl', HOUR_IN_SECONDS ) ) ) ),
			'stale_while_revalidate' => max( 0, min( DAY_IN_SECONDS, absint( get_option( 'nbh_performance_cache_stale_while_revalidate', 300 ) ) ) ),
			'stale_if_error'         => max( 0, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_cache_stale_if_error', DAY_IN_SECONDS ) ) ) ),
			'site_scheme'            => $scheme,
			'site_host'              => strtolower( (string) wp_parse_url( $home_url, PHP_URL_HOST ) ),
			'site_port'              => $port,
			'ignored_queries'        => (array) apply_filters( 'nbh_performance_ignored_query_parameters', array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' ) ),
			'allowed_queries'        => $this->lines( 'nbh_performance_cache_allowed_query_args' ),
			'bypass_cookies'         => self::MANDATORY_BYPASS_COOKIES,
			'vary_cookies'           => $this->lines( 'nbh_performance_cache_vary_cookies' ),
			'language_variant'       => (bool) get_option( 'nbh_performance_language_cache_variant', false ),
			'language_cookies'       => array_merge( array( 'wp-wpml_current_language', 'pll_language', 'trp_language', 'weglot_language' ), $this->lines( 'nbh_performance_language_variant_cookies' ) ),
			'currency_variant'       => (bool) get_option( 'nbh_performance_woocommerce_currency_variant', false ),
			'currency_cookies'       => array_merge( array( 'woocommerce_currency', 'aelia_cs_selected_currency', 'wmc_current_currency', 'woocs_current_currency' ), $this->lines( 'nbh_performance_woocommerce_currency_cookies' ) ),
			'generated_at'           => time(),
			'mobile_cache'           => (bool) get_option( 'nbh_performance_mobile_cache_enabled', false ),
			'gzip_cache'             => (bool) get_option( 'nbh_performance_cache_gzip_enabled', true ),
			'brotli_cache'           => (bool) get_option( 'nbh_performance_cache_brotli_enabled', true ),
			'lock_timeout'           => max( 10, min( 300, absint( get_option( 'nbh_performance_cache_lock_timeout', 45 ) ) ) ),
			'verify_integrity'       => (bool) get_option( 'nbh_performance_cache_verify_integrity', false ),
			'cache_404'              => (bool) get_option( 'nbh_performance_cache_404_enabled', false ),
			'cache_redirects'        => (bool) get_option( 'nbh_performance_cache_redirects_enabled', false ),
			'metadata_required'      => true,
			'config_version'         => self::CONFIG_VERSION,
		);

		$config = (array) apply_filters( 'nbh_performance_runtime_config', $config );
		$config = $this->normalize_config( $config, $root, $scheme, $port );

		$json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return false;
		}

		$file = $root . '/runtime.json';
		$temp = tempnam( $root, '.runtime-' );
		if ( false === $temp ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $temp, 'wb' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
				fclose( $handle );
			}
			wp_delete_file( $temp );
			return false;
		}
		$length = strlen( $json );
		$offset = 0;
		while ( $offset < $length ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The destination is an owned WP_CONTENT_DIR/cache/nbh-performance runtime/probe/lock file, not a plugin source directory.
			$written = fwrite( $handle, substr( $json, $offset ) );
			if ( false === $written || 0 === $written ) {
				flock( $handle, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
				fclose( $handle );
				wp_delete_file( $temp );
				return false;
			}
			$offset += $written;
		}
		$flushed = fflush( $handle );
		if ( $flushed && function_exists( 'fsync' ) ) {
			$flushed = fsync( $handle );
		}
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $handle );
		clearstatcache( true, $temp );
		if ( ! $flushed || ! is_file( $temp ) || (int) filesize( $temp ) !== $length ) {
			wp_delete_file( $temp );
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, 0644 );
		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- The destination is an owned WP_CONTENT_DIR/cache/nbh-performance runtime/probe/lock file, not a plugin source directory.
		if ( ! @rename( $temp, $file ) ) {
			wp_delete_file( $temp );
			return false;
		}
		clearstatcache( true, $file );
		return is_file( $file ) && (int) filesize( $file ) === $length;
	}

	/**
	 * Reapply security and compatibility invariants after filters.
	 *
	 * @param array  $config Runtime configuration.
	 * @param string $root   Canonical cache root.
	 * @param string $scheme Canonical site scheme.
	 * @param int    $port   Canonical non-standard port, or zero.
	 * @return array
	 */
	private function normalize_config( array $config, $root, $scheme, $port ) {
		$config['enabled']           = ! empty( $config['enabled'] ) && ! RuntimeReliabilityManager::is_degraded() && ! is_multisite();
		$config['cache_root']        = wp_normalize_path( $root . '/pages' );
		$config['site_scheme']       = $scheme;
		$config['site_host']         = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$config['site_port']         = absint( $port );
		$config['metadata_required'] = true;
		$config['config_version']    = self::CONFIG_VERSION;

		$config['ttl']                    = max( 60, min( WEEK_IN_SECONDS, absint( isset( $config['ttl'] ) ? $config['ttl'] : HOUR_IN_SECONDS ) ) );
		$config['stale_while_revalidate'] = max( 0, min( DAY_IN_SECONDS, absint( isset( $config['stale_while_revalidate'] ) ? $config['stale_while_revalidate'] : 300 ) ) );
		$config['stale_if_error']         = max( 0, min( WEEK_IN_SECONDS, absint( isset( $config['stale_if_error'] ) ? $config['stale_if_error'] : DAY_IN_SECONDS ) ) );
		$config['lock_timeout']           = max( 10, min( 300, absint( isset( $config['lock_timeout'] ) ? $config['lock_timeout'] : 45 ) ) );

		foreach ( array( 'mobile_cache', 'language_variant', 'currency_variant', 'gzip_cache', 'brotli_cache', 'verify_integrity', 'cache_404', 'cache_redirects' ) as $boolean ) {
			$config[ $boolean ] = ! empty( $config[ $boolean ] );
		}

		$config['ignored_queries']               = array_values( array_diff( $this->normalize_names( isset( $config['ignored_queries'] ) ? $config['ignored_queries'] : array(), 100 ), self::MANDATORY_BYPASS_QUERIES ) );
		$config['allowed_queries']               = array_values( array_diff( $this->normalize_names( isset( $config['allowed_queries'] ) ? $config['allowed_queries'] : array(), 100 ), self::MANDATORY_BYPASS_QUERIES ) );
		$config['vary_cookies']                  = $this->normalize_names( isset( $config['vary_cookies'] ) ? $config['vary_cookies'] : array(), 10 );
		$config['language_cookies']              = $this->normalize_names( isset( $config['language_cookies'] ) ? $config['language_cookies'] : array(), 25 );
		$config['currency_cookies']              = $this->normalize_names( isset( $config['currency_cookies'] ) ? $config['currency_cookies'] : array(), 25 );
		$filtered_bypass                         = $this->normalize_cookie_prefixes( isset( $config['bypass_cookies'] ) ? $config['bypass_cookies'] : array(), 100 );
		$config['bypass_cookies']                = array_slice( array_values( array_unique( array_merge( self::MANDATORY_BYPASS_COOKIES, $filtered_bypass ) ) ), 0, 100 );
		$config['bypass_paths']                  = $this->normalize_paths( isset( $config['bypass_paths'] ) ? $config['bypass_paths'] : array(), 250, false );
		$config['excluded_paths']                = $this->normalize_paths( isset( $config['excluded_paths'] ) ? $config['excluded_paths'] : array(), 500, true );
		$config['mobile_variant_disabled_paths'] = $this->normalize_paths( isset( $config['mobile_variant_disabled_paths'] ) ? $config['mobile_variant_disabled_paths'] : array(), 500, false );

		$ttl_overrides           = isset( $config['ttl_overrides'] ) && is_array( $config['ttl_overrides'] ) ? $config['ttl_overrides'] : array();
		$config['ttl_overrides'] = array();
		foreach ( array_slice( $ttl_overrides, 0, 500, true ) as $path => $ttl ) {
			$path = $this->normalize_path( $path );
			$ttl  = max( 60, min( WEEK_IN_SECONDS, absint( $ttl ) ) );
			if ( '' !== $path ) {
				$config['ttl_overrides'][ $path ] = $ttl;
			}
		}

		return $config;
	}

	/** Normalize query keys and exact cookie names. */
	private function normalize_names( $values, $limit ) {
		$values = is_array( $values ) ? $values : array();
		$values = array_map( 'sanitize_key', $values );
		$values = array_values( array_unique( array_filter( $values ) ) );
		return array_slice( $values, 0, max( 0, absint( $limit ) ) );
	}

	/** Normalize cookie prefixes while preserving deliberate trailing separators. */
	private function normalize_cookie_prefixes( $values, $limit ) {
		$out = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$value = strtolower( preg_replace( '/[^a-z0-9_.-]+/i', '', trim( (string) $value ) ) );
			if ( '' !== $value ) {
				$out[] = substr( $value, 0, 100 );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, max( 0, absint( $limit ) ) );
	}

	/** Normalize exact paths or user-entered wildcard/regex patterns. */
	private function normalize_paths( $values, $limit, $allow_patterns ) {
		$out = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value || strlen( $value ) > 500 ) {
				continue;
			}
			if ( $allow_patterns && 0 === stripos( $value, 'regex:' ) ) {
				$expression = trim( substr( $value, 6 ) );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( '' !== $expression && strlen( $expression ) <= 450 && false !== @preg_match( $expression, '' ) ) {
					$out[] = 'regex:' . $expression;
				}
				continue;
			}
			if ( $allow_patterns && ( false !== strpos( $value, '*' ) || false !== strpos( $value, '?' ) ) ) {
				$out[] = '/' . ltrim( str_replace( array( "\0", '\\' ), array( '', '/' ), $value ), '/' );
				continue;
			}
			$path = $this->normalize_path( $value );
			if ( '' !== $path ) {
				$out[] = $path;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, max( 0, absint( $limit ) ) );
	}

	/** Normalize one path without allowing traversal. */
	private function normalize_path( $value ) {
		$path = (string) wp_parse_url( (string) $value, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$path = str_replace( array( "\0", '\\' ), array( '', '/' ), $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = preg_replace( '#(?:^|/)\.\.(?:/|$)#', '/', $path );
		return '/' . ltrim( (string) $path, '/' );
	}

	private function lines( $option ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( $option, '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}
}
