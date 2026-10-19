<?php
/** Tools Hub status aggregator. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\ProductQualityManager;
use NBH\Performance\Modules\Jobs\JobStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class ToolsSupportController implements Module {
	/** @var OperationsHealthMonitor */
	private $health;

	/** @var OperationalLogger */
	private $logger;

	/** @var ProductQualityManager */
	private $quality;

	/** @var JobStore */
	private $jobs;

	public function __construct( OperationsHealthMonitor $health, OperationalLogger $logger, ProductQualityManager $quality, JobStore $jobs ) {
		$this->health  = $health;
		$this->logger  = $logger;
		$this->quality = $quality;
		$this->jobs    = $jobs;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/tools/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to manage Tools.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function status() {
		$safe_mode  = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$rum        = (bool) get_option( 'nbh_performance_rum_enabled', 0 );
		$heartbeat  = (bool) get_option( 'nbh_performance_heartbeat_control_enabled', 0 );
		$ops        = $this->health->snapshot();
		$jobs       = $this->jobs->stats();
		$validation = (array) get_option( 'nbh_performance_last_staging_validation', array() );
		$compat     = (array) get_option( 'nbh_performance_compatibility_matrix', array() );
		$smart      = (array) get_option( 'nbh_performance_smart_optimize_last_run', array() );
		$snapshot   = (array) get_option( 'nbh_performance_configuration_snapshot', array() );
		$quality    = $this->quality->status();
		$logs       = $this->logger->recent( 8 );
		$warnings   = 0;
		foreach ( $logs as $entry ) {
			if ( is_array( $entry ) && isset( $entry['severity'] ) && in_array( $entry['severity'], array( 'warning', 'error', 'critical' ), true ) ) {
				++$warnings;
			}
		}

		return rest_ensure_response(
			array(
				'safe_mode'              => $safe_mode,
				'rum_enabled'            => $rum,
				'heartbeat_enabled'      => $heartbeat,
				'active_features'        => ( $rum ? 1 : 0 ) + ( $heartbeat ? 1 : 0 ),
				'runtime_ready'          => ! $safe_mode,
				'operations'             => $ops,
				'jobs'                   => $jobs,
				'validation'             => $validation,
				'compatibility'          => $compat,
				'smart_optimize'         => array(
					'status'  => isset( $smart['status'] ) ? sanitize_key( (string) $smart['status'] ) : '',
					'mode'    => isset( $smart['mode'] ) ? sanitize_key( (string) $smart['mode'] ) : ( isset( $smart['plan']['mode'] ) ? sanitize_key( (string) $smart['plan']['mode'] ) : '' ),
					'updated' => isset( $smart['updated_at'] ) ? absint( $smart['updated_at'] ) : ( isset( $smart['completed_at'] ) ? absint( $smart['completed_at'] ) : 0 ),
					'has_run' => ! empty( $smart ),
				),
				'configuration_snapshot' => array(
					'available'   => ! empty( $snapshot['created_at'] ),
					'created_at'  => isset( $snapshot['created_at'] ) ? absint( $snapshot['created_at'] ) : 0,
					'restored_at' => absint( get_option( 'nbh_performance_configuration_snapshot_restored_at', 0 ) ),
				),
				'product_quality'        => $quality,
				'recovery_runbook'       => ( new RecoveryRunbook() )->steps(),
				'recent_logs'            => $logs,
				'log_warnings'           => $warnings,
				'reliability'            => array(
					'jobs'               => $jobs,
					'validation'         => $validation,
					'compatibility'      => $compat,
					'latest_transaction' => $this->latest_transaction(),
				),
			)
		);
	}

	/** @return array<string,mixed> */
	private function latest_transaction() {
		$history = (array) get_option( 'nbh_performance_configuration_transactions', array() );
		if ( empty( $history ) ) {
			return array();
		}
		$latest = end( $history );
		return is_array( $latest ) ? $latest : array();
	}
}
