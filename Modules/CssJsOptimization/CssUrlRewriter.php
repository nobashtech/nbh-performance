<?php
/** Rewrites CSS relative references and resolves bounded local imports. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class CssUrlRewriter {
	private $resolver;
	public function __construct( AssetUrlResolver $resolver ) {
		$this->resolver = $resolver;
	}

	/** @param string $css CSS. @param string $source_url Source URL. @param int $depth Import depth. @return string|false */
	public function rewrite( $css, $source_url, $depth = 0 ) {
		if ( $depth > 3 || strlen( $css ) > 3145728 ) {
			return false;
		}
		$base = trailingslashit( dirname( strtok( $source_url, '?#' ) ) );
		$self = $this;
		$css  = preg_replace_callback(
			'/@import\s+(?:url\()?\s*(["\']?)([^"\')\s;]+)\1\s*\)?\s*([^;]*);/i',
			static function ( $m ) use ( $self, $base, $depth ) {
				$target = $self->absolute_url( $m[2], $base );
				if ( false === $target ) {
					return $m[0];
				}
				$resolved = $self->resolver->resolve( $target );
				if ( false === $resolved || 'css' !== strtolower( pathinfo( $resolved['file'], PATHINFO_EXTENSION ) ) ) {
					return $m[0];
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = \NBH\Performance\Modules\Core\Filesystem::get_contents( $resolved['file'] );
				if ( false === $content ) {
					return $m[0];
				}
				$content = $self->rewrite( $content, $target, $depth + 1 );
				if ( false === $content ) {
					return $m[0];
				}
				$media = trim( $m[3] );
				return '' === $media ? $content : '@media ' . $media . '{' . $content . '}';
			},
			$css
		);
		if ( null === $css ) {
			return false;
		}
		$css = preg_replace_callback(
			'/url\(\s*(["\']?)(?!data:|blob:|#)([^"\')]+)\1\s*\)/i',
			static function ( $m ) use ( $self, $base ) {
				$url = $self->absolute_url( trim( $m[2] ), $base );
				return false === $url ? $m[0] : 'url("' . esc_url_raw( $url ) . '")';
			},
			$css
		);
		return null === $css ? false : $css;
	}

	/** @param string $reference Reference. @param string $base Base URL. @return string|false */
	public function absolute_url( $reference, $base ) {
		$reference = trim( html_entity_decode( $reference, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $reference || 0 === strpos( $reference, 'data:' ) || 0 === strpos( $reference, 'blob:' ) || 0 === strpos( $reference, '#' ) ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $reference ) ) {
			return $reference;
		}
		if ( 0 === strpos( $reference, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $reference;
		}
		if ( 0 === strpos( $reference, '/' ) ) {
			return home_url( $reference );
		}
		$joined = $base . $reference;
		$parts  = wp_parse_url( $joined );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return false;
		}
		$segments = array();
		foreach ( explode( '/', $parts['path'] ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $scheme . $host . $port . '/' . implode( '/', $segments ) . $query;
	}
}
