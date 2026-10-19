<?php
/**
 * Resolve and remove every artifact that belongs to a page-cache entry.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheEntryFiles {
	/**
	 * Return canonical artifact paths for a cache HTML file.
	 *
	 * @param string $html_file Canonical index.html path.
	 * @return array
	 */
	public static function paths( $html_file ) {
		$html_file = wp_normalize_path( (string) $html_file );
		$directory = dirname( $html_file );

		return array(
			'html'     => $html_file,
			'gzip'     => $html_file . '.gz',
			'brotli'   => $html_file . '.br',
			'metadata' => wp_normalize_path( $directory . '/metadata.json' ),
			'error'    => wp_normalize_path( $directory . '/error.json' ),
			'refresh'  => wp_normalize_path( $directory . '/refresh.lock' ),
			'lock'     => $html_file . '.lock',
		);
	}

	/**
	 * Delete all artifacts for one cache entry.
	 *
	 * @param string $html_file Canonical index.html path.
	 * @return bool
	 */
	public static function delete( $html_file ) {
		$success = true;
		$paths   = self::paths( $html_file );

		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
				clearstatcache( true, $path );
				if ( is_file( $path ) ) {
					$success = false;
				}
			}
		}

		$directory = dirname( $paths['html'] );
		if ( is_dir( $directory ) ) {
			$contents = scandir( $directory );
			if ( is_array( $contents ) && 2 === count( $contents ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				\NBH\Performance\Modules\Core\Filesystem::rmdir( $directory );
			}
		}

		return $success;
	}
}
