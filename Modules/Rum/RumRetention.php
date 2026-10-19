<?php
/** RUM retention cleanup. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

use NBH\Performance\Modules\Core\Module;

final class RumRetention implements Module {
	private $storage;
	public function __construct( RumStorage $storage ) {
		$this->storage = $storage;
	}
	public function register() {
		add_action( 'nbh_performance_rum_cleanup_tick', array( $this, 'cleanup' ) );

		// Older builds used a daily event. Migrate it to hourly so bounded cleanup
		// can keep pace without one large database-locking delete.
		$scheduled = wp_next_scheduled( 'nbh_performance_rum_cleanup_tick' );
		if ( $scheduled && function_exists( 'wp_get_schedule' ) && 'hourly' !== wp_get_schedule( 'nbh_performance_rum_cleanup_tick' ) ) {
			wp_unschedule_event( $scheduled, 'nbh_performance_rum_cleanup_tick' );
			$scheduled = false;
		}
		if ( ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'nbh_performance_rum_cleanup_tick' );
		}
	}
	public function cleanup() {
		$days     = min( 365, max( 7, (int) get_option( 'nbh_performance_rum_retention_days', 90 ) ) );
		$date     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$max_rows = max( 10000, min( 5000000, (int) apply_filters( 'nbh_performance_rum_max_rows', 500000 ) ) );
		$deleted  = $this->storage->purge_before( $date, 10 );
		$trimmed  = $this->storage->trim_to_max_rows( $max_rows, 10 );
		do_action( 'nbh_performance_rum_retention_cleanup', $deleted + $trimmed, $days );
	}
}
