<?php
/** Verified database backup orchestration and integration contract. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

use WP_Error;

final class BackupCoordinator {
	private $store;
	private $native;

	public function __construct( BackupStore $store, NativeSqlBackup $native ) {
		$this->store  = $store;
		$this->native = $native;
	}

	public function create( $user_id, array $preview, $provider = 'auto' ) {
		$record = $this->store->create(
			array(
				'user_id'  => (int) $user_id,
				'provider' => sanitize_key( $provider ),
				'status'   => 'creating',
				'preview'  => $preview,
			)
		);

		do_action( 'nbh_performance_before_database_backup', $record );
		$external = apply_filters( 'nbh_performance_create_database_backup', null, $record, $preview );
		if ( is_array( $external ) ) {
			$result = $external;
		} elseif ( is_wp_error( $external ) ) {
			$result = $external;
		} else {
			$result = $this->native->create( $record['id'] );
		}

		if ( is_wp_error( $result ) ) {
			$this->store->update(
				$record['id'],
				array(
					'status' => 'failed',
					'error'  => $result->get_error_message(),
				)
			);
			return $result;
		}

		$verified = ! empty( $result['verified'] ) && ! empty( $result['sha256'] ) && ! empty( $result['size_bytes'] );
		if ( ! $verified ) {
			$error = new WP_Error( 'nbh_database_backup_unverified', __( 'The backup provider did not return a verified backup artifact.', 'nbh-performance' ) );
			$this->store->update(
				$record['id'],
				array(
					'status' => 'failed',
					'error'  => $error->get_error_message(),
				)
			);
			return $error;
		}

		$record = $this->store->update(
			$record['id'],
			array_merge(
				$result,
				array(
					'status'      => 'verified',
					'verified_at' => time(),
				)
			)
		);
		do_action( 'nbh_performance_after_database_backup', $record );
		return $record;
	}

	public function verified( $backup_id, $user_id = 0 ) {
		$record = $this->store->get( $backup_id );
		if ( ! is_array( $record ) || 'verified' !== ( $record['status'] ?? '' ) || empty( $record['sha256'] ) || empty( $record['size_bytes'] ) || empty( $record['path'] ) ) {
			return false;
		}
		if ( $user_id && ! current_user_can( 'manage_options' ) && (int) ( $record['user_id'] ?? 0 ) !== (int) $user_id ) {
			return false;
		}
		$max_age = max( HOUR_IN_SECONDS, absint( get_option( 'nbh_performance_database_backup_max_age', DAY_IN_SECONDS ) ) );
		if ( empty( $record['verified_at'] ) || ( time() - (int) $record['verified_at'] ) > $max_age ) {
			return false;
		}
		return $this->native->verify_artifact( $record['path'], $record['sha256'], $record['size_bytes'] );
	}

	public function get( $backup_id ) {
		return $this->store->get( $backup_id );
	}
	public function latest() {
		return $this->store->latest();
	}
	public function public_record( array $record ) {
		return $this->store->public_record( $record );
	}
	public function before_cleanup( array $operations, $backup_id ) {
		do_action( 'nbh_performance_before_database_cleanup', $operations, $backup_id );
	}
	public function after_cleanup( array $result, $backup_id ) {
		do_action( 'nbh_performance_after_database_cleanup', $result, $backup_id );
	}
}
