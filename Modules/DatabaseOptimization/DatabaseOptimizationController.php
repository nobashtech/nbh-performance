<?php
/** Enterprise database optimization REST API. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class DatabaseOptimizationController implements Module {
	private $preview;
	private $tokens;
	private $backup;
	private $cleanup;
	private $optimizer;
	private $journal;
	public function __construct( DryRunPreview $preview, ConfirmationToken $tokens, BackupCoordinator $backup, CleanupManager $cleanup, TableOptimizer $optimizer, CleanupJournal $journal ) {
		$this->preview   = $preview;
		$this->tokens    = $tokens;
		$this->backup    = $backup;
		$this->cleanup   = $cleanup;
		$this->optimizer = $optimizer;
		$this->journal   = $journal;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/backup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'backup' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/backup/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'backup_status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/cleanup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cleanup' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/cleanup/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'cleanup_status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/database/optimize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'optimize' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}
	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to optimize the database.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'nbh_performance_invalid_nonce', __( 'Invalid request nonce.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Hub status payload for the dedicated Database Cleanup workspace. @return WP_REST_Response */
	public function rest_status() {
		return new WP_REST_Response( $this->status(), 200 );
	}

	/** @return array<string,mixed> */
	public function status() {
		$preview        = $this->preview->generate();
		$operation_keys = array(
			'revisions',
			'auto_drafts',
			'trashed_posts',
			'spam_comments',
			'trashed_comments',
			'expired_transients',
			'expired_site_transients',
			'orphan_postmeta',
			'orphan_termmeta',
			'orphan_commentmeta',
			'orphan_term_relationships',
			'woocommerce_sessions',
			'woocommerce_transients',
			'action_scheduler',
		);
		$clutter        = array();
		$total          = 0;
		$types          = 0;
		foreach ( $operation_keys as $key ) {
			$count           = isset( $preview[ $key ] ) ? absint( $preview[ $key ] ) : 0;
			$clutter[ $key ] = $count;
			$total          += $count;
			if ( $count > 0 ) {
				++$types;
			}
		}
		$latest   = $this->backup->latest();
		$last_run = get_option( 'nbh_performance_database_last_cleanup', array() );
		$last_run = is_array( $last_run ) ? $last_run : array();
		$enabled  = (bool) get_option( 'nbh_performance_database_cleanup_enabled', 0 );
		$schedule = sanitize_key( (string) get_option( 'nbh_performance_database_schedule', 'weekly' ) );
		$next     = wp_next_scheduled( 'nbh_performance_database_cleanup_tick' );
		$autoload = isset( $preview['autoload'] ) && is_array( $preview['autoload'] ) ? $preview['autoload'] : array();

		return array(
			'safe_mode'                => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
			'cleanup_enabled'          => $enabled,
			'schedule'                 => $schedule,
			'next_scheduled'           => $next ? absint( $next ) : 0,
			'batch_size'               => max( 10, min( 500, absint( get_option( 'nbh_performance_database_batch_size', 250 ) ) ) ),
			'revision_retention_days'  => max( 1, absint( get_option( 'nbh_performance_revision_retention_days', 30 ) ) ),
			'preview'                  => $clutter,
			'clutter_total'            => $total,
			'clutter_types'            => $types,
			'autoload_bytes'           => isset( $autoload['total_bytes'] ) ? absint( $autoload['total_bytes'] ) : 0,
			'latest_backup'            => $this->public_backup( $latest ),
			'latest_backup_usable'     => is_array( $latest ) && $this->backup->verified( $latest['id'], get_current_user_id() ),
			'last_cleanup'             => $this->public_last_cleanup( $last_run ),
			'verified_backup_required' => true,
			'ops_available'            => array(
				'preview'  => true,
				'backup'   => true,
				'cleanup'  => true,
				'optimize' => true,
			),
		);
	}

	public function preview() {
		$latest = $this->backup->latest();
		return new WP_REST_Response(
			array(
				'preview'                  => $this->preview->generate(),
				'destructive'              => true,
				'verified_backup_required' => true,
				'latest_backup'            => $this->public_backup( $latest ),
				'latest_backup_usable'     => is_array( $latest ) && $this->backup->verified( $latest['id'], get_current_user_id() ),
				'backup_strategy'          => 'automatic_before_change',
			),
			200
		);
	}
	public function backup( WP_REST_Request $request ) {
		$result = $this->backup->create( get_current_user_id(), $this->preview->generate(), sanitize_key( (string) $request->get_param( 'provider' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'backup' => $this->backup->public_record( $result ) ), 201 );
	}
	public function backup_status( WP_REST_Request $request ) {
		$id     = sanitize_key( (string) $request->get_param( 'backup_id' ) );
		$record = $id ? $this->backup->get( $id ) : $this->backup->latest();
		if ( ! is_array( $record ) ) {
			return new WP_Error( 'nbh_database_backup_not_found', __( 'No database backup record was found.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'backup' => $this->backup->public_record( $record ),
				'usable' => $this->backup->verified( $record['id'], get_current_user_id() ),
			),
			200
		);
	}
	public function confirm( WP_REST_Request $request ) {
		$requested           = array_values( array_unique( array_map( 'sanitize_key', (array) $request->get_param( 'operations' ) ) ) );
		$operations          = array_values( array_intersect( array_merge( $this->cleanup->sanitize_operations( $requested ), array( 'optimize_tables' ) ), $requested ) );
		$requested_backup_id = sanitize_key( (string) $request->get_param( 'backup_id' ) );

		if ( empty( $operations ) ) {
			return new WP_Error( 'nbh_database_operations_required', __( 'Select at least one database operation.', 'nbh-performance' ), array( 'status' => 400 ) );
		}

		$backup = $this->ensure_verified_backup( $requested_backup_id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$backup_id = sanitize_key( (string) $backup['id'] );
		return new WP_REST_Response(
			array(
				'confirmation_token' => $this->tokens->issue( get_current_user_id(), $operations, $backup_id ),
				'backup_id'          => $backup_id,
				'backup'             => $this->backup->public_record( $backup ),
				'backup_created'     => empty( $requested_backup_id ) || $requested_backup_id !== $backup_id,
				'expires_in'         => ConfirmationToken::TTL,
			),
			200
		);
	}
	public function cleanup( WP_REST_Request $request ) {
		$operations = $this->cleanup->sanitize_operations( (array) $request->get_param( 'operations' ) );
		$backup_id  = sanitize_key( (string) $request->get_param( 'backup_id' ) );
		if ( empty( $operations ) || ! $this->backup->verified( $backup_id, get_current_user_id() ) || ! $this->tokens->consume( (string) $request->get_param( 'confirmation_token' ), get_current_user_id(), $operations, $backup_id ) ) {
			return new WP_Error( 'nbh_performance_invalid_confirmation', __( 'The confirmation token or verified backup is invalid or expired.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$before = $this->preview->generate();
		$run    = $this->journal->start( get_current_user_id(), $operations, $backup_id, $before );
		try {
			$this->backup->before_cleanup( $operations, $backup_id );
			$result    = $this->cleanup->run( $operations );
			$remaining = $this->preview->generate();
			$this->backup->after_cleanup( $result, $backup_id );
			$run = $this->journal->complete( $run['id'], $result, $remaining );
			update_option( 'nbh_performance_database_last_cleanup', $run, false );
			return new WP_REST_Response(
				array(
					'run'             => $run,
					'deleted'         => $result,
					'remaining'       => $remaining,
					'repeat_required' => $this->repeat_required( $operations, $remaining ),
				),
				200
			);
		} catch ( \Throwable $e ) {
			$this->journal->fail( $run['id'], $e->getMessage() );
			return new WP_Error( 'nbh_database_cleanup_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
	public function cleanup_status( WP_REST_Request $request ) {
		$id  = sanitize_key( (string) $request->get_param( 'run_id' ) );
		$run = $id ? $this->journal->get( $id ) : get_option( 'nbh_performance_database_last_cleanup', array() );
		return new WP_REST_Response( array( 'run' => is_array( $run ) ? $run : array() ), 200 );
	}
	public function optimize( WP_REST_Request $request ) {
		$operations = array( 'optimize_tables' );
		$backup_id  = sanitize_key( (string) $request->get_param( 'backup_id' ) );
		if ( ! $this->backup->verified( $backup_id, get_current_user_id() ) || ! $this->tokens->consume( (string) $request->get_param( 'confirmation_token' ), get_current_user_id(), $operations, $backup_id ) ) {
			return new WP_Error( 'nbh_performance_invalid_confirmation', __( 'A verified backup and separate confirmation token are required for table optimization.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return new WP_REST_Response(
			array(
				'tables'    => $this->optimizer->optimize( (array) $request->get_param( 'tables' ) ),
				'backup_id' => $backup_id,
			),
			200
		);
	}

	private function ensure_verified_backup( $backup_id = '' ) {
		$backup_id = sanitize_key( (string) $backup_id );
		if ( $backup_id && $this->backup->verified( $backup_id, get_current_user_id() ) ) {
			$existing = $this->backup->get( $backup_id );
			if ( is_array( $existing ) ) {
				return $existing;
			}
		}

		$created = $this->backup->create( get_current_user_id(), $this->preview->generate(), 'auto' );
		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'nbh_database_automatic_backup_failed',
				sprintf(
					/* translators: %s: original backup error. */
					__( 'NBH Performance could not create and verify a database backup automatically, so no database changes were made. %s', 'nbh-performance' ),
					$created->get_error_message()
				),
				array(
					'status' => 500,
					'action' => __( 'Check that the NBH Performance private backup vault is writable, then try again.', 'nbh-performance' ),
				)
			);
		}

		if ( empty( $created['id'] ) || ! $this->backup->verified( $created['id'], get_current_user_id() ) ) {
			return new WP_Error(
				'nbh_database_automatic_backup_unverified',
				__( 'NBH Performance created a backup record but could not verify the backup artifact. No database changes were made.', 'nbh-performance' ),
				array( 'status' => 500 )
			);
		}

		return $created;
	}

	private function public_backup( $record ) {
		return is_array( $record ) ? $this->backup->public_record( $record ) : null;
	}

	/** @param array<string,mixed> $run Last cleanup record. @return array<string,mixed> */
	private function public_last_cleanup( array $run ) {
		if ( empty( $run ) ) {
			return array();
		}
		$result        = isset( $run['result'] ) && is_array( $run['result'] ) ? $run['result'] : ( isset( $run['deleted'] ) && is_array( $run['deleted'] ) ? $run['deleted'] : array() );
		$deleted_total = 0;
		foreach ( $result as $count ) {
			$deleted_total += absint( $count );
		}
		return array(
			'id'            => isset( $run['id'] ) ? sanitize_key( (string) $run['id'] ) : '',
			'status'        => isset( $run['status'] ) ? sanitize_key( (string) $run['status'] ) : '',
			'source'        => isset( $run['source'] ) ? sanitize_key( (string) $run['source'] ) : '',
			'backup_id'     => isset( $run['backup_id'] ) ? sanitize_key( (string) $run['backup_id'] ) : '',
			'completed_at'  => isset( $run['completed_at'] ) ? absint( $run['completed_at'] ) : ( isset( $run['timestamp'] ) ? absint( $run['timestamp'] ) : 0 ),
			'deleted_total' => $deleted_total,
			'error'         => isset( $run['error'] ) ? sanitize_text_field( (string) $run['error'] ) : '',
		);
	}

	private function repeat_required( array $operations, array $remaining ) {
		foreach ( $operations as $operation ) {
			if ( ! empty( $remaining[ $operation ] ) ) {
				return true;
			}
		}
		return false;
	}
}
