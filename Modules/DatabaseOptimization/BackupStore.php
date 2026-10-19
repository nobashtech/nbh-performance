<?php
/** Durable database backup metadata store with artifact retention. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

use NBH\Performance\Modules\Core\DataOwnershipRegistry;

final class BackupStore {
	const OPTION = 'nbh_performance_database_backups_v2';
	const LIMIT  = 20;

	public function create( array $record ) {
		$id                   = isset( $record['id'] ) ? sanitize_key( $record['id'] ) : 'db_' . str_replace( '-', '', wp_generate_uuid4() );
		$record['id']         = $id;
		$record['created_at'] = isset( $record['created_at'] ) ? absint( $record['created_at'] ) : time();
		$record['status']     = isset( $record['status'] ) ? sanitize_key( $record['status'] ) : 'requested';
		$records              = $this->all();
		$records[ $id ]       = $record;
		$this->persist_with_retention( $records );
		return $record;
	}

	public function update( $id, array $changes ) {
		$records = $this->all();
		$id      = sanitize_key( $id );
		if ( empty( $records[ $id ] ) ) {
			return null;
		}
		$records[ $id ] = array_merge( $records[ $id ], $changes, array( 'updated_at' => time() ) );
		$this->persist_with_retention( $records );
		return $records[ $id ];
	}

	public function get( $id ) {
		$records = $this->all();
		$id      = sanitize_key( $id );
		return isset( $records[ $id ] ) && is_array( $records[ $id ] ) ? $records[ $id ] : null;
	}

	public function latest() {
		$records = $this->all();
		if ( empty( $records ) ) {
			return null;
		}
		return end( $records );
	}

	public function all() {
		$records = get_option( self::OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	public function public_record( array $record ) {
		unset( $record['path'], $record['manifest_path'] );
		return $record;
	}

	/** @param array<string,array<string,mixed>> $records Records. */
	private function persist_with_retention( array $records ) {
		$expired       = array_slice( $records, 0, max( 0, count( $records ) - self::LIMIT ), true );
		$keep          = array_slice( $records, -self::LIMIT, null, true );
		$delete_failed = array();
		foreach ( $expired as $id => $record ) {
			if ( $this->delete_artifacts( $record ) ) {
				continue;
			}
			$record['delete_pending'] = true;
			$delete_failed[ $id ]     = $record;
		}
		update_option( self::OPTION, array_merge( $delete_failed, $keep ), false );
	}

	/** @param array<string,mixed> $record Record. */
	private function delete_artifacts( array $record ) {
		$success = true;
		$root    = wp_normalize_path( trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'database-backups' );
		foreach ( array( 'path', 'manifest_path' ) as $key ) {
			if ( empty( $record[ $key ] ) ) {
				continue;
			}
			$file = wp_normalize_path( (string) $record[ $key ] );
			if ( 0 !== strpos( $file, trailingslashit( $root ) ) ) {
				$success = false;
				continue;
			}
			if ( ! file_exists( $file ) ) {
				continue;
			}
			if ( ! is_file( $file ) || is_link( $file ) ) {
				$success = false;
				continue;
			}
			wp_delete_file( $file );
			if ( file_exists( $file ) ) {
				$success = false;
			}
		}
		return $success;
	}
}
