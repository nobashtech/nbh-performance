<?php
/** Durable media batch state for pause, resume and cancellation. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Jobs\JobQueue;

final class ImageBatchManager {
	const OPTION = 'nbh_performance_image_batch_state_v2';
	private $queue;
	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	public function start( array $ids, $force = false ) {
		$run_id = wp_generate_uuid4();
		$state  = array(
			'run_id'     => $run_id,
			'status'     => 'running',
			'total'      => count( $ids ),
			'queued'     => 0,
			'processed'  => 0,
			'succeeded'  => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'started_at' => time(),
			'updated_at' => time(),
			'force'      => (bool) $force,
		);
		update_option( self::OPTION, $state, false );
		foreach ( $ids as $id ) {
			$job = $this->queue->enqueue(
				'image_optimize',
				array(
					'attachment_id' => absint( $id ),
					'force'         => (bool) $force,
					'image_run_id'  => $run_id,
				),
				array(
					'priority'     => 35,
					'max_attempts' => 3,
					'unique_key'   => 'image|' . absint( $id ) . '|' . $run_id,
				)
			);
			if ( $job ) {
				++$state['queued'];
			}
		}
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );
		return $state;
	}

	public function state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}
	public function pause() {
		return $this->transition( 'paused' );
	}
	public function resume() {
		return $this->transition( 'running' );
	}
	public function cancel() {
		return $this->transition( 'cancelled' );
	}
	public function runnable( $run_id ) {
		$state = $this->state();
		return empty( $run_id ) || empty( $state['run_id'] ) || $run_id !== $state['run_id'] || 'running' === ( $state['status'] ?? '' );
	}
	public function record( $run_id, $status ) {
		$state = $this->state();
		if ( empty( $run_id ) || empty( $state['run_id'] ) || $run_id !== $state['run_id'] ) {
			return;
		}
		++$state['processed'];
		if ( in_array( $status, array( 'converted', 'reused', 'processed' ), true ) ) {
			++$state['succeeded'];
		} elseif ( in_array( $status, array( 'excluded', 'unsupported_mime', 'no_format_enabled', 'animated_unsupported', 'remote_source' ), true ) ) {
			++$state['skipped'];
		} else {
				++$state['failed'];
		}
		if ( $state['processed'] >= $state['total'] && 'cancelled' !== $state['status'] ) {
			$state['status']       = 'completed';
			$state['completed_at'] = time();
		}
			$state['updated_at'] = time();
			update_option( self::OPTION, $state, false );
	}
	private function transition( $status ) {
		$state = $this->state();
		if ( empty( $state ) ) {
			return array();
		}
		$state['status']     = $status;
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );
		return $state;
	}
}
