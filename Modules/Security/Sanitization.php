<?php
/**
 * Sanitization helpers.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Security;

final class Sanitization {
	public static function url_path( $path ) {
		$path = rawurldecode( (string) $path );
		$path = str_replace( array( "\0", '\\' ), array( '', '/' ), $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = preg_replace( '#(?:^|/)\.\.(?:/|$)#', '/', $path );

		return '/' . ltrim( (string) $path, '/' );
	}

	public static function cache_segment( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9._-]+/', '-', $value );
		$value = trim( (string) $value, '.-_' );

		return '' !== $value ? $value : 'default';
	}
}
