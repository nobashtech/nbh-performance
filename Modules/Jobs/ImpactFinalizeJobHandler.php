<?php
/** Delayed real-user impact finalization job. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\PerformanceIntelligence\PerformanceImpactTracker;

final class ImpactFinalizeJobHandler implements JobHandlerInterface {
	private $tracker;
	public function __construct( PerformanceImpactTracker $tracker ) {
		$this->tracker = $tracker;
	}
	public function supports( $type ) {
		return 'performance_impact_finalize' === $type;
	}
	public function handle( array $payload ) {
		$change_id = isset( $payload['change_id'] ) ? sanitize_text_field( $payload['change_id'] ) : '';
		return $this->tracker->finalize( $change_id );
	}
}
