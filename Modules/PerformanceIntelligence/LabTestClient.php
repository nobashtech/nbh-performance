<?php
/**
 * PageSpeed Insights laboratory test client.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\PerformanceIntelligence;

final class LabTestClient {
	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/** Explain when a remote Lighthouse provider cannot reach this site. */
	public static function availability() {
		$host  = strtolower( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '.' ) );
		$local = ! strpos( $host, '.' ) || preg_match( '/(?:^|\.)(?:local|localhost|test|invalid|example)$/', $host );
		$ip    = trim( $host, '[]' );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$local = true;
		}
		$local = $local || in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		return array(
			'available' => ! $local,
			'reason'    => $local ? __( 'Google PageSpeed cannot reach this local or development site. Use the local homepage check and real-user monitoring here. Lighthouse tests require a publicly accessible website.', 'nbh-performance' ) : __( 'Optional Google PageSpeed test: sends selected public page URLs to Google. Provider quotas or an API key may apply; local checks and visitor monitoring work independently.', 'nbh-performance' ),
		);
	}

	/** Run a performance-only Lighthouse test through PageSpeed Insights. */
	public function run( $url, $strategy ) {
		$availability = self::availability();
		if ( ! $availability['available'] ) {
			return new \WP_Error( 'nbh_performance_lab_unavailable', $availability['reason'], array( 'status' => 400 ) );
		}
		$url      = $this->validate_url( $url );
		$strategy = in_array( $strategy, array( 'mobile', 'desktop' ), true ) ? $strategy : 'mobile';
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$query = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		$key   = defined( 'NBH_PERFORMANCE_PAGESPEED_API_KEY' ) ? (string) NBH_PERFORMANCE_PAGESPEED_API_KEY : '';
		$key   = (string) apply_filters( 'nbh_performance_pagespeed_api_key', $key );
		if ( '' !== trim( $key ) ) {
			$query['key'] = trim( $key );
		}
		$endpoint = add_query_arg( $query, self::ENDPOINT );
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'     => 90,
				'redirection' => 2,
				'headers'     => array( 'User-Agent' => 'NBH Performance/' . NBH_PERFORMANCE_VERSION . '; ' . home_url( '/' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : __( 'PageSpeed Insights returned an invalid response.', 'nbh-performance' );
			return new \WP_Error( 'nbh_performance_lab_provider_error', $message, array( 'status' => $code ) );
		}
		return $this->normalize( $url, $strategy, $body );
	}

	/** Normalize Lighthouse output to NBH Performance metrics. */
	private function normalize( $url, $strategy, array $body ) {
		$lighthouse = isset( $body['lighthouseResult'] ) && is_array( $body['lighthouseResult'] ) ? $body['lighthouseResult'] : array();
		$audits     = isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
		$score      = isset( $lighthouse['categories']['performance']['score'] ) && is_numeric( $lighthouse['categories']['performance']['score'] ) ? round( (float) $lighthouse['categories']['performance']['score'] * 100, 2 ) : null;
		$requests   = isset( $audits['network-requests']['details']['items'] ) && is_array( $audits['network-requests']['details']['items'] ) ? count( $audits['network-requests']['details']['items'] ) : null;
		$result     = array(
			'url'               => $url,
			'strategy'          => $strategy,
			'provider'          => 'pagespeed',
			'status'            => 'complete',
			'performance_score' => $score,
			'lcp'               => $this->numeric_value( $audits, 'largest-contentful-paint' ),
			'cls'               => $this->numeric_value( $audits, 'cumulative-layout-shift' ),
			'tbt'               => $this->numeric_value( $audits, 'total-blocking-time' ),
			'fcp'               => $this->numeric_value( $audits, 'first-contentful-paint' ),
			'speed_index'       => $this->numeric_value( $audits, 'speed-index' ),
			'ttfb'              => $this->numeric_value( $audits, 'server-response-time' ),
			'total_byte_weight' => $this->numeric_value( $audits, 'total-byte-weight' ),
			'request_count'     => $requests,
			'audits'            => $this->audit_summary( $audits ),
			'opportunities'     => $this->opportunities( $audits ),
			'captured_at'       => time(),
		);
		return apply_filters( 'nbh_performance_lab_test_result', $result, $body );
	}

	private function audit_summary( array $audits ) {
		$summary = array();
		foreach ( array( 'largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time', 'first-contentful-paint', 'speed-index', 'server-response-time', 'total-byte-weight' ) as $id ) {
			if ( ! isset( $audits[ $id ] ) || ! is_array( $audits[ $id ] ) ) {
				continue;
			}
			$summary[ $id ] = array(
				'title'        => isset( $audits[ $id ]['title'] ) ? sanitize_text_field( $audits[ $id ]['title'] ) : $id,
				'displayValue' => isset( $audits[ $id ]['displayValue'] ) ? sanitize_text_field( $audits[ $id ]['displayValue'] ) : '',
				'score'        => isset( $audits[ $id ]['score'] ) && is_numeric( $audits[ $id ]['score'] ) ? (float) $audits[ $id ]['score'] : null,
			);
		}
		return $summary;
	}

	private function opportunities( array $audits ) {
		$items = array();
		foreach ( $audits as $id => $audit ) {
			if ( ! is_array( $audit ) || ! isset( $audit['details'] ) || ! is_array( $audit['details'] ) ) {
				continue;
			}
			$savings_ms    = isset( $audit['details']['overallSavingsMs'] ) ? (float) $audit['details']['overallSavingsMs'] : 0;
			$savings_bytes = isset( $audit['details']['overallSavingsBytes'] ) ? absint( $audit['details']['overallSavingsBytes'] ) : 0;
			$score         = isset( $audit['score'] ) && is_numeric( $audit['score'] ) ? (float) $audit['score'] : 1;
			if ( $score >= 0.9 || ( $savings_ms <= 0 && $savings_bytes <= 0 ) ) {
				continue;
			}
			$items[] = array(
				'id'            => sanitize_key( $id ),
				'title'         => isset( $audit['title'] ) ? sanitize_text_field( $audit['title'] ) : sanitize_text_field( $id ),
				'description'   => isset( $audit['description'] ) ? wp_strip_all_tags( $audit['description'] ) : '',
				'savings_ms'    => round( $savings_ms, 2 ),
				'savings_bytes' => $savings_bytes,
				'score'         => $score,
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return ( $b['savings_ms'] + $b['savings_bytes'] / 1024 ) <=> ( $a['savings_ms'] + $a['savings_bytes'] / 1024 );
			}
		);
		return array_slice( $items, 0, 12 );
	}

	private function numeric_value( array $audits, $id ) {
		return isset( $audits[ $id ]['numericValue'] ) && is_numeric( $audits[ $id ]['numericValue'] ) ? (float) $audits[ $id ]['numericValue'] : null;
	}

	private function validate_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'nbh_performance_invalid_lab_url', __( 'Select a valid public HTTP or HTTPS URL.', 'nbh-performance' ) );
		}
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $url_host || ! hash_equals( $home_host, $url_host ) ) {
			return new \WP_Error( 'nbh_performance_external_lab_url', __( 'Performance tests are limited to this WordPress site.', 'nbh-performance' ) );
		}
		return $url;
	}
}
