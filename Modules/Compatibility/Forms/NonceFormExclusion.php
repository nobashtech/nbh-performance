<?php
/**
 * Conservative nonce-based form cache exclusions.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Compatibility\Forms;

final class NonceFormExclusion {
	public function register() {
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'exclude_requests' ), 20, 2 );
	}

	public function exclude_requests( $should_cache, $request_uri ) {
		if ( 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			return false;
		}
		$markers = array( 'gf_page=', 'wpforms', 'fluentform', 'wpcf7' );
		foreach ( $markers as $marker ) {
			if ( false !== stripos( (string) $request_uri, $marker ) ) {
				return false;
			}
		}

		return $should_cache;
	}
}
