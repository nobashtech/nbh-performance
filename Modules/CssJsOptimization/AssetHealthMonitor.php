<?php
/** Frontend asset optimization health and emergency rollback. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use NBH\Performance\Modules\Core\Module;

final class AssetHealthMonitor implements Module {
	/** Register hooks. */
	public function register() {
		add_action( 'wp', array( $this, 'maybe_activate_emergency_rollback' ), 1 );
		add_action( 'nbh_performance_asset_optimized', array( $this, 'record_success' ), 10, 4 );
	}

	/** Disable aggressive optimization after a bounded failure threshold. */
	public function maybe_activate_emergency_rollback() {
		$failures = (array) get_option( 'nbh_performance_asset_failures', array() );
		$recent   = 0;
		$cutoff   = time() - HOUR_IN_SECONDS;
		foreach ( $failures as $failure ) {
			if ( isset( $failure['time'] ) && (int) $failure['time'] >= $cutoff ) {
				++$recent;
			}
		}
		$threshold = (int) apply_filters( 'nbh_performance_asset_failure_threshold', 10 );
		if ( $recent >= $threshold && ! get_option( 'nbh_performance_asset_emergency_rollback', 0 ) ) {
			update_option( 'nbh_performance_asset_emergency_rollback', 1, false );
			update_option( 'nbh_performance_defer_js_enabled', 0, false );
			update_option( 'nbh_performance_delay_js_enabled', 0, false );
			do_action( 'nbh_performance_asset_emergency_rollback_activated', $recent );
		}
	}

	/** @param string $original Original URL. @param string $optimized Optimized URL. @param string $type Asset type. @param string $handle Handle. */
	public function record_success( $original, $optimized, $type, $handle ) {
		update_option(
			'nbh_performance_last_asset_success',
			array(
				'time'   => time(),
				'type'   => sanitize_key( $type ),
				'handle' => sanitize_key( $handle ),
			),
			false
		);
	}

	/** Clear failure log and resume after emergency rollback. @return array<string,mixed> */
	public function clear_and_resume() {
		update_option( 'nbh_performance_asset_failures', array(), false );
		update_option( 'nbh_performance_asset_emergency_rollback', 0, false );
		return array(
			'success'            => true,
			'emergency_rollback' => false,
			'failure_count'      => 0,
		);
	}
}
