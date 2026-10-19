<?php
/** Background staging-validation handler with automatic rollback. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\Hub\ConfigurationTransactionManager;
use NBH\Performance\Modules\Validation\StagingValidator;

final class StagingValidationJobHandler implements JobHandlerInterface {
	private $validator;
	private $transactions;
	public function __construct( StagingValidator $validator, ConfigurationTransactionManager $transactions ) {
		$this->validator    = $validator;
		$this->transactions = $transactions;
	}
	public function supports( $type ) {
		return 'staging_validation' === $type;
	}
	public function handle( array $payload ) {
		$report         = $this->validator->run( $payload );
		$transaction_id = isset( $payload['transaction_id'] ) ? sanitize_text_field( $payload['transaction_id'] ) : '';
		if ( '' !== $transaction_id ) {
			$this->transactions->record_validation( $transaction_id, $report );
		}
		$auto_rollback    = (bool) get_option( 'nbh_performance_auto_rollback_on_validation_failure', 1 );
		$allow_production = (bool) get_option( 'nbh_performance_allow_production_auto_rollback', 0 );
		if ( empty( $report['passed'] ) && ! empty( $report['rollback_recommended'] ) && $auto_rollback && ( $this->validator->is_staging() || $allow_production ) && '' !== $transaction_id ) {
			$this->transactions->rollback( $transaction_id, 'automatic_validation_failure' );
			$report['rolled_back'] = true;
		}
		if ( empty( $report['passed'] ) && ! empty( $report['transport_failures'] ) && empty( $report['rollback_recommended'] ) ) {
			return new \WP_Error( 'nbh_performance_validation_transport_failure', __( 'Frontend validation could not reach one or more pages. The job will retry without changing the current configuration.', 'nbh-performance' ) );
		}
		return $report;
	}
}
