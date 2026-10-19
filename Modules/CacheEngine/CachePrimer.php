<?php
/** Same-origin cache priming helper. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

final class CachePrimer {
	public static function warm( $url, $variant = 'desktop' ) {
		$url       = esc_url_raw( (string) $url );
		$variant   = 'mobile' === $variant ? 'mobile' : 'desktop';
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$scheme    = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$result    = array(
			'success'      => false,
			'url'          => $url,
			'variant'      => $variant,
			'status'       => 0,
			'error'        => '',
			'duration_ms'  => 0,
			'cache_status' => '',
			'checked_at'   => time(),
		);
		if ( '' === $url || '' === $home_host || $home_host !== $url_host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$result['error'] = 'invalid_same_origin_url';
			self::store( $result );
			return $result;
		}
		$environment           = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$is_local              = in_array( $environment, array( 'local', 'development', 'staging' ), true ) || 'localhost' === $home_host || preg_match( '/\.(?:local|test|localhost)$/i', $home_host );
		$user_agent            = 'mobile' === $variant ? 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36 NBH-Performance-Preload' : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36 NBH-Performance-Preload';
		$started               = microtime( true );
		$response              = wp_remote_get(
			$url,
			array(
				'timeout'     => (int) apply_filters( 'nbh_performance_preload_request_timeout', 12 ),
				'redirection' => 2,
				'blocking'    => true,
				'sslverify'   => ! $is_local,
				'cookies'     => array(),
				'headers'     => array(
					'X-NBH-Performance-Preload' => '1',
					'X-NBH-Performance-Variant' => $variant,
					'User-Agent'                => $user_agent,
				),
			)
		);
		$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			self::store( $result );
			return $result;
		}
		$result['status']       = (int) wp_remote_retrieve_response_code( $response );
		$result['cache_status'] = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'x-nbh-performance' ) );
		if ( '' === $result['cache_status'] ) {
			$result['cache_status'] = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'x-nbh-performance-cache' ) );
		}
		$result['success'] = in_array( $result['status'], array( 200, 301, 308 ), true );
		if ( ! $result['success'] ) {
			$result['error'] = 'unexpected_http_status';
		}
		if ( $result['duration_ms'] > absint( get_option( 'nbh_performance_preload_slow_request_ms', 5000 ) ) ) {
			$result['slow'] = true;
		}
		self::store( $result );
		return $result;
	}
	private static function store( array $result ) {
		update_option( 'nbh_performance_last_warm_result', $result, false );
		$history = get_option( 'nbh_performance_preload_recent_results', array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, $result );
		update_option( 'nbh_performance_preload_recent_results', array_slice( $history, 0, 50 ), false );
	}
}
