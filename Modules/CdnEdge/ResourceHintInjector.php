<?php
/** Automated resource hints for configured CDN origins. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

final class ResourceHintInjector {
	public function filter_hints( $urls, $relation_type ) {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_resource_hints_enabled', 0 ) || ! in_array( $relation_type, array( 'preconnect', 'dns-prefetch' ), true ) ) {
			return $urls;
		}
		$origins = array();
		$cdn     = esc_url_raw( (string) get_option( 'nbh_performance_cdn_base_url', '' ) );
		if ( $cdn ) {
			$origins[] = $this->origin( $cdn );
		}
		$origins = array_filter( array_unique( (array) apply_filters( 'nbh_performance_resource_hint_origins', $origins ) ) );
		foreach ( array_slice( $origins, 0, 8 ) as $origin ) {
			$urls[] = 'preconnect' === $relation_type ? array(
				'href'        => $origin,
				'crossorigin' => 'anonymous',
			) : $origin;
		}
		return $urls;
	}
	private function origin( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return ( 'https' === $scheme && '' !== $host ) ? 'https://' . $host : '';
	}
}
