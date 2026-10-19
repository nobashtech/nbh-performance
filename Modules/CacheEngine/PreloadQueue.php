<?php
/** Resource-aware durable preload queue. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;

final class PreloadQueue implements Module {
	private $resources;
	private $sitemaps;
	private $jobs;
	private $runs;
	public function __construct( SharedHostingAwareness $resources, SitemapDiscovery $sitemaps, JobQueue $jobs, PreloadRunStore $runs ) {
		$this->resources = $resources;
		$this->sitemaps  = $sitemaps;
		$this->jobs      = $jobs;
		$this->runs      = $runs;
	}
	public function register() {
		add_action( 'nbh_performance_sitemap_discovery_tick', array( $this, 'discover' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}
	public function schedule() {
		if ( ! wp_next_scheduled( 'nbh_performance_sitemap_discovery_tick' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'twicedaily', 'nbh_performance_sitemap_discovery_tick' );
		}
	}
	public function discover() {
		if ( ! get_option( 'nbh_performance_sitemap_preload_enabled', true ) || get_option( 'nbh_performance_preload_paused', 0 ) ) {
			return 0;
		}
		$limit   = max( 10, min( 10000, absint( get_option( 'nbh_performance_preload_max_urls', 500 ) ) ) );
		$records = $this->sitemaps->discover_records( $limit );
		$run     = $this->runs->start( 'scheduled', count( $records ) );
		$count   = $this->enqueue_records( $records, 20, $run['id'] );
		$this->runs->mark_queued( $count );
		return $count;
	}
	public function enqueue( array $urls, $priority = 50, $run_id = '' ) {
		$records = array();
		foreach ( $urls as $url ) {
			$records[] = array(
				'url'      => $url,
				'priority' => max( 1, min( 100, 100 - absint( $priority ) ) ),
				'lastmod'  => 0,
				'source'   => 'runtime',
			);
		}
		return $this->enqueue_records( $records, $priority, $run_id );
	}
	public function enqueue_records( array $records, $priority = 50, $run_id = '' ) {
		$host     = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$excluded = $this->excluded_patterns();
		$queued   = 0;
		$mobile   = (bool) get_option( 'nbh_performance_preload_mobile_variant', get_option( 'nbh_performance_mobile_cache_enabled', 0 ) );
		$variants = $mobile ? array( 'desktop', 'mobile' ) : array( 'desktop' );
		foreach ( $records as $record ) {
			$url = esc_url_raw( is_array( $record ) && isset( $record['url'] ) ? $record['url'] : (string) $record );
			if ( $this->is_excluded( $url, $excluded ) || ! wp_http_validate_url( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				continue;
			}
			$record_priority = is_array( $record ) && isset( $record['priority'] ) ? absint( $record['priority'] ) : max( 1, 100 - absint( $priority ) );
			$lastmod         = is_array( $record ) && isset( $record['lastmod'] ) ? absint( $record['lastmod'] ) : 0;
			$recency         = $lastmod > time() - 7 * DAY_IN_SECONDS ? 10 : ( $lastmod > time() - 30 * DAY_IN_SECONDS ? 5 : 0 );
			$job_priority    = max( 1, min( 100, $record_priority + $recency ) );
			foreach ( $variants as $variant ) {
				$id = $this->jobs->enqueue(
					'warm_url',
					array(
						'url'     => $url,
						'variant' => $variant,
						'run_id'  => sanitize_text_field( $run_id ),
						'source'  => is_array( $record ) ? sanitize_text_field( (string) ( $record['source'] ?? '' ) ) : '',
					),
					array(
						'priority'     => $job_priority,
						'max_attempts' => max( 1, min( 5, absint( get_option( 'nbh_performance_preload_retry_limit', 3 ) ) ) ),
						'unique_key'   => 'preload|' . hash( 'sha256', $url . '|' . $variant ),
					)
				);
				if ( $id ) {
					++$queued;
				}
			}
		}
		return $queued;
	}
	private function excluded_patterns() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_preload_excluded_urls', '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}
	private function is_excluded( $url, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( (string) $url, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
