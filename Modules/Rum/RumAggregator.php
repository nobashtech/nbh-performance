<?php
/** RUM percentile and trend aggregation. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;

final class RumAggregator implements Module {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/rum/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
	public function summary( WP_REST_Request $request ) {
		global $wpdb;
		$requested_days_1 = $request->get_param( 'days' );
		$days             = min( 90, max( 1, absint( $requested_days_1 ? $requested_days_1 : 28 ) ) );
		$table            = RumStorage::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(collected_at) day, lcp, inp, cls FROM %i WHERE collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) ORDER BY collected_at ASC', $table, $days ), ARRAY_A );
		$daily = array();
		foreach ( $rows as $row ) {
			$day = $row['day'];
			if ( ! isset( $daily[ $day ] ) ) {
				$daily[ $day ] = array(
					'lcp' => array(),
					'inp' => array(),
					'cls' => array(),
				);
			}
			foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
				if ( null !== $row[ $metric ] ) {
					$daily[ $day ][ $metric ][] = (float) $row[ $metric ];
				}
			}
		}
		$trend = array();
		foreach ( $daily as $day => $metrics ) {
			$trend[] = array(
				'day'     => $day,
				'samples' => count( $metrics['lcp'] ),
				'p75_lcp' => $this->percentile( $metrics['lcp'], 75 ),
				'p75_inp' => $this->percentile( $metrics['inp'], 75 ),
				'p75_cls' => $this->percentile( $metrics['cls'], 75 ),
			);
		}
		return rest_ensure_response(
			array(
				'days'    => $days,
				'trend'   => $trend,
				'privacy' => 'No raw URL, cookie, user ID, or IP address is stored.',
			)
		);
	}
	private function percentile( array $values, $percentile ) {
		if ( empty( $values ) ) {
			return null;
		}
		sort( $values, SORT_NUMERIC );
		$index = (int) ceil( ( $percentile / 100 ) * count( $values ) ) - 1;
		return round( $values[ max( 0, $index ) ], 3 );
	}
}
