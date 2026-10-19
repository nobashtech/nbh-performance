<?php
/** REST management for the background queue. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class JobController implements Module {
	private $store;
	private $worker;

	public function __construct( JobStore $store, JobWorker $worker ) {
		$this->store  = $store;
		$this->worker = $worker;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/jobs/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/jobs/run',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/jobs/retry',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/jobs/cancel',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/jobs/pause',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'pause' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/jobs/resume',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}

	public function status() {
		return rest_ensure_response(
			array(
				'stats' => $this->store->stats(),
				'jobs'  => $this->store->recent( 30 ),
			)
		);
	}
	public function run( WP_REST_Request $request ) {
		return rest_ensure_response( $this->worker->run( max( 1, absint( $request->get_param( 'limit' ) ) ) ) );
	}
	public function retry( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => $this->store->retry( absint( $request->get_param( 'id' ) ) ) ) );
	}
	public function cancel( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => $this->store->cancel( absint( $request->get_param( 'id' ) ) ) ) );
	}
	public function pause() {
		update_option( 'nbh_performance_job_queue_paused', 1, false );
		return rest_ensure_response(
			array(
				'success' => true,
				'paused'  => true,
			)
		);
	}
	public function resume() {
		update_option( 'nbh_performance_job_queue_paused', 0, false );
		return rest_ensure_response(
			array(
				'success' => true,
				'paused'  => false,
			)
		);
	}
}
