<?php
/** Conservative scheduled database cleanup with verified backup + journal. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

use NBH\Performance\Modules\Core\Module;

final class CleanupScheduler implements Module {
	private $cleanup;
	private $preview;
	private $backup;
	private $journal;

	public function __construct( CleanupManager $cleanup, DryRunPreview $preview, BackupCoordinator $backup, CleanupJournal $journal ) {
		$this->cleanup = $cleanup;
		$this->preview = $preview;
		$this->backup  = $backup;
		$this->journal = $journal;
	}

	public function register() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'nbh_performance_database_cleanup_tick', array( $this, 'run' ) );
		add_action( 'update_option_nbh_performance_database_cleanup_enabled', array( $this, 'force_reschedule' ), 10, 2 );
		add_action( 'update_option_nbh_performance_database_schedule', array( $this, 'force_reschedule' ), 10, 2 );
	}

	/** @param array<string,array<string,mixed>> $schedules Cron schedules. @return array<string,array<string,mixed>> */
	public function cron_schedules( $schedules ) {
		$schedules['nbh_weekly']  = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (NBH Performance)', 'nbh-performance' ),
		);
		$schedules['nbh_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (NBH Performance)', 'nbh-performance' ),
		);
		return $schedules;
	}

	public function schedule() {
		$enabled = (bool) get_option( 'nbh_performance_database_cleanup_enabled', 0 );
		$mode    = sanitize_key( (string) get_option( 'nbh_performance_database_schedule', 'weekly' ) );

		if ( ! $enabled || 'off' === $mode ) {
			wp_clear_scheduled_hook( 'nbh_performance_database_cleanup_tick' );
			return;
		}

		$recurrence = $this->recurrence_for_mode( $mode );
		if ( wp_next_scheduled( 'nbh_performance_database_cleanup_tick' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, 'nbh_performance_database_cleanup_tick' );
	}

	/** @param mixed $old Previous value. @param mixed $new_value New value. */
	public function force_reschedule( $old = null, $new_value = null ) {
		unset( $old, $new_value );
		wp_clear_scheduled_hook( 'nbh_performance_database_cleanup_tick' );
		$this->schedule();
	}

	/** @deprecated Use force_reschedule. @param mixed $old Previous. @param mixed $new_value New. */
	public function reschedule( $old, $new_value ) {
		$this->force_reschedule( $old, $new_value );
	}

	public function run() {
		if ( ! get_option( 'nbh_performance_database_cleanup_enabled', 0 ) ) {
			return;
		}
		if ( 'off' === sanitize_key( (string) get_option( 'nbh_performance_database_schedule', 'weekly' ) ) ) {
			return;
		}

		$operations = $this->cleanup->sanitize_operations(
			(array) apply_filters(
				'nbh_performance_scheduled_database_operations',
				array( 'expired_transients', 'expired_site_transients', 'woocommerce_sessions', 'woocommerce_transients', 'action_scheduler' )
			)
		);
		if ( empty( $operations ) ) {
			return;
		}

		$before = $this->preview->generate();
		$backup = $this->backup->create( 0, $before, 'scheduled' );
		if ( is_wp_error( $backup ) || empty( $backup['id'] ) || ! $this->backup->verified( $backup['id'], 0 ) ) {
			update_option(
				'nbh_performance_database_last_cleanup',
				array(
					'status'    => 'failed',
					'source'    => 'scheduled',
					'error'     => is_wp_error( $backup ) ? $backup->get_error_message() : __( 'Scheduled cleanup aborted because backup verification failed.', 'nbh-performance' ),
					'timestamp' => time(),
				),
				false
			);
			return;
		}

		$run    = $this->journal->start( 0, $operations, $backup['id'], $before );
		$totals = array_fill_keys( $operations, 0 );
		$rounds = 0;
		$max    = 8;

		try {
			$this->backup->before_cleanup( $operations, $backup['id'] );
			do {
				++$rounds;
				$batch = $this->cleanup->run( $operations );
				foreach ( $batch as $operation => $count ) {
					$totals[ $operation ] = (int) ( $totals[ $operation ] ?? 0 ) + (int) $count;
				}
				$remaining = $this->preview->generate();
				$more      = false;
				foreach ( $operations as $operation ) {
					if ( ! empty( $remaining[ $operation ] ) ) {
						$more = true;
						break;
					}
				}
			} while ( $more && $rounds < $max );

			$this->backup->after_cleanup( $totals, $backup['id'] );
			$run                  = $this->journal->complete( $run['id'], $totals, $remaining );
			$run['source']        = 'scheduled';
			$run['backup_id']     = $backup['id'];
			$run['rounds']        = $rounds;
			$run['repeat_capped'] = $more;
			update_option( 'nbh_performance_database_last_cleanup', $run, false );
		} catch ( \Throwable $e ) {
			$this->journal->fail( $run['id'], $e->getMessage() );
			update_option(
				'nbh_performance_database_last_cleanup',
				array(
					'status'    => 'failed',
					'source'    => 'scheduled',
					'error'     => $e->getMessage(),
					'timestamp' => time(),
				),
				false
			);
		}
	}

	/** @param string $mode Schedule mode. @return string */
	private function recurrence_for_mode( $mode ) {
		if ( 'monthly' === $mode ) {
			return 'nbh_monthly';
		}
		if ( 'daily' === $mode ) {
			return 'daily';
		}
		return 'nbh_weekly';
	}
}
