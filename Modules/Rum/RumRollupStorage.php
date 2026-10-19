<?php
/** Durable hourly and daily RUM rollups. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

final class RumRollupStorage {
	const SCHEMA_VERSION = 2;
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_rum_rollups';
	}
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_start datetime NOT NULL,
			bucket_type varchar(12) NOT NULL DEFAULT 'daily',
			segment_type varchar(20) NOT NULL DEFAULT 'overall',
			segment_value varchar(64) NOT NULL DEFAULT 'all',
			samples int(10) unsigned NOT NULL DEFAULT 0,
			p50_lcp decimal(12,2) DEFAULT NULL,
			p75_lcp decimal(12,2) DEFAULT NULL,
			p95_lcp decimal(12,2) DEFAULT NULL,
			p50_inp decimal(12,2) DEFAULT NULL,
			p75_inp decimal(12,2) DEFAULT NULL,
			p95_inp decimal(12,2) DEFAULT NULL,
			p50_cls decimal(12,4) DEFAULT NULL,
			p75_cls decimal(12,4) DEFAULT NULL,
			p95_cls decimal(12,4) DEFAULT NULL,
			p50_ttfb decimal(12,2) DEFAULT NULL,
			p75_ttfb decimal(12,2) DEFAULT NULL,
			p95_ttfb decimal(12,2) DEFAULT NULL,
			p50_fcp decimal(12,2) DEFAULT NULL,
			p75_fcp decimal(12,2) DEFAULT NULL,
			p95_fcp decimal(12,2) DEFAULT NULL,
			good_lcp decimal(6,2) DEFAULT NULL,
			good_inp decimal(6,2) DEFAULT NULL,
			good_cls decimal(6,2) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_segment (bucket_start,bucket_type,segment_type,segment_value),
			KEY bucket_time (bucket_type,bucket_start)
		) {$charset};";
		dbDelta( $sql );
		$expected = array( 'id', 'bucket_start', 'bucket_type', 'segment_type', 'segment_value', 'samples', 'good_lcp', 'good_inp', 'good_cls', 'created_at', 'updated_at' );
		foreach ( array( 'lcp', 'inp', 'cls', 'ttfb', 'fcp' ) as $metric ) {
			foreach ( array( 'p50', 'p75', 'p95' ) as $percentile ) {
				$expected[] = $percentile . '_' . $metric;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$indexes = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 2 );
		if ( array_diff( $expected, (array) $columns ) || array_diff( array( 'PRIMARY', 'bucket_segment', 'bucket_time' ), (array) $indexes ) ) {
			return false;
		}
		update_option( 'nbh_performance_rum_rollup_schema_version', self::SCHEMA_VERSION, false );
		return true;
	}
	public function replace( array $row ) {
		global $wpdb;
		$formats = array_fill( 0, count( $row ), '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		return false !== $wpdb->replace( self::table_name(), $row, $formats );
	}
	public function history( $days = 28, $bucket = 'daily', $segment_type = 'overall', $segment_value = 'all' ) {
		global $wpdb;
		$table = self::table_name();
		$days  = max( 1, min( 90, absint( $days ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE bucket_type=%s AND segment_type=%s AND segment_value=%s AND bucket_start >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) ORDER BY bucket_start ASC', $table, sanitize_key( $bucket ), sanitize_key( $segment_type ), sanitize_text_field( $segment_value ), $days ), ARRAY_A );
	}
}
