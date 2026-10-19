<?php
/**
 * Safe wildcard and regular-expression matcher for user-configured exclusions.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

final class PatternMatcher {
	/**
	 * Determine whether a subject matches any configured pattern.
	 *
	 * Supported patterns:
	 * - Plain fragments: /checkout/
	 * - Wildcards: /shop/* or *.example.com
	 * - Explicit regular expressions: regex:#^/private/[0-9]+/#i
	 *
	 * @param string $subject  Value to inspect.
	 * @param array  $patterns Configured patterns.
	 * @return bool
	 */
	public static function matches_any( $subject, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( $subject, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/** Match one pattern. */
	public static function matches( $subject, $pattern ) {
		$subject = (string) $subject;
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return false;
		}

		if ( 0 === stripos( $pattern, 'regex:' ) ) {
			$expression = trim( substr( $pattern, 6 ) );
			if ( '' === $expression || strlen( $expression ) > 500 ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$result = @preg_match( $expression, $subject );
			return 1 === $result;
		}

		if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) {
			$quoted = preg_quote( $pattern, '#' );
			$quoted = str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), $quoted );
			return 1 === preg_match( '#^' . $quoted . '$#i', $subject );
		}

		return false !== stripos( $subject, $pattern );
	}
}
