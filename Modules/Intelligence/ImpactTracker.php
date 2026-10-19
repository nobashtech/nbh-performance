<?php
/** Before/after recommendation impact snapshots. @package NBHPerformance */
namespace NBH\Performance\Modules\Intelligence;

final class ImpactTracker {
	const OPTION = 'nbh_performance_intelligence_impacts';
	public function start( $action, array $before ) {
		$id         = wp_generate_uuid4();
		$all        = (array) get_option( self::OPTION, array() );
		$all[ $id ] = array(
			'action'     => sanitize_key( $action ),
			'started_at' => time(),
			'before'     => $before,
			'after'      => null,
			'status'     => 'tracking',
		);
		$all        = array_slice( $all, -50, null, true );
		update_option( self::OPTION, $all, false );
		return $id;
	}
	public function complete_due() {
		$all = (array) get_option( self::OPTION, array() );
		$now = time();
		foreach ( $all as &$entry ) {
			if ( 'tracking' === $entry['status'] && $now - (int) $entry['started_at'] >= DAY_IN_SECONDS ) {
				$entry['after']            = $this->snapshot();
					$entry['status']       = 'complete';
					$entry['completed_at'] = $now;
			}
		}
		update_option( self::OPTION, $all, false );
	}
	public function snapshot() {
		return array(
			'cache'          => (array) get_option( 'nbh_performance_cache_stats', array() ),
			'asset_failures' => count( (array) get_option( 'nbh_performance_asset_failures', array() ) ),
			'captured_at'    => time(),
		);
	}
	public function history() {
		return array_values( (array) get_option( self::OPTION, array() ) );
	}
}
