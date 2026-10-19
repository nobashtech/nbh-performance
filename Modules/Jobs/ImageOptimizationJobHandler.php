<?php
/** Background image optimization job. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\ImageOptimization\ImageOptimizationPipeline;

final class ImageOptimizationJobHandler implements JobHandlerInterface {
	private $pipeline;
	private $batches;
	public function __construct( ImageOptimizationPipeline $pipeline, \NBH\Performance\Modules\ImageOptimization\ImageBatchManager $batches ) {
		$this->pipeline = $pipeline;
		$this->batches  = $batches;
	}
	public function supports( $type ) {
		return 'image_optimize' === $type;
	}
	public function handle( array $payload ) {
		$id     = isset( $payload['attachment_id'] ) ? absint( $payload['attachment_id'] ) : 0;
		$run_id = isset( $payload['image_run_id'] ) ? sanitize_text_field( (string) $payload['image_run_id'] ) : '';
		if ( ! $this->batches->runnable( $run_id ) ) {
			return new \WP_Error( 'nbh_performance_image_batch_paused', __( 'The image optimization batch is paused or cancelled.', 'nbh-performance' ) );
		}
		if ( ! $id ) {
			return new \WP_Error( 'nbh_performance_image_job_invalid', __( 'The image optimization job is missing an attachment ID.', 'nbh-performance' ) );
		}
		$result = $this->pipeline->process( $id, ! empty( $payload['force'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status = isset( $result['status'] ) ? sanitize_key( $result['status'] ) : 'processed';
		$this->batches->record( $run_id, $status );
		if ( in_array( $status, array( 'conversion_failed', 'invalid_source' ), true ) ) {
			return new \WP_Error( 'nbh_performance_image_job_' . $status, __( 'Image optimization did not produce a valid output.', 'nbh-performance' ) );
		}
		return $result;
	}
}
