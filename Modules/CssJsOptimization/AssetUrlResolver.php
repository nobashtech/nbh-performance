<?php
/**
 * Resolves safe, same-origin WordPress asset URLs to local files.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CssJsOptimization;

final class AssetUrlResolver {
	/**
	 * Resolve an asset URL.
	 *
	 * @param string $url Asset URL.
	 * @return array|false
	 */
	public function resolve( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['path'] ) ) {
			return false;
		}

		if ( ! empty( $parts['host'] ) && ( empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) ) {
			return false;
		}

		$path = rawurldecode( $parts['path'] );
		if ( false !== strpos( $path, "\0" ) || false !== strpos( $path, '..' ) ) {
			return false;
		}

		$content_path  = wp_parse_url( content_url( '/' ), PHP_URL_PATH );
		$includes_path = wp_parse_url( includes_url( '/' ), PHP_URL_PATH );
		$file          = '';
		if ( is_string( $content_path ) && 0 === strpos( $path, trailingslashit( $content_path ) ) ) {
			$relative = ltrim( substr( $path, strlen( trailingslashit( $content_path ) ) ), '/' );
			$file     = wp_normalize_path( WP_CONTENT_DIR . '/' . $relative );
		} elseif ( is_string( $includes_path ) && 0 === strpos( $path, trailingslashit( $includes_path ) ) ) {
			$relative = ltrim( substr( $path, strlen( trailingslashit( $includes_path ) ) ), '/' );
			$file     = wp_normalize_path( ABSPATH . WPINC . '/' . $relative );
		}

		if ( '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}

		$real = realpath( $file );
		if ( false === $real ) {
			return false;
		}
		$real          = wp_normalize_path( $real );
		$allowed_roots = array( wp_normalize_path( WP_CONTENT_DIR ), wp_normalize_path( ABSPATH . WPINC ) );
		$allowed       = false;
		foreach ( $allowed_roots as $root ) {
			if ( 0 === strpos( $real, trailingslashit( $root ) ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return false;
		}

		return array(
			'file' => $real,
			'url'  => $url,
		);
	}
}
