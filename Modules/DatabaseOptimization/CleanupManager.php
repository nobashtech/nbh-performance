<?php
/** Extensible, batched database cleanup registry. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

final class CleanupManager {
	private $allowed = array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'expired_site_transients', 'orphan_postmeta', 'orphan_termmeta', 'orphan_commentmeta', 'orphan_term_relationships', 'woocommerce_sessions', 'woocommerce_transients', 'action_scheduler' );

	public function sanitize_operations( array $operations ) {
		$allowed = (array) apply_filters( 'nbh_performance_database_cleanup_operations', $this->allowed );
		return array_values( array_intersect( $allowed, array_unique( array_map( 'sanitize_key', $operations ) ) ) );
	}

	public function run( array $operations ) {
		$result = array();
		foreach ( $this->sanitize_operations( $operations ) as $operation ) {
			$result[ $operation ] = $this->run_operation( $operation );
		}
		return $result;
	}

	private function run_operation( $operation ) {
		global $wpdb;
		$batch = $this->batch_size();
		switch ( $operation ) {
			case 'revisions':
			case 'auto_drafts':
			case 'trashed_posts':
				return $this->delete_posts( $operation, $batch );
			case 'spam_comments':
				return $this->delete_comments( 'spam', $batch );
			case 'trashed_comments':
				return $this->delete_comments( 'trash', $batch );
			case 'expired_transients':
				return $this->delete_expired_transients( $wpdb->options, '_transient_timeout_', false, $batch );
			case 'expired_site_transients':
				return $this->delete_expired_transients( $wpdb->sitemeta, '_site_transient_timeout_', true, $batch );
			case 'orphan_postmeta':
				return $this->delete_orphans( $wpdb->postmeta, 'meta_id', $wpdb->prepare( 'SELECT pm.meta_id FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL LIMIT %d', $wpdb->postmeta, $wpdb->posts, $batch ) );
			case 'orphan_commentmeta':
				return $this->delete_orphans( $wpdb->commentmeta, 'meta_id', $wpdb->prepare( 'SELECT cm.meta_id FROM %i cm LEFT JOIN %i c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL LIMIT %d', $wpdb->commentmeta, $wpdb->comments, $batch ) );
			case 'orphan_termmeta':
				if ( empty( $wpdb->termmeta ) ) {
					return 0;
				}
				return $this->delete_orphans( $wpdb->termmeta, 'meta_id', $wpdb->prepare( 'SELECT tm.meta_id FROM %i tm LEFT JOIN %i t ON t.term_id = tm.term_id WHERE t.term_id IS NULL LIMIT %d', $wpdb->termmeta, $wpdb->terms, $batch ) );
			case 'orphan_term_relationships':
				return $this->delete_orphans( $wpdb->term_relationships, 'object_id', $wpdb->prepare( 'SELECT tr.object_id FROM %i tr LEFT JOIN %i p ON p.ID = tr.object_id WHERE p.ID IS NULL LIMIT %d', $wpdb->term_relationships, $wpdb->posts, $batch ) );
			case 'woocommerce_sessions':
				return $this->delete_woocommerce_sessions( $batch );
			case 'woocommerce_transients':
				return $this->delete_woocommerce_transients( $batch );
			case 'action_scheduler':
				return $this->delete_action_scheduler( $batch );
		}
		$external = apply_filters( 'nbh_performance_run_database_cleanup_operation', null, $operation, $batch );
		return null === $external ? 0 : absint( $external );
	}

	private function delete_posts( $operation, $batch ) {
		global $wpdb;
		if ( 'revisions' === $operation ) {
			$days = max( 1, absint( get_option( 'nbh_performance_revision_retention_days', 30 ) ) );
			$sql  = $wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s AND post_modified_gmt < %s LIMIT %d', $wpdb->posts, 'revision', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), $batch );
		} else {
			$status = 'auto_drafts' === $operation ? 'auto-draft' : 'trash';
			$sql    = $wpdb->prepare( 'SELECT ID FROM %i WHERE post_status = %s LIMIT %d', $wpdb->posts, $status, $batch );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here. Both branches above prepare the table identifier, status/date and limit before this common read.
		$ids   = $wpdb->get_col( $sql );
		$count = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	private function delete_comments( $status, $batch ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT comment_ID FROM %i WHERE comment_approved = %s LIMIT %d', $wpdb->comments, $status, $batch ) );
		$count = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	private function delete_orphans( $table, $id_column, $select_sql ) {
		global $wpdb;
		if ( $this->table_excluded( $table ) || ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here. This private helper receives only fully prepared orphan SELECTs from its fixed call sites above; table existence is checked before use.
		$ids = array_values( array_unique( array_map( 'intval', $wpdb->get_col( $select_sql ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$arguments    = array_merge( array( $table, $id_column ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The dynamic IN fragment contains only generated %d placeholders; the argument array binds two %i identifiers followed by all validated integer IDs. Isolated deletion tests verify the count.
		$sql = $wpdb->prepare( "DELETE FROM %i WHERE %i IN ({$placeholders})", $arguments );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->query( $sql );
	}

	private function delete_expired_transients( $table, $prefix, $site, $batch ) {
		global $wpdb;
		if ( ! $table || $this->table_excluded( $table ) || ! $this->table_exists( $table ) ) {
			return 0;
		}
		$name_column  = $site ? 'meta_key' : 'option_name';
		$value_column = $site ? 'meta_value' : 'option_value';
		$like         = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i LIKE %s AND CAST(%i AS UNSIGNED) < %d LIMIT %d', $name_column, $table, $name_column, $like, $value_column, time(), $batch ) );
		$count = 0;
		foreach ( $names as $timeout_name ) {
			$name    = substr( $timeout_name, strlen( $prefix ) );
			$deleted = $site ? delete_site_transient( $name ) : delete_transient( $name );
			if ( $deleted ) {
				++$count;
			}
		}
		return $count;
	}

	private function delete_woocommerce_sessions( $batch ) {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		if ( $this->table_excluded( $table ) || ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_expiry < %d LIMIT %d', $table, time(), $batch ) );
	}

	private function delete_woocommerce_transients( $batch ) {
		global $wpdb;
		if ( $this->table_excluded( $wpdb->options ) ) {
			return 0;
		}
		$like = $wpdb->esc_like( '_transient_timeout_wc_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d', $wpdb->options, $like, time(), $batch ) );
		$count = 0;
		foreach ( $names as $timeout ) {
			if ( delete_transient( substr( $timeout, strlen( '_transient_timeout_' ) ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	private function delete_action_scheduler( $batch ) {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( $this->table_excluded( $actions ) || ! $this->table_exists( $actions ) ) {
			return 0;
		}
		$days   = max( 7, absint( get_option( 'nbh_performance_action_scheduler_retention_days', 30 ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT action_id FROM %i WHERE status IN (\'complete\',\'canceled\',\'failed\') AND scheduled_date_gmt < %s LIMIT %d', $actions, $cutoff, $batch ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		if ( $this->table_exists( $logs ) && ! $this->table_excluded( $logs ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here. IN contains only generated %d placeholders; the table and integer action IDs are bound by prepare().
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ({$in})", array_merge( array( $logs ), $ids ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here. IN contains only generated %d placeholders; the table and integer action IDs are bound by prepare().
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ({$in})", array_merge( array( $actions ), $ids ) ) );
	}

	private function batch_size() {
		return max( 25, min( 1000, absint( get_option( 'nbh_performance_database_batch_size', 250 ) ) ) );
	}
	private function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		return (string) $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
	private function table_excluded( $table ) {
		$lines  = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_database_excluded_tables', '' ) );
		$tables = array_filter( array_map( 'trim', (array) $lines ) );
		return in_array( (string) $table, $tables, true ) || in_array( preg_replace( '/^' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . '/', '', (string) $table ), $tables, true );
	}
}
