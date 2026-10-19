<?php
/** Shared hardened HTTP client for CDN providers. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

use WP_Error;

abstract class AbstractApiClient {
	/**
	 * @param string               $url  Absolute HTTPS URL.
	 * @param array<string,mixed>  $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function request( $url, array $args ) {
		if ( ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'nbh_performance_invalid_edge_endpoint', __( 'The CDN endpoint is invalid.', 'nbh-performance' ) );
		}
		$args['timeout']            = min( 20, max( 3, isset( $args['timeout'] ) ? (int) $args['timeout'] : 10 ) );
		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = true;
		$args['user-agent']         = 'NBH Performance/' . NBH_PERFORMANCE_VERSION . '; ' . home_url( '/' );
		$response                   = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d: HTTP response status code. */
			$message = sprintf( __( 'The CDN API returned HTTP %d.', 'nbh-performance' ), $status );
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				if ( ! empty( $decoded['errors'][0]['message'] ) ) {
					$message = sanitize_text_field( (string) $decoded['errors'][0]['message'] );
				} elseif ( ! empty( $decoded['Message'] ) ) {
					$message = sanitize_text_field( (string) $decoded['Message'] );
				} elseif ( ! empty( $decoded['description'] ) ) {
					$message = sanitize_text_field( (string) $decoded['description'] );
				}
			}
			return new WP_Error(
				'nbh_performance_edge_api_error',
				$message,
				array(
					'status' => $status,
					'body'   => $decoded,
				)
			);
		}
		if ( '' === $body ) {
			return array( 'success' => true );
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : array(
			'success' => true,
			'body'    => $body,
		);
	}

	/**
	 * Allow same-origin and configured CDN hostname URLs.
	 *
	 * @param array<int,string> $urls Raw URLs.
	 * @return array<int,string>
	 */
	protected function clean_urls( array $urls ) {
		$allowed  = array(
			strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
		);
		$cdn_base = esc_url_raw( (string) get_option( 'nbh_performance_cdn_base_url', '' ) );
		if ( $cdn_base ) {
			$cdn_host = strtolower( (string) wp_parse_url( $cdn_base, PHP_URL_HOST ) );
			if ( $cdn_host ) {
				$allowed[] = $cdn_host;
			}
		}
		$allowed = array_values( array_unique( array_filter( $allowed ) ) );
		$clean   = array();
		foreach ( array_slice( $urls, 0, 50 ) as $url ) {
			$url  = esc_url_raw( (string) $url );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( wp_http_validate_url( $url ) && $host && in_array( $host, $allowed, true ) ) {
				$clean[] = $url;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Home host and parent domain candidates for zone matching.
	 *
	 * @return array<int,string>
	 */
	protected function site_host_candidates() {
		$host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host  = preg_replace( '/^www\./', '', $host );
		$items = array( $host );
		$parts = explode( '.', $host );
		if ( count( $parts ) > 2 ) {
			$items[] = implode( '.', array_slice( $parts, -2 ) );
		}
		return array_values( array_unique( array_filter( $items ) ) );
	}
}
