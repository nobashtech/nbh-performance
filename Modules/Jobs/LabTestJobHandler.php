<?php
/** Scheduled laboratory performance-test job handler. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\PerformanceIntelligence\LabTestRunner;

final class LabTestJobHandler implements JobHandlerInterface {
	private $runner;
	public function __construct( LabTestRunner $runner ) {
		$this->runner = $runner;
	}
	public function supports( $type ) {
		return 'performance_lab_test' === $type;
	}
	public function handle( array $payload ) {
		$url       = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : '';
		$strategy  = isset( $payload['strategy'] ) ? sanitize_key( $payload['strategy'] ) : 'mobile';
		$change_id = isset( $payload['change_id'] ) ? sanitize_text_field( $payload['change_id'] ) : '';
		return $this->runner->run( $url, $strategy, $change_id );
	}
}
