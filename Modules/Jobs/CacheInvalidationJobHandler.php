<?php
/** Targeted cache invalidation background handler. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\CacheEngine\CacheIndex;
use NBH\Performance\Modules\CacheEngine\PageCacheManager;

final class CacheInvalidationJobHandler implements JobHandlerInterface {
	private $index;
	private $page_cache;
	private $queue;

	public function __construct( CacheIndex $index, PageCacheManager $page_cache, JobQueue $queue ) {
		$this->index      = $index;
		$this->page_cache = $page_cache;
		$this->queue      = $queue;
	}

	public function supports( $type ) {
		return 'cache_invalidate' === $type;
	}

	public function handle( array $payload ) {
		$all          = ! empty( $payload['all'] );
		$dependencies = isset( $payload['dependencies'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $payload['dependencies'] ) ) ) ) : array();
		$urls         = isset( $payload['urls'] ) ? array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $payload['urls'] ) ) ) ) : array();
		$warm_urls    = isset( $payload['warm_urls'] ) ? array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $payload['warm_urls'] ) ) ) ) : array();

		$purged = true;
		if ( $all ) {
			$purged = $this->page_cache->purge_all();
		} else {
			if ( $dependencies ) {
				$this->index->purge_dependencies( $dependencies );
				if ( in_array( 'global-layout', $dependencies, true ) ) {
					do_action( 'nbh_performance_after_cache_purge', 'all', true );
				}
			}
			foreach ( array_slice( $urls, 0, 100 ) as $url ) {
				$purged = $this->page_cache->purge_url( $url ) && $purged;
			}
		}

		$this->flush_object_cache_groups( isset( $payload['object_groups'] ) ? (array) $payload['object_groups'] : array( 'posts', 'terms', 'options' ) );
		foreach ( array_slice( $warm_urls, 0, 50 ) as $url ) {
			$this->queue->enqueue(
				'warm_url',
				array( 'url' => $url ),
				array(
					'priority'     => 20,
					'max_attempts' => 3,
					'unique_key'   => 'warm|' . md5( $url ),
				)
			);
		}
		do_action( 'nbh_performance_invalidation_graph_completed', $payload, $purged );
		return array(
			'success'      => $purged,
			'all'          => $all,
			'dependencies' => count( $dependencies ),
			'urls'         => count( $urls ),
			'warm_urls'    => count( $warm_urls ),
		);
	}

	private function flush_object_cache_groups( array $groups ) {
		foreach ( array_unique( array_filter( array_map( 'sanitize_key', $groups ) ) ) as $group ) {
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( $group );
			}
		}
	}
}
