<?php
/**
 * Scalable page-cache index with normalized cache tags.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

final class CacheIndex {
	const SCHEMA_VERSION = 4;

	/** Return the primary cache-entry table. */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_cache_index';
	}

	/** Return the normalized cache-tag mapping table. */
	public static function tags_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_cache_tags';
	}

	/** Install or upgrade cache index tables. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table      = self::table_name();
		$tags_table = self::tags_table_name();
		$charset    = $wpdb->get_charset_collate();
		$sql        = "CREATE TABLE {$table} (
			cache_key varchar(191) NOT NULL,
			file_path text NOT NULL,
			metadata_path text NOT NULL,
			url_hash char(64) NOT NULL,
			url text NOT NULL,
			dependencies longtext NOT NULL,
			variants longtext NOT NULL,
			status_code smallint(5) unsigned NOT NULL DEFAULT 200,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			generated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			stale_until bigint(20) unsigned NOT NULL DEFAULT 0,
			last_accessed bigint(20) unsigned NOT NULL DEFAULT 0,
			hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (cache_key),
			KEY url_hash (url_hash),
			KEY generated_at (generated_at),
			KEY stale_until (stale_until)
		) {$charset};";
		$tag_sql    = "CREATE TABLE {$tags_table} (
			cache_key varchar(191) NOT NULL,
			cache_tag varchar(191) NOT NULL,
			PRIMARY KEY  (cache_key, cache_tag),
			KEY cache_tag (cache_tag)
		) {$charset};";

		dbDelta( $sql );
		dbDelta( $tag_sql );
		update_option( 'nbh_performance_cache_index_schema_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Add or replace one entry and its normalized dependency tags.
	 *
	 * @param string $key          Cache key.
	 * @param string $file         HTML file.
	 * @param string $url          Canonical URL.
	 * @param array  $dependencies Dependency tags.
	 * @param array  $variants     Cache variants.
	 * @param array  $metadata     Response metadata.
	 * @return bool
	 */
	public function record( $key, $file, $url, array $dependencies, array $variants, array $metadata = array() ) {
		global $wpdb;

		$key = $this->normalize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$file         = wp_normalize_path( $file );
		$url          = esc_url_raw( $url );
		$dependencies = array_values( array_unique( array_filter( array_map( 'sanitize_key', $dependencies ) ) ) );
		$paths        = CacheEntryFiles::paths( $file );
		$generated    = isset( $metadata['generated_at'] ) ? absint( $metadata['generated_at'] ) : time();
		$expires      = isset( $metadata['expires_at'] ) ? absint( $metadata['expires_at'] ) : $generated;
		$stale_until  = isset( $metadata['stale_until'] ) ? absint( $metadata['stale_until'] ) : $expires;
		$data         = array(
			'cache_key'     => $key,
			'file_path'     => $file,
			'metadata_path' => $paths['metadata'],
			'url_hash'      => hash( 'sha256', $this->normalize_url( $url ) ),
			'url'           => $url,
			'dependencies'  => wp_json_encode( $dependencies ),
			'variants'      => wp_json_encode( $variants ),
			'status_code'   => isset( $metadata['status'] ) ? absint( $metadata['status'] ) : 200,
			'file_size'     => is_file( $file ) ? (int) filesize( $file ) : 0,
			'generated_at'  => $generated,
			'expires_at'    => $expires,
			'stale_until'   => $stale_until,
			'last_accessed' => $generated,
			'hit_count'     => 0,
		);
		$formats      = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$result = $wpdb->replace( self::table_name(), $data, $formats );
		if ( false === $result ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$wpdb->delete( self::tags_table_name(), array( 'cache_key' => $key ), array( '%s' ) );
		foreach ( $dependencies as $tag ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (cache_key, cache_tag) VALUES (%s, %s)',
					self::tags_table_name(),
					$key,
					$tag
				)
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$this->prune();
		return true;
	}

	/** Return all entries keyed by cache key. */
	public function all() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cache_key, file_path, metadata_path, url, dependencies, variants, status_code, file_size, generated_at, expires_at, stale_until, last_accessed, hit_count FROM %i ORDER BY generated_at DESC', $table ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['cache_key'] ] = $this->hydrate( $row );
		}
		return $out;
	}

	/** Delete every entry carrying one of the supplied normalized tags. */
	public function purge_dependencies( array $dependencies ) {
		global $wpdb;
		$dependencies = array_values( array_unique( array_filter( array_map( 'sanitize_key', $dependencies ) ) ) );
		if ( empty( $dependencies ) ) {
			return true;
		}

		$placeholders = implode( ',', array_fill( 0, count( $dependencies ), '%s' ) );
		$sql          = "SELECT DISTINCT i.cache_key, i.file_path FROM %i t INNER JOIN %i i ON i.cache_key=t.cache_key WHERE t.cache_tag IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::tags_table_name(), self::table_name() ), $dependencies ) ), ARRAY_A );
		$success = true;

		foreach ( (array) $rows as $row ) {
			if ( ! CacheEntryFiles::delete( isset( $row['file_path'] ) ? $row['file_path'] : '' ) ) {
				$success = false;
			}
			$this->remove( isset( $row['cache_key'] ) ? $row['cache_key'] : '' );
		}
		return $success;
	}

	/** Return all indexed variants for a canonical URL. */
	public function entries_for_url( $url ) {
		global $wpdb;
		$table = self::table_name();
		$hash  = hash( 'sha256', $this->normalize_url( $url ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cache_key, file_path, metadata_path, url, dependencies, variants, status_code, file_size, generated_at, expires_at, stale_until, last_accessed, hit_count FROM %i WHERE url_hash=%s', $table, $hash ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/** Remove one indexed key and all normalized tags. */
	public function remove( $key ) {
		global $wpdb;
		$key = $this->normalize_key( $key );
		if ( '' === $key ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$wpdb->delete( self::tags_table_name(), array( 'cache_key' => $key ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		return false !== $wpdb->delete( self::table_name(), array( 'cache_key' => $key ), array( '%s' ) );
	}

	/** Return constant-time cache totals. */
	public function summary() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS files, COALESCE(SUM(file_size), 0) AS bytes, COALESCE(SUM(hit_count), 0) AS hits FROM %i', $table ), ARRAY_A );
		return array(
			'files' => is_array( $row ) ? (int) $row['files'] : 0,
			'bytes' => is_array( $row ) ? (int) $row['bytes'] : 0,
			'hits'  => is_array( $row ) ? (int) $row['hits'] : 0,
		);
	}

	/** Return a bounded batch whose stale safety window has ended. */
	public function stale_entries( $cutoff, $limit = 1000 ) {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = max( 0, (int) $cutoff );
		$limit  = max( 1, min( 5000, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cache_key, file_path, stale_until FROM %i WHERE stale_until > 0 AND stale_until < %d ORDER BY stale_until ASC LIMIT %d', $table, $cutoff, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Clear both index tables. */
	public function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::tags_table_name() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table_name() ) );
	}

	/** Remove orphaned index rows whose HTML or metadata is missing. */
	public function repair_orphans( $limit = 2000 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 5000, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT cache_key, file_path, metadata_path FROM %i ORDER BY generated_at ASC LIMIT %d', $table, $limit ), ARRAY_A );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			if ( ! is_file( $row['file_path'] ) || ! is_file( $row['metadata_path'] ) ) {
				CacheEntryFiles::delete( $row['file_path'] );
				$this->remove( $row['cache_key'] );
				++$count;
			}
		}
		return $count;
	}

	/** Bound the index and remove the oldest complete entries. */
	private function prune() {
		global $wpdb;
		if ( get_transient( 'nbh_performance_cache_index_prune_lock' ) ) {
			return;
		}
		set_transient( 'nbh_performance_cache_index_prune_lock', 1, 5 * MINUTE_IN_SECONDS );
		$maximum = max( 1000, (int) apply_filters( 'nbh_performance_cache_index_limit', 100000 ) );
		$table   = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $count <= $maximum ) {
			return;
		}
		$remove = min( 5000, $count - $maximum );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cache_key, file_path FROM %i ORDER BY generated_at ASC LIMIT %d', $table, $remove ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			CacheEntryFiles::delete( isset( $row['file_path'] ) ? $row['file_path'] : '' );
			$this->remove( isset( $row['cache_key'] ) ? $row['cache_key'] : '' );
		}
	}

	/** Normalize a key into the database limit. */
	private function normalize_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key || false !== strpos( $key, '..' ) ) {
			return '';
		}
		return strlen( $key ) <= 191 ? $key : 'sha256:' . hash( 'sha256', $key );
	}

	/** Normalize URLs so all variants map to one purge target. */
	private function normalize_url( $url ) {
		$parts = wp_parse_url( esc_url_raw( (string) $url ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';
		$query = isset( $parts['query'] ) ? ( new CacheKeyGenerator() )->canonical_query( $parts['query'] ) : '';
		return $host . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/** Hydrate one database row. */
	private function hydrate( $row ) {
		return array(
			'key'           => isset( $row['cache_key'] ) ? $row['cache_key'] : '',
			'file'          => isset( $row['file_path'] ) ? wp_normalize_path( $row['file_path'] ) : '',
			'metadata'      => isset( $row['metadata_path'] ) ? wp_normalize_path( $row['metadata_path'] ) : '',
			'url'           => isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '',
			'dependencies'  => isset( $row['dependencies'] ) ? $this->decode_array( $row['dependencies'] ) : array(),
			'variants'      => isset( $row['variants'] ) ? $this->decode_array( $row['variants'] ) : array(),
			'status'        => isset( $row['status_code'] ) ? (int) $row['status_code'] : 200,
			'file_size'     => isset( $row['file_size'] ) ? (int) $row['file_size'] : 0,
			'generated_at'  => isset( $row['generated_at'] ) ? (int) $row['generated_at'] : 0,
			'expires_at'    => isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			'stale_until'   => isset( $row['stale_until'] ) ? (int) $row['stale_until'] : 0,
			'last_accessed' => isset( $row['last_accessed'] ) ? (int) $row['last_accessed'] : 0,
			'hit_count'     => isset( $row['hit_count'] ) ? (int) $row['hit_count'] : 0,
		);
	}

	private function decode_array( $value ) {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
