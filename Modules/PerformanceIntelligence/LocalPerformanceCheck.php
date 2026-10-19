<?php
/**
 * Bounded, anonymous homepage diagnostics without an external measurement service.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\PerformanceIntelligence;

final class LocalPerformanceCheck {
	const OPTION    = 'nbh_performance_local_performance_check';
	const MAX_BYTES = 2097152;

	/** Fetch only the configured homepage; never follow redirects or fetch its assets. */
	public function run() {
		$previous = get_option( self::OPTION, array() );
		if ( is_array( $previous ) && isset( $previous['checked_at'] ) && $previous['checked_at'] > time() - 15 ) {
			return $previous;
		}
		$url     = home_url( '/' );
		$started = microtime( true );
		// Trusted administrator-configured homepage only; no user URL, cookies or redirects. Local/private loopbacks are intentional.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'cookies'             => array(),
				'headers'             => array( 'User-Agent' => 'NBH Performance local homepage check/' . NBH_PERFORMANCE_VERSION ),
			)
		);
		$duration = round( ( microtime( true ) - $started ) * 1000, 2 );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'nbh_performance_local_check_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'nbh_performance_local_check_status', sprintf( /* translators: %d: HTTP response status. */ __( 'Homepage returned HTTP %d. Check the site URL, redirects and access restrictions.', 'nbh-performance' ), $code ), array( 'status' => 502 ) );
		}
		$body = wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( strlen( $body ) > self::MAX_BYTES || '' === trim( $body ) || false === strpos( $type, 'text/html' ) ) {
			return new \WP_Error( 'nbh_performance_local_check_html', __( 'The homepage must return HTML smaller than 2 MB for this lightweight check.', 'nbh-performance' ), array( 'status' => 422 ) );
		}
		$processor = new \WP_HTML_Tag_Processor( $body );
		$scripts   = 0;
		$styles    = 0;
		$beacon    = false;
		while ( $processor->next_tag() ) {
			if ( 'SCRIPT' === $processor->get_tag() ) {
				$src = $processor->get_attribute( 'src' );
				if ( is_string( $src ) && '' !== $src ) {
					++$scripts;
					$beacon = $beacon || false !== strpos( $src, '/assets/js/rum-beacon.js' );
				}
			} elseif ( 'LINK' === $processor->get_tag() && preg_match( '/(?:^|\s)stylesheet(?:\s|$)/i', (string) $processor->get_attribute( 'rel' ) ) ) {
				++$styles;
			}
		}
		$cache = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'x-nbh-performance' ) );
		if ( '' === $cache ) {
			$cache = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'x-nbh-performance-cache' ) );
		}
		$result = array(
			'checked_at'         => time(),
			'url'                => $url,
			'http_status'        => $code,
			'response_ms'        => $duration,
			'html_bytes'         => strlen( $body ),
			'external_scripts'   => $scripts,
			'stylesheets'        => $styles,
			'cache_status'       => substr( $cache, 0, 100 ),
			'rum_beacon_present' => $beacon,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}
}
