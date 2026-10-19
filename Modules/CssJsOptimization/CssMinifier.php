<?php
/** Parser-backed CSS minifier with fail-safe fallback. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use Throwable;

final class CssMinifier {
	/** @param string $css CSS source. @return string|false */
	public function minify( $css ) {
		if ( ! is_string( $css ) || '' === trim( $css ) || false !== stripos( $css, 'sourceMappingURL=' ) ) {
			return false;
		}
		if ( class_exists( '\\NBH\Performance\\Vendor\\MatthiasMullie\\Minify\\CSS' ) ) {
			try {
				$engine = new \NBH\Performance\Vendor\MatthiasMullie\Minify\CSS();
				$engine->setMaxImportSize( 0 );
				$engine->add( $css );
				$result = $engine->minify();
				return is_string( $result ) && '' !== trim( $result ) ? $result : false;
			} catch ( Throwable $error ) {
				do_action( 'nbh_performance_minifier_failure', 'css', $error );
			}
		}
		return $this->state_machine_fallback( $css );
	}

	/** @return string|false */
	private function state_machine_fallback( $css ) {
		$length        = strlen( $css );
		$output        = '';
		$quote         = '';
		$comment       = false;
		$escape        = false;
		$pending_space = false;
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];
			$next = $i + 1 < $length ? $css[ $i + 1 ] : '';
			if ( $comment ) {
				if ( '*' === $char && '/' === $next ) {
					$comment = false;
					++$i;
				}
				continue;
			}
			if ( '' !== $quote ) {
				$output .= $char;
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $char ) {
					$escape = true;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '/' === $char && '*' === $next ) {
				if ( $i + 2 < $length && '!' === $css[ $i + 2 ] ) {
					$end = strpos( $css, '*/', $i + 3 );
					if ( false === $end ) {
						return false;
					}
					$output .= substr( $css, $i, $end + 2 - $i );
					$i       = $end + 1;
					continue;
				}
				$comment = true;
				++$i;
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				if ( $pending_space && '' !== $output && ! preg_match( '/[{}:;,>+~(]$/', $output ) ) {
					$output .= ' ';
				}
				$pending_space = false;
				$quote         = $char;
				$output       .= $char;
				continue;
			}
			if ( ctype_space( $char ) ) {
				$pending_space = true;
				continue;
			}
			if ( false !== strpos( '{}:;,>+~()', $char ) ) {
				$output        = rtrim( $output );
				$output       .= $char;
				$pending_space = false;
				continue;
			}
			if ( $pending_space && '' !== $output ) {
				$output .= ' ';
			}
			$pending_space = false;
			$output       .= $char;
		}
		if ( $comment || '' !== $quote ) {
			return false;
		}
		$output = str_replace( ';}', '}', trim( $output ) );
		return '' !== $output ? $output : false;
	}
}
