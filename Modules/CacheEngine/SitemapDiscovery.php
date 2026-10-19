<?php
/** Same-origin sitemap discovery with nested indexes and metadata. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

final class SitemapDiscovery {
	public function discover( $limit = 500 ) {
		return wp_list_pluck( $this->discover_records( $limit ), 'url' );
	}
	public function discover_records( $limit = 500 ) {
		$limit   = max( 1, min( 10000, absint( $limit ) ) );
		$seeds   = apply_filters( 'nbh_performance_preload_sitemap_seeds', array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/sitemap.xml' ) ) );
		$seen    = array();
		$records = array();
		foreach ( array_unique( array_filter( (array) $seeds ) ) as $seed ) {
			$this->parse( esc_url_raw( $seed ), $seen, $records, $limit, 0 );
			if ( count( $records ) >= $limit ) {
				break;
			}
		}
		$records = array_values( $records );
		usort(
			$records,
			static function ( $a, $b ) {
				$priority = (int) $b['priority'] <=> (int) $a['priority'];
				return 0 !== $priority ? $priority : (int) $b['lastmod'] <=> (int) $a['lastmod'];
			}
		);
		return array_slice( $records, 0, $limit );
	}
	private function parse( $url, array &$seen, array &$records, $limit, $depth ) {
		if ( $depth > 4 || count( $records ) >= $limit || isset( $seen[ $url ] ) || ! $this->is_same_origin( $url ) ) {
			return;
		}
		$seen[ $url ] = true;
		$response     = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 3,
				'limit_response_size' => 5242880,
				'headers'             => array( 'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.5' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || false === stripos( $body, '<loc>' ) ) {
			return;
		}
		if ( ! preg_match_all( '#<url>\s*(.*?)</url>|<sitemap>\s*(.*?)</sitemap>#is', $body, $nodes, PREG_SET_ORDER ) ) {
			return;
		}
		foreach ( $nodes as $node ) {
			$chunk = ! empty( $node[1] ) ? $node[1] : $node[2];
			if ( ! preg_match( '#<loc>\s*([^<]+)\s*</loc>#i', $chunk, $loc ) ) {
				continue;
			}
			$location = esc_url_raw( html_entity_decode( trim( $loc[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( ! $this->is_same_origin( $location ) ) {
				continue;
			}
			if ( preg_match( '/\.xml(?:\?|$)/i', $location ) ) {
				$this->parse( $location, $seen, $records, $limit, $depth + 1 );
				continue;
			}
			$lastmod = 0;
			if ( preg_match( '#<lastmod>\s*([^<]+)\s*</lastmod>#i', $chunk, $match ) ) {
				$lastmod = absint( strtotime( trim( $match[1] ) ) );
			}
			$priority = 50;
			if ( preg_match( '#<priority>\s*([0-9.]+)\s*</priority>#i', $chunk, $match ) ) {
				$priority = max( 1, min( 100, (int) round( (float) $match[1] * 100 ) ) );
			}
			$path = (string) wp_parse_url( $location, PHP_URL_PATH );
			if ( '/' === $path || '' === $path ) {
				$priority = 100;
			}
			$records[ hash( 'sha256', $location ) ] = array(
				'url'      => $location,
				'lastmod'  => $lastmod,
				'priority' => $priority,
				'source'   => $url,
			);
			if ( count( $records ) >= $limit ) {
				break;
			}
		}
	}
	private function is_same_origin( $url ) {
		return wp_http_validate_url( $url ) && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}
}
