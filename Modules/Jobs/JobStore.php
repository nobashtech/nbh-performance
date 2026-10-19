<?php
/** Database-backed background job store with lease-based crash recovery. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

final class JobStore {
	const SCHEMA_VERSION = 4;

	/** Install or upgrade the queue table. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		self::deduplicate_unique_keys( $table );
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(100) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			priority smallint(5) unsigned NOT NULL DEFAULT 10,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
			timeout_seconds int(10) unsigned NOT NULL DEFAULT 900,
			available_at datetime NOT NULL,
			reserved_at datetime NULL,
			heartbeat_at datetime NULL,
			lease_token varchar(64) NULL,
			completed_at datetime NULL,
			last_error text NULL,
			result longtext NULL,
			unique_key varchar(191) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_available (status, available_at),
			KEY running_heartbeat (status, heartbeat_at),
			KEY type_status (type, status),
			UNIQUE KEY unique_key (unique_key),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'nbh_performance_job_schema_version', self::SCHEMA_VERSION, false );
	}

	/** Return the queue table name. */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_jobs';
	}

	/** Enqueue a job and return its ID. */
	public function enqueue( $type, array $payload = array(), array $args = array() ) {
		global $wpdb;
		$type       = sanitize_key( $type );
		$unique_key = isset( $args['unique_key'] ) ? substr( sanitize_text_field( (string) $args['unique_key'] ), 0, 191 ) : '';
		if ( '' === $type ) {
			return 0;
		}

		if ( '' !== $unique_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
			$existing = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE unique_key = %s AND status IN (\'pending\',\'running\') ORDER BY id DESC LIMIT 1', self::table_name(), $unique_key )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			if ( $existing ) {
				return absint( $existing );
			}
		}

		$now             = gmdate( 'Y-m-d H:i:s' );
		$delay           = max( 0, isset( $args['delay'] ) ? absint( $args['delay'] ) : 0 );
		$available       = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$priority        = max( 0, min( 100, isset( $args['priority'] ) ? absint( $args['priority'] ) : 10 ) );
		$max_attempts    = max( 1, min( 10, isset( $args['max_attempts'] ) ? absint( $args['max_attempts'] ) : 3 ) );
		$timeout_seconds = max( 30, min( 7200, isset( $args['timeout'] ) ? absint( $args['timeout'] ) : 900 ) );
		$json            = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'type'            => $type,
				'payload'         => $json,
				'status'          => 'pending',
				'priority'        => $priority,
				'attempts'        => 0,
				'max_attempts'    => $max_attempts,
				'timeout_seconds' => $timeout_seconds,
				'available_at'    => $available,
				'unique_key'      => '' !== $unique_key ? $unique_key : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( $inserted ) {
			return absint( $wpdb->insert_id );
		}
		if ( '' !== $unique_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE unique_key = %s LIMIT 1', self::table_name(), $unique_key ) );
			return absint( $existing );
		}
		return 0;
	}

	/** Reserve due jobs atomically and attach a unique lease to each row. */
	public function reserve( $limit = 5, $worker_token = '' ) {
		global $wpdb;
		$this->recover_stale();
		$limit        = max( 1, min( 25, absint( $limit ) ) );
		$worker_token = substr( sanitize_text_field( (string) $worker_token ), 0, 36 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE status = \'pending\' AND attempts < max_attempts AND available_at <= %s ORDER BY priority DESC, id ASC LIMIT %d', self::table_name(), gmdate( 'Y-m-d H:i:s' ), $limit )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$jobs = array();
		foreach ( (array) $ids as $id ) {
			$now   = gmdate( 'Y-m-d H:i:s' );
			$lease = ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'nbh-', true ) ) . ( '' !== $worker_token ? '-' . substr( $worker_token, 0, 8 ) : '' );
			$lease = substr( $lease, 0, 64 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
			$updated = $wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET status = \'running\', attempts = attempts + 1, reserved_at = %s, heartbeat_at = %s, lease_token = %s, updated_at = %s WHERE id = %d AND status = \'pending\' AND attempts < max_attempts', self::table_name(), $now, $now, $lease, $now, absint( $id ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			if ( 1 !== $updated ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND lease_token = %s', self::table_name(), absint( $id ), $lease ), ARRAY_A );
			if ( is_array( $row ) ) {
				$row['payload'] = json_decode( (string) $row['payload'], true );
				if ( ! is_array( $row['payload'] ) ) {
					$row['payload'] = array();
				}
				$jobs[] = $row;
			}
		}
		return $jobs;
	}

	/** Refresh one active lease so a healthy long-running job is not reclaimed. */
	public function heartbeat( $id, $lease_token ) {
		global $wpdb;
		if ( '' === (string) $lease_token ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		return 1 === $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET heartbeat_at = %s, updated_at = %s WHERE id = %d AND status = \'running\' AND lease_token = %s', self::table_name(), $now, $now, absint( $id ), (string) $lease_token )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** Mark a job complete only when the caller still owns its lease. */
	public function complete( $id, $result = null, $lease_token = '' ) {
		global $wpdb;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$encoded = null;
		if ( null !== $result ) {
			$json    = wp_json_encode( $result, JSON_UNESCAPED_SLASHES );
			$encoded = false === $json ? null : substr( (string) $json, 0, 4000 );
		}
		if ( '' !== (string) $lease_token ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
			return 1 === $wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET status = \'completed\', completed_at = %s, updated_at = %s, last_error = NULL, result = %s, unique_key = NULL, lease_token = NULL, heartbeat_at = NULL WHERE id = %d AND status = \'running\' AND lease_token = %s', self::table_name(), $now, $now, $encoded, absint( $id ), (string) $lease_token )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		return false !== $wpdb->update(
			self::table_name(),
			array(
				'status'       => 'completed',
				'completed_at' => $now,
				'updated_at'   => $now,
				'last_error'   => null,
				'result'       => $encoded,
				'unique_key'   => null,
				'lease_token'  => null,
				'heartbeat_at' => null,
			),
			array( 'id' => absint( $id ) )
		);
	}

	/** Fail or retry a job with exponential backoff, preserving lease ownership. */
	public function fail( array $job, $message, $lease_token = '' ) {
		global $wpdb;
		$id           = absint( $job['id'] );
		$attempts     = max( 1, absint( $job['attempts'] ) );
		$max_attempts = max( 1, absint( $job['max_attempts'] ) );
		$terminal     = $attempts >= $max_attempts;
		$backoff      = min( HOUR_IN_SECONDS, (int) pow( 2, max( 0, $attempts - 1 ) ) * MINUTE_IN_SECONDS );
		$now          = gmdate( 'Y-m-d H:i:s' );
		$lease_token  = '' !== (string) $lease_token ? (string) $lease_token : ( isset( $job['lease_token'] ) ? (string) $job['lease_token'] : '' );
		$sql          = 'UPDATE %i SET status = %s, available_at = %s, reserved_at = NULL, heartbeat_at = NULL, lease_token = NULL, updated_at = %s, last_error = %s';
		$args         = array(
			self::table_name(),
			$terminal ? 'failed' : 'pending',
			gmdate( 'Y-m-d H:i:s', time() + $backoff ),
			$now,
			substr( sanitize_textarea_field( (string) $message ), 0, 4000 ),
		);
		if ( $terminal ) {
			$sql .= ', unique_key = NULL';
		}
		$sql   .= " WHERE id = %d AND status = 'running'";
		$args[] = $id;
		if ( '' !== $lease_token ) {
			$sql   .= ' AND lease_token = %s';
			$args[] = $lease_token;
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers. Only fixed SET/WHERE clauses are appended; the table is bound with %i and every status/date/ID/lease value is passed to prepare(). Isolated retry/lease tests cover both branches.
		return 1 === $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/** Retry a failed/cancelled job. */
	public function retry( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		return 1 === $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = \'pending\', attempts = 0, available_at = %s, reserved_at = NULL, heartbeat_at = NULL, lease_token = NULL, completed_at = NULL, updated_at = %s, last_error = NULL WHERE id = %d AND status IN (\'failed\',\'cancelled\')', self::table_name(), gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), absint( $id ) )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** Cancel a pending job. */
	public function cancel( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		return 1 === $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = \'cancelled\', updated_at = %s, unique_key = NULL, lease_token = NULL, heartbeat_at = NULL WHERE id = %d AND status = \'pending\'', self::table_name(), gmdate( 'Y-m-d H:i:s' ), absint( $id ) )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** Queue statistics. */
	public function stats() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', self::table_name() ), ARRAY_A );
		$stats = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'cancelled' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( $row['status'] );
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ] = absint( $row['total'] );
			}
		}
		$stats['last_run']       = absint( get_option( 'nbh_performance_job_worker_last_run', 0 ) );
		$stats['last_heartbeat'] = absint( get_option( 'nbh_performance_job_worker_last_heartbeat', 0 ) );
		$stats['paused']         = (bool) get_option( 'nbh_performance_job_queue_paused', 0 );
		return $stats;
	}

	/** Recent jobs for diagnostics. */
	public function recent( $limit = 20 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,type,status,priority,attempts,max_attempts,timeout_seconds,available_at,reserved_at,heartbeat_at,completed_at,last_error,result,created_at,updated_at FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Remove old terminal jobs. */
	public function prune() {
		global $wpdb;
		$completed = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$failed    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE (status = \'completed\' AND updated_at < %s) OR (status IN (\'failed\',\'cancelled\') AND updated_at < %s)', self::table_name(), $completed, $failed )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** Recover jobs abandoned by a crashed or timed-out worker. */
	public function recover_stale() {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = \'failed\', updated_at = %s, unique_key = NULL, lease_token = NULL, heartbeat_at = NULL, last_error = \'Maximum retry count reached.\' WHERE status = \'pending\' AND attempts >= max_attempts', self::table_name(), $now )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = \'pending\', reserved_at = NULL, heartbeat_at = NULL, lease_token = NULL, available_at = %s, updated_at = %s, last_error = \'Recovered after an interrupted worker lease expired.\' WHERE status = \'running\' AND TIMESTAMPADD(SECOND, timeout_seconds, COALESCE(heartbeat_at, reserved_at)) < %s AND attempts < max_attempts', self::table_name(), $now, $now, $now )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = \'failed\', reserved_at = NULL, heartbeat_at = NULL, lease_token = NULL, updated_at = %s, unique_key = NULL, last_error = \'Worker lease expired after maximum retries.\' WHERE status = \'running\' AND TIMESTAMPADD(SECOND, timeout_seconds, COALESCE(heartbeat_at, reserved_at)) < %s AND attempts >= max_attempts', self::table_name(), $now, $now )
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** Remove duplicate active unique keys before adding the unique index. */
	private static function deduplicate_unique_keys( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $exists ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET unique_key = NULL WHERE unique_key = \'\'', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT unique_key FROM %i WHERE unique_key IS NOT NULL AND unique_key <> \'\' GROUP BY unique_key HAVING COUNT(*) > 1', $table ) );
		foreach ( (array) $keys as $unique_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE unique_key = %s ORDER BY FIELD(status,\'running\',\'pending\',\'failed\',\'cancelled\',\'completed\'), id ASC', $table, $unique_key ) );
			array_shift( $ids );
			if ( $ids ) {
				$id_list = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$args    = array_merge( array( $table ), array_map( 'absint', $ids ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The custom queue must use live lease/deduplication state; caching these reads or writes can admit duplicate workers.
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET unique_key = NULL WHERE id IN ({$id_list})", $args ) );
			}
		}
	}
}
