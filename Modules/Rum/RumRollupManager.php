<?php
/** Bounded RUM aggregation and retention manager. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

use NBH\Performance\Modules\Core\Module;

final class RumRollupManager implements Module {
	private $storage;
	public function __construct( RumRollupStorage $storage ) {
		$this->storage = $storage;
	}
	public function register() {
		add_action( 'init', array( $this, 'schedule' ), 8 );
		add_action( 'nbh_performance_rum_rollup_hourly', array( $this, 'rollup_hourly' ) );
		add_action( 'nbh_performance_rum_rollup_daily', array( $this, 'rollup_daily' ) );
	}
	public function schedule() {
		if ( ! wp_next_scheduled( 'nbh_performance_rum_rollup_hourly' ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', 'nbh_performance_rum_rollup_hourly' );
		}
		if ( ! wp_next_scheduled( 'nbh_performance_rum_rollup_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nbh_performance_rum_rollup_daily' );
		}
	}
	public function rollup_hourly() {
		$this->aggregate( 'hourly', gmdate( 'Y-m-d H:00:00', time() - HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:00:00' ) );
	}
	public function rollup_daily() {
		$this->aggregate( 'daily', gmdate( 'Y-m-d 00:00:00', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d 00:00:00' ) );
	}
	private function aggregate( $bucket_type, $start, $end ) {
		global $wpdb;
		$table = RumStorage::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT context,device,connection,lcp,inp,cls,ttfb,fcp FROM %i WHERE collected_at >= %s AND collected_at < %s LIMIT 25000', $table, $start, $end ), ARRAY_A );
		if ( ! $rows ) {
			return;
		}
		$segments = array( 'overall:all' => $rows );
		foreach ( $rows as $row ) {
			foreach ( array( 'device', 'context', 'connection' ) as $type ) {
				$key = $type . ':' . sanitize_key( $row[ $type ] );
				if ( ! isset( $segments[ $key ] ) ) {
					$segments[ $key ] = array();
				}
				$segments[ $key ][] = $row;
			}
		}
		foreach ( $segments as $key => $items ) {
			list( $type, $value ) = explode( ':', $key, 2 );
			$this->storage->replace( $this->summarize( $items, $start, $bucket_type, $type, $value ) );
		}
	}
	private function summarize( array $rows, $start, $bucket, $type, $value ) {
		$out = array(
			'bucket_start'  => $start,
			'bucket_type'   => $bucket,
			'segment_type'  => $type,
			'segment_value' => $value,
			'samples'       => count( $rows ),
			'created_at'    => current_time( 'mysql', true ),
			'updated_at'    => current_time( 'mysql', true ),
		);
		foreach ( array( 'lcp', 'inp', 'cls', 'ttfb', 'fcp' ) as $metric ) {
			$values = array();
			foreach ( $rows as $row ) {
				if ( isset( $row[ $metric ] ) && null !== $row[ $metric ] && is_numeric( $row[ $metric ] ) ) {
					$values[] = (float) $row[ $metric ];
				}
			}
			foreach ( array( 50, 75, 95 ) as $p ) {
				$out[ 'p' . $p . '_' . $metric ] = $this->percentile( $values, $p );
			}
		}
		$out['good_lcp'] = $this->good_ratio( $rows, 'lcp', 2500 );
		$out['good_inp'] = $this->good_ratio( $rows, 'inp', 200 );
		$out['good_cls'] = $this->good_ratio( $rows, 'cls', 0.1 );
		return $out;
	}
	private function percentile( array $values, $p ) {
		if ( ! $values ) {
			return null;
		}
		sort( $values, SORT_NUMERIC );
		return round( $values[ max( 0, (int) ceil( ( $p / 100 ) * count( $values ) ) - 1 ) ], 4 );
	}
	private function good_ratio( array $rows, $metric, $limit ) {
		$valid = 0;
		$good  = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row[ $metric ] ) && null !== $row[ $metric ] && is_numeric( $row[ $metric ] ) ) {
				++$valid;
				if ( (float) $row[ $metric ] <= $limit ) {
					++$good;
				}
			}
		}
		return $valid ? round( ( $good / $valid ) * 100, 2 ) : null;
	}
}
