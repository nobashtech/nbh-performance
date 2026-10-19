<?php
/** RUM module coordinator. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

use NBH\Performance\Modules\Core\Module;
final class RumManager implements Module {
	private $beacon;
	private $ingestion;
	private $aggregator;
	private $retention;
	private $rollups;
	public function __construct( RumBeacon $beacon, RumIngestionController $ingestion, RumAggregator $aggregator, RumRetention $retention, RumRollupManager $rollups ) {
		$this->beacon     = $beacon;
		$this->ingestion  = $ingestion;
		$this->aggregator = $aggregator;
		$this->retention  = $retention;
		$this->rollups    = $rollups;
	}
	public function register() {
		$this->beacon->register();
		$this->ingestion->register();
		$this->aggregator->register();
		$this->retention->register();
		$this->rollups->register();
	}
}
