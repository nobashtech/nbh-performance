<?php
/**
 * Typed request input used after WordPress applies magic quotes.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\Security;

final class RequestInput {
	/** Read a header/server label for comparison, never for raw output. @return string */
	public static function server_text( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
	}

	/** Return a validated request target unchanged, or an empty value on invalid input. @return string */
	public static function uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		if ( ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw bytes are compared with the sanitized URL below; changed targets are rejected.
		$raw = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( '' === $raw || '/' !== $raw[0] || preg_match( '/[\x00-\x20\x7f]/', $raw ) ) {
			return '';
		}
		$clean = esc_url_raw( $raw );
		return $clean === $raw ? $clean : '';
	}

	/** Raw cookie bytes are only for hashes or bounded variant validation, never HTML output. @return string */
	public static function cookie_bytes( $name ) {
		if ( ! isset( $_COOKIE[ $name ] ) || ! is_scalar( $_COOKIE[ $name ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Hash inputs must preserve the same bytes seen by the pre-WordPress cache drop-in.
		return wp_unslash( (string) $_COOKIE[ $name ] );
	}
}
