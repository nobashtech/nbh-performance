<?php
/** Background queue façade. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

final class JobQueue {
	private $store;

	public function __construct( JobStore $store ) {
		$this->store = $store;
	}

	public function enqueue( $type, array $payload = array(), array $args = array() ) {
		$id = $this->store->enqueue( $type, $payload, $args );
		if ( $id && ! wp_next_scheduled( 'nbh_performance_job_worker_kick' ) ) {
			wp_schedule_single_event( time() + 2, 'nbh_performance_job_worker_kick' );
		}
		return $id;
	}

	public function stats() {
		return $this->store->stats();
	}
	public function recent( $limit = 20 ) {
		return $this->store->recent( $limit );
	}
}
