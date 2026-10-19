<?php
/**
 * Creates and retains verified local rollback archives before NBH updates.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Licensing;

use NBH\Performance\Modules\Core\DataOwnershipRegistry;
use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Licensing\SignedPayload;
use NBH\Performance\Modules\Licensing\SigningKeyStore;

final class UpdateRollbackManager implements Module {
	const OPTION    = 'nbh_performance_update_rollback_archives';
	const RETENTION = 3;

	public function register() {
		add_filter( 'upgrader_pre_install', array( $this, 'snapshot_before_update_without_blocking' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'cleanup' ), 20, 2 );
		add_action( 'nbh_performance_restore_latest_update', array( $this, 'restore_latest' ), 10, 1 );
		add_action( 'nbh_performance_cleanup_rollback_work', array( $this, 'cleanup_work_directories' ) );
	}

	/**
	 * Optional rollback snapshots must never block the standard WordPress updater.
	 * Automatic snapshots are disabled on fresh installs and require administrator opt-in.
	 * A failed snapshot is recorded separately; the official update still proceeds.
	 *
	 * @param mixed $response Standard upgrader response.
	 * @param array $hook_extra WordPress updater metadata.
	 * @return mixed
	 */
	public function snapshot_before_update_without_blocking( $response, $hook_extra ) {
		if ( ! get_option( 'nbh_performance_update_auto_rollback_enabled', 0 ) ) {
			return $response;
		}
		$result = $this->backup_before_update( $response, $hook_extra );
		if ( is_wp_error( $result ) ) {
			update_option( 'nbh_performance_last_rollback_snapshot_error', sanitize_text_field( $result->get_error_code() ), false );
			return $response;
		}
		delete_option( 'nbh_performance_last_rollback_snapshot_error' );
		return $result;
	}

	public function backup_before_update( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || empty( $hook_extra['plugin'] ) || NBH_PERFORMANCE_BASENAME !== $hook_extra['plugin'] ) {
			return $response;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'nbh_performance_rollback_zip_missing', __( 'The update was stopped because ZipArchive is unavailable for the rollback snapshot.', 'nbh-performance' ) );
		}

		$base = $this->directory();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$required = $this->directory_size( NBH_PERFORMANCE_PATH );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$free = @disk_free_space( $base );
		if ( false !== $free && $free < max( 32 * MB_IN_BYTES, (int) ceil( $required * 1.25 ) ) ) {
			return new \WP_Error( 'nbh_performance_rollback_disk_space', __( 'The update was stopped because there is not enough free disk space for a verified rollback archive.', 'nbh-performance' ) );
		}

		$file = trailingslashit( $base ) . 'nbh-performance-' . gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_password( 12, false, false ), 0, 12 ) . '.zip';
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) {
			return new \WP_Error( 'nbh_performance_rollback_archive', __( 'The update was stopped because the rollback archive could not be created.', 'nbh-performance' ) );
		}

		$root     = untrailingslashit( NBH_PERFORMANCE_PATH );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$added    = 0;
		foreach ( $iterator as $entry ) {
			if ( ! $entry->isFile() || $entry->isLink() ) {
				continue;
			}
			$path     = $entry->getPathname();
			$relative = 'nbh-performance/' . ltrim( str_replace( $root, '', $path ), '/\\' );
			if ( ! $zip->addFile( $path, $relative ) ) {
				$zip->close();
				$this->safe_delete( $file );
				return new \WP_Error( 'nbh_performance_rollback_add_failed', __( 'The update was stopped because a plugin file could not be added to the rollback archive.', 'nbh-performance' ) );
			}
			++$added;
		}
		if ( $added < 1 || ! $zip->close() || ! $this->verify_archive( $file ) ) {
			$this->safe_delete( $file );
			return new \WP_Error( 'nbh_performance_rollback_invalid', __( 'The update was stopped because the rollback archive failed validation.', 'nbh-performance' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $file, 0600 );

		$items = $this->records();
		array_unshift(
			$items,
			array(
				'file'       => wp_normalize_path( $file ),
				'sha256'     => hash_file( 'sha256', $file ),
				'size_bytes' => (int) filesize( $file ),
				'created_at' => time(),
				'version'    => NBH_PERFORMANCE_VERSION,
				'build'      => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
			)
		);
		$this->persist_and_prune( $items );
		return $response;
	}

	public function cleanup( $upgrader, $options ) {
		unset( $upgrader );
		if ( ! $this->is_nbh_update( $options ) ) {
			return;
		}
		$this->persist_and_prune( $this->records() );
		$this->remove_orphans();
	}

	/** Schedule a verified restore outside the current validation request. */
	public function schedule_latest_restore( $reason = 'automatic' ) {
		if ( get_transient( 'nbh_performance_update_restore_scheduled' ) ) {
			return true;
		}
		$records = $this->records();
		if ( empty( $records ) ) {
			return false;
		}
		$reason    = sanitize_key( (string) $reason );
		$scheduled = wp_schedule_single_event( time() + 5, 'nbh_performance_restore_latest_update', array( $reason ) );
		if ( false !== $scheduled ) {
			set_transient( 'nbh_performance_update_restore_scheduled', 1, 15 * MINUTE_IN_SECONDS );
		}
		return false !== $scheduled;
	}

	/** Restore the newest verified pre-update archive with an atomic directory swap. */
	public function restore_latest( $reason = 'automatic' ) {
		delete_transient( 'nbh_performance_update_restore_scheduled' );
		if ( get_transient( 'nbh_performance_update_restore_running' ) ) {
			return false;
		}
		set_transient( 'nbh_performance_update_restore_running', 1, 15 * MINUTE_IN_SECONDS );
		$reason  = sanitize_key( (string) $reason );
		$records = $this->records();
		$target  = $this->select_restore_record( $records );
		if ( is_wp_error( $target ) ) {
			$this->record_restore_result( false, $reason, $target->get_error_code(), $target->get_error_message() );
			delete_transient( 'nbh_performance_update_restore_running' );
			return false;
		}

		// Preserve the currently installed files before replacing them.
		$snapshot = $this->backup_before_update( true, array( 'plugin' => NBH_PERFORMANCE_BASENAME ) );
		if ( is_wp_error( $snapshot ) ) {
			$this->record_restore_result( false, $reason, $snapshot->get_error_code(), $snapshot->get_error_message() );
			delete_transient( 'nbh_performance_update_restore_running' );
			return false;
		}

		$work = $this->prepare_restore_directory();
		if ( is_wp_error( $work ) ) {
			$this->record_restore_result( false, $reason, $work->get_error_code(), $work->get_error_message() );
			delete_transient( 'nbh_performance_update_restore_running' );
			return false;
		}
		$extracted = $this->extract_verified_archive( $target, $work );
		if ( is_wp_error( $extracted ) ) {
			$this->recursive_delete( $work );
			$this->record_restore_result( false, $reason, $extracted->get_error_code(), $extracted->get_error_message() );
			delete_transient( 'nbh_performance_update_restore_running' );
			return false;
		}

		$current = wp_normalize_path( untrailingslashit( NBH_PERFORMANCE_PATH ) );
		$holding = wp_normalize_path( trailingslashit( dirname( $work ) ) . 'replaced-' . wp_generate_uuid4() );
		$success = false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		if ( @rename( $current, $holding ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $extracted, $current ) ) {
				$success = true;
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				@rename( $holding, $current );
			}
		}

		if ( $success ) {
			$this->invalidate_opcache_tree( $current );
			delete_transient( 'nbh_performance_integrity_verification' );
			update_option( 'nbh_performance_safe_mode', 1, false );
			update_option( 'nbh_performance_cache_enabled', 0, false );
			$this->record_restore_result( true, $reason, 'restored', __( 'The previous verified NBH Performance build was restored and Safe Mode was activated.', 'nbh-performance' ), $target );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'nbh_performance_cleanup_rollback_work' );
		} else {
			$this->record_restore_result( false, $reason, 'atomic_swap_failed', __( 'The verified rollback files could not replace the active plugin directory. The active build was left unchanged.', 'nbh-performance' ) );
		}
		$this->recursive_delete( $work );
		if ( $success ) {
			$this->recursive_delete( $holding );
		}
		delete_transient( 'nbh_performance_update_restore_running' );
		return $success;
	}

	/** Remove abandoned restore work directories from previous interrupted attempts. */
	public function cleanup_work_directories() {
		$root = trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'update-rollback-work';
		if ( ! is_dir( $root ) ) {
			return;
		}
		foreach ( (array) glob( trailingslashit( $root ) . '*' ) as $path ) {
			if ( is_dir( $path ) && filemtime( $path ) < time() - HOUR_IN_SECONDS ) {
				$this->recursive_delete( $path );
			}
		}
	}

	/** Privacy-safe rollback summary for Hub. @return array<string,mixed> */
	public function hub_status() {
		$archives = array();
		foreach ( array_slice( $this->records(), 0, self::RETENTION ) as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$archives[] = array(
				'version'    => isset( $record['version'] ) ? sanitize_text_field( (string) $record['version'] ) : '',
				'build'      => isset( $record['build'] ) ? sanitize_text_field( (string) $record['build'] ) : '',
				'created_at' => isset( $record['created_at'] ) ? absint( $record['created_at'] ) : 0,
				'size_bytes' => isset( $record['size_bytes'] ) ? absint( $record['size_bytes'] ) : 0,
				'sha256'     => isset( $record['sha256'] ) ? substr( sanitize_text_field( (string) $record['sha256'] ), 0, 16 ) : '',
			);
		}
		$last = (array) get_option( 'nbh_performance_last_update_rollback', array() );
		return array(
			'archives'          => $archives,
			'count'             => count( $this->records() ),
			'restore_scheduled' => (bool) get_transient( 'nbh_performance_update_restore_scheduled' ),
			'restore_running'   => (bool) get_transient( 'nbh_performance_update_restore_running' ),
			'last_restore'      => array(
				'success'        => ! empty( $last['success'] ),
				'reason'         => isset( $last['reason'] ) ? sanitize_key( (string) $last['reason'] ) : '',
				'code'           => isset( $last['code'] ) ? sanitize_key( (string) $last['code'] ) : '',
				'message'        => isset( $last['message'] ) ? sanitize_text_field( (string) $last['message'] ) : '',
				'restored_build' => isset( $last['restored_build'] ) ? sanitize_text_field( (string) $last['restored_build'] ) : '',
				'completed_at'   => isset( $last['completed_at'] ) ? absint( $last['completed_at'] ) : 0,
			),
		);
	}

	/** Queue a manual restore of the newest verified archive. @return array<string,mixed>|\WP_Error */
	public function request_manual_restore() {
		if ( empty( $this->records() ) ) {
			return new \WP_Error( 'nbh_performance_restore_archive_missing', __( 'No verified rollback archive is available.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		if ( get_transient( 'nbh_performance_update_restore_running' ) ) {
			return new \WP_Error( 'nbh_performance_restore_busy', __( 'A rollback restore is already running.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$scheduled = $this->schedule_latest_restore( 'manual' );
		if ( ! $scheduled && ! get_transient( 'nbh_performance_update_restore_scheduled' ) ) {
			return new \WP_Error( 'nbh_performance_restore_schedule_failed', __( 'Unable to schedule the rollback restore.', 'nbh-performance' ), array( 'status' => 500 ) );
		}
		return array(
			'success'   => true,
			'scheduled' => true,
			'message'   => __( 'Rollback restore scheduled. Safe Mode will activate when the previous build is restored.', 'nbh-performance' ),
			'status'    => $this->hub_status(),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function select_restore_record( array $records ) {
		$base = wp_normalize_path( trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'update-rollbacks' );
		foreach ( $records as $record ) {
			if ( empty( $record['file'] ) || empty( $record['sha256'] ) || empty( $record['size_bytes'] ) ) {
				continue;
			}
			$file = wp_normalize_path( (string) $record['file'] );
			if ( 0 !== strpos( $file, trailingslashit( $base ) ) || ! is_file( $file ) || is_link( $file ) || (int) filesize( $file ) !== (int) $record['size_bytes'] ) {
				continue;
			}
			$hash = hash_file( 'sha256', $file );
			if ( is_string( $hash ) && hash_equals( strtolower( (string) $record['sha256'] ), strtolower( $hash ) ) ) {
				return $record;
			}
		}
		return new \WP_Error( 'nbh_performance_restore_archive_missing', __( 'No verified rollback archive is available.', 'nbh-performance' ) );
	}

	/** @return string|\WP_Error */
	private function prepare_restore_directory() {
		$root = trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'update-rollback-work';
		if ( ! wp_mkdir_p( $root ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $root ) ) {
			return new \WP_Error( 'nbh_performance_restore_work_directory', __( 'The private rollback work directory is unavailable.', 'nbh-performance' ) );
		}
		foreach ( array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n",
		) as $name => $contents ) {
			$protection = trailingslashit( $root ) . $name;
			if ( ! is_file( $protection ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
				\NBH\Performance\Modules\Core\Filesystem::put_contents( $protection, $contents );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		$work = trailingslashit( $root ) . 'restore-' . wp_generate_uuid4();
		if ( ! wp_mkdir_p( $work ) ) {
			return new \WP_Error( 'nbh_performance_restore_work_create', __( 'The rollback work directory could not be created.', 'nbh-performance' ) );
		}
		return wp_normalize_path( $work );
	}

	/** @return string|\WP_Error Extracted plugin root. */
	private function extract_verified_archive( array $record, $work ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'nbh_performance_restore_zip_missing', __( 'ZipArchive is required to restore a verified plugin snapshot.', 'nbh-performance' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $record['file'] ) ) {
			return new \WP_Error( 'nbh_performance_restore_zip_invalid', __( 'The rollback archive could not be opened.', 'nbh-performance' ) );
		}
		$total = 0;
		$seen  = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive::numFiles is the native extension property name and cannot be renamed.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = is_array( $stat ) && isset( $stat['name'] ) ? str_replace( '\\', '/', (string) $stat['name'] ) : '';
			if ( '' === $name || isset( $seen[ $name ] ) || 0 !== strpos( $name, 'nbh-performance/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || 0 === strpos( $name, '/' ) || $this->zip_entry_is_symlink( $zip, $i ) ) {
				$zip->close();
				return new \WP_Error( 'nbh_performance_restore_zip_unsafe', __( 'The rollback archive contains an unsafe path or link.', 'nbh-performance' ) );
			}
			$seen[ $name ] = true;
			$total        += isset( $stat['size'] ) ? max( 0, (int) $stat['size'] ) : 0;
			if ( count( $seen ) > 5000 || $total > 64 * MB_IN_BYTES ) {
				$zip->close();
				return new \WP_Error( 'nbh_performance_restore_zip_limits', __( 'The rollback archive exceeds the safety limits.', 'nbh-performance' ) );
			}
		}
		if ( ! isset( $seen['nbh-performance/nbh-performance.php'], $seen['nbh-performance/release-integrity.json'] ) || ! $zip->extractTo( $work ) ) {
			$zip->close();
			return new \WP_Error( 'nbh_performance_restore_extract_failed', __( 'The rollback archive could not be extracted safely.', 'nbh-performance' ) );
		}
		$zip->close();
		$root     = wp_normalize_path( trailingslashit( $work ) . 'nbh-performance' );
		$verified = $this->verify_extracted_release( $root );
		return is_wp_error( $verified ) ? $verified : $root;
	}

	/** Verify the signed complete file map in an extracted release. */
	private function verify_extracted_release( $root ) {
		$manifest_file = trailingslashit( $root ) . 'release-integrity.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = is_readable( $manifest_file ) ? json_decode( (string) \NBH\Performance\Modules\Core\Filesystem::get_contents( $manifest_file ), true ) : null;
		if ( ! is_array( $manifest ) || empty( $manifest['manifest_signature'] ) || empty( $manifest['key_id'] ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return new \WP_Error( 'nbh_performance_restore_manifest_invalid', __( 'The rollback release manifest is invalid.', 'nbh-performance' ) );
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new \WP_Error( 'nbh_performance_restore_sodium_missing', __( 'The rollback signature cannot be verified because PHP Sodium is unavailable.', 'nbh-performance' ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode cryptographic public-key/signature bytes for integrity verification; decoded data is never evaluated as code.
		$signature = base64_decode( (string) $manifest['manifest_signature'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode cryptographic public-key/signature bytes for integrity verification; decoded data is never evaluated as code.
		$public = base64_decode( SigningKeyStore::public_key( 'update', (string) $manifest['key_id'] ), true );
		$signed = $manifest;
		unset( $signed['manifest_signature'] );
		if ( false === $signature || false === $public || 64 !== strlen( $signature ) || 32 !== strlen( $public ) || ! sodium_crypto_sign_verify_detached( $signature, SignedPayload::canonical_json( $signed ), $public ) ) {
			return new \WP_Error( 'nbh_performance_restore_manifest_signature', __( 'The rollback release signature is invalid.', 'nbh-performance' ) );
		}
		$expected = array( 'release-integrity.json' => true );
		foreach ( $manifest['files'] as $relative => $hash ) {
			$relative = str_replace( '\\', '/', trim( (string) $relative ) );
			if ( '' === $relative || preg_match( '#(^|/)\.\.(/|$)#', $relative ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $hash ) ) {
				return new \WP_Error( 'nbh_performance_restore_file_map', __( 'The rollback release file map is unsafe.', 'nbh-performance' ) );
			}
			$file   = trailingslashit( $root ) . $relative;
			$actual = is_file( $file ) && ! is_link( $file ) ? hash_file( 'sha256', $file ) : false;
			if ( ! is_string( $actual ) || ! hash_equals( strtolower( (string) $hash ), strtolower( $actual ) ) ) {
				return new \WP_Error( 'nbh_performance_restore_file_hash', __( 'A rollback release file failed integrity verification.', 'nbh-performance' ) );
			}
			$expected[ $relative ] = true;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $iterator as $entry ) {
			$relative = ltrim( substr( wp_normalize_path( $entry->getPathname() ), strlen( wp_normalize_path( $root ) ) ), '/' );
			if ( $entry->isLink() || ! $entry->isFile() || ! isset( $expected[ $relative ] ) ) {
				return new \WP_Error( 'nbh_performance_restore_unexpected_file', __( 'The rollback release contains an unsigned file.', 'nbh-performance' ) );
			}
		}
		return true;
	}

	private function record_restore_result( $success, $reason, $code, $message, array $record = array() ) {
		update_option(
			'nbh_performance_last_update_rollback',
			array(
				'success'        => (bool) $success,
				'reason'         => sanitize_key( $reason ),
				'code'           => sanitize_key( $code ),
				'message'        => sanitize_text_field( $message ),
				'restored_build' => isset( $record['build'] ) ? sanitize_text_field( $record['build'] ) : '',
				'completed_at'   => time(),
			),
			false
		);
	}

	private function invalidate_opcache_tree( $root ) {
		if ( ! function_exists( 'opcache_invalidate' ) || ! is_dir( $root ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry->isFile() && 'php' === strtolower( $entry->getExtension() ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort invalidation of an already verified owned PHP file; unavailable or disabled opcode invalidation must not break recovery.
				@opcache_invalidate( $entry->getPathname(), true );
			}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private function recursive_delete( $path ) {
		$path = wp_normalize_path( (string) $path );
		$base = wp_normalize_path( trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'update-rollback-work' );
		if ( '' === $path || 0 !== strpos( $path, trailingslashit( $base ) ) || ! file_exists( $path ) ) {
			return false;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $path );
			return ! file_exists( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() || $entry->isFile() ) {
				\NBH\Performance\Modules\Core\Filesystem::unlink( $entry->getPathname() );
			} else {
				\NBH\Performance\Modules\Core\Filesystem::rmdir( $entry->getPathname() );
			}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::rmdir( $path );
		return ! file_exists( $path );
	}

	/** @return string|\WP_Error */
	private function directory() {
		$base = trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'update-rollbacks';
		if ( ! wp_mkdir_p( $base ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $base ) ) {
			return new \WP_Error( 'nbh_performance_rollback_directory', __( 'The update was stopped because the rollback directory could not be created.', 'nbh-performance' ) );
		}
		$protection = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $protection as $name => $contents ) {
			$path = trailingslashit( $base ) . $name;
			if ( ! is_file( $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				\NBH\Performance\Modules\Core\Filesystem::put_contents( $path, $contents );
			}
		}
		return wp_normalize_path( $base );
	}

	/** @return array<int,array<string,mixed>> */
	private function records() {
		$items = get_site_option( self::OPTION, array() );
		return is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
	}

	/** @param array<int,array<string,mixed>> $items Records. */
	private function persist_and_prune( array $items ) {
		$keep          = array_slice( $items, 0, self::RETENTION );
		$expired       = array_slice( $items, self::RETENTION );
		$delete_failed = array();

		foreach ( $expired as $item ) {
			if ( empty( $item['file'] ) || $this->safe_delete( $item['file'] ) ) {
				continue;
			}

			// Keep failed deletions tracked so a later cleanup can retry safely.
			$item['delete_pending'] = true;
			$delete_failed[]        = $item;
		}

		update_site_option( self::OPTION, array_merge( $keep, $delete_failed ) );
	}

	private function remove_orphans() {
		$base = $this->directory();
		if ( is_wp_error( $base ) ) {
			return;
		}
		$known = array();
		foreach ( $this->records() as $item ) {
			if ( ! empty( $item['file'] ) ) {
				$known[ wp_normalize_path( $item['file'] ) ] = true;
			}
		}
		$files = glob( trailingslashit( $base ) . 'nbh-performance-*.zip' );
		foreach ( (array) $files as $file ) {
			$normalized = wp_normalize_path( $file );
			if ( ! isset( $known[ $normalized ] ) && filemtime( $file ) < ( time() - DAY_IN_SECONDS ) ) {
				$this->safe_delete( $file );
			}
		}
	}

	private function verify_archive( $file ) {
		if ( ! is_file( $file ) || filesize( $file ) < 1024 ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return false;
		}
		$valid = false !== $zip->locateName( 'nbh-performance/nbh-performance.php', \ZipArchive::FL_NOCASE ) && false !== $zip->locateName( 'nbh-performance/release-integrity.json', \ZipArchive::FL_NOCASE );
		$zip->close();
		return $valid;
	}

	private function safe_delete( $file ) {
		$base = wp_normalize_path( trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'update-rollbacks' );
		$file = wp_normalize_path( (string) $file );

		if ( 0 !== strpos( $file, trailingslashit( $base ) ) ) {
			return false;
		}
		if ( ! file_exists( $file ) ) {
			return true;
		}
		if ( ! is_file( $file ) || is_link( $file ) ) {
			return false;
		}

		wp_delete_file( $file );
		return ! file_exists( $file );
	}

	private function directory_size( $directory ) {
		$size     = 0;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry->isFile() && ! $entry->isLink() ) {
				$size += (int) $entry->getSize();
			}
		}
		return $size;
	}

	private function is_nbh_update( $options ) {
		if ( ! is_array( $options ) || ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return false;
		}
		if ( isset( $options['plugin'] ) ) {
			return NBH_PERFORMANCE_BASENAME === $options['plugin'];
		}
		return isset( $options['plugins'] ) && is_array( $options['plugins'] ) && in_array( NBH_PERFORMANCE_BASENAME, $options['plugins'], true );
	}
}
