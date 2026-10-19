<?php
/** Custom HTTPS webhook CDN / edge purge adapter. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

use WP_Error;

final class CustomCdnClient extends AbstractApiClient implements EdgeProviderInterface {
	public function id() {
		return 'custom';
	}

	public function is_configured() {
		$url    = $this->webhook_url();
		$secret = $this->secret();
		return '' !== $url && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && strlen( $secret ) >= 32;
	}

	public function purge_all() {
		return $this->dispatch(
			array(
				'action' => 'purge_all',
				'site'   => home_url( '/' ),
			)
		);
	}

	public function purge_urls( array $urls ) {
		$urls = $this->clean_urls( $urls );
		if ( empty( $urls ) ) {
			return new WP_Error( 'nbh_performance_no_edge_urls', __( 'No valid URLs were supplied.', 'nbh-performance' ) );
		}
		return $this->dispatch(
			array(
				'action' => 'purge_urls',
				'site'   => home_url( '/' ),
				'urls'   => $urls,
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function test_connection() {
		return $this->dispatch(
			array(
				'action' => 'test',
				'site'   => home_url( '/' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 * @return true|WP_Error
	 */
	private function dispatch( array $payload ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'nbh_performance_custom_cdn_not_configured', __( 'Custom CDN webhook is not fully configured.', 'nbh-performance' ) );
		}
		$payload['timestamp'] = time();
		$body                 = wp_json_encode( $payload );
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'nbh_performance_custom_cdn_payload', __( 'Unable to encode the CDN webhook payload.', 'nbh-performance' ) );
		}
		$timestamp = time();
		$nonce     = wp_generate_password( 24, false, false );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $this->secret() );
		$result    = $this->request(
			$this->webhook_url(),
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'                => 'application/json',
					'X-NBH-Performance-Timestamp' => (string) $timestamp,
					'X-NBH-Performance-Nonce'     => $nonce,
					'X-NBH-Performance-Signature' => $signature,
				),
				'body'    => $body,
				'timeout' => 12,
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	private function webhook_url() {
		return esc_url_raw( (string) get_option( 'nbh_performance_edge_purge_webhook_url', '' ) );
	}

	private function secret() {
		return (string) get_option( 'nbh_performance_edge_purge_secret', '' );
	}
}
