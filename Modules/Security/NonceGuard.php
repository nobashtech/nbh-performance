<?php
/**
 * Nonce verification guard.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Security;

final class NonceGuard {
	public static function verify_rest( $request, $action = 'wp_rest' ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return false;
		}
		return true;
	}

	public static function verify_ajax( $action, $field = '_ajax_nonce' ) {
		check_ajax_referer( $action, $field );
	}
}
