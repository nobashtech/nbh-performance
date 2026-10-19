<?php
/** Execute, store, and evaluate one lab performance test. @package NBHPerformance */
namespace NBH\Performance\Modules\PerformanceIntelligence;

final class LabTestRunner {
	private $client;
	private $storage;
	private $regressions;

	public function __construct( LabTestClient $client, LabTestStorage $storage, RegressionDetector $regressions ) {
		$this->client      = $client;
		$this->storage     = $storage;
		$this->regressions = $regressions;
	}

	public function run( $url, $strategy, $change_id = '' ) {
		$result = $this->client->run( $url, $strategy );
		if ( is_wp_error( $result ) ) {
			$id = $this->storage->insert(
				array(
					'url'           => $url,
					'strategy'      => $strategy,
					'provider'      => 'pagespeed',
					'status'        => 'failed',
					'error_message' => $result->get_error_message(),
					'change_id'     => $change_id,
				)
			);
			return new \WP_Error( 'nbh_performance_lab_test_failed', $result->get_error_message(), array( 'result_id' => $id ) );
		}
		$result['change_id'] = $change_id;
		$id                  = $this->storage->insert( $result );
		if ( ! $id ) {
			return new \WP_Error( 'nbh_performance_lab_result_store_failed', __( 'The lab test completed but its result could not be stored.', 'nbh-performance' ) );
		}
		$alert = $this->regressions->evaluate( $id );
		do_action( 'nbh_performance_lab_test_completed', $id, $result, $alert );
		return array(
			'result_id'  => $id,
			'metrics'    => $result,
			'regression' => $alert,
		);
	}
}
