<?php
/**
 * Detects server compression capabilities conservatively.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ServerOptimization;

final class CompressionDetector {
	/** @return array<string, mixed> */
	public function detect() {
		return array(
			'gzip'   => $this->detect_gzip(),
			'brotli' => $this->detect_brotli(),
			'zlib'   => extension_loaded( 'zlib' ),
		);
	}

	/** @return array<string, mixed> */
	private function detect_gzip() {
		$enabled = false;
		$source  = 'unknown';

		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_deflate', apache_get_modules(), true ) ) {
			$enabled = true;
			$source  = 'apache_mod_deflate';
		} elseif ( extension_loaded( 'zlib' ) && (bool) ini_get( 'zlib.output_compression' ) ) {
			$enabled = true;
			$source  = 'php_zlib';
		} elseif ( isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== stripos( \NBH\Performance\Modules\Security\RequestInput::server_text( 'HTTP_ACCEPT_ENCODING' ), 'gzip' ) ) {
			$source = 'client_supported_unverified';
		}

		return array(
			'enabled' => $enabled,
			'source'  => $source,
		);
	}

	/** @return array<string, mixed> */
	private function detect_brotli() {
		$enabled = false;
		$source  = 'unknown';

		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_brotli', apache_get_modules(), true ) ) {
			$enabled = true;
			$source  = 'apache_mod_brotli';
		} elseif ( extension_loaded( 'brotli' ) ) {
			$enabled = true;
			$source  = 'php_brotli';
		} elseif ( isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== stripos( \NBH\Performance\Modules\Security\RequestInput::server_text( 'HTTP_ACCEPT_ENCODING' ), 'br' ) ) {
			$source = 'client_supported_unverified';
		}

		return array(
			'enabled' => $enabled,
			'source'  => $source,
		);
	}
}
