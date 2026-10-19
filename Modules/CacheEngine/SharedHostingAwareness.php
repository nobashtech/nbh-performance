<?php
/**
 * Resource-aware preload policy.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class SharedHostingAwareness {
	/** Return a safe worker batch size. */
	public function batch_size() {
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$size   = ( $memory > 0 && $memory < 268435456 ) ? 1 : 3;
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			if ( is_array( $load ) && isset( $load[0] ) && $load[0] > 4 ) {
				$size = 1;
			}
		}
		return max( 1, min( 10, (int) apply_filters( 'nbh_performance_preload_batch_size', $size ) ) );
	}
}
