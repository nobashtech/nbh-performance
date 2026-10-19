<?php
/** Diagnostics REST controller. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\Module;

final class DiagnosticsController implements Module {
	private $scanner;
	private $exporter;
	public function __construct( ProductionReadinessScanner $scanner, SupportReportExporter $exporter ) {
		$this->scanner  = $scanner;
		$this->exporter = $exporter;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/diagnostics/readiness',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'readiness' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/diagnostics/support-report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'support_report' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
	}
	public function allowed() {
		return current_user_can( 'manage_options' );
	}
	public function readiness() {
		return rest_ensure_response( $this->scanner->scan() );
	}
	public function support_report() {
		return rest_ensure_response( $this->exporter->build() );
	}
}
