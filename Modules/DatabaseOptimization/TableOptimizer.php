<?php
/** Safe allowlisted table optimization. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

final class TableOptimizer {
	public function optimize( array $tables ) {
		global $wpdb;
		$allowed   = array_values( array_filter( $wpdb->tables( 'all', true ) ) );
		$requested = empty( $tables ) ? $allowed : array_values( array_intersect( $allowed, array_map( 'sanitize_text_field', $tables ) ) );
		$result    = array();
		foreach ( $requested as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
			$result[ $table ] = $wpdb->get_results( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ), ARRAY_A );
		}
		do_action( 'nbh_performance_after_optimize_tables', $result );
		return $result;
	}
}
