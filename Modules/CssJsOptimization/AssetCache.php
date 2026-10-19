<?php
/** Minified asset cache. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class AssetCache {
	/** @param string $source_file Source. @param string $type Type. @return array */
	public function destination( $source_file, $type ) {
		$mtime     = (int) filemtime( $source_file );
		$key       = hash( 'sha256', wp_normalize_path( $source_file ) . '|' . $mtime . '|' . NBH_PERFORMANCE_VERSION );
		$directory = WP_CONTENT_DIR . '/cache/nbh-performance/assets/' . $type;
		return array(
			'file' => $directory . '/' . $key . '.' . $type,
			'url'  => content_url( 'cache/nbh-performance/assets/' . $type . '/' . $key . '.' . $type ),
		);
	}
	/** @param string $key Cache key. @param string $type Asset type. @return array */
	public function combined_destination( $key, $type ) {
		$type      = sanitize_key( $type );
		$directory = WP_CONTENT_DIR . '/cache/nbh-performance/assets/' . $type;
		$filename  = 'combined-' . sanitize_file_name( $key ) . '.' . $type;
		return array(
			'file' => $directory . '/' . $filename,
			'url'  => content_url( 'cache/nbh-performance/assets/' . $type . '/' . $filename ),
		);
	}

	/** @param string $file Destination. @param string $content Content. @return bool */
	public function write( $file, $content ) {
		$directory = dirname( $file );
		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		$temp = tempnam( $directory, '.nbh-performance-asset-' );
		if ( false === $temp ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $temp, $content, LOCK_EX );
		if ( false === $bytes || strlen( $content ) !== $bytes ) {
			wp_delete_file( $temp );
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. Atomic same-filesystem rename is required for publication; WP_Filesystem::move() can unlink/copy and is not an atomic commit. Failure is checked before accepting the new artifact.
		if ( ! @rename( $temp, $file ) ) {
			wp_delete_file( $temp );
			return false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		}
		return true;
	}
}
