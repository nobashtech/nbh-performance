<?php
/**
 * Atomic page-cache entry writer with metadata and precompressed variants.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheWriter {
	/**
	 * Backward-compatible HTML-only write.
	 *
	 * @param string $file    Destination file.
	 * @param string $content HTML content.
	 * @return bool
	 */
	public function write( $file, $content ) {
		return $this->atomic_write( $file, $content );
	}

	/**
	 * Atomically write a complete cache entry. Metadata is committed last so the
	 * drop-in never serves a partially replaced response.
	 *
	 * @param string $file     Destination index.html file.
	 * @param string $content  HTML content.
	 * @param array  $metadata Response metadata.
	 * @return array|false
	 */
	public function write_entry( $file, $content, array $metadata ) {
		$status      = isset( $metadata['status'] ) ? absint( $metadata['status'] ) : 200;
		$is_redirect = in_array( $status, array( 301, 308 ), true );
		if ( '' === $file || ( ! $is_redirect && '' === trim( (string) $content ) ) ) {
			return false;
		}

		$maximum = max( 1, min( 50, absint( get_option( 'nbh_performance_cache_max_html_mb', 8 ) ) ) ) * MB_IN_BYTES;
		$minimum = max( 0, min( 65536, absint( get_option( 'nbh_performance_cache_min_html_bytes', 256 ) ) ) );
		$length  = strlen( (string) $content );
		if ( ( ! $is_redirect && $length < $minimum ) || $length > $maximum ) {
			return false;
		}

		$paths = CacheEntryFiles::paths( $file );
		if ( ! wp_mkdir_p( dirname( $paths['html'] ) ) || ! $this->has_disk_capacity( dirname( $paths['html'] ), $length ) ) {
			return false;
		}

		// Invalidate the previous commit marker before replacing any response bytes.
		// The drop-in requires metadata, so a crash during replacement safely bypasses.
		if ( is_file( $paths['metadata'] ) ) {
			wp_delete_file( $paths['metadata'] );
			clearstatcache( true, $paths['metadata'] );
			if ( is_file( $paths['metadata'] ) ) {
				return false;
			}
		}

		if ( ! $this->atomic_write( $paths['html'], $content ) ) {
			return false;
		}

		$compression  = array();
		$compressible = $length > 0 && in_array( $status, array( 200, 404 ), true );
		if ( $compressible && get_option( 'nbh_performance_cache_gzip_enabled', 1 ) && function_exists( 'gzencode' ) ) {
			$gzip = gzencode( (string) $content, 6, FORCE_GZIP );
			if ( is_string( $gzip ) && strlen( $gzip ) > 0 && strlen( $gzip ) < $length && $this->atomic_write( $paths['gzip'], $gzip ) ) {
				$compression['gzip'] = array(
					'file'  => basename( $paths['gzip'] ),
					'bytes' => strlen( $gzip ),
				);
			} elseif ( is_file( $paths['gzip'] ) ) {
				wp_delete_file( $paths['gzip'] );
			}
		} elseif ( is_file( $paths['gzip'] ) ) {
			wp_delete_file( $paths['gzip'] );
		}

		if ( $compressible && get_option( 'nbh_performance_cache_brotli_enabled', 1 ) && function_exists( 'brotli_compress' ) ) {
			$brotli = brotli_compress( (string) $content, 5, defined( 'BROTLI_TEXT' ) ? BROTLI_TEXT : 1 );
			if ( is_string( $brotli ) && strlen( $brotli ) > 0 && strlen( $brotli ) < $length && $this->atomic_write( $paths['brotli'], $brotli ) ) {
				$compression['br'] = array(
					'file'  => basename( $paths['brotli'] ),
					'bytes' => strlen( $brotli ),
				);
			} elseif ( is_file( $paths['brotli'] ) ) {
				wp_delete_file( $paths['brotli'] );
			}
		} elseif ( is_file( $paths['brotli'] ) ) {
			wp_delete_file( $paths['brotli'] );
		}

		$metadata['content_length'] = $length;
		$metadata['compression']    = $compression;
		$json                       = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		if ( false === $json || ! $this->atomic_write( $paths['metadata'], $json ) ) {
			CacheEntryFiles::delete( $file );
			return false;
		}

		$this->clear_failure( $file );
		do_action( 'nbh_performance_after_cache_write', $file, $length, $metadata );

		return array(
			'file'        => $paths['html'],
			'metadata'    => $paths['metadata'],
			'bytes'       => $length,
			'compression' => $compression,
		);
	}

	/** Record an origin-generation failure for stale-if-error decisions. */
	public function mark_failure( $file, $reason, $status = 0 ) {
		$paths = CacheEntryFiles::paths( $file );
		if ( ! is_file( $paths['html'] ) || ! wp_mkdir_p( dirname( $paths['error'] ) ) ) {
			return false;
		}
		$payload = wp_json_encode(
			array(
				'failed_at' => time(),
				'reason'    => sanitize_key( (string) $reason ),
				'status'    => absint( $status ),
			),
			JSON_UNESCAPED_SLASHES
		);
		return false !== $payload && $this->atomic_write( $paths['error'], $payload );
	}

	/** Remove any stale-if-error failure marker after a successful generation. */
	public function clear_failure( $file ) {
		$path = CacheEntryFiles::paths( $file );
		if ( is_file( $path['error'] ) ) {
			wp_delete_file( $path['error'] );
		}
	}

	/** Ensure cache writes retain a configurable emergency disk reserve. */
	private function has_disk_capacity( $directory, $content_length ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$free = @disk_free_space( $directory );
		if ( false === $free ) {
			return true;
		}
		$reserve = max( 16, min( 10240, absint( get_option( 'nbh_performance_cache_min_free_disk_mb', 128 ) ) ) ) * MB_IN_BYTES;
		$needed  = max( $reserve, max( 1, (int) $content_length ) * 4 );
		return (float) $free > (float) $needed;
	}

	/**
	 * Atomically replace one file.
	 *
	 * @param string $file    Destination file.
	 * @param string $content File bytes.
	 * @return bool
	 */
	private function atomic_write( $file, $content ) {
		$directory = dirname( $file );
		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$temp_file = tempnam( $directory, '.nbh-performance-' );
		if ( false === $temp_file ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $temp_file, 'wb' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
				fclose( $handle );
			}
			wp_delete_file( $temp_file );
			return false;
		}
		$payload = (string) $content;
		$length  = strlen( $payload );
		$offset  = 0;
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$written = fwrite( $handle, substr( $payload, $offset ) );
			if ( false === $written || 0 === $written ) {
				flock( $handle, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
				fclose( $handle );
				wp_delete_file( $temp_file );
				return false;
			}
			$offset += $written;
		}
		$flushed = fflush( $handle );
		if ( $flushed && function_exists( 'fsync' ) ) {
			$flushed = fsync( $handle );
		}
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $handle );
		clearstatcache( true, $temp_file );
		if ( ! $flushed || ! is_file( $temp_file ) || (int) filesize( $temp_file ) !== $length ) {
			wp_delete_file( $temp_file );
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp_file, 0644 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $temp_file, $file ) ) {
			wp_delete_file( $temp_file );
			return false;
		}
		clearstatcache( true, $file );
		return is_file( $file ) && (int) filesize( $file ) === $length;
	}
}
