<?php
/** Retire obsolete download settings without blocking normal page requests. @package NBHPerformance */
namespace NBH\Performance\Modules\Core;

final class FreeReleaseMigration {
	const CLEANUP_HOOK = 'nbh_performance_retire_download_cache';
	const BATCH_SIZE   = 200;

	public static function register() {
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
	}

	public static function run() {
		if ( ! get_option( 'nbh_performance_free_local_migrated', false ) ) {
			foreach ( array( 'nbh_performance_analytics_proxy_refresh', 'nbh_performance_google_fonts_refresh' ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			foreach ( array( 'nbh_performance_generator_endpoint', 'nbh_performance_generator_secret', 'nbh_performance_analytics_proxy_enabled', 'nbh_performance_analytics_proxy_queue', 'nbh_performance_google_fonts_local_enabled', 'nbh_performance_google_fonts_queue' ) as $option ) {
				delete_option( $option );
			}
			update_option( 'nbh_performance_free_local_migrated', 1, false );
		}
		if ( ! get_option( 'nbh_performance_download_cleanup_done', false ) ) {
			self::schedule( MINUTE_IN_SECONDS );
		}
	}

	private static function schedule( $delay ) {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CLEANUP_HOOK );
		}
	}

	/** Process a bounded batch on cron. Unreadable directories cannot break the frontend. */
	public static function cleanup() {
		$processed = 0;
		$pending   = false;
		$error     = false;
		$base      = WP_CONTENT_DIR . '/cache/nbh-performance/third-party';
		try {
			foreach ( array( 'analytics', 'fonts' ) as $folder ) {
				$directory = $base . '/' . $folder;
				if ( ! is_dir( $directory ) || is_link( $directory ) ) {
					continue;
				}
				$trusted = realpath( WP_CONTENT_DIR );
				if ( false === $trusted || realpath( $directory ) !== $trusted . '/cache/nbh-performance/third-party/' . $folder ) {
					continue;
				}
				$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $files as $file ) {
					if ( $processed >= self::BATCH_SIZE ) {
						$pending = true;
						break 2;
					}
					if ( $file->isLink() ) {
						continue;
					}
					if ( $file->isDir() ) {
						\NBH\Performance\Modules\Core\Filesystem::rmdir( $file->getPathname() );
						continue;
					}
					if ( ! in_array( strtolower( $file->getExtension() ), array( 'js', 'css', 'woff', 'woff2', 'ttf', 'tmp' ), true ) ) {
						continue;
					}
					$path = $file->getPathname();
					wp_delete_file( $path );
					clearstatcache( true, $path );
					if ( is_file( $path ) ) {
						$error = true;
					} else {
						++$processed;
					}
				}
			}
		} catch ( \Throwable $exception ) {
			$error = true;
		}
		$complete = ! $pending && ! $error;
		update_option(
			'nbh_performance_download_cleanup_status',
			array(
				'complete'     => $complete,
				'processed'    => $processed,
				'retry_needed' => $error,
				'checked_at'   => time(),
			),
			false
		);
		if ( $complete ) {
			update_option( 'nbh_performance_download_cleanup_done', 1, false );
		} else {
			self::schedule( $error ? 6 * HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
		}
			return $complete;
	}
}
