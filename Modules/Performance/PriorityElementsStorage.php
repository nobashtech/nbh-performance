<?php
/**
 * Privacy-safe aggregated browser priority-elements storage.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Performance;

final class PriorityElementsStorage {
	const SCHEMA_VERSION = 2;
	const REPORT_VERSION = 2;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'nbh_performance_priority_elements';
	}

	/** Install or upgrade the bounded aggregate table. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(64) NOT NULL,
			url text NOT NULL,
			variant varchar(16) NOT NULL DEFAULT 'desktop',
			report_version smallint(5) unsigned NOT NULL DEFAULT 2,
			report longtext NOT NULL,
			samples int(10) unsigned NOT NULL DEFAULT 0,
			quality_score decimal(6,4) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_variant (url_hash, variant),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'nbh_performance_priority_elements_schema_version', self::SCHEMA_VERSION, false );
	}

	/** Return a recent aggregate with per-resource confidence. */
	public function get( $url, $variant ) {
		global $wpdb;
		$url     = $this->normalize_url( $url );
		$variant = $this->normalize_variant( $variant );
		if ( ! $url ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT report,report_version,samples,quality_score,updated_at FROM %i WHERE url_hash = %s AND variant = %s LIMIT 1', self::table_name(), hash( 'sha256', strtolower( $url ) ), $variant ),
			ARRAY_A
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( ! is_array( $row ) ) {
			return array();
		}
		$retention = max( 1, min( 365, absint( get_option( 'nbh_performance_priority_elements_retention_days', 30 ) ) ) );
		if ( strtotime( (string) $row['updated_at'] ) < time() - $retention * DAY_IN_SECONDS ) {
			return array();
		}
		$report = json_decode( (string) $row['report'], true );
		if ( ! is_array( $report ) ) {
			return array();
		}
		$report['report_version'] = absint( $row['report_version'] );
		$report['samples']        = absint( $row['samples'] );
		$report['quality_score']  = (float) $row['quality_score'];
		$report['updated_at']     = (string) $row['updated_at'];
		return $report;
	}

	/** Merge one anonymous observation into the URL/device aggregate. */
	public function record( $url, $variant, array $incoming ) {
		global $wpdb;
		$url     = $this->normalize_url( $url );
		$variant = $this->normalize_variant( $variant );
		if ( ! $url ) {
			return false;
		}

		$lock = $this->acquire_lock( $url, $variant );
		if ( ! $lock ) {
			return false;
		}
		try {
			$current = $this->get_unrestricted( $url, $variant );
			$samples = min( 1000000, 1 + ( isset( $current['samples'] ) ? absint( $current['samples'] ) : 0 ) );
			$merged  = $this->merge( isset( $current['report'] ) ? $current['report'] : array(), $incoming, $samples );
			$quality = $this->quality_score( $merged, $samples );
			$now     = gmdate( 'Y-m-d H:i:s' );
			$json    = wp_json_encode( $merged, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) || strlen( $json ) > 131072 ) {
				return false;
			}

			$data    = array(
				'url_hash'       => hash( 'sha256', strtolower( $url ) ),
				'url'            => $url,
				'variant'        => $variant,
				'report_version' => self::REPORT_VERSION,
				'report'         => $json,
				'samples'        => $samples,
				'quality_score'  => $quality,
				'updated_at'     => $now,
			);
			$formats = array( '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%s' );
			if ( ! empty( $current['id'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
				return false !== $wpdb->update( self::table_name(), $data, array( 'id' => absint( $current['id'] ) ), $formats, array( '%d' ) );
			}
			$data['created_at'] = $now;
			$formats[]          = '%s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
			return (bool) $wpdb->insert( self::table_name(), $data, $formats );
		} finally {
			$this->release_lock( $url, $variant, $lock );
		}
	}

	/** Dashboard summary without exposing report contents. */
	public function stats() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$count = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(updated_at) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$samples = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(samples),0) FROM %i', $table ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$quality = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(AVG(quality_score),0) FROM %i', $table ) );
		return array(
			'urls'            => $count,
			'samples'         => $samples,
			'average_quality' => round( $quality, 4 ),
			'last_report_at'  => $last ? strtotime( $last ) : 0,
		);
	}

	/** Remove old aggregates. */
	public function prune() {
		global $wpdb;
		$retention = max( 1, min( 365, absint( get_option( 'nbh_performance_priority_elements_retention_days', 30 ) ) ) );
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s', self::table_name(), $cutoff ) );
	}

	/** Clear learned data when explicitly requested by an administrator. */
	public function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table_name() ) );
	}

	private function get_unrestricted( $url, $variant ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id,report,samples FROM %i WHERE url_hash = %s AND variant = %s LIMIT 1', self::table_name(), hash( 'sha256', strtolower( $url ) ), $variant ),
			ARRAY_A
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( ! is_array( $row ) ) {
			return array();
		}
		$report        = json_decode( (string) $row['report'], true );
		$row['report'] = is_array( $report ) ? $report : array();
		return $row;
	}

	/** Merge bounded counters rather than retaining individual visits. */
	private function merge( array $current, array $incoming, $samples ) {
		$merged = array();
		foreach ( array( 'lcp', 'images', 'backgrounds', 'fonts', 'origins' ) as $type ) {
			$items = array();
			foreach ( (array) ( $current[ $type ] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['key'] ) ) {
					continue;
				}
				$key           = substr( sanitize_text_field( (string) $item['key'] ), 0, 300 );
				$items[ $key ] = $this->normalize_item( $item );
			}
			foreach ( (array) ( $incoming[ $type ] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['key'] ) ) {
					continue;
				}
				$key = substr( sanitize_text_field( (string) $item['key'] ), 0, 300 );
				if ( ! isset( $items[ $key ] ) ) {
					$items[ $key ]                = $this->normalize_item( $item );
					$items[ $key ]['hits']        = 0;
					$items[ $key ]['score_total'] = 0;
				}
				$score                        = max( 1, min( 100, absint( $item['score'] ?? 1 ) ) );
				$items[ $key ]['hits']        = min( 1000000, absint( $items[ $key ]['hits'] ?? 0 ) + 1 );
				$items[ $key ]['score_total'] = min( 100000000, absint( $items[ $key ]['score_total'] ?? 0 ) + $score );
				$items[ $key ]['last_seen']   = time();
				if ( empty( $items[ $key ]['url'] ) && ! empty( $item['url'] ) ) {
					$items[ $key ]['url'] = esc_url_raw( (string) $item['url'] );
				}
				if ( empty( $items[ $key ]['selector'] ) && ! empty( $item['selector'] ) ) {
					$items[ $key ]['selector'] = substr( sanitize_text_field( (string) $item['selector'] ), 0, 200 );
				}
			}
			foreach ( $items as &$item ) {
				$hits                = max( 1, absint( $item['hits'] ?? 1 ) );
				$score_total         = max( $hits, absint( $item['score_total'] ?? ( $item['score'] ?? 1 ) * $hits ) );
				$item['score']       = round( $score_total / $hits, 2 );
				$item['confidence']  = round( min( 1, ( $hits / max( 1, $samples ) ) * min( 1, $item['score'] / 50 ) ), 4 );
				$item['hits']        = $hits;
				$item['score_total'] = $score_total;
			}
			unset( $item );
			usort(
				$items,
				static function ( $a, $b ) {
					$confidence = (float) $b['confidence'] <=> (float) $a['confidence'];
					return 0 !== $confidence ? $confidence : (float) $b['score'] <=> (float) $a['score'];
				}
			);
			$merged[ $type ] = array_slice( array_values( $items ), 0, 'origins' === $type ? 10 : 16 );
		}

		$viewport             = isset( $incoming['viewport'] ) && is_array( $incoming['viewport'] ) ? $incoming['viewport'] : ( $current['viewport'] ?? array() );
		$merged['viewport']   = array(
			'width'  => max( 320, min( 3840, absint( $viewport['width'] ?? 0 ) ) ),
			'height' => max( 320, min( 2160, absint( $viewport['height'] ?? 0 ) ) ),
		);
		$merged['updated_at'] = time();
		return $merged;
	}

	private function normalize_item( array $item ) {
		$hits        = max( 1, absint( $item['hits'] ?? 1 ) );
		$score       = max( 1, min( 100, (float) ( $item['score'] ?? 1 ) ) );
		$score_total = max( $hits, absint( $item['score_total'] ?? round( $score * $hits ) ) );
		return array(
			'key'         => substr( sanitize_text_field( (string) ( $item['key'] ?? '' ) ), 0, 300 ),
			'url'         => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
			'selector'    => substr( sanitize_text_field( (string) ( $item['selector'] ?? '' ) ), 0, 200 ),
			'type'        => sanitize_key( (string) ( $item['type'] ?? '' ) ),
			'hits'        => $hits,
			'score_total' => $score_total,
			'score'       => round( $score_total / $hits, 2 ),
			'confidence'  => max( 0, min( 1, (float) ( $item['confidence'] ?? 0 ) ) ),
			'last_seen'   => absint( $item['last_seen'] ?? time() ),
		);
	}

	private function quality_score( array $report, $samples ) {
		$best = 0;
		foreach ( array( 'lcp', 'images', 'fonts', 'origins' ) as $type ) {
			foreach ( (array) ( $report[ $type ] ?? array() ) as $item ) {
				$best = max( $best, (float) ( $item['confidence'] ?? 0 ) );
			}
		}
		return round( min( 1, $best * min( 1, $samples / 5 ) ), 4 );
	}

	/** Normalize the page URL and drop non-allowlisted query parameters. */
	public function normalize_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || strtolower( (string) ( $home['host'] ?? '' ) ) !== $host || absint( $parts['port'] ?? 0 ) !== absint( $home['port'] ?? 0 ) ) {
			return '';
		}
		$path       = isset( $parts['path'] ) && '' !== $parts['path'] ? '/' . ltrim( $parts['path'], '/' ) : '/';
		$normalized = $scheme . '://' . $host;
		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . absint( $parts['port'] );
		}
		$normalized .= $path;

		$allowed = $this->allowed_query_args();
		if ( ! empty( $parts['query'] ) && $allowed ) {
			parse_str( (string) $parts['query'], $query );
			$query = array_intersect_key( $query, array_flip( $allowed ) );
			ksort( $query );
			if ( $query ) {
				$normalized = add_query_arg( array_map( 'sanitize_text_field', $query ), $normalized );
			}
		}
		return esc_url_raw( $normalized );
	}

	private function allowed_query_args() {
		$value = (string) get_option( 'nbh_performance_priority_elements_allowed_query_args', '' );
		if ( '' === trim( $value ) ) {
			$value = (string) get_option( 'nbh_performance_cache_allowed_query_args', '' );
		}
		$lines = preg_split( '/\r\n|\r|\n|,/', $value );
		return array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $lines ) ) ) ), 0, 25 );
	}

	private function normalize_variant( $variant ) {
		return 'mobile' === sanitize_key( $variant ) ? 'mobile' : 'desktop';
	}

	private function acquire_lock( $url, $variant ) {
		$key    = 'nbh_performance_pe_lock_' . substr( hash( 'sha256', strtolower( $url ) . '|' . $variant ), 0, 32 );
		$token  = wp_generate_uuid4();
		$record = get_option( $key, array() );
		if ( is_array( $record ) && ! empty( $record['created_at'] ) && absint( $record['created_at'] ) < time() - 30 ) {
			delete_option( $key );
		}
		return add_option(
			$key,
			array(
				'token'      => $token,
				'created_at' => time(),
			),
			'',
			false
		) ? array( $key, $token ) : false;
	}

	private function release_lock( $url, $variant, $lock ) {
		unset( $url, $variant );
		if ( ! is_array( $lock ) || 2 !== count( $lock ) ) {
			return;
		}
		$record = get_option( $lock[0], array() );
		if ( is_array( $record ) && isset( $record['token'] ) && hash_equals( (string) $record['token'], (string) $lock[1] ) ) {
			delete_option( $lock[0] );
		}
	}
}
