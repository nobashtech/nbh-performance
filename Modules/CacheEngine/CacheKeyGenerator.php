<?php
/**
 * Path-traversal-safe cache key generation.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Security\Sanitization;

final class CacheKeyGenerator {
	/**
	 * Generate a deterministic cache key.
	 *
	 * @param string $url      URL.
	 * @param array  $variants Cache variants.
	 * @return string
	 */
	public function generate( $url, array $variants = array() ) {
		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$scheme = in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : 'https';
		$host   = isset( $parts['host'] ) ? Sanitization::cache_segment( strtolower( $parts['host'] ) ) : 'unknown-host';
		$port   = isset( $parts['port'] ) ? absint( $parts['port'] ) : 0;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		$path   = isset( $parts['path'] ) ? Sanitization::url_path( $parts['path'] ) : '/';
		$query  = isset( $parts['query'] ) ? $this->canonical_query( $parts['query'] ) : '';
		$origin = $scheme . '://' . $host . ( $port ? ':' . $port : '' );

		ksort( $variants );
		$source = $origin . '|' . $path . '|' . $query . '|' . wp_json_encode( $variants );
		$hash   = hash( 'sha256', $source );

		return $host . '/' . substr( $hash, 0, 2 ) . '/' . $hash;
	}

	/**
	 * Build the final cache file path.
	 *
	 * @param string $cache_root Cache root.
	 * @param string $key        Cache key.
	 * @return string
	 */
	public function path( $cache_root, $key ) {
		$root      = wp_normalize_path( untrailingslashit( $cache_root ) );
		$segments  = array_map( array( Sanitization::class, 'cache_segment' ), explode( '/', $key ) );
		$candidate = wp_normalize_path( $root . '/' . implode( '/', $segments ) . '/index.html' );

		if ( 0 !== strpos( $candidate, $root . '/' ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Remove tracking parameters and sort remaining query parameters.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	public function canonical_query( $query ) {
		parse_str( $query, $parameters );
		$ignored = (array) apply_filters(
			'nbh_performance_ignored_query_parameters',
			array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' )
		);

		foreach ( $ignored as $key ) {
			unset( $parameters[ $key ] );
		}

		ksort( $parameters );
		return http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 );
	}
}
