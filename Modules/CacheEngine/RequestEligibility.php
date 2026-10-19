<?php
/**
 * Determines whether the current public request and completed HTML response are safe to cache.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class RequestEligibility {
	/** Cookies that always imply authenticated, transactional or personalized state. */
	private const MANDATORY_BYPASS_COOKIE_PREFIXES = array(
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'phpsessid',
	);

	/** State-bearing query keys that extensions may never declare cache-safe. */
	private const MANDATORY_BYPASS_QUERY_KEYS = array(
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
		'nbh_css_source',
	);
	/** Determine whether the current request may generate a cache entry. */
	public function is_request_cacheable() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$uri    = \NBH\Performance\Modules\Security\RequestInput::uri();
		if ( '' === $uri ) {
			return false;
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( 'GET' !== $method || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( preg_match( '#/(?:wp-admin(?:/|$)|wp-login\.php(?:/|$)|wp-cron\.php(?:/|$)|xmlrpc\.php(?:/|$)|wp-comments-post\.php(?:/|$)|wp-json(?:/|$))#i', $path ) ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		if ( is_user_logged_in() || is_preview() || is_feed() || is_trackback() || is_robots() || is_search() ) {
			return false;
		}
		if ( function_exists( 'post_password_required' ) && post_password_required() ) {
			return false;
		}
		if ( ! $this->is_same_origin_request() || $this->has_sensitive_request_headers() || $this->has_bypass_query() || $this->has_bypass_cookie() ) {
			return false;
		}

		return (bool) apply_filters( 'nbh_performance_should_cache_request', true, $uri );
	}

	/** Determine whether the completed response is safe to persist. */
	public function is_response_cacheable() {
		$status = http_response_code();
		$status = false === $status || 0 === $status ? 200 : (int) $status;

		$cacheable_status = 200 === $status;
		if ( 404 === $status && get_option( 'nbh_performance_cache_404_enabled', false ) ) {
			$cacheable_status = true;
		}
		if ( in_array( $status, array( 301, 308 ), true ) && get_option( 'nbh_performance_cache_redirects_enabled', false ) ) {
			$cacheable_status = $this->has_safe_redirect_location();
		}
		if ( ! $cacheable_status ) {
			return false;
		}

		$content_type_seen = false;
		foreach ( headers_list() as $header ) {
			$header_lower = strtolower( $header );
			if ( 0 === strpos( $header_lower, 'set-cookie:' ) && ! $this->is_allowed_response_cookie( $header ) ) {
				return false;
			}
			if ( 0 === strpos( $header_lower, 'cache-control:' ) && preg_match( '/(?:no-store|no-cache|private|must-understand)/i', $header ) ) {
				return false;
			}
			if ( 0 === strpos( $header_lower, 'pragma:' ) && false !== stripos( $header, 'no-cache' ) ) {
				return false;
			}
			if ( 0 === strpos( $header_lower, 'vary:' ) && preg_match( '/(?:\*|cookie|authorization)/i', $header ) ) {
				return false;
			}
			if ( 0 === strpos( $header_lower, 'x-nbh-performance-do-not-cache:' ) ) {
				return false;
			}
			if ( 0 === strpos( $header_lower, 'content-type:' ) ) {
				$content_type_seen = true;
				if ( ! in_array( $status, array( 301, 308 ), true ) && false === stripos( $header, 'text/html' ) && false === stripos( $header, 'application/xhtml+xml' ) ) {
					return false;
				}
			}
		}

		if ( in_array( $status, array( 301, 308 ), true ) ) {
			return true;
		}

		return ! $content_type_seen || (bool) apply_filters( 'nbh_performance_cacheable_html_response', true );
	}

	/** Ensure Host, scheme and non-standard port match the configured WordPress origin. */
	private function is_same_origin_request() {
		$home        = wp_parse_url( home_url( '/' ) );
		$home_host   = is_array( $home ) && isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';
		$home_port   = is_array( $home ) && isset( $home['port'] ) ? absint( $home['port'] ) : 0;
		$home_scheme = is_array( $home ) && isset( $home['scheme'] ) ? strtolower( (string) $home['scheme'] ) : ( is_ssl() ? 'https' : 'http' );
		if ( ( 'https' === $home_scheme && 443 === $home_port ) || ( 'http' === $home_scheme && 80 === $home_port ) ) {
			$home_port = 0;
		}

		$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$parsed   = wp_parse_url( 'http://' . $raw_host );
		$host     = is_array( $parsed ) && isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$port     = is_array( $parsed ) && isset( $parsed['port'] ) ? absint( $parsed['port'] ) : 0;
		$scheme   = is_ssl() ? 'https' : 'http';
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}

		return '' !== $home_host && $host === $home_host && $scheme === $home_scheme && $port === $home_port;
	}

	/** Reject authentication, range, AJAX and explicit cache-bypass request headers. */
	private function has_sensitive_request_headers() {
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) || isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return true;
		}
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['HTTP_RANGE'] ) ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) ) {
			return true;
		}
		$cache_control = isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ? strtolower( \NBH\Performance\Modules\Security\RequestInput::server_text( 'HTTP_CACHE_CONTROL' ) ) : '';
		$pragma        = isset( $_SERVER['HTTP_PRAGMA'] ) ? strtolower( \NBH\Performance\Modules\Security\RequestInput::server_text( 'HTTP_PRAGMA' ) ) : '';
		return (bool) preg_match( '/(?:^|,)\s*(?:no-cache|no-store|max-age=0)\s*(?:,|$)/', $cache_control ) || false !== strpos( $pragma, 'no-cache' );
	}

	/** Whether query parameters are neither ignored nor explicitly allow-listed. */
	private function has_bypass_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification to bypass private/cart/cache requests; no settings or content are modified and public navigation cannot require an admin nonce.
		if ( empty( $_GET ) ) {
			return false;
		}
		$ignored = array_map( 'sanitize_key', (array) apply_filters( 'nbh_performance_ignored_query_parameters', array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' ) ) );
		$allowed = array_map( 'sanitize_key', $this->lines( 'nbh_performance_cache_allowed_query_args' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification to bypass private/cart/cache requests; no settings or content are modified and public navigation cannot require an admin nonce.
		foreach ( array_keys( $_GET ) as $key ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, self::MANDATORY_BYPASS_QUERY_KEYS, true ) ) {
				return true;
			}
			if ( ! in_array( $key, $ignored, true ) && ! in_array( $key, $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a cookie indicates authenticated, transactional or personalized content. */
	private function has_bypass_cookie() {
		$filtered        = (array) apply_filters( 'nbh_performance_bypass_cookie_prefixes', self::MANDATORY_BYPASS_COOKIE_PREFIXES );
		$bypass_prefixes = array_values( array_unique( array_merge( self::MANDATORY_BYPASS_COOKIE_PREFIXES, $filtered ) ) );
		foreach ( $_COOKIE as $cookie_value ) {
			if ( ! is_scalar( $cookie_value ) ) {
				return true;
			}
		}
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			foreach ( $bypass_prefixes as $prefix ) {
				if ( '' !== (string) $prefix && 0 === stripos( (string) $cookie_name, (string) $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Allow only explicitly declared, non-personal response cookies. */
	private function is_allowed_response_cookie( $header ) {
		$cookie   = trim( substr( (string) $header, strlen( 'set-cookie:' ) ) );
		$raw_name = strtolower( trim( (string) strtok( $cookie, '=' ) ) );
		foreach ( self::MANDATORY_BYPASS_COOKIE_PREFIXES as $prefix ) {
			if ( 0 === stripos( $raw_name, $prefix ) ) {
				return false;
			}
		}
		$name    = sanitize_key( $raw_name );
		$allowed = array_map( 'sanitize_key', (array) apply_filters( 'nbh_performance_cacheable_response_cookies', array() ) );
		return '' !== $name && in_array( $name, $allowed, true );
	}

	/** Ensure permanent redirects remain on the current origin or use a relative URL. */
	private function has_safe_redirect_location() {
		$location = '';
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'location:' ) ) {
				$location = trim( substr( $header, strlen( 'location:' ) ) );
				break;
			}
		}
		if ( '' === $location || preg_match( '/[\r\n]/', $location ) ) {
			return false;
		}
		$parts = wp_parse_url( $location );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 0 === strpos( $location, '/' );
		}
		$home_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$home_scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$scheme      = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : $home_scheme;
		return strtolower( (string) $parts['host'] ) === $home_host && $scheme === $home_scheme;
	}

	private function lines( $option ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( $option, '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}
}
