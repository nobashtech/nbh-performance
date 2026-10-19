<?php
/** Page warm-up background handler. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\CacheEngine\CachePrimer;
use NBH\Performance\Modules\CacheEngine\PreloadRunStore;

final class WarmUrlJobHandler implements JobHandlerInterface {
	private $runs;
	public function __construct( PreloadRunStore $runs ) {
		$this->runs = $runs;
	}
	public function supports( $type ) {
		return 'warm_url' === $type;
	}
	public function handle( array $payload ) {
		$url     = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : '';
		$run     = $this->runs->current();
		$tracked = ! empty( $payload['run_id'] ) && isset( $run['id'] ) && hash_equals( (string) $run['id'], (string) $payload['run_id'] );
		$variant = isset( $payload['variant'] ) ? sanitize_key( $payload['variant'] ) : 'desktop';
		if ( get_option( 'nbh_performance_preload_paused', 0 ) ) {
			return new \WP_Error( 'nbh_performance_preload_paused', __( 'The preload queue is paused.', 'nbh-performance' ) );
		}
		if ( ! get_option( 'nbh_performance_cache_enabled', 0 ) || get_option( 'nbh_performance_safe_mode', 1 ) ) {
			if ( $tracked ) {
				$this->runs->mark_skipped( $url, 'cache_disabled_or_safe_mode' );
			}
			return array(
				'success' => true,
				'skipped' => 'cache_disabled_or_safe_mode',
			);
		}
		if ( ! wp_http_validate_url( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
			if ( $tracked ) {
				$this->runs->mark_failed( $url, 'invalid_same_origin_url' );
			}
			return new \WP_Error( 'nbh_performance_invalid_warm_url', __( 'The warm-up URL is not a valid same-origin URL.', 'nbh-performance' ) );
		}
		$result = CachePrimer::warm( $url, $variant );
		if ( ! empty( $result['success'] ) ) {
			if ( $tracked ) {
				$this->runs->mark_completed( $url );
			}
		} elseif ( $tracked ) {
				$this->runs->mark_failed( $url, isset( $result['error'] ) ? $result['error'] : 'warm_failed' );
		}
		/* translators: %s: page URL that failed to preload. */
		return ! empty( $result['success'] ) ? $result : new \WP_Error( 'nbh_performance_warm_failed', sprintf( __( 'Warm-up failed for %s.', 'nbh-performance' ), $url ), $result );
	}
}
