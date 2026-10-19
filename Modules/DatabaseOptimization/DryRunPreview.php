<?php
/** Database cleanup dry-run preview and autoload audit. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

final class DryRunPreview {
	public function generate() {
		global $wpdb;
		$counts               = array(
			'revisions'                 => $this->count_revisions(),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
			'auto_drafts'               => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(ID) FROM %i WHERE post_status = \'auto-draft\'', $wpdb->posts ) ),
			'trashed_posts'             => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(ID) FROM %i WHERE post_status = \'trash\'', $wpdb->posts ) ),
			'spam_comments'             => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(comment_ID) FROM %i WHERE comment_approved = \'spam\'', $wpdb->comments ) ),
			'trashed_comments'          => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(comment_ID) FROM %i WHERE comment_approved = \'trash\'', $wpdb->comments ) ),
			'expired_transients'        => $this->count_expired( $wpdb->options, 'option_name', 'option_value', '_transient_timeout_' ),
			'expired_site_transients'   => $this->count_expired( $wpdb->sitemeta, 'meta_key', 'meta_value', '_site_transient_timeout_' ),
			'orphan_postmeta'           => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(pm.meta_id) FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL', $wpdb->postmeta, $wpdb->posts ) ),
			'orphan_termmeta'           => $this->count_orphan_termmeta(),
			'orphan_commentmeta'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(cm.meta_id) FROM %i cm LEFT JOIN %i c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL', $wpdb->commentmeta, $wpdb->comments ) ),
			'orphan_term_relationships' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(tr.object_id) FROM %i tr LEFT JOIN %i p ON p.ID = tr.object_id WHERE p.ID IS NULL', $wpdb->term_relationships, $wpdb->posts ) ),
			'woocommerce_sessions'      => $this->count_woocommerce_sessions(),
			'woocommerce_transients'    => $this->count_expired( $wpdb->options, 'option_name', 'option_value', '_transient_timeout_wc_' ),
			'action_scheduler'          => $this->count_action_scheduler(),
		);
		$counts['total_rows'] = array_sum( $counts );
		$counts['autoload']   = $this->autoload_audit();
		return apply_filters( 'nbh_performance_database_dry_run_preview', $counts );
	}

	private function count_revisions() {
		global $wpdb;
		$days = max( 1, absint( get_option( 'nbh_performance_revision_retention_days', 30 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(ID) FROM %i WHERE post_type = \'revision\' AND post_modified_gmt < %s', $wpdb->posts, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
	private function count_expired( $table, $name_column, $value_column, $prefix ) {
		global $wpdb;
		if ( ! $table || ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i LIKE %s AND CAST(%i AS UNSIGNED) < %d', $table, $name_column, $wpdb->esc_like( $prefix ) . '%', $value_column, time() ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	private function count_orphan_termmeta() {
		global $wpdb;
		if ( empty( $wpdb->termmeta ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(tm.meta_id) FROM %i tm LEFT JOIN %i t ON t.term_id = tm.term_id WHERE t.term_id IS NULL', $wpdb->termmeta, $wpdb->terms ) );
	}
	private function count_woocommerce_sessions() {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_expiry < %d', $table, time() ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	private function count_action_scheduler() {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		$days = max( 7, absint( get_option( 'nbh_performance_action_scheduler_retention_days', 30 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (\'complete\',\'canceled\',\'failed\') AND scheduled_date_gmt < %s', $table, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	private function autoload_audit() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, LENGTH(option_value) AS bytes FROM %i WHERE autoload IN (\'yes\',\'on\',\'auto-on\',\'auto\') ORDER BY LENGTH(option_value) DESC LIMIT 20', $wpdb->options ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM %i WHERE autoload IN (\'yes\',\'on\',\'auto-on\',\'auto\')', $wpdb->options ) );
		return array(
			'total_bytes' => $total,
			'largest'     => is_array( $rows ) ? $rows : array(),
		);
	}
	private function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (string) $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
