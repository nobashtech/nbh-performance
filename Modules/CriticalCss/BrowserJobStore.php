<?php
/**
 * Durable one-time browser generation request store.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CriticalCss;

use NBH\Performance\Modules\Core\Module;

final class BrowserJobStore implements Module {
	const TABLE_VERSION = 1;
	const CALLBACK_TTL  = 1800;

	public function register() {
		add_action( 'init', array( $this, 'maybe_install' ), 2 );
	}

	public function maybe_install() {
		if ( self::TABLE_VERSION === absint( get_option( 'nbh_performance_browser_jobs_schema', 0 ) ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id char(36) NOT NULL,
			url text NOT NULL,
			url_hash char(64) NOT NULL,
			context varchar(80) NOT NULL DEFAULT 'generic',
			variant varchar(20) NOT NULL DEFAULT 'desktop',
			features longtext NULL,
			callback_token_hash char(64) NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			result_hash char(64) NULL,
			error_code varchar(100) NULL,
			metrics longtext NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_id (request_id),
			KEY status_expires (status,expires_at),
			KEY url_hash (url_hash)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'nbh_performance_browser_jobs_schema', self::TABLE_VERSION, false );
	}

	/** Return an unexpired active request for the same URL and variant. */
	public function find_active( $url, $variant ) {
		global $wpdb;
		$this->maybe_install();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT request_id,status,expires_at FROM %i WHERE url_hash = %s AND variant = %s AND status IN (\'pending\',\'processing\') AND expires_at >= %s ORDER BY id DESC LIMIT 1', $table, hash( 'sha256', strtolower( esc_url_raw( $url ) ) ), 'mobile' === sanitize_key( $variant ) ? 'mobile' : 'desktop', current_time( 'mysql', true ) ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	/** Create one callback-authorized request. */
	public function create( $url, $context, $variant, array $features ) {
		global $wpdb;
		$this->maybe_install();
		$request_id     = wp_generate_uuid4();
		$callback_token = wp_generate_password( 64, false, false );
		$now            = current_time( 'mysql', true );
		$expires        = gmdate( 'Y-m-d H:i:s', time() + self::CALLBACK_TTL );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'request_id'          => $request_id,
				'url'                 => esc_url_raw( $url ),
				'url_hash'            => hash( 'sha256', strtolower( esc_url_raw( $url ) ) ),
				'context'             => sanitize_key( $context ),
				'variant'             => 'mobile' === sanitize_key( $variant ) ? 'mobile' : 'desktop',
				'features'            => wp_json_encode( $features ),
				'callback_token_hash' => hash( 'sha256', $callback_token ),
				'status'              => 'pending',
				'attempts'            => 1,
				'created_at'          => $now,
				'expires_at'          => $expires,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new \WP_Error( 'nbh_performance_browser_job_create_failed', __( 'Unable to create the browser generation job.', 'nbh-performance' ) );
		}
		return array(
			'request_id'     => $request_id,
			'callback_token' => $callback_token,
			'expires_at'     => $expires,
		);
	}

	/** Atomically consume one callback token. */
	public function authorize_callback( $request_id, $callback_token ) {
		global $wpdb;
		$request_id = sanitize_text_field( $request_id );
		$token_hash = hash( 'sha256', (string) $callback_token );
		$now        = current_time( 'mysql', true );
		$table      = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$updated = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET status = \'processing\', callback_token_hash = \'\', updated_at = %s WHERE request_id = %s AND callback_token_hash = %s AND status = \'pending\' AND expires_at >= %s', $table, $now, $request_id, $token_hash, $now )
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %s LIMIT 1', $table, $request_id ), ARRAY_A );
		return is_array( $row ) ? $row : false;
	}

	public function complete( $request_id, $result_hash, array $metrics = array() ) {
		return $this->finish( $request_id, 'complete', '', $result_hash, $metrics );
	}

	public function fail( $request_id, $error_code, array $metrics = array() ) {
		return $this->finish( $request_id, 'failed', sanitize_key( $error_code ), '', $metrics );
	}

	private function finish( $request_id, $status, $error_code, $result_hash, array $metrics ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'      => $status,
				'error_code'  => $error_code,
				'result_hash' => preg_match( '/^[a-f0-9]{64}$/', $result_hash ) ? $result_hash : '',
				'metrics'     => wp_json_encode( $this->bounded_metrics( $metrics ) ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'request_id' => sanitize_text_field( $request_id ) ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	public function prune() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE (status IN (\'complete\',\'failed\') AND updated_at < %s) OR expires_at < %s', $table, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	public function stats() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ), ARRAY_A );
		$out  = array(
			'pending'    => 0,
			'processing' => 0,
			'complete'   => 0,
			'failed'     => 0,
		);
		foreach ( (array) $rows as $row ) {
			$key = sanitize_key( $row['status'] );
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = absint( $row['total'] );
			}
		}
		return $out;
	}

	private function bounded_metrics( array $metrics ) {
		$allowed = array( 'visual_difference', 'missing_nodes', 'visible_node_delta', 'dom_node_delta', 'page_height_delta', 'original_bytes', 'used_bytes', 'critical_bytes', 'coverage', 'layout_shift', 'viewport_width', 'viewport_height', 'baseline_sha256', 'optimized_sha256', 'engine_version', 'duration_ms' );
		$out     = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $metrics ) ) {
				continue;
			}
			$value       = $metrics[ $key ];
			$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 128 ) : ( is_numeric( $value ) ? (float) $value : (bool) $value );
		}
		return $out;
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_browser_jobs';
	}
}
