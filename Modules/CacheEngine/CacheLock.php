<?php
/**
 * Filesystem cache lock to reduce cache stampedes.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheLock {
	/** Acquire a non-blocking generation lock and return its handle. */
	public function acquire( $cache_file ) {
		$lock_file = $cache_file . '.lock';
		$directory = dirname( $lock_file );
		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$handle = @fopen( $lock_file, 'c' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
			}
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate
		ftruncate( $handle, 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, (string) time() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush
		fflush( $handle );
		return $handle;
	}

	/** Release ownership while retaining the inode used by concurrent flock waiters. */
	public function release( $handle, $lock_file = '' ) {
		unset( $lock_file ); // Retained as a compatibility argument; releasing must not unlink a shared lock inode.
		if ( is_resource( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}
		// The path argument remains for compatibility; unlinking here breaks mutual exclusion.
	}
}
