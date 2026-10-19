<?php
/** Creates time-limited, privacy-safe support bundles. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Core\DataOwnershipRegistry;

final class SupportBundleManager {
	private $exporter;
	private $logger;
	private $health;

	public function __construct( SupportReportExporter $exporter, OperationalLogger $logger, OperationsHealthMonitor $health ) {
		$this->exporter = $exporter;
		$this->logger   = $logger;
		$this->health   = $health;
	}

	public function create() {
		$dir = $this->directory();
		if ( ! wp_mkdir_p( $dir ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $dir ) ) {
			return new \WP_Error( 'nbh_support_bundle_storage', 'Support bundle directory is not writable.' );
		}
		$this->protect_directory( $dir );
		$id      = wp_generate_uuid4();
		$payload = array(
			'manifest' => array(
				'bundle_id'  => $id,
				'created_at' => gmdate( 'c' ),
				'expires_at' => gmdate( 'c', time() + DAY_IN_SECONDS ),
				'format'     => 1,
			),
			'report'   => $this->exporter->build(),
			'health'   => $this->health->snapshot(),
			'logs'     => $this->logger->recent( 150 ),
		);
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new \WP_Error( 'nbh_support_bundle_encode', 'Support bundle could not be encoded.' );
		}
		$file = trailingslashit( $dir ) . 'support-' . sanitize_file_name( $id ) . '.json';
		$tmp  = $file . '.tmp-' . wp_generate_password( 8, false, false );
		if ( ! \NBH\Performance\Modules\Core\Filesystem::put_contents( $tmp, $json, 0600 ) ) {
			return new \WP_Error( 'nbh_support_bundle_write', 'Support bundle could not be written.' );
		}
		\NBH\Performance\Modules\Core\Filesystem::chmod( $tmp, 0600 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. Atomic same-filesystem rename is required for publication; WP_Filesystem::move() can unlink/copy and is not an atomic commit. Failure is checked before accepting the new artifact.
		if ( ! @rename( $tmp, $file ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $tmp );
			return new \WP_Error( 'nbh_support_bundle_publish', 'Support bundle could not be published.' );
		}
		\NBH\Performance\Modules\Core\Filesystem::chmod( $file, 0600 );
		$token          = wp_generate_password( 48, false, false );
		$record         = array(
			'id'         => $id,
			'file'       => $file,
			'sha256'     => hash_file( 'sha256', $file ),
			'bytes'      => filesize( $file ),
			'created_at' => time(),
			'expires_at' => time() + DAY_IN_SECONDS,
			'token_hash' => hash( 'sha256', $token ),
		);
		$records        = $this->records();
		$records[ $id ] = $record;
		update_option( 'nbh_performance_support_bundles', $records, false );
		$this->logger->log(
			'support',
			'support_bundle_created',
			array(
				'bundle_id' => $id,
				'bytes'     => $record['bytes'],
			)
		);
		return array(
			'id'         => $id,
			'token'      => $token,
			'sha256'     => $record['sha256'],
			'bytes'      => $record['bytes'],
			'expires_at' => $record['expires_at'],
		);
	}

	public function resolve( $id, $token ) {
		$records = $this->records();
		if ( ! isset( $records[ $id ] ) || ! is_array( $records[ $id ] ) ) {
			return new \WP_Error( 'nbh_support_bundle_missing', 'Support bundle was not found.' );
		}
		$record = $records[ $id ];
		if ( time() > absint( $record['expires_at'] ) || ! hash_equals( (string) $record['token_hash'], hash( 'sha256', (string) $token ) ) ) {
			return new \WP_Error( 'nbh_support_bundle_forbidden', 'Support bundle token is invalid or expired.' );
		}
		$file = isset( $record['file'] ) ? (string) $record['file'] : '';
		if ( ! $this->inside_directory( $file ) || ! is_file( $file ) || ! hash_equals( (string) $record['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
			return new \WP_Error( 'nbh_support_bundle_integrity', 'Support bundle integrity verification failed.' );
		}
		return $record;
	}

	public function cleanup() {
		$records = $this->records();
		$kept    = array();
		$deleted = 0;
		foreach ( $records as $id => $record ) {
			if ( ! is_array( $record ) ) {
				++$deleted;
				continue;
			}
			if ( time() > absint( isset( $record['expires_at'] ) ? $record['expires_at'] : 0 ) ) {
				if ( empty( $record['file'] ) || $this->safe_delete( $record['file'] ) ) {
					++$deleted;
					continue;
				}
				$record['delete_pending'] = true;
			}
			$kept[ $id ] = $record;
		}
		update_option( 'nbh_performance_support_bundles', $kept, false );
		return $deleted;
	}

	private function records() {
		$records = get_option( 'nbh_performance_support_bundles', array() );
		return is_array( $records ) ? $records : array();
	}
	private function directory() {
		return trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'support-bundles';
	}
	private function protect_directory( $dir ) {
		if ( ! is_file( trailingslashit( $dir ) . 'index.php' ) ) {
			\NBH\Performance\Modules\Core\Filesystem::put_contents( trailingslashit( $dir ) . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! is_file( trailingslashit( $dir ) . '.htaccess' ) ) {
			\NBH\Performance\Modules\Core\Filesystem::put_contents( trailingslashit( $dir ) . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! is_file( trailingslashit( $dir ) . 'web.config' ) ) {
			\NBH\Performance\Modules\Core\Filesystem::put_contents( trailingslashit( $dir ) . 'web.config', '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
	}
	private function inside_directory( $file ) {
		$root = wp_normalize_path( trailingslashit( $this->directory() ) );
		$file = wp_normalize_path( (string) $file );
		return 0 === strpos( $file, $root );
	}

	private function safe_delete( $file ) {
		$file = wp_normalize_path( (string) $file );
		if ( ! $this->inside_directory( $file ) ) {
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
}
