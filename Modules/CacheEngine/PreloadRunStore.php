<?php
/** Durable preload run state and bounded diagnostics. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

final class PreloadRunStore {
	const OPTION         = 'nbh_performance_preload_run';
	const HISTORY_OPTION = 'nbh_performance_preload_history';

	public function start( $source, $total = 0 ) {
		$run = array(
			'id'           => wp_generate_uuid4(),
			'source'       => sanitize_key( $source ),
			'status'       => 'running',
			'total'        => absint( $total ),
			'queued'       => 0,
			'completed'    => 0,
			'failed'       => 0,
			'skipped'      => 0,
			'started_at'   => time(),
			'updated_at'   => time(),
			'completed_at' => 0,
			'last_url'     => '',
			'last_error'   => '',
		);
		update_option( self::OPTION, $run, false );
		return $run;
	}

	public function current() {
		$run = get_option( self::OPTION, array() );
		return is_array( $run ) ? $run : array();
	}

	public function mark_queued( $count ) {
		return $this->increment( 'queued', absint( $count ) );
	}
	public function mark_completed( $url ) {
		return $this->record( 'completed', $url, '' );
	}
	public function mark_failed( $url, $error ) {
		return $this->record( 'failed', $url, $error );
	}
	public function mark_skipped( $url, $reason ) {
		return $this->record( 'skipped', $url, $reason );
	}

	public function pause() {
		return $this->set_status( 'paused' );
	}
	public function resume() {
		return $this->set_status( 'running' );
	}
	public function cancel() {
		return $this->set_status( 'cancelled', true );
	}

	public function complete_if_finished() {
		$run = $this->current();
		if ( empty( $run ) || ! in_array( $run['status'], array( 'running', 'paused' ), true ) ) {
			return $run;
		}
		$done = absint( $run['completed'] ) + absint( $run['failed'] ) + absint( $run['skipped'] );
		if ( absint( $run['queued'] ) > 0 && $done >= absint( $run['queued'] ) ) {
			return $this->set_status( 'completed', true );
		}
		return $run;
	}

	private function increment( $field, $amount ) {
		$run = $this->current();
		if ( empty( $run ) ) {
			return $run;
		}
		$run[ $field ]     = absint( isset( $run[ $field ] ) ? $run[ $field ] : 0 ) + absint( $amount );
		$run['updated_at'] = time();
		update_option( self::OPTION, $run, false );
		return $run;
	}

	private function record( $field, $url, $error ) {
		$run = $this->current();
		if ( empty( $run ) || ! in_array( $run['status'], array( 'running', 'paused' ), true ) ) {
			return $run;
		}
		$run = $this->increment( $field, 1 );
		if ( empty( $run ) ) {
			return $run;
		}
		$run['last_url']   = esc_url_raw( (string) $url );
		$run['last_error'] = substr( sanitize_text_field( (string) $error ), 0, 500 );
		$run['updated_at'] = time();
		update_option( self::OPTION, $run, false );
		return $this->complete_if_finished();
	}

	private function set_status( $status, $terminal = false ) {
		$run = $this->current();
		if ( empty( $run ) ) {
			return $run;
		}
		$run['status']     = sanitize_key( $status );
		$run['updated_at'] = time();
		if ( $terminal ) {
			$run['completed_at'] = time();
			$history             = get_option( self::HISTORY_OPTION, array() );
			$history             = is_array( $history ) ? $history : array();
			array_unshift( $history, $run );
			update_option( self::HISTORY_OPTION, array_slice( $history, 0, 20 ), false );
		}
		update_option( self::OPTION, $run, false );
		return $run;
	}
}
