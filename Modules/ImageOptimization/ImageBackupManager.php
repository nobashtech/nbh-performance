<?php
/** Verified backup and restore for attachment source files. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageBackupManager {
	const META_KEY = '_nbh_performance_image_backup_v1';

	public function backup( $attachment_id, array $files ) {
		$attachment_id = absint( $attachment_id );
		$uploads       = wp_get_upload_dir();
		$root          = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'nbh-performance-originals/' . $attachment_id );
		if ( ! wp_mkdir_p( $root ) ) {
			return new \WP_Error( 'nbh_performance_image_backup_directory', __( 'NBH Performance could not create the image backup directory.', 'nbh-performance' ) );
		}
		$this->protect_root( dirname( $root ) );
		$manifest = array(
			'version'    => 1,
			'created_at' => time(),
			'files'      => array(),
		);
		foreach ( array_unique( $files ) as $file ) {
			$file = wp_normalize_path( $file );
			if ( ! $this->inside_uploads( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$relative = $this->relative_upload_path( $file );
			$target   = $root . '/' . str_replace( '/', '__', $relative );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! is_file( $target ) && ! copy( $file, $target ) ) {
				/* translators: %s: image filename. */
				return new \WP_Error( 'nbh_performance_image_backup_failed', sprintf( __( 'NBH Performance could not back up %s.', 'nbh-performance' ), wp_basename( $file ) ) );
			}
			$manifest['files'][ $relative ] = array(
				'backup' => $target,
				'sha256' => hash_file( 'sha256', $target ),
				'bytes'  => filesize( $target ),
			);
		}
		if ( empty( $manifest['files'] ) ) {
			return new \WP_Error( 'nbh_performance_image_backup_empty', __( 'No eligible attachment files were available to back up.', 'nbh-performance' ) );
		}
		update_post_meta( $attachment_id, self::META_KEY, $manifest );
		return $manifest;
	}

	public function restore( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$manifest      = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) ) {
			return array(
				'restored' => 0,
				'status'   => 'no_backup',
			);
		}
		$uploads = wp_get_upload_dir();
		$count   = 0;
		foreach ( $manifest['files'] as $relative => $record ) {
			$backup = isset( $record['backup'] ) ? wp_normalize_path( $record['backup'] ) : '';
			$target = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' ) );
			if ( ! is_file( $backup ) || ! $this->inside_uploads( $target ) || ! $this->valid_backup( $backup, $record ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( wp_mkdir_p( dirname( $target ) ) && copy( $backup, $target ) ) {
				++$count;
			}
		}
		return array(
			'restored' => $count,
			'status'   => $count ? 'restored' : 'restore_failed',
		);
	}


	/** Delete verified backup artifacts when an attachment is permanently removed. */
	public function delete_backup( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$manifest      = get_post_meta( $attachment_id, self::META_KEY, true );
		$deleted       = 0;
		if ( is_array( $manifest ) && ! empty( $manifest['files'] ) ) {
			foreach ( $manifest['files'] as $record ) {
				$backup = isset( $record['backup'] ) ? wp_normalize_path( $record['backup'] ) : '';
				if ( $backup && $this->inside_uploads( $backup ) && is_file( $backup ) ) {
					wp_delete_file( $backup );
					if ( ! is_file( $backup ) ) {
						++$deleted;
					}
				}
			}
		}
		$uploads = wp_get_upload_dir();
		$dir     = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'nbh-performance-originals/' . $attachment_id );
		if ( is_dir( $dir ) ) {
			\NBH\Performance\Modules\Core\Filesystem::rmdir( $dir );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		delete_post_meta( $attachment_id, self::META_KEY );
		return $deleted;
	}

	public function has_backup( $attachment_id ) {
		$manifest = get_post_meta( absint( $attachment_id ), self::META_KEY, true );
		return is_array( $manifest ) && ! empty( $manifest['files'] );
	}


	private function protect_root( $root ) {
		$root  = wp_normalize_path( $root );
		$files = array(
			'index.php'  => '<?php
// Silence is golden.
',
			'.htaccess'  => 'Options -Indexes
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Deny from all
</IfModule>
',
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $root ) . $name;
			if ( ! is_file( $path ) ) {
				\NBH\Performance\Modules\Core\Filesystem::put_contents( $path, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	private function valid_backup( $backup, array $record ) {
		return empty( $record['sha256'] ) || hash_equals( (string) $record['sha256'], (string) hash_file( 'sha256', $backup ) );
	}

	private function inside_uploads( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		return 0 === strpos( wp_normalize_path( $path ), $base . '/' );
	}

	private function relative_upload_path( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		return ltrim( substr( wp_normalize_path( $path ), strlen( $base ) ), '/' );
	}
}
