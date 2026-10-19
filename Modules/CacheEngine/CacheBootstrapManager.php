<?php
/**
 * Ensures the WordPress page-cache bootstrap is installed and enabled.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;

final class CacheBootstrapManager implements Module {
	/** Register bootstrap checks. */
	public function register() {
		add_action( 'admin_init', array( $this, 'ensure' ), 2 );
		add_action( 'update_option_nbh_performance_cache_enabled', array( $this, 'ensure' ), 10, 2 );
		add_action( 'update_option_nbh_performance_safe_mode', array( $this, 'ensure' ), 10, 2 );
	}

	/**
	 * Ensure WP_CACHE, the drop-in and cache directories are ready.
	 *
	 * @return array
	 */
	public function ensure() {
		$status = array(
			'wp_cache_defined'  => defined( 'WP_CACHE' ) && WP_CACHE,
			'wp_config_written' => false,
			'dropin_installed'  => false,
			'directories_ready' => false,
			'checked_at'        => time(),
		);

		if ( ! get_option( 'nbh_performance_cache_enabled', false ) ) {
			update_option( 'nbh_performance_cache_bootstrap_status', $status, false );
			return $status;
		}

		$status['directories_ready'] = wp_mkdir_p( WP_CONTENT_DIR . '/cache/nbh-performance/pages' );
		$status['dropin_installed']  = ( new DropInInstaller() )->install();

		if ( ! $status['wp_cache_defined'] ) {
			$status['wp_config_written'] = $this->enable_wp_cache_constant();
		}

		update_option( 'nbh_performance_cache_bootstrap_status', $status, false );
		return $status;
	}

	/** Safely write or update the WP_CACHE constant for the next request. */
	private function enable_wp_cache_constant() {
		$file = $this->locate_wp_config();
		if ( '' === $file || ! is_readable( $file ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = \NBH\Performance\Modules\Core\Filesystem::get_contents( $file );
		if ( false === $contents ) {
			return false;
		}

		$updated = preg_replace(
			"/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(?:false|0|true|1)\s*\)\s*;/i",
			"define( 'WP_CACHE', true ); // Added by NBH Performance",
			$contents,
			1,
			$count
		);

		if ( 0 === $count ) {
			$line   = "\n/** Enable NBH Performance early page-cache serving. */\ndefine( 'WP_CACHE', true ); // Added by NBH\Performance\n";
			$marker = "/* That's all, stop editing!";
			$pos    = strpos( $contents, $marker );
			if ( false === $pos ) {
				$marker = 'require_once ABSPATH';
				$pos    = strpos( $contents, $marker );
			}
			$updated = false === $pos ? $contents . $line : substr( $contents, 0, $pos ) . $line . substr( $contents, $pos );
		}

		if ( ! is_string( $updated ) || $updated === $contents ) {
			return false;
		}

		$backup = $file . '.nbh-performance-backup';
		if ( ! file_exists( $backup ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@copy( $file, $backup );
		}

		$temp = tempnam( dirname( $file ), '.nbh-performance-wp-config-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $temp || false === file_put_contents( $temp, $updated, LOCK_EX ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, fileperms( $file ) & 0777 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $temp, $file ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		}
		return true;
	}

	/** Locate wp-config.php in standard WordPress layouts. */
	private function locate_wp_config() {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);
		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}
}
