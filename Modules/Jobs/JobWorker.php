<?php
/** Reliable lease-based database job worker. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\Core\Module;

final class JobWorker implements Module {
	private $store;
	private $handlers;
	private $active_job          = array();
	private $shutdown_registered = false;
	private $active_worker_token = '';

	public function __construct( JobStore $store, array $handlers ) {
		$this->store    = $store;
		$this->handlers = $handlers;
	}

	public function register() {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- A bounded asynchronous queue needs a one-minute fallback tick; worker time/memory/batch budgets prevent unbounded work.
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule' ), 2 );
		add_action( 'nbh_performance_job_worker_tick', array( $this, 'run' ) );
		add_action( 'nbh_performance_job_worker_kick', array( $this, 'run' ) );
		add_action( 'nbh_performance_job_prune_tick', array( $this->store, 'prune' ) );
		add_action( 'admin_init', array( $this, 'opportunistic_dispatch' ), 100 );
	}

	public function cron_schedules( $schedules ) {
		$schedules['nbh_performance_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute', 'nbh-performance' ),
		);
		return $schedules;
	}

	public function schedule() {
		if ( ! wp_next_scheduled( 'nbh_performance_job_worker_tick' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nbh_performance_every_minute', 'nbh_performance_job_worker_tick' );
		}
		if ( ! wp_next_scheduled( 'nbh_performance_job_prune_tick' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'nbh_performance_job_prune_tick' );
		}
	}

	public function opportunistic_dispatch() {
		$last = absint( get_option( 'nbh_performance_job_worker_last_run', 0 ) );
		if ( time() - $last >= MINUTE_IN_SECONDS ) {
			$this->run( 3 );
		}
	}

	/** Process a bounded batch and return a summary. */
	public function run( $limit = null ) {
		$limit = null === $limit ? absint( get_option( 'nbh_performance_job_worker_batch_size', 5 ) ) : absint( $limit );
		$limit = max( 1, min( 20, $limit ) );
		if ( get_option( 'nbh_performance_job_queue_paused', 0 ) ) {
			return array(
				'processed' => 0,
				'failed'    => 0,
				'locked'    => false,
				'paused'    => true,
			);
		}

		$token = wp_generate_uuid4();
		if ( ! $this->acquire_lock( $token ) ) {
			return array(
				'processed' => 0,
				'failed'    => 0,
				'locked'    => true,
			);
		}
		$this->active_worker_token = $token;
		if ( ! $this->shutdown_registered ) {
			register_shutdown_function( array( $this, 'shutdown_handler' ) );
			$this->shutdown_registered = true;
		}

		$processed = 0;
		$failed    = 0;
		$started   = microtime( true );
		$budget    = max( 5, min( 240, absint( get_option( 'nbh_performance_job_worker_time_budget', 20 ) ) ) );
		try {
			while ( $processed < $limit && ! $this->resource_budget_exhausted( $started, $budget ) ) {
				$this->heartbeat_lock( $token );
				$jobs = $this->store->reserve( 1, $token );
				if ( empty( $jobs ) ) {
					break;
				}
				$job              = $jobs[0];
				$lease            = isset( $job['lease_token'] ) ? (string) $job['lease_token'] : '';
				$this->active_job = $job;
				$job_timeout      = isset( $job['timeout_seconds'] ) ? absint( $job['timeout_seconds'] ) : 900;
				$this->heartbeat_lock( $token, max( 5 * MINUTE_IN_SECONDS, $job_timeout + MINUTE_IN_SECONDS ) );
				++$processed;
				$this->store->heartbeat( $job['id'], $lease );
				try {
					$result = $this->dispatch( $job['type'], (array) $job['payload'] );
					$this->store->heartbeat( $job['id'], $lease );
					if ( is_wp_error( $result ) ) {
						++$failed;
						$this->store->fail( $job, $result->get_error_message(), $lease );
						do_action( 'nbh_performance_job_failed', $job, $result );
					} elseif ( false === $result ) {
						++$failed;
						$this->store->fail( $job, 'The job handler returned a failure result.', $lease );
						do_action( 'nbh_performance_job_failed', $job, 'handler_failure' );
					} elseif ( ! $this->store->complete( $job['id'], $result, $lease ) ) {
						++$failed;
						do_action( 'nbh_performance_job_lease_lost', $job );
					} else {
						do_action( 'nbh_performance_job_completed', $job, $result );
					}
				} catch ( \Throwable $error ) {
					++$failed;
					$this->store->fail( $job, $error->getMessage(), $lease );
					do_action( 'nbh_performance_job_failed', $job, $error );
				}
				$this->active_job = array();
			}
			update_option( 'nbh_performance_job_worker_last_run', time(), false );
			update_option( 'nbh_performance_job_worker_last_heartbeat', time(), false );
		} finally {
			$this->active_job = array();
			$this->release_lock( $token );
			$this->active_worker_token = '';
		}

		return array(
			'processed'        => $processed,
			'failed'           => $failed,
			'locked'           => false,
			'budget_exhausted' => $this->resource_budget_exhausted( $started, $budget ),
		);
	}

	/** Mark the currently leased job failed when PHP terminates fatally. */
	public function shutdown_handler() {
		if ( empty( $this->active_job['id'] ) || empty( $this->active_job['lease_token'] ) ) {
			return;
		}
		$error = error_get_last();
		$fatal = is_array( $error ) && in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		if ( ! $fatal ) {
			return;
		}
		$message = isset( $error['message'] ) ? 'Fatal worker error: ' . (string) $error['message'] : 'Fatal worker error.';
		try {
			$this->store->fail( $this->active_job, $message, (string) $this->active_job['lease_token'] );
		} catch ( \Throwable $store_error ) {
			unset( $store_error );
		} finally {
			if ( '' !== $this->active_worker_token ) {
				$this->release_lock( $this->active_worker_token );
			}
		}
		update_option(
			'nbh_performance_job_worker_last_fatal',
			array(
				'job_id' => absint( $this->active_job['id'] ),
				'type'   => sanitize_key( $this->active_job['type'] ),
				'at'     => time(),
			),
			false
		);
	}

	/** Acquire an atomic database lock and recover an abandoned lock. */
	private function acquire_lock( $token ) {
		$option = 'nbh_performance_job_worker_lock';
		$record = get_option( $option, array() );
		if ( is_array( $record ) && ! empty( $record['expires_at'] ) && absint( $record['expires_at'] ) < time() ) {
			delete_option( $option );
		}
		return add_option(
			$option,
			array(
				'token'        => sanitize_text_field( $token ),
				'created_at'   => time(),
				'heartbeat_at' => time(),
				'expires_at'   => time() + 5 * MINUTE_IN_SECONDS,
			),
			'',
			false
		);
	}

	private function heartbeat_lock( $token, $ttl = 300 ) {
		$record = get_option( 'nbh_performance_job_worker_lock', array() );
		if ( is_array( $record ) && isset( $record['token'] ) && hash_equals( (string) $record['token'], (string) $token ) ) {
			$record['heartbeat_at'] = time();
			$record['expires_at']   = time() + max( MINUTE_IN_SECONDS, min( 3 * HOUR_IN_SECONDS, absint( $ttl ) ) );
			update_option( 'nbh_performance_job_worker_lock', $record, false );
			update_option( 'nbh_performance_job_worker_last_heartbeat', time(), false );
		}
	}

	/** Release only the lock owned by this worker. */
	private function release_lock( $token ) {
		$record = get_option( 'nbh_performance_job_worker_lock', array() );
		if ( is_array( $record ) && isset( $record['token'] ) && hash_equals( (string) $record['token'], (string) $token ) ) {
			delete_option( 'nbh_performance_job_worker_lock' );
		}
	}

	private function resource_budget_exhausted( $started, $budget ) {
		if ( microtime( true ) - (float) $started >= (float) $budget ) {
			return true;
		}
		$limit = $this->memory_limit_bytes();
		if ( PHP_INT_MAX === $limit ) {
			return false;
		}
		$reserve = max( 8, min( 128, absint( get_option( 'nbh_performance_job_worker_memory_reserve_mb', 16 ) ) ) ) * MB_IN_BYTES;
		return memory_get_usage( true ) + $reserve >= $limit;
	}

	private function memory_limit_bytes() {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '-1' === $value || '' === $value ) {
			return PHP_INT_MAX;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (int) $value;
		if ( 'g' === $unit ) {
			return $number * 1024 * 1024 * 1024;
		}
		if ( 'm' === $unit ) {
			return $number * 1024 * 1024;
		}
		if ( 'k' === $unit ) {
			return $number * 1024;
		}
		return $number;
	}

	private function dispatch( $type, array $payload ) {
		foreach ( $this->handlers as $handler ) {
			if ( $handler instanceof JobHandlerInterface && $handler->supports( $type ) ) {
				return $handler->handle( $payload );
			}
		}
		$external = apply_filters( 'nbh_performance_run_background_job', null, $type, $payload );
		if ( null !== $external ) {
			return $external;
		}
		/* translators: %s: background job type. */
		return new \WP_Error( 'nbh_performance_job_handler_missing', sprintf( __( 'No handler is registered for job type %s.', 'nbh-performance' ), $type ) );
	}
}
