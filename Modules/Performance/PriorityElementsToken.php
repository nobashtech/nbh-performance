<?php
/**
 * One-time signed public tokens for browser priority observations.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Performance;

final class PriorityElementsToken {
	const VERSION = 'v2';

	/** Issue a short-lived one-time token bound to the normalized page URL. */
	public function issue( $url ) {
		$url = ( new PriorityElementsStorage() )->normalize_url( $url );
		if ( ! $url ) {
			return '';
		}
		$expires = time() + 10 * MINUTE_IN_SECONDS;
		$jti     = wp_generate_password( 24, false, false );
		$payload = $this->payload( $url, $expires, $jti );
		return self::VERSION . '.' . $expires . '.' . $jti . '.' . hash_hmac( 'sha256', $payload, $this->secret() );
	}

	/**
	 * Verify and atomically consume a token.
	 *
	 * @return bool False for expired, replayed, malformed, or mismatched tokens.
	 */
	public function consume( $url, $token ) {
		$url   = ( new PriorityElementsStorage() )->normalize_url( $url );
		$parts = explode( '.', (string) $token, 4 );
		if ( ! $url || 4 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			return false;
		}
		$expires = absint( $parts[1] );
		$jti     = sanitize_text_field( $parts[2] );
		$sig     = (string) $parts[3];
		if ( ! $jti || $expires < time() || $expires > time() + 15 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $this->payload( $url, $expires, $jti ), $this->secret() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return false;
		}

		$key = 'nbh_performance_pe_token_' . substr( hash( 'sha256', $jti ), 0, 32 );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, max( 60, $expires - time() + 60 ) );
		return true;
	}

	private function payload( $url, $expires, $jti ) {
		return self::VERSION . '|' . hash( 'sha256', strtolower( $url ) ) . '|' . absint( $expires ) . '|' . sanitize_text_field( $jti );
	}

	private function secret() {
		return hash( 'sha256', wp_salt( 'nonce' ) . '|nbh-performance-priority-elements|' . home_url( '/' ) );
	}
}
