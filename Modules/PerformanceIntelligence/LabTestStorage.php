<?php
/**
 * Durable laboratory performance result storage.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\PerformanceIntelligence;

final class LabTestStorage {
	const SCHEMA_VERSION = 1;

	/** Install or upgrade the lab-results table. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_uuid char(36) NOT NULL,
			url_hash char(64) NOT NULL,
			canonical_url varchar(500) NOT NULL,
			strategy varchar(16) NOT NULL DEFAULT 'mobile',
			provider varchar(32) NOT NULL DEFAULT 'pagespeed',
			status varchar(20) NOT NULL DEFAULT 'complete',
			performance_score decimal(6,2) DEFAULT NULL,
			lcp decimal(12,2) DEFAULT NULL,
			cls decimal(12,4) DEFAULT NULL,
			tbt decimal(12,2) DEFAULT NULL,
			fcp decimal(12,2) DEFAULT NULL,
			speed_index decimal(12,2) DEFAULT NULL,
			ttfb decimal(12,2) DEFAULT NULL,
			total_byte_weight bigint(20) unsigned DEFAULT NULL,
			request_count int(10) unsigned DEFAULT NULL,
			audits longtext NULL,
			opportunities longtext NULL,
			error_message text NULL,
			is_baseline tinyint(1) unsigned NOT NULL DEFAULT 0,
			change_id varchar(64) NULL,
			captured_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY url_strategy_time (url_hash, strategy, captured_at),
			KEY captured_at (captured_at),
			KEY status_time (status, captured_at),
			KEY change_id (change_id),
			KEY baseline_lookup (url_hash, strategy, is_baseline)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'nbh_performance_lab_results_schema_version', self::SCHEMA_VERSION, false );
	}

	/** Return the table name. */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_lab_results';
	}

	/** Insert a completed or failed test result. */
	public function insert( array $result ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$url = esc_url_raw( isset( $result['url'] ) ? (string) $result['url'] : '' );
		if ( '' === $url ) {
			return 0;
		}
		$data = array(
			'run_uuid'          => isset( $result['run_uuid'] ) ? sanitize_text_field( $result['run_uuid'] ) : wp_generate_uuid4(),
			'url_hash'          => hash( 'sha256', $url ),
			'canonical_url'     => substr( $url, 0, 500 ),
			'strategy'          => in_array( isset( $result['strategy'] ) ? $result['strategy'] : '', array( 'mobile', 'desktop' ), true ) ? $result['strategy'] : 'mobile',
			'provider'          => sanitize_key( isset( $result['provider'] ) ? $result['provider'] : 'pagespeed' ),
			'status'            => in_array( isset( $result['status'] ) ? $result['status'] : '', array( 'complete', 'failed' ), true ) ? $result['status'] : 'complete',
			'performance_score' => $this->nullable_number( isset( $result['performance_score'] ) ? $result['performance_score'] : null ),
			'lcp'               => $this->nullable_number( isset( $result['lcp'] ) ? $result['lcp'] : null ),
			'cls'               => $this->nullable_number( isset( $result['cls'] ) ? $result['cls'] : null ),
			'tbt'               => $this->nullable_number( isset( $result['tbt'] ) ? $result['tbt'] : null ),
			'fcp'               => $this->nullable_number( isset( $result['fcp'] ) ? $result['fcp'] : null ),
			'speed_index'       => $this->nullable_number( isset( $result['speed_index'] ) ? $result['speed_index'] : null ),
			'ttfb'              => $this->nullable_number( isset( $result['ttfb'] ) ? $result['ttfb'] : null ),
			'total_byte_weight' => isset( $result['total_byte_weight'] ) ? absint( $result['total_byte_weight'] ) : null,
			'request_count'     => isset( $result['request_count'] ) ? absint( $result['request_count'] ) : null,
			'audits'            => wp_json_encode( isset( $result['audits'] ) && is_array( $result['audits'] ) ? $result['audits'] : array(), JSON_UNESCAPED_SLASHES ),
			'opportunities'     => wp_json_encode( isset( $result['opportunities'] ) && is_array( $result['opportunities'] ) ? $result['opportunities'] : array(), JSON_UNESCAPED_SLASHES ),
			'error_message'     => isset( $result['error_message'] ) ? substr( sanitize_textarea_field( $result['error_message'] ), 0, 4000 ) : null,
			'is_baseline'       => ! empty( $result['is_baseline'] ) ? 1 : 0,
			'change_id'         => isset( $result['change_id'] ) ? substr( sanitize_text_field( $result['change_id'] ), 0, 64 ) : null,
			'captured_at'       => isset( $result['captured_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $result['captured_at'] ) ) : $now,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$inserted = $wpdb->insert( self::table_name(), $data );
		return $inserted ? absint( $wpdb->insert_id ) : 0;
	}

	/** Get one result. */
	public function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), absint( $id ) ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : array();
	}

	/** Return latest successful results, one per URL and strategy. */
	public function latest( $limit = 30 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$table = self::table_name();
		$sql   = "SELECT r.* FROM {$table} r INNER JOIN (SELECT url_hash, strategy, MAX(id) latest_id FROM {$table} WHERE status='complete' GROUP BY url_hash,strategy) x ON r.id=x.latest_id ORDER BY r.id DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/** History with bounded filters. */
	public function history( $limit = 100, $url_hash = '', $strategy = '' ) {
		global $wpdb;
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$where = array( '1=1' );
		$args  = array( self::table_name() );
		if ( '' !== $url_hash ) {
			$where[] = 'url_hash = %s';
			$args[]  = sanitize_text_field( $url_hash );
		}
		if ( in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) {
			$where[] = 'strategy = %s';
			$args[]  = $strategy;
		}
		$args[] = $limit;
		$sql    = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables. WHERE contains only fixed allowlisted templates; identifiers, URL hashes, strategy and limits are separately bound by prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/** Mark a result as the baseline for its URL and strategy. */
	public function set_baseline( $id ) {
		global $wpdb;
		$row = $this->get( $id );
		if ( empty( $row ) || 'complete' !== $row['status'] ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$wpdb->update(
			self::table_name(),
			array( 'is_baseline' => 0 ),
			array(
				'url_hash' => $row['url_hash'],
				'strategy' => $row['strategy'],
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		return false !== $wpdb->update(
			self::table_name(),
			array(
				'is_baseline' => 1,
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => absint( $id ) )
		);
	}

	/** Find explicit baseline or previous successful result. */
	public function comparison_for( array $current ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$baseline = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE url_hash=%s AND strategy=%s AND is_baseline=1 AND status=\'complete\' AND id<>%d ORDER BY id DESC LIMIT 1', $table, $current['url_hash'], $current['strategy'], absint( $current['id'] ) ),
			ARRAY_A
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( is_array( $baseline ) ) {
			return $this->hydrate( $baseline );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$previous = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE url_hash=%s AND strategy=%s AND status=\'complete\' AND id<%d ORDER BY id DESC LIMIT 1', $table, $current['url_hash'], $current['strategy'], absint( $current['id'] ) ),
			ARRAY_A
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return is_array( $previous ) ? $this->hydrate( $previous ) : array();
	}

	/** Delete expired results. */
	public function prune() {
		global $wpdb;
		$days   = max( 30, min( 365, absint( get_option( 'nbh_performance_lab_retention_days', 180 ) ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE captured_at < %s AND is_baseline=0 LIMIT 5000', self::table_name(), $cutoff ) );
	}

	/** Convert database values to API-safe values. */
	private function hydrate( array $row ) {
		foreach ( array( 'id', 'total_byte_weight', 'request_count', 'is_baseline' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? absint( $row[ $key ] ) : 0;
		}
		foreach ( array( 'performance_score', 'lcp', 'cls', 'tbt', 'fcp', 'speed_index', 'ttfb' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) && null !== $row[ $key ] ? (float) $row[ $key ] : null;
		}
		$row['audits']        = $this->decode_json( isset( $row['audits'] ) ? $row['audits'] : '' );
		$row['opportunities'] = $this->decode_json( isset( $row['opportunities'] ) ? $row['opportunities'] : '' );
		return $row;
	}

	private function decode_json( $value ) {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function nullable_number( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
