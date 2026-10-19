<?php
/** WP-CLI background job commands. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\Core\Module;

final class WpCliJobs implements Module {
	private $store;
	private $worker;
	public function __construct( JobStore $store, JobWorker $worker ) {
		$this->store  = $store;
		$this->worker = $worker;
	}
	public function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nbh-performance jobs', $this );
		}
	}
	/** Run due jobs. ## OPTIONS [--limit=<number>] */
	public function run( $args, $assoc_args ) {
		$result = $this->worker->run( isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10 );
		\WP_CLI::success( sprintf( 'Processed %d job(s); %d failed.', absint( $result['processed'] ), absint( $result['failed'] ) ) );
	}
	/** Show queue statistics. */
	public function status() {
		\WP_CLI::log( (string) wp_json_encode( $this->store->stats(), JSON_PRETTY_PRINT ) );
	}
	/** Prune old terminal jobs. */
	public function prune() {
		\WP_CLI::success( sprintf( 'Pruned %d job(s).', $this->store->prune() ) );
	}
	/** Pause background processing without discarding jobs. */
	public function pause() {
		update_option( 'nbh_performance_job_queue_paused', 1, false );
		\WP_CLI::success( 'NBH Performance background jobs are paused.' );
	}
	/** Resume background processing. */
	public function resume() {
		update_option( 'nbh_performance_job_queue_paused', 0, false );
		\WP_CLI::success( 'NBH Performance background jobs are active.' );
	}
}
