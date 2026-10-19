<?php
/** Background compatibility matrix handler. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\Compatibility\CompatibilityTestMatrix;

final class CompatibilityScanJobHandler implements JobHandlerInterface {
	private $matrix;
	public function __construct( CompatibilityTestMatrix $matrix ) {
		$this->matrix = $matrix;
	}
	public function supports( $type ) {
		return 'compatibility_scan' === $type;
	}
	public function handle( array $payload ) {
		unset( $payload );
		return $this->matrix->run();
	}
}
