<?php
/**
 * Build a safe, versioned metadata envelope for cached HTML responses.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheResponseMetadata {
	const SCHEMA_VERSION = 2;

	/**
	 * Build cache metadata for the current response.
	 *
	 * @param string $content  Cached HTML.
	 * @param string $url      Canonical URL.
	 * @param array  $variants Cache variants.
	 * @param int    $ttl      Cache lifetime.
	 * @return array
	 */
	public function build( $content, $url, array $variants, $ttl ) {
		$generated_at = time();
		$ttl          = max( 60, min( WEEK_IN_SECONDS, absint( $ttl ) ) );
		$swr          = max( 0, min( DAY_IN_SECONDS, absint( get_option( 'nbh_performance_cache_stale_while_revalidate', 300 ) ) ) );
		$sie          = max( 0, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_cache_stale_if_error', DAY_IN_SECONDS ) ) ) );
		$hash         = hash( 'sha256', (string) $content );
		$status       = http_response_code();
		$status       = false === $status || 0 === $status ? 200 : absint( $status );
		$headers      = $this->safe_headers( $status );

		return array(
			'schema_version'         => self::SCHEMA_VERSION,
			'generated_at'           => $generated_at,
			'expires_at'             => $generated_at + $ttl,
			'stale_while_revalidate' => $swr,
			'stale_if_error'         => $sie,
			'stale_until'            => $generated_at + $ttl + max( $swr, $sie ),
			'ttl'                    => $ttl,
			'status'                 => $status,
			'headers'                => $headers,
			'etag'                   => in_array( $status, array( 200, 404 ), true ) ? 'W/"nbh-' . substr( $hash, 0, 32 ) . '"' : '',
			'last_modified'          => in_array( $status, array( 200, 404 ), true ) ? gmdate( 'D, d M Y H:i:s', $generated_at ) . ' GMT' : '',
			'content_sha256'         => $hash,
			'content_length'         => strlen( (string) $content ),
			'url'                    => esc_url_raw( (string) $url ),
			'variants'               => $variants,
			'compression'            => array(),
			'generator'              => 'NBH Performance/' . ( defined( 'NBH_PERFORMANCE_VERSION' ) ? NBH_PERFORMANCE_VERSION : '1.0.0' ),
		);
	}

	/**
	 * Preserve only response headers that are safe to replay before WordPress boots.
	 *
	 * @param int $status Response status.
	 * @return array
	 */
	private function safe_headers( $status ) {
		$allowed = array(
			'content-type',
			'content-language',
			'link',
			'x-robots-tag',
			'x-content-type-options',
			'x-frame-options',
			'referrer-policy',
			'permissions-policy',
		);
		if ( in_array( (int) $status, array( 301, 308 ), true ) ) {
			$allowed[] = 'location';
		}

		$headers = array();
		foreach ( headers_list() as $line ) {
			$position = strpos( $line, ':' );
			if ( false === $position ) {
				continue;
			}
			$name  = strtolower( trim( substr( $line, 0, $position ) ) );
			$value = trim( substr( $line, $position + 1 ) );
			if ( ! in_array( $name, $allowed, true ) || '' === $value || preg_match( '/[\r\n]/', $value ) ) {
				continue;
			}
			if ( 'location' === $name && ! $this->safe_redirect_location( $value ) ) {
				continue;
			}
			$headers[ $name ] = substr( $value, 0, 4096 );
		}

		if ( ! in_array( (int) $status, array( 301, 308 ), true ) && empty( $headers['content-type'] ) ) {
			$headers['content-type'] = 'text/html; charset=UTF-8';
		}

		return $headers;
	}

	/** Validate a redirect before storing it in early-runtime metadata. */
	private function safe_redirect_location( $location ) {
		$parts = wp_parse_url( (string) $location );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 0 === strpos( (string) $location, '/' );
		}

		$home_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$home_scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$scheme      = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : $home_scheme;

		return strtolower( (string) $parts['host'] ) === $home_host && $scheme === $home_scheme;
	}
}
