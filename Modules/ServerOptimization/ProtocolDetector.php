<?php
/**
 * Detects the current HTTP protocol and server hints.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ServerOptimization;

final class ProtocolDetector {
	/** @return array<string, mixed> */
	public function detect() {
		$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) ) : 'UNKNOWN';
		$http2    = false !== strpos( $protocol, 'HTTP/2' ) || 'h2' === strtolower( $protocol );
		$http3    = false !== strpos( $protocol, 'HTTP/3' ) || isset( $_SERVER['HTTP3'] ) || isset( $_SERVER['QUIC'] );

		if ( isset( $_SERVER['HTTP_CF_HTTP_PROTOCOL'] ) ) {
			$cloudflare_protocol = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_HTTP_PROTOCOL'] ) ) );
			$http2               = $http2 || false !== strpos( $cloudflare_protocol, 'h2' );
			$http3               = $http3 || false !== strpos( $cloudflare_protocol, 'h3' );
		}

		return array(
			'protocol' => $protocol,
			'http2'    => $http2,
			'http3'    => $http3,
			'tls'      => is_ssl(),
		);
	}
}
