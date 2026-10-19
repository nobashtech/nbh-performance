<?php
/** Enterprise operations REST controller. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\Module;

final class OperationsController implements Module {
	private $health;
	private $logger;
	private $bundles;
	public function __construct( OperationsHealthMonitor $health, OperationalLogger $logger, SupportBundleManager $bundles ) {
		$this->health  = $health;
		$this->logger  = $logger;
		$this->bundles = $bundles;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_post_nbh_performance_support_bundle', array( $this, 'download_bundle_admin' ) );
		add_action( 'nbh_performance_daily_maintenance', array( $this->bundles, 'cleanup' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/operations/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/operations/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => array( $this, 'allowed' ),
				'args'                => array(
					'limit'    => array( 'sanitize_callback' => 'absint' ),
					'severity' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/operations/logs/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear_logs' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/operations/support-bundle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_bundle' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/operations/support-bundle/(?P<id>[a-f0-9-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_bundle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}
	public function allowed() {
		return current_user_can( 'manage_options' );
	}
	public function health() {
		return rest_ensure_response( $this->health->snapshot() );
	}
	public function logs( \WP_REST_Request $request ) {
		$requested_limit_1 = $request->get_param( 'limit' );
		return rest_ensure_response( array( 'items' => $this->logger->recent( $requested_limit_1 ? $requested_limit_1 : 100, (string) $request->get_param( 'severity' ) ) ) );
	}
	public function clear_logs() {
		$this->logger->clear();
		return rest_ensure_response( array( 'cleared' => true ) );
	}
	public function create_bundle() {
		$bundle = $this->bundles->create();
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		$bundle['download_url'] = add_query_arg(
			array(
				'action'   => 'nbh_performance_support_bundle',
				'id'       => rawurlencode( $bundle['id'] ),
				'token'    => rawurlencode( $bundle['token'] ),
				'_wpnonce' => wp_create_nonce( 'nbh_performance_support_bundle_' . $bundle['id'] ),
			),
			admin_url( 'admin-post.php' )
		);
		return rest_ensure_response( $bundle );
	}
	public function download_bundle( \WP_REST_Request $request ) {
		$record = $this->bundles->resolve( sanitize_text_field( $request['id'] ), (string) $request->get_param( 'token' ) );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$response = new \WP_REST_Response( (string) \NBH\Performance\Modules\Core\Filesystem::get_contents( $record['file'] ) );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="nbh-performance-support-' . sanitize_file_name( $record['id'] ) . '.json"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}
	public function download_bundle_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this support bundle.', 'nbh-performance' ), 403 );
		}
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		check_admin_referer( 'nbh_performance_support_bundle_' . $id );
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$record = $this->bundles->resolve( $id, $token );
		if ( is_wp_error( $record ) ) {
			wp_die( esc_html( $record->get_error_message() ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="nbh-performance-support-' . sanitize_file_name( $record['id'] ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPress.WP.AlternativeFunctions.file_system_read_readfile -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		readfile( $record['file'] );
		exit;
	}
}
