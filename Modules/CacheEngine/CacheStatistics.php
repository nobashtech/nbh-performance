<?php
/**
 * Live cache statistics reconciliation.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheStatistics {
	const OPTION = 'nbh_performance_cache_stats';

	/**
	 * Reconcile statistics from disk and the cache index.
	 *
	 * @param CacheIndex|null $index Cache index.
	 * @return array
	 */
	public static function reconcile( CacheIndex $index = null, $scan_disk = false ) {
		$files = 0;
		$bytes = 0;
		$seen  = array();

		if ( null !== $index ) {
			$summary = $index->summary();
			$files   = isset( $summary['files'] ) ? (int) $summary['files'] : 0;
			$bytes   = isset( $summary['bytes'] ) ? (int) $summary['bytes'] : 0;
		}

		if ( $scan_disk || null === $index ) {
			$files = 0;
			$bytes = 0;
			$root  = WP_CONTENT_DIR . '/cache/nbh-performance/pages';
			if ( is_dir( $root ) ) {
				try {
					$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
					foreach ( $iterator as $item ) {
						if ( ! $item->isFile() || 'index.html' !== $item->getFilename() ) {
							continue;
						}
						$file = wp_normalize_path( $item->getPathname() );
						if ( isset( $seen[ $file ] ) ) {
							continue;
						}
						$seen[ $file ] = true;
						++$files;
						$bytes += max( 0, (int) $item->getSize() );
					}
				} catch ( \UnexpectedValueException $exception ) {
					// Preserve partial counts and expose the interrupted scan to diagnostics.
					do_action( 'nbh_performance_cache_statistics_scan_interrupted', $exception );
				}
			}
		}

		$stats = array(
			'files'      => $files,
			'bytes'      => $bytes,
			'updated_at' => time(),
		);
		update_option( self::OPTION, $stats, false );
		return $stats;
	}

	/** Reset statistics immediately after a full purge. */
	public static function reset() {
		$stats = array(
			'files'      => 0,
			'bytes'      => 0,
			'updated_at' => time(),
		);
		update_option( self::OPTION, $stats, false );
		return $stats;
	}
}
