<?php
/**
 * Classifies hosting resources and supplies adaptive workload limits.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ServerOptimization;

final class HostingIntelligence {
	/** @return array<string, mixed> */
	public function profile() {
		$memory_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$cpu_count    = $this->cpu_count();
		$load         = $this->load_average();
		$tier         = $this->classify( $memory_limit, $cpu_count, $load );

		return array(
			'tier'              => $tier,
			'memory_limit'      => $memory_limit,
			'cpu_count'         => $cpu_count,
			'load_average_1m'   => $load,
			'preload_batch'     => $this->limit_for_tier( $tier, 1, 3, 6 ),
			'generator_workers' => $this->limit_for_tier( $tier, 1, 2, 4 ),
			'cleanup_batch'     => $this->limit_for_tier( $tier, 100, 500, 1000 ),
		);
	}

	/** @return int */
	private function cpu_count() {
		// Hosting integrations may supply an authoritative count without running a shell command.
		return max( 0, min( 256, absint( apply_filters( 'nbh_performance_host_cpu_count', 0 ) ) ) );
	}

	/** @return float */
	private function load_average() {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return 0.0;
		}
		$load = sys_getloadavg();
		return is_array( $load ) && isset( $load[0] ) ? max( 0.0, (float) $load[0] ) : 0.0;
	}

	/**
	 * @param int   $memory_limit Memory bytes.
	 * @param int   $cpu_count CPU count.
	 * @param float $load Load average.
	 * @return string
	 */
	private function classify( $memory_limit, $cpu_count, $load ) {
		if ( ( $memory_limit > 0 && $memory_limit < 268435456 ) || ( $cpu_count > 0 && $cpu_count <= 1 ) || $load >= 4.0 ) {
			return 'constrained';
		}
		if ( $memory_limit >= 1073741824 && ( 0 === $cpu_count || $cpu_count >= 4 ) && $load < 3.0 ) {
			return 'high_capacity';
		}
		return 'balanced';
	}

	/**
	 * @param string $tier Tier.
	 * @param int    $low Low value.
	 * @param int    $medium Medium value.
	 * @param int    $high High value.
	 * @return int
	 */
	private function limit_for_tier( $tier, $low, $medium, $high ) {
		if ( 'constrained' === $tier ) {
			return $low;
		}
		if ( 'high_capacity' === $tier ) {
			return $high;
		}
		return $medium;
	}
}
