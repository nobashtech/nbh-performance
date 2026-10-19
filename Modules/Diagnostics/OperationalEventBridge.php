<?php
/** Bridges important NBH Performance events into the structured operational log. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\Module;

final class OperationalEventBridge implements Module {
	private $logger;
	public function __construct( OperationalLogger $logger ) {
		$this->logger = $logger;
	}
	public function register() {
		add_action( 'nbh_performance_after_cache_purge', array( $this, 'cache_purge' ), 10, 2 );
		add_action( 'nbh_performance_job_completed', array( $this, 'job_completed' ), 10, 2 );
		add_action( 'nbh_performance_job_failed', array( $this, 'job_failed' ), 10, 2 );
		add_action( 'nbh_performance_asset_bundle_published', array( $this, 'asset_published' ) );
		add_action( 'nbh_performance_asset_emergency_rollback_activated', array( $this, 'asset_rollback' ) );
		add_action( 'nbh_performance_visual_regression_blocked_used_css', array( $this, 'visual_blocked' ), 10, 3 );
		add_action( 'nbh_performance_after_database_backup', array( $this, 'database_backup' ) );
		add_action( 'nbh_performance_after_database_cleanup', array( $this, 'database_cleanup' ), 10, 2 );
		add_action( 'nbh_performance_image_conversion_failed', array( $this, 'image_failure' ), 10, 4 );
		add_action( 'nbh_performance_safe_mode_enabled', array( $this, 'safe_mode_enabled' ) );
		add_action( 'nbh_performance_safe_mode_disabled', array( $this, 'safe_mode_disabled' ) );
		add_action( 'nbh_performance_configuration_transaction_rolled_back', array( $this, 'configuration_rollback' ), 10, 2 );
		add_action( 'nbh_performance_performance_regression_detected', array( $this, 'performance_regression' ), 10, 3 );
		add_action( 'nbh_performance_object_cache_enabled', array( $this, 'object_cache_enabled' ) );
		add_action( 'nbh_performance_object_cache_disabled', array( $this, 'object_cache_disabled' ) );
	}
	public function cache_purge( $scope, $result ) {
		$this->logger->log(
			'cache',
			'cache_purged',
			array(
				'scope'   => is_scalar( $scope ) ? $scope : 'unknown',
				'success' => (bool) $result,
			)
		);
	}
	public function job_completed( $job, $result ) {
		$this->logger->log( 'queue', 'job_completed', $this->job_context( $job ) );
	}
	public function job_failed( $job, $error ) {
		$context          = $this->job_context( $job );
		$context['error'] = is_wp_error( $error ) ? $error->get_error_code() : ( is_scalar( $error ) ? (string) $error : 'handler_failure' );
		$this->logger->log( 'queue', 'job_failed', $context, 'error' );
	}
	public function asset_published( $manifest ) {
		$this->logger->log(
			'assets',
			'asset_bundle_published',
			array(
				'manifest_id' => is_array( $manifest ) && isset( $manifest['id'] ) ? $manifest['id'] : '',
				'asset_count' => is_array( $manifest ) && isset( $manifest['assets'] ) && is_array( $manifest['assets'] ) ? count( $manifest['assets'] ) : 0,
			)
		);
	}
	public function asset_rollback( $recent ) {
		$this->logger->log( 'assets', 'asset_emergency_rollback', array( 'failure_count' => is_array( $recent ) ? count( $recent ) : 0 ), 'critical' );
	}
	public function visual_blocked( $url, $variant, $metrics ) {
		$this->logger->log(
			'browser',
			'visual_regression_blocked',
			array(
				'url_hash' => hash( 'sha256', (string) $url ),
				'variant'  => $variant,
				'metrics'  => is_array( $metrics ) ? $metrics : array(),
			),
			'warning'
		);
	}
	public function database_backup( $record ) {
		$this->logger->log(
			'database',
			'database_backup_completed',
			array(
				'backup_id' => is_array( $record ) && isset( $record['id'] ) ? $record['id'] : '',
				'verified'  => is_array( $record ) && ! empty( $record['verified_at'] ),
			)
		);
	}
	public function database_cleanup( $result, $backup_id ) {
		$this->logger->log(
			'database',
			'database_cleanup_completed',
			array(
				'backup_id'  => $backup_id,
				'operations' => is_array( $result ) ? array_keys( $result ) : array(),
			)
		);
	}
	public function image_failure( $attachment_id, $mime, $error, $source ) {
		$this->logger->log(
			'images',
			'image_conversion_failed',
			array(
				'attachment_id' => absint( $attachment_id ),
				'mime'          => $mime,
				'error'         => is_wp_error( $error ) ? $error->get_error_code() : 'conversion_failure',
				'source_hash'   => hash( 'sha256', (string) $source ),
			),
			'error'
		);
	}
	public function safe_mode_enabled( $snapshot ) {
		$this->logger->log( 'safety', 'safe_mode_enabled', array( 'snapshot_count' => is_array( $snapshot ) ? count( $snapshot ) : 0 ), 'warning' );
	}
	public function safe_mode_disabled( $restore ) {
		$this->logger->log( 'safety', 'safe_mode_disabled', array( 'restored' => (bool) $restore ) );
	}
	public function configuration_rollback( $id, $reason ) {
		$this->logger->log(
			'configuration',
			'configuration_rolled_back',
			array(
				'transaction_id' => $id,
				'reason'         => $reason,
			),
			'warning'
		);
	}
	public function performance_regression( $alert, $current, $before ) {
		$this->logger->log(
			'performance',
			'performance_regression_detected',
			array(
				'alert_id' => is_array( $alert ) && isset( $alert['id'] ) ? $alert['id'] : '',
				'metric'   => is_array( $alert ) && isset( $alert['metric'] ) ? $alert['metric'] : '',
			),
			'critical'
		);
	}
	public function object_cache_enabled( $config ) {
		$this->logger->log( 'object_cache', 'object_cache_enabled', array( 'provider' => is_array( $config ) && isset( $config['provider'] ) ? $config['provider'] : '' ) );
	}
	public function object_cache_disabled() {
		$this->logger->log( 'object_cache', 'object_cache_disabled', array(), 'warning' );
	}
	private function job_context( $job ) {
		return array(
			'job_id'   => is_array( $job ) && isset( $job['id'] ) ? absint( $job['id'] ) : 0,
			'type'     => is_array( $job ) && isset( $job['type'] ) ? sanitize_key( $job['type'] ) : '',
			'attempts' => is_array( $job ) && isset( $job['attempts'] ) ? absint( $job['attempts'] ) : 0,
		);
	}
}
