<?php
/** Frontend RUM beacon injector with browser-side sampling and cache-safe token retrieval. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

final class RumBeacon {
	private $token;
	public function __construct( RumToken $token ) {
		$this->token = $token;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	public function enqueue() {
		if ( is_admin() || is_user_logged_in() || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_rum_enabled', 0 ) ) {
			return;
		}
		$percentage = max( 1, min( 100, (float) get_option( 'nbh_performance_rum_sample_rate', 10 ) ) );
		$sample     = $percentage / 100;
		wp_enqueue_script( 'nbh-performance-rum', NBH_PERFORMANCE_URL . 'assets/js/rum-beacon.js', array(), NBH_PERFORMANCE_BUILD, true );
		wp_localize_script(
			'nbh-performance-rum',
			'NBHPerformanceRum',
			array(
				'endpoint'      => esc_url_raw( rest_url( 'nbh-performance/v1/rum' ) ),
				'tokenEndpoint' => esc_url_raw( rest_url( 'nbh-performance/v1/rum/token' ) ),
				'context'       => $this->context(),
				'sampleRate'    => $sample,
			)
		);
	}

	private function context() {
		if ( is_front_page() ) {
			return 'home';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( is_singular() ) {
			return 'single';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		if ( is_search() ) {
			return 'search';
		}
		return 'other';
	}
}
