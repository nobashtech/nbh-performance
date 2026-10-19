<?php
/** Anonymous RUM ingestion limiter. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

final class RumRateLimiter {
	/**
	 * Apply both a per-client and a global admission bound.
	 *
	 * Persistent object cache increments are atomic across PHP workers. The
	 * transient fallback preserves compatibility on hosts without Redis/Memcached.
	 *
	 * @return bool
	 */
	public function allow() {
		$address      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$bucket       = (string) floor( time() / MINUTE_IN_SECONDS );
		$client_hash  = hash_hmac( 'sha256', $address, wp_salt( 'nonce' ) );
		$client_limit = max( 5, min( 1000, (int) apply_filters( 'nbh_performance_rum_rate_limit', 30 ) ) );
		$global_limit = max( 30, min( 100000, (int) apply_filters( 'nbh_performance_rum_global_rate_limit', 600 ) ) );

		if ( ! $this->allow_bucket( 'global_' . $bucket, $global_limit ) ) {
			return false;
		}
		return $this->allow_bucket( 'client_' . $bucket . '_' . $client_hash, $client_limit );
	}

	/** Check one bounded counter. */
	private function allow_bucket( $key, $limit ) {
		$group = 'nbh_performance_rum_rate';
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			if ( wp_cache_add( $key, 1, $group, 2 * MINUTE_IN_SECONDS ) ) {
				return true;
			}
			$count = wp_cache_incr( $key, 1, $group );
			return false !== $count && (int) $count <= (int) $limit;
		}

		$transient = 'nbh_performance_rum_rate_' . substr( hash( 'sha256', $key ), 0, 40 );
		$count     = (int) get_transient( $transient );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $transient, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}
}
