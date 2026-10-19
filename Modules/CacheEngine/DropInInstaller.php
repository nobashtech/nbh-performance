<?php
/**
 * advanced-cache.php drop-in installer.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\DataOwnershipRegistry;

final class DropInInstaller {
	const MARKER = 'NBH_PERFORMANCE_DROPIN';

	/**
	 * Install or refresh the NBH Performance page-cache drop-in.
	 *
	 * @return bool
	 */
	public function install() {
		$source = NBH_PERFORMANCE_PATH . 'advanced-cache.php';
		$target = WP_CONTENT_DIR . '/advanced-cache.php';

		if ( ! is_readable( $source ) ) {
			update_option( 'nbh_performance_dropin_error', 'source_unreadable', false );
			return false;
		}

		$status = $this->inspect( $target );
		if ( 'foreign' === $status || 'unreadable' === $status ) {
			update_option( 'nbh_performance_dropin_conflict', 1, false );
			return false;
		}

		// A previously reported conflict may have been removed manually.
		delete_option( 'nbh_performance_dropin_conflict' );

		if ( 'owned' === $status ) {
			$source_hash = hash_file( 'sha256', $source );
			$target_hash = hash_file( 'sha256', $target );
			if ( is_string( $source_hash ) && is_string( $target_hash ) && hash_equals( $source_hash, $target_hash ) ) {
				delete_option( 'nbh_performance_dropin_error' );
				update_option( 'nbh_performance_dropin_installed', 1, false );
				return true;
			}
		}

		$temp = tempnam( WP_CONTENT_DIR, '.nbh-performance-dropin-' );
		if ( false === $temp || ! \NBH\Performance\Modules\Core\Filesystem::copy( $source, $temp ) ) {
			update_option( 'nbh_performance_dropin_error', 'copy_failed', false );
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, 0644 );
		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- The verified destination is the WordPress-mandated wp-content drop-in or its owned backup, not the plugin directory; foreign ownership is checked before replacement.
		$installed = @rename( $temp, $target );
		if ( ! $installed ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			update_option( 'nbh_performance_dropin_error', 'rename_failed', false );
		} else {
			delete_option( 'nbh_performance_dropin_error' );
			delete_option( 'nbh_performance_dropin_conflict' );
		}
		update_option( 'nbh_performance_dropin_installed', (int) $installed, false );
		return $installed;
	}

	/**
	 * Verify that a foreign drop-in can be backed up before a takeover transaction.
	 *
	 * @return array<string,mixed>
	 */
	public function prepare_takeover() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		$status = $this->inspect( $target );
		if ( 'missing' === $status || 'owned' === $status ) {
			return array(
				'success' => true,
				'status'  => $status,
			);
		}
		if ( 'unreadable' === $status ) {
			return array(
				'success' => false,
				'message' => __( 'The existing advanced-cache.php file cannot be read and was left unchanged.', 'nbh-performance' ),
			);
		}
		$backup = $this->backup_foreign_dropin( $target );
		if ( ! empty( $backup['success'] ) ) {
			$backup['status'] = $status;
		}
		return $backup;
	}

	/**
	 * Safely back up and replace a foreign drop-in after administrator approval.
	 *
	 * @return array<string,mixed>
	 */
	public function take_ownership() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		$status = $this->inspect( $target );

		if ( 'unreadable' === $status ) {
			return array(
				'success' => false,
				'message' => __( 'The existing advanced-cache.php file is unreadable. NBH Performance left it unchanged.', 'nbh-performance' ),
			);
		}

		if ( 'missing' === $status || 'owned' === $status ) {
			$installed = $this->install();
			return array(
				'success' => $installed,
				'message' => $installed ? __( 'NBH Performance installed its verified page-cache drop-in.', 'nbh-performance' ) : __( 'NBH Performance could not install the page-cache drop-in.', 'nbh-performance' ),
			);
		}

		$backup = $this->backup_foreign_dropin( $target );
		if ( empty( $backup['success'] ) ) {
			return $backup;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! \NBH\Performance\Modules\Core\Filesystem::unlink( $target ) ) {
			return array(
				'success' => false,
				'message' => __( 'The existing cache drop-in was backed up, but WordPress could not remove the original file.', 'nbh-performance' ),
			);
		}

		$installed = $this->install();
		if ( ! $installed ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.PHP.NoSilencedErrors.Discouraged -- The verified destination is the WordPress-mandated wp-content drop-in or its owned backup, not the plugin directory; foreign ownership is checked before replacement.
			@copy( $backup['path'], $target );
			return array(
				'success' => false,
				'message' => __( 'NBH Performance could not publish its drop-in, so the previous cache file was restored from the verified backup.', 'nbh-performance' ),
			);
		}

		update_option(
			'nbh_performance_last_dropin_backup',
			array(
				'file'       => basename( $backup['path'] ),
				'sha256'     => $backup['sha256'],
				'created_at' => time(),
			),
			false
		);

		return array(
			'success' => true,
			'message' => __( 'NBH Performance backed up the previous cache file and installed its verified drop-in.', 'nbh-performance' ),
			'backup'  => basename( $backup['path'] ),
		);
	}

	/** @return array<string,mixed> */
	private function backup_foreign_dropin( $target ) {
		$directory = trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'cache-conflict-backups';
		if ( ! wp_mkdir_p( $directory ) ) {
			return array(
				'success' => false,
				'message' => __( 'NBH Performance could not create a private backup directory.', 'nbh-performance' ),
			);
		}
		$this->protect_private_directory( dirname( $directory ) );
		$this->protect_private_directory( $directory );

		$hash = hash_file( 'sha256', $target );
		if ( false === $hash ) {
			return array(
				'success' => false,
				'message' => __( 'NBH Performance could not verify the existing cache file before backup.', 'nbh-performance' ),
			);
		}
		$backup = trailingslashit( $directory ) . 'advanced-cache-' . gmdate( 'Ymd-His' ) . '-' . substr( $hash, 0, 12 ) . '.php.bak';
		if ( ! copy( $target, $backup ) || ! is_readable( $backup ) || hash_file( 'sha256', $backup ) !== $hash ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			\NBH\Performance\Modules\Core\Filesystem::unlink( $backup );
			return array(
				'success' => false,
				'message' => __( 'NBH Performance could not create and verify a backup of the existing cache file. Nothing was changed.', 'nbh-performance' ),
			);
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $backup, 0600 );
		return array(
			'success' => true,
			'path'    => $backup,
			'sha256'  => $hash,
		);
	}

	private function protect_private_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$htaccess = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		$webconfig = trailingslashit( $directory ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $webconfig, '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Reconcile the stored conflict flag with the actual filesystem state.
	 *
	 * @return string missing|owned|foreign|unreadable
	 */
	public function reconcile() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		$status = $this->inspect( $target );

		if ( 'foreign' === $status || 'unreadable' === $status ) {
			update_option( 'nbh_performance_dropin_conflict', 1, false );
		} else {
			delete_option( 'nbh_performance_dropin_conflict' );
		}

		return $status;
	}

	/**
	 * Inspect a drop-in without generating filesystem warnings.
	 *
	 * @param string $file Drop-in path.
	 * @return string
	 */
	public function inspect( $file ) {
		if ( ! file_exists( $file ) ) {
			return 'missing';
		}
		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
			return 'unreadable';
		}
		return $this->is_ours( $file ) ? 'owned' : 'foreign';
	}

	/**
	 * Determine whether a readable drop-in belongs to NBH Performance.
	 *
	 * @param string $file Drop-in path.
	 * @return bool
	 */
	public function is_ours( $file ) {
		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		$header = fread( $handle, 1024 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $handle );
		return false !== strpos( (string) $header, self::MARKER );
	}
}
