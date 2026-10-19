<?php
/**
 * Exposes a capability report to administrators and Site Health.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\ServerOptimization;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Response;

final class ServerCapabilityReport implements Module {
	private $compression;
	private $protocol;
	private $hosting;
	private $guidance;
	private $environment;

	public function __construct( CompressionDetector $compression, ProtocolDetector $protocol, HostingIntelligence $hosting, ServerGuidance $guidance, HostingEnvironmentDetector $environment ) {
		$this->compression = $compression;
		$this->protocol    = $protocol;
		$this->hosting     = $hosting;
		$this->guidance    = $guidance;
		$this->environment = $environment;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'debug_information', array( $this, 'add_site_health_info' ) );
	}

	public function register_route() {
		register_rest_route(
			'nbh-performance/v1',
			'/server-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_report' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/** @param WP_REST_Request $request Request. @return WP_REST_Response */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function rest_report( WP_REST_Request $request ) {
		return new WP_REST_Response( $this->report(), 200 );
	}

	/** @return array<string, mixed> */
	public function report() {
		$report                    = array(
			'generated_at' => time(),
			'php'          => array(
				'version'      => PHP_VERSION,
				'sapi'         => PHP_SAPI,
				'memory_limit' => (string) ini_get( 'memory_limit' ),
			),
			'wordpress'    => array(
				'version'   => get_bloginfo( 'version' ),
				'multisite' => is_multisite(),
			),
			'web_server'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'compression'  => $this->compression->detect(),
			'protocol'     => $this->protocol->detect(),
			'hosting'      => $this->hosting->profile(),
			'environment'  => $this->environment->detect(),
		);
		$report['recommendations'] = $this->guidance->recommendations( $report );
		return (array) apply_filters( 'nbh_performance_server_capability_report', $report );
	}

	/** @param array<string, mixed> $info Site health info. @return array<string, mixed> */
	public function add_site_health_info( $info ) {
		$report                         = $this->report();
		$info['nbh_performance_server'] = array(
			'label'  => __( 'NBH Performance server capabilities', 'nbh-performance' ),
			'fields' => array(
				'hosting_tier'     => array(
					'label' => __( 'Hosting tier', 'nbh-performance' ),
					'value' => $report['hosting']['tier'],
				),
				'http_protocol'    => array(
					'label' => __( 'HTTP protocol', 'nbh-performance' ),
					'value' => $report['protocol']['protocol'],
				),
				'gzip'             => array(
					'label' => __( 'GZIP detected', 'nbh-performance' ),
					'value' => $report['compression']['gzip']['enabled'] ? 'Yes' : 'No',
				),
				'brotli'           => array(
					'label' => __( 'Brotli detected', 'nbh-performance' ),
					'value' => $report['compression']['brotli']['enabled'] ? 'Yes' : 'No',
				),
				'server_family'    => array(
					'label' => __( 'Web server', 'nbh-performance' ),
					'value' => $report['environment']['server_family'],
				),
				'hosting_provider' => array(
					'label' => __( 'Hosting profile', 'nbh-performance' ),
					'value' => $report['environment']['provider'],
				),
				'adaptive_mode'    => array(
					'label' => __( 'Adaptive mode', 'nbh-performance' ),
					'value' => $report['environment']['recommend_mode'],
				),
			),
		);
		return $info;
	}
}
