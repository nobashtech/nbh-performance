<?php
/** WP-CLI diagnostics and enterprise operations commands. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\Module;

final class WpCliDiagnostics implements Module {
	private $scanner;
	private $exporter;
	private $health;
	private $logger;
	private $bundles;
	public function __construct( ProductionReadinessScanner $scanner, SupportReportExporter $exporter, ?OperationsHealthMonitor $health = null, ?OperationalLogger $logger = null, ?SupportBundleManager $bundles = null ) {
		$this->scanner  = $scanner;
		$this->exporter = $exporter;
		$this->health   = $health;
		$this->logger   = $logger;
		$this->bundles  = $bundles;
	}
	public function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'nbh-performance doctor', array( $this, 'doctor' ) );
			\WP_CLI::add_command( 'nbh-performance support-report', array( $this, 'report' ) );
			\WP_CLI::add_command( 'nbh-performance operations health', array( $this, 'operations_health' ) );
			\WP_CLI::add_command( 'nbh-performance operations logs', array( $this, 'operations_logs' ) );
			\WP_CLI::add_command( 'nbh-performance operations support-bundle', array( $this, 'support_bundle' ) );
		}
	}
	public function doctor( $args, $assoc_args ) {
		$result = $this->scanner->scan();
		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		if ( empty( $result['ready'] ) ) {
			\WP_CLI::halt( 1 );
		}
	}
	public function report( $args, $assoc_args ) {
		\WP_CLI::line( wp_json_encode( $this->exporter->build(), JSON_PRETTY_PRINT ) );
	}
	public function operations_health( $args, $assoc_args ) {
		\WP_CLI::line( wp_json_encode( $this->health ? $this->health->snapshot() : array(), JSON_PRETTY_PRINT ) );
	}
	public function operations_logs( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 100;
		\WP_CLI::line( wp_json_encode( $this->logger ? $this->logger->recent( $limit ) : array(), JSON_PRETTY_PRINT ) );
	}
	public function support_bundle( $args, $assoc_args ) {
		$result = $this->bundles ? $this->bundles->create() : new \WP_Error( 'unavailable', 'Support bundle service unavailable.' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}
}
