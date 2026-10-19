<?php
/** Intent-based same-origin page preloading. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class InstantPageManager implements Module {
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 30 );
	}

	public function enqueue() {
		if ( is_admin() || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_instant_page', 0 ) || is_user_logged_in() ) {
			return;
		}
		wp_enqueue_script( 'nbh-performance-instant-page', NBH_PERFORMANCE_URL . 'assets/js/instant-page.js', array(), NBH_PERFORMANCE_VERSION, true );
		wp_script_add_data( 'nbh-performance-instant-page', 'strategy', 'defer' );
	}
}
