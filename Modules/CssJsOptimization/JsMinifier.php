<?php
/** Parser-backed JavaScript minifier with conservative fallback. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use Throwable;

final class JsMinifier {
	/** @param string $javascript JavaScript source. @return string|false */
	public function minify( $javascript ) {
		if ( ! is_string( $javascript ) || '' === trim( $javascript ) || false !== stripos( $javascript, 'sourceMappingURL=' ) ) {
			return false;
		}
		if ( class_exists( '\\NBH\Performance\\Vendor\\MatthiasMullie\\Minify\\JS' ) ) {
			try {
				$engine = new \NBH\Performance\Vendor\MatthiasMullie\Minify\JS();
				$engine->add( $javascript );
				$result = $engine->minify();
				return is_string( $result ) && '' !== trim( $result ) ? $result : false;
			} catch ( Throwable $error ) {
				do_action( 'nbh_performance_minifier_failure', 'javascript', $error );
			}
		}
		return $this->conservative_fallback( $javascript );
	}
	/** @return string|false */
	private function conservative_fallback( $javascript ) {
		$length        = strlen( $javascript );
		$output        = '';
		$quote         = '';
		$escape        = false;
		$line_comment  = false;
		$block_comment = false;
		$regex         = false;
		$last_token    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $javascript[ $i ];
			$next = $i + 1 < $length ? $javascript[ $i + 1 ] : '';
			if ( $line_comment ) {
				if ( "\n" === $char || "\r" === $char ) {
					$line_comment = false;
					$output      .= "\n";
				}
				continue;
			}
			if ( $block_comment ) {
				if ( '*' === $char && '/' === $next ) {
					$block_comment = false;
					++$i;
				}
				continue;
			}
			if ( '' !== $quote || $regex ) {
				$output .= $char;
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $char ) {
					$escape = true;
				} elseif ( $regex && '/' === $char ) {
					$regex = false;
				} elseif ( ! $regex && $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$quote   = $char;
				$output .= $char;
				continue;
			}
			if ( '/' === $char && '/' === $next ) {
				$line_comment = true;
				++$i;
				continue;
			}
			if ( '/' === $char && '*' === $next ) {
				if ( $i + 2 < $length && '!' === $javascript[ $i + 2 ] ) {
					$output .= '/*!';
					$i      += 2;
					continue;
				}
				$block_comment = true;
				++$i;
				continue;
			}
			if ( '/' === $char && preg_match( '/^(|[({[=,:;!&|?+*%~-])$/', $last_token ) ) {
				$regex   = true;
				$output .= $char;
				continue;
			}
			$output .= $char;
			if ( ! ctype_space( $char ) ) {
				$last_token = $char;
			}
		}
		if ( $block_comment || '' !== $quote || $regex ) {
			return false;
		}
		$lines = preg_split( '/\R/', $output );
		if ( false === $lines ) {
			return false;
		}
		$lines  = array_map( 'trim', $lines );
		$output = implode(
			"\n",
			array_filter(
				$lines,
				static function ( $line ) {
					return '' !== $line;
				}
			)
		);
		return '' !== trim( $output ) ? $output : false;
	}
}
