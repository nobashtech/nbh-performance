<?php
/** Generator request rate limiting. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class GeneratorRateLimiter {
	const WINDOW = 60;
	const LIMIT  = 10;

	/** @return bool */
	public function allow( $scope = 'default' ) {
		$key   = 'nbh_performance_cc_rate_' . hash( 'sha256', sanitize_key( (string) $scope ) );
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array(
				'count'   => 0,
				'started' => time(),
			);
		}
		if ( time() - (int) $state['started'] >= self::WINDOW ) {
			$state = array(
				'count'   => 0,
				'started' => time(),
			);
		}
		if ( (int) $state['count'] >= (int) apply_filters( 'nbh_performance_critical_css_rate_limit', self::LIMIT, $scope ) ) {
			return false;
		}
		++$state['count'];
		set_transient( $key, $state, self::WINDOW );
		return true;
	}
}
