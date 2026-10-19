<?php
/**
 * Guarded automatic rollback for verified regressions.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\ConfigurationTransactionManager;
use NBH\Performance\Modules\Security\AuditLogger;
use NBH\Performance\Modules\Validation\StagingValidator;

final class AutomaticRollbackManager implements Module {
	/** @var ConfigurationTransactionManager */
	private $transactions;
	/** @var StagingValidator */
	private $validator;
	/** @var AuditLogger */
	private $audit;

	public function __construct( ConfigurationTransactionManager $transactions, StagingValidator $validator, AuditLogger $audit ) {
		$this->transactions = $transactions;
		$this->validator    = $validator;
		$this->audit        = $audit;
	}

	public function register() {
		add_action( 'nbh_performance_performance_regression_detected', array( $this, 'performance_regression' ), 10, 3 );
		add_action( 'nbh_performance_visual_regression_blocked_used_css', array( $this, 'visual_regression' ), 10, 3 );
	}

	public function performance_regression( $alert, $current = array(), $before = array() ) {
		if ( ! is_array( $alert ) || 'critical' !== ( isset( $alert['severity'] ) ? $alert['severity'] : '' ) ) {
			return;
		}
		$run       = get_option( 'nbh_performance_smart_optimize_last_run', array() );
		$change_id = is_array( $current ) && isset( $current['change_id'] ) ? sanitize_text_field( $current['change_id'] ) : '';
		if ( empty( $run['id'] ) || '' === $change_id || ! hash_equals( (string) $run['id'], $change_id ) ) {
			return;
		}
		$this->maybe_rollback(
			'critical_performance_regression',
			array(
				'alert'    => $alert,
				'current'  => is_array( $current ) ? $current : array(),
				'baseline' => is_array( $before ) ? $before : array(),
			)
		);
	}

	public function visual_regression( $url, $variant, $metrics ) {
		$this->maybe_rollback(
			'visual_regression',
			array(
				'url'     => esc_url_raw( $url ),
				'variant' => sanitize_key( $variant ),
				'metrics' => is_array( $metrics ) ? $metrics : array(),
			)
		);
	}

	/** Return whether protection is currently armed. */
	public function status() {
		return array(
			'enabled'            => (bool) get_option( 'nbh_performance_smart_rollback_enabled', 1 ),
			'production_enabled' => (bool) get_option( 'nbh_performance_smart_rollback_production_enabled', 0 ),
			'window_minutes'     => absint( get_option( 'nbh_performance_smart_rollback_window_minutes', 360 ) ),
			'last'               => current( (array) get_option( 'nbh_performance_automatic_rollback_history', array() ) ),
		);
	}

	private function maybe_rollback( $reason, array $evidence ) {
		if ( ! get_option( 'nbh_performance_smart_rollback_enabled', 1 ) ) {
			return false;
		}
		if ( ! $this->validator->is_staging() && ! get_option( 'nbh_performance_smart_rollback_production_enabled', 0 ) ) {
			$this->record( '', $reason, false, 'production_guard', $evidence );
			return false;
		}
		$run = get_option( 'nbh_performance_smart_optimize_last_run', array() );
		if ( ! is_array( $run ) || empty( $run['transaction_id'] ) || empty( $run['started_at'] ) || 'rolled_back' === ( isset( $run['status'] ) ? $run['status'] : '' ) ) {
			return false;
		}
		$window = max( 15, min( 1440, absint( get_option( 'nbh_performance_smart_rollback_window_minutes', 360 ) ) ) );
		if ( time() - absint( $run['started_at'] ) > $window * MINUTE_IN_SECONDS ) {
			return false;
		}
		$transaction_id = sanitize_text_field( $run['transaction_id'] );
		$success        = $this->transactions->rollback( $transaction_id, 'smart_' . sanitize_key( $reason ) );
		if ( $success ) {
			$run['status']          = 'rolled_back';
			$run['rolled_back_at']  = time();
			$run['rollback_reason'] = sanitize_key( $reason );
			update_option( 'nbh_performance_smart_optimize_last_run', $run, false );
		}
		$this->record( $transaction_id, $reason, $success, $success ? 'rolled_back' : 'rollback_failed', $evidence );
		return $success;
	}

	private function record( $transaction_id, $reason, $success, $status, array $evidence ) {
		$history = (array) get_option( 'nbh_performance_automatic_rollback_history', array() );
		$record  = array(
			'id'             => wp_generate_uuid4(),
			'transaction_id' => sanitize_text_field( (string) $transaction_id ),
			'reason'         => sanitize_key( $reason ),
			'success'        => (bool) $success,
			'status'         => sanitize_key( $status ),
			'evidence'       => $evidence,
			'created_at'     => time(),
		);
		array_unshift( $history, $record );
		update_option( 'nbh_performance_automatic_rollback_history', array_slice( $history, 0, 50 ), false );
		$this->audit->record(
			'automatic_rollback',
			array(
				'transaction_id' => $transaction_id,
				'reason'         => $reason,
				'success'        => $success,
			),
			$success ? 'warning' : 'info'
		);
	}
}
