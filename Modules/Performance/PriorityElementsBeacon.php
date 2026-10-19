<?php
/** Frontend browser-observation beacon for Priority Elements. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class PriorityElementsBeacon implements Module {
	private $token;
	private $storage;
	public function __construct( PriorityElementsToken $token, PriorityElementsStorage $storage ) {
		$this->token   = $token;
		$this->storage = $storage;
	}
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 99 );
	}
	public function enqueue() {
		if ( is_admin() || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_resource_priority_enabled', 0 ) || $this->skip_request() ) {
			return;
		}
		$sample = max( 1, min( 100, absint( get_option( 'nbh_performance_priority_elements_sample_rate', 20 ) ) ) );
		$url    = $this->storage->normalize_url( $this->current_url() );
		if ( ! $url ) {
			return;
		}
		wp_enqueue_script( 'nbh-performance-priority-elements', NBH_PERFORMANCE_URL . 'assets/js/priority-elements-beacon.js', array(), NBH_PERFORMANCE_VERSION, true );
		wp_add_inline_script(
			'nbh-performance-priority-elements',
			'window.nbhPerformancePriorityElements=' . wp_json_encode(
				array(
					'endpoint' => rest_url( 'nbh-performance/v1/priority-elements/report' ),
					'url'      => $url,
					'token'    => $this->token->issue( $url ),
					'sample'   => $sample / 100,
					'maxItems' => 12,
				)
			) . ';',
			'before'
		);
	}
	private function current_url() {
		return home_url( isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/' );
	}
	private function skip_request() {
		return is_feed() || is_robots() || is_trackback() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) || is_user_logged_in();
	}
}
