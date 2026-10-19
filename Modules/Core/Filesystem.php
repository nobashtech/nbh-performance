<?php
/**
 * Local filesystem operations through the WordPress filesystem API.
 *
 * Cache files must be on the local filesystem used by PHP. The direct transport
 * avoids prompting visitors or cron workers for remote filesystem credentials.
 * Streaming, flock and atomic rename operations are kept at their call sites.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

final class Filesystem {
	/** @var \WP_Filesystem_Direct|null */
	private static $driver;

	/** @return \WP_Filesystem_Direct */
	private static function driver() {
		if ( null === self::$driver ) {
			if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			self::$driver = new \WP_Filesystem_Direct( null );
		}
		return self::$driver;
	}

	/** Read a local file through WordPress. @return string|false */
	public static function get_contents( $path ) {
		return self::driver()->get_contents( $path );
	}

	/** Write a complete buffer and verify its length through WordPress. @return bool */
	public static function put_contents( $path, $contents, $mode = 0644 ) {
		return self::driver()->put_contents( $path, (string) $contents, $mode );
	}

	/** Copy only where the caller has already checked destination ownership. @return bool */
	public static function copy( $source, $destination ) {
		return self::driver()->copy( $source, $destination, true, 0644 );
	}

	/** @return bool */
	public static function is_writable( $path ) {
		return self::driver()->is_writable( $path );
	}

	/** @return bool */
	public static function chmod( $path, $mode ) {
		return self::driver()->chmod( $path, $mode );
	}

	/** Delete one file or symlink, without following directories. @return bool */
	public static function unlink( $path ) {
		return self::driver()->delete( $path, false, 'f' );
	}

	/** Remove an empty directory only; recursive deletion is deliberately absent. @return bool */
	public static function rmdir( $path ) {
		return self::driver()->rmdir( $path, false );
	}
}
