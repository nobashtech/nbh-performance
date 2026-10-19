<?php
/** Versioned cleanup transaction journal and recovery record. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

final class CleanupJournal {
	const OPTION = 'nbh_performance_database_cleanup_journal_v2';
	const LIMIT  = 30;

	public function start( $user_id, array $operations, $backup_id, array $preview ) {
		$id     = 'run_' . str_replace( '-', '', wp_generate_uuid4() );
		$record = array(
			'id'             => $id,
			'status'         => 'running',
			'user_id'        => (int) $user_id,
			'operations'     => array_values( $operations ),
			'backup_id'      => sanitize_key( $backup_id ),
			'preview_before' => $preview,
			'result'         => array(),
			'started_at'     => time(),
		);
		$this->save( $record );
		return $record;
	}

	public function complete( $id, array $result, array $remaining ) {
		return $this->update(
			$id,
			array(
				'status'        => 'completed',
				'result'        => $result,
				'preview_after' => $remaining,
				'completed_at'  => time(),
			)
		);
	}

	public function fail( $id, $message ) {
		return $this->update(
			$id,
			array(
				'status'    => 'failed',
				'error'     => sanitize_text_field( $message ),
				'failed_at' => time(),
			)
		);
	}
	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}
	public function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}
	private function save( array $record ) {
		$all                  = $this->all();
		$all[ $record['id'] ] = $record;
		$all                  = array_slice( $all, -self::LIMIT, null, true );
		update_option( self::OPTION, $all, false );
	}
	private function update( $id, array $changes ) {
		$all = $this->all();
		if ( empty( $all[ $id ] ) ) {
			return null;
		}
		$all[ $id ] = array_merge( $all[ $id ], $changes, array( 'updated_at' => time() ) );
		update_option( self::OPTION, $all, false );
		return $all[ $id ];
	}
}
