<?php
/**
 * Cache purge endpoint rate limiter.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Security;

final class PurgeEndpointRateLimiter {
	const DEFAULT_LIMIT  = 10;
	const DEFAULT_WINDOW = 60;

	public function allow( $actor ) {
		$actor  = hash( 'sha256', (string) $actor );
		$key    = 'nbh_performance_purge_' . substr( $actor, 0, 32 );
		$record = get_transient( $key );
		$limit  = (int) apply_filters( 'nbh_performance_purge_rate_limit', self::DEFAULT_LIMIT );
		$window = (int) apply_filters( 'nbh_performance_purge_rate_window', self::DEFAULT_WINDOW );

		if ( ! is_array( $record ) ) {
			set_transient( $key, array( 'count' => 1 ), max( 1, $window ) );
			return true;
		}

		if ( (int) $record['count'] >= max( 1, $limit ) ) {
			return false;
		}

		++$record['count'];
		set_transient( $key, $record, max( 1, $window ) );
		return true;
	}
}
