<?php
/**
 * Scheduled cache garbage collection, orphan repair and statistics.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;

final class CacheMaintenance implements Module {
	private $index;
	public function __construct( CacheIndex $index ) {
		$this->index = $index;
	}
	public function register() {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'nbh_performance_cleanup_tick', array( $this, 'cleanup' ) );
	}
	public function schedule() {
		if ( ! wp_next_scheduled( 'nbh_performance_cleanup_tick' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'nbh_performance_cleanup_tick' );
		}
	}
	public function cleanup() {
		$batch = $this->index->stale_entries( time(), 2000 );
		foreach ( $batch as $entry ) {
			CacheEntryFiles::delete( isset( $entry['file_path'] ) ? $entry['file_path'] : '' );
			$this->index->remove( isset( $entry['cache_key'] ) ? $entry['cache_key'] : '' );
		}
		$orphans = $this->index->repair_orphans( 2000 );
		$stats   = CacheStatistics::reconcile( $this->index );
		update_option(
			'nbh_performance_last_cache_maintenance',
			array(
				'expired'      => count( $batch ),
				'orphans'      => $orphans,
				'stats'        => $stats,
				'completed_at' => time(),
			),
			false
		);
	}
}
