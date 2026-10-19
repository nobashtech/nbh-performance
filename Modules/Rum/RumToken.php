<?php
/** Short-lived signed anonymous beacon tokens fetched after browser-side sampling. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

final class RumToken {
	const TTL = 1800;
	public function create( $url_hash, $context ) {
		$timestamp = time();
		$payload   = $timestamp . '|' . $url_hash . '|' . $context;
		return array(
			'timestamp' => $timestamp,
			'signature' => hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ),
		);
	}
	public function verify( $timestamp, $signature, $url_hash, $context ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp < ( time() - self::TTL ) || $timestamp > ( time() + 60 ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '|' . $url_hash . '|' . $context, wp_salt( 'auth' ) );
		return hash_equals( $expected, (string) $signature );
	}
}
