<?php
/** Enterprise runtime security controls. @package NBHPerformance */
namespace NBH\Performance\Modules\Security;

use NBH\Performance\Modules\Core\Module;

final class EnterpriseSecurityManager implements Module {
	/** @var AuditLogger */
	private $audit;

	/** @var IntegrityVerifier */
	private $integrity;

	public function __construct( AuditLogger $audit, ?IntegrityVerifier $integrity = null ) {
		$this->audit     = $audit;
		$this->integrity = $integrity ? $integrity : new IntegrityVerifier();
	}

	public function register() {
		add_filter( 'nbh_performance_manage_capability', array( $this, 'capability' ) );
		if ( defined( 'NBH_PERFORMANCE_RELEASE_INTEGRITY_ENFORCED' ) && NBH_PERFORMANCE_RELEASE_INTEGRITY_ENFORCED ) {
			add_action( 'admin_init', array( $this, 'verify_integrity' ), 2 );
			add_action( 'upgrader_process_complete', array( $this, 'refresh_integrity' ), 50, 2 );
		}
	}

	public function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	public function verify_integrity() {
		$report = $this->integrity->verify();
		if ( ! empty( $report['valid'] ) ) {
			delete_option( 'nbh_performance_integrity_alert' );
			delete_option( 'nbh_performance_integrity_baseline' );
			return;
		}

		$alert = array(
			'detected_at' => time(),
			'missing'     => array_slice( isset( $report['missing'] ) ? (array) $report['missing'] : array(), 0, 20 ),
			'modified'    => array_slice( isset( $report['modified'] ) ? (array) $report['modified'] : array(), 0, 20 ),
			'unexpected'  => array_slice( isset( $report['unexpected'] ) ? (array) $report['unexpected'] : array(), 0, 20 ),
			'errors'      => array_slice( isset( $report['errors'] ) ? (array) $report['errors'] : array(), 0, 20 ),
		);
		update_option( 'nbh_performance_integrity_alert', $alert, false );
		$this->audit->log(
			'integrity_mismatch',
			array(
				'missing'    => count( $alert['missing'] ),
				'modified'   => count( $alert['modified'] ),
				'unexpected' => count( $alert['unexpected'] ),
				'errors'     => count( $alert['errors'] ),
			),
			'critical'
		);
	}

	public function refresh_integrity( $upgrader, $options ) {
		unset( $upgrader );
		if ( ! $this->is_nbh_update( $options ) ) {
			return;
		}
		delete_transient( IntegrityVerifier::TRANSIENT );
		delete_transient( IntegrityMonitor::TRANSIENT );
		$report = $this->integrity->verify( true );
		if ( ! empty( $report['valid'] ) ) {
			delete_option( 'nbh_performance_integrity_alert' );
		} else {
			$this->verify_integrity();
		}
	}

	private function is_nbh_update( $options ) {
		if ( ! is_array( $options ) || ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return false;
		}
		if ( isset( $options['plugin'] ) ) {
			return NBH_PERFORMANCE_BASENAME === $options['plugin'];
		}
		return isset( $options['plugins'] ) && is_array( $options['plugins'] ) && in_array( NBH_PERFORMANCE_BASENAME, $options['plugins'], true );
	}
}
