<?php
/** Critical CSS generation concurrency lock. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class GenerationLock {
	const TTL = 300;
	/** @return string|false */
	public function acquire( $url, $variant ) {
		$key   = 'nbh_performance_cc_lock_' . hash( 'sha256', strtolower( (string) $url ) . '|' . sanitize_key( $variant ) );
		$token = wp_generate_uuid4();
		if ( ! add_option(
			$key,
			array(
				'token'   => $token,
				'expires' => time() + self::TTL,
			),
			'',
			false
		) ) {
			$current = get_option( $key );
			if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] < time() ) {
				delete_option( $key );
				return $this->acquire( $url, $variant );
			}
			return false;
		}
		return $token;
	}
	public function release( $url, $variant, $token ) {
		$key     = 'nbh_performance_cc_lock_' . hash( 'sha256', strtolower( (string) $url ) . '|' . sanitize_key( $variant ) );
		$current = get_option( $key );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) {
			delete_option( $key );
		}
	}
}
