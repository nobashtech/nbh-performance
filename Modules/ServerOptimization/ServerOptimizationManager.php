<?php
/** Server optimization module coordinator. @package NBHPerformance */
namespace NBH\Performance\Modules\ServerOptimization;

use NBH\Performance\Modules\Core\Module;

final class ServerOptimizationManager implements Module {
	private $heartbeat;
	private $report;

	public function __construct( HeartbeatController $heartbeat, ServerCapabilityReport $report ) {
		$this->heartbeat = $heartbeat;
		$this->report    = $report;
	}

	public function register() {
		$this->heartbeat->register();
		$this->report->register();
		add_filter( 'nbh_performance_preload_batch_size', array( $this, 'adaptive_preload_batch' ), 20 );
	}

	/** @param int $batch Existing batch. @return int */
	public function adaptive_preload_batch( $batch ) {
		$report = $this->report->report();
		$limit  = isset( $report['hosting']['preload_batch'] ) ? (int) $report['hosting']['preload_batch'] : 1;
		return max( 1, min( (int) $batch, $limit ) );
	}
}
