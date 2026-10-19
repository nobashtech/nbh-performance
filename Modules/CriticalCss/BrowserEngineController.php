<?php
/** Administrative control and health checks for the browser CSS engine. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Server;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;

final class BrowserEngineController implements Module {
	private $queue;
	private $priority_storage;
	private $jobs;
	public function __construct( CriticalCssQueue $queue, PriorityElementsStorage $priority_storage, BrowserJobStore $jobs ) {
		$this->queue            = $queue;
		$this->priority_storage = $priority_storage;
		$this->jobs             = $jobs;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		$permission = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/browser-engine/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/browser-engine/test',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/browser-engine/generate',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => $permission,
			)
		);
	}
	public function status() {
		return rest_ensure_response( $this->health( false ) );
	}
	public function test() {
		return rest_ensure_response( $this->health( true ) );
	}
	public function generate( WP_REST_Request $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		if ( ! $this->same_origin( $url ) ) {
			return new \WP_Error( 'nbh_performance_invalid_browser_css_url', __( 'Select a same-origin URL.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$queued = $this->queue->enqueue( $url, 'manual-browser-engine' );
		return rest_ensure_response(
			array(
				'queued' => (bool) $queued,
				'url'    => $url,
			)
		);
	}
	private function health( $remote ) {
		$configured = ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) );
		return array(
			'configured'        => $configured,
			'endpoint'          => '',
			'remote_checked'    => false,
			'healthy'           => $configured,
			'local_only'        => true,
			'capabilities'      => $configured ? array( 'php_conservative_generation' ) : array(),
			'mode'              => PhpCssGenerator::MODE,
			'visual_verified'   => false,
			'priority_elements' => $this->priority_storage->stats(),
			'browser_jobs'      => $this->jobs->stats(),
		);
	}

	private function same_origin( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		if ( ! is_array( $home ) || ! is_array( $test ) ) {
			return false;
		}
		return strtolower( (string) ( $home['scheme'] ?? '' ) ) === strtolower( (string) ( $test['scheme'] ?? '' ) )
			&& strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $test['host'] ?? '' ) )
			&& absint( $home['port'] ?? 0 ) === absint( $test['port'] ?? 0 );
	}
}
