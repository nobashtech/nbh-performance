<?php
/** HTTP protocol-aware combining policy foundation. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class FileCombiner {
	/** @return bool */
	public function should_combine() {
		$mode = sanitize_key( (string) get_option( 'nbh_performance_combine_mode', 'auto' ) );
		if ( 'off' === $mode ) {
			return false;
		}

		if ( ! in_array( $mode, array( 'auto', 'http1' ), true ) ) {
			return (bool) get_option( 'nbh_performance_combine_assets_enabled', 0 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$protocol  = isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '';
		$is_modern = false !== stripos( $protocol, 'HTTP/2' ) || false !== stripos( $protocol, 'HTTP/3' );

		return (bool) apply_filters( 'nbh_performance_should_combine_assets', ! $is_modern, $protocol );
	}
}
