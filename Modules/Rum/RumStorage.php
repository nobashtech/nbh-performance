<?php
/** RUM storage. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

final class RumStorage {
	const DELETE_BATCH = 5000;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_rum';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			collected_at datetime NOT NULL,
			url_hash char(64) NOT NULL,
			context varchar(32) NOT NULL DEFAULT 'other',
			device varchar(16) NOT NULL DEFAULT 'desktop',
			connection varchar(16) NOT NULL DEFAULT 'unknown',
			lcp decimal(10,2) DEFAULT NULL,
			inp decimal(10,2) DEFAULT NULL,
			cls decimal(10,4) DEFAULT NULL,
			ttfb decimal(10,2) DEFAULT NULL,
			fcp decimal(10,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY collected_at (collected_at),
			KEY context_time (context, collected_at),
			KEY url_time (url_hash, collected_at)
		) {$charset};";
		dbDelta( $sql );
	}

	public function insert( array $record ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$result = $wpdb->insert(
			self::table_name(),
			$record,
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f' )
		);
		return false !== $result;
	}

	/** Purge old rows in bounded batches to avoid long table locks. */
	public function purge_before( $date, $max_batches = 10 ) {
		global $wpdb;
		$table   = self::table_name();
		$total   = 0;
		$batches = max( 1, min( 100, absint( $max_batches ) ) );
		for ( $i = 0; $i < $batches; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE collected_at < %s LIMIT %d', $table, $date, self::DELETE_BATCH ) );
			if ( $deleted <= 0 ) {
				break;
			}
			$total += $deleted;
			if ( $deleted < self::DELETE_BATCH ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Enforce an absolute row ceiling without one unbounded DELETE statement.
	 *
	 * @param int $max_rows    Maximum retained rows.
	 * @param int $max_batches Maximum delete batches this invocation.
	 * @return int Rows deleted.
	 */
	public function trim_to_max_rows( $max_rows, $max_batches = 10 ) {
		global $wpdb;
		$table    = self::table_name();
		$max_rows = max( 1000, absint( $max_rows ) );
		$offset   = max( 0, $max_rows - 1 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $offset ) );
		if ( ! $cutoff ) {
			return 0;
		}

		$total   = 0;
		$batches = max( 1, min( 100, absint( $max_batches ) ) );
		for ( $i = 0; $i < $batches; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d LIMIT %d', $table, (int) $cutoff, self::DELETE_BATCH ) );
			if ( $deleted <= 0 ) {
				break;
			}
			$total += $deleted;
			if ( $deleted < self::DELETE_BATCH ) {
				break;
			}
		}
		return $total;
	}
}
