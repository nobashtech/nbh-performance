<?php
/** Prioritized, explainable performance recommendations. @package NBHPerformance */
namespace NBH\Performance\Modules\Intelligence;

final class RecommendationEngine {
	private $server;
	private $conflicts;
	public function __construct( \NBH\Performance\Modules\ServerOptimization\ServerCapabilityReport $server, \NBH\Performance\Modules\Compatibility\ConflictDiagnosis $conflicts ) {
		$this->server    = $server;
		$this->conflicts = $conflicts;
	}
	public function recommendations() {
		$items    = array();
		$rum      = $this->rum_snapshot();
		$cache    = (array) get_option( 'nbh_performance_cache_stats', array() );
		$failures = (array) get_option( 'nbh_performance_asset_failures', array() );
		$server   = $this->server->report();
		if ( isset( $rum['lcp'] ) && $rum['lcp'] > 2500 ) {
			$items[] = $this->item( 'poor-lcp', 95, 'critical', __( 'Improve Largest Contentful Paint', 'nbh-performance' ), __( 'Real-user p75 LCP is above 2.5 seconds.', 'nbh-performance' ), 'enable_render_path', array( 'p75_lcp' => $rum['lcp'] ) );
		}
		if ( isset( $rum['inp'] ) && $rum['inp'] > 200 ) {
			$items[] = $this->item( 'poor-inp', 90, 'critical', __( 'Reduce main-thread JavaScript work', 'nbh-performance' ), __( 'Real-user p75 INP is above 200 ms.', 'nbh-performance' ), 'enable_safe_script_delay', array( 'p75_inp' => $rum['inp'] ) );
		}
		if ( isset( $rum['cls'] ) && $rum['cls'] > 0.1 ) {
			$items[] = $this->item( 'poor-cls', 85, 'warning', __( 'Stabilize page layout', 'nbh-performance' ), __( 'Real-user p75 CLS is above 0.1.', 'nbh-performance' ), null, array( 'p75_cls' => $rum['cls'] ) );
		}
		if ( empty( $cache['files'] ) && get_option( 'nbh_performance_cache_enabled', 0 ) ) {
			$items[] = $this->item( 'empty-cache', 80, 'warning', __( 'Warm the page cache', 'nbh-performance' ), __( 'Page caching is enabled but no cached pages are currently reported.', 'nbh-performance' ), 'enable_preload', array() );
		}
		if ( count( $failures ) >= 5 || get_option( 'nbh_performance_asset_emergency_rollback', 0 ) ) {
			$items[] = $this->item( 'asset-failures', 100, 'critical', __( 'Recover unstable asset optimization', 'nbh-performance' ), __( 'Recent asset failures indicate that aggressive optimization should be rolled back.', 'nbh-performance' ), 'rollback_assets', array( 'failures' => count( $failures ) ) );
		}
		foreach ( $this->conflicts->diagnose() as $issue ) {
			if ( in_array( $issue['severity'], array( 'critical', 'warning' ), true ) ) {
				$items[] = $this->item( 'conflict-' . $issue['id'], 'critical' === $issue['severity'] ? 99 : 75, $issue['severity'], __( 'Resolve optimization overlap', 'nbh-performance' ), $issue['message'], isset( $issue['group'] ) ? 'rollback_' . sanitize_key( $issue['group'] ) : null, array( 'group' => isset( $issue['group'] ) ? $issue['group'] : '' ) );
			}
		}
		if ( isset( $server['compression']['brotli']['enabled'] ) && ! $server['compression']['brotli']['enabled'] ) {
			$items[] = $this->item( 'brotli', 45, 'info', __( 'Consider Brotli compression', 'nbh-performance' ), __( 'Brotli was not detected. Enable it at the server or CDN when supported.', 'nbh-performance' ), null, array() );
		}
		if ( isset( $server['hosting']['tier'] ) && 'constrained' === $server['hosting']['tier'] ) {
			$items[] = $this->item( 'constrained-hosting', 70, 'warning', __( 'Use conservative background processing', 'nbh-performance' ), __( 'The server appears resource-constrained, so aggressive preload concurrency is not recommended.', 'nbh-performance' ), 'conservative_preload', array() );
		}
		$items = apply_filters( 'nbh_performance_intelligence_recommendations', $items );
		$items = is_array( $items ) ? $items : array();
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['priority'] <=> (int) $a['priority'];
			}
		);
		return array_slice( $items, 0, 20 );
	}
	private function item( $id, $priority, $severity, $title, $reason, $action, array $evidence ) {
		return array(
			'id'       => sanitize_key( $id ),
			'priority' => (int) $priority,
			'severity' => $severity,
			'title'    => $title,
			'reason'   => $reason,
			'action'   => $action,
			'evidence' => $evidence,
		);
	}
	private function rum_snapshot() {
		global $wpdb;
		$table = \NBH\Performance\Modules\Rum\RumStorage::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reads/writes of plugin-owned measurements/jobs must reflect asynchronous updates and retention; WordPress has no object API for these tables.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT lcp, inp, cls FROM %i WHERE collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 28 DAY)', $table ), ARRAY_A );
		$out  = array();
		foreach ( array( 'lcp', 'inp', 'cls' ) as $m ) {
			$v = array();
			foreach ( $rows as $r ) {
				if ( null !== $r[ $m ] ) {
						$v[] = (float) $r[ $m ];
				}
			}
			if ( $v ) {
				sort( $v, SORT_NUMERIC );
				$out[ $m ] = $v[ max( 0, (int) ceil( .75 * count( $v ) ) - 1 ) ];
			}
		}
		return $out;
	}
}
