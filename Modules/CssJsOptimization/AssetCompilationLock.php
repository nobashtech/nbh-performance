<?php
/** Filesystem compilation lock. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class AssetCompilationLock {
	/** @param string $key Key. @param int $ttl TTL. @return resource|false */
	public function acquire( $key, $ttl = 60 ) {
		$directory = WP_CONTENT_DIR . '/cache/nbh-performance/locks';
		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		$file = $directory . '/asset-' . hash( 'sha256', $key ) . '.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file, 'c+' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
				fclose( $handle );
			}
			return false;
		}
		$stat = fstat( $handle );
		if ( is_array( $stat ) && isset( $stat['mtime'] ) && time() - (int) $stat['mtime'] > $ttl ) {
			ftruncate( $handle, 0 );
		}
		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The destination is an owned WP_CONTENT_DIR/cache/nbh-performance runtime/probe/lock file, not a plugin source directory.
		fwrite( $handle, (string) time() );
		fflush( $handle );
		return $handle;
	}
	/** @param resource $handle Handle. */
	public function release( $handle ) {
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
			fclose( $handle );
		}
	}
}
