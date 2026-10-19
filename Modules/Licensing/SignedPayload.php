<?php
/**
 * Deterministic JSON and timestamp validation for signed service payloads.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Licensing;

final class SignedPayload {
	/**
	 * Produce deterministic JSON for Ed25519 signing and verification.
	 *
	 * @param array<mixed> $payload Payload.
	 * @return string
	 */
	public static function canonical_json( array $payload ) {
		$payload = self::sort_recursive( $payload );
		return (string) wp_json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
	}

	/**
	 * Validate the bounded lifetime attached to a signed payload.
	 *
	 * @param array<mixed> $payload      Payload.
	 * @param int          $max_lifetime Maximum allowed validity window.
	 * @return true|\WP_Error
	 */
	public static function validate_lifetime( array $payload, $max_lifetime ) {
		$issued  = isset( $payload['signature_issued_at'] ) ? absint( $payload['signature_issued_at'] ) : 0;
		$expires = isset( $payload['signature_expires_at'] ) ? absint( $payload['signature_expires_at'] ) : 0;
		$now     = time();
		$skew    = 300;

		if ( $issued <= 0 || $expires <= 0 || $expires <= $issued ) {
			return new \WP_Error(
				'nbh_performance_signature_lifetime_missing',
				__( 'The signed response did not include a valid verification lifetime.', 'nbh-performance' )
			);
		}
		if ( $issued > ( $now + $skew ) || $expires < ( $now - $skew ) ) {
			return new \WP_Error(
				'nbh_performance_signature_expired',
				__( 'The signed response is expired or not yet valid.', 'nbh-performance' )
			);
		}
		if ( ( $expires - $issued ) > absint( $max_lifetime ) ) {
			return new \WP_Error(
				'nbh_performance_signature_lifetime_excessive',
				__( 'The signed response validity window is longer than NBH Performance allows.', 'nbh-performance' )
			);
		}

		return true;
	}

	/** @param array<mixed> $data Data. @return array<mixed> */
	private static function sort_recursive( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sort_recursive( $value );
			}
		}
		ksort( $data );
		return $data;
	}
}
