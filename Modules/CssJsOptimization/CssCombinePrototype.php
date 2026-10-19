<?php
/** Conservative HTTP/1.1-only CSS combine prototype. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class CssCombinePrototype {
	private $resolver;
	private $cache;
	private $minifier;
	private $combiner;

	public function __construct( AssetUrlResolver $resolver, AssetCache $cache, CssMinifier $minifier, FileCombiner $combiner ) {
		$this->resolver = $resolver;
		$this->cache    = $cache;
		$this->minifier = $minifier;
		$this->combiner = $combiner;
	}

	/**
	 * Combine ordered same-origin CSS URLs when HTTP/1.1 policy allows it.
	 * This method is intentionally opt-in and is not attached globally yet.
	 *
	 * @param array $urls Ordered CSS URLs.
	 * @return string|false
	 */
	public function combine( array $urls ) {
		if ( ! get_option( 'nbh_performance_css_combine_enabled', 0 ) || ! $this->combiner->should_combine() ) {
			return false;
		}
		$contents = array();
		$files    = array();
		foreach ( $urls as $url ) {
			$resolved = $this->resolver->resolve( $url );
			if ( false === $resolved || 'css' !== strtolower( pathinfo( $resolved['file'], PATHINFO_EXTENSION ) ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$source = \NBH\Performance\Modules\Core\Filesystem::get_contents( $resolved['file'] );
			if ( false === $source || false !== stripos( $source, '@import' ) ) {
				return false;
			}
			$contents[] = $this->minifier->minify( $source );
			$files[]    = $resolved['file'];
		}
		$key         = hash( 'sha256', implode( '|', $files ) . '|' . implode( '|', array_map( 'filemtime', $files ) ) );
		$destination = $this->cache->combined_destination( $key, 'css' );
		if ( ! is_file( $destination['file'] ) && ! $this->cache->write( $destination['file'], implode( "\n", $contents ) ) ) {
			return false;
		}
		return $destination['url'];
	}
}
