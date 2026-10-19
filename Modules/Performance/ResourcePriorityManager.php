<?php
/** Browser-learned resource priority, preload, and preconnect manager. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Core\PatternMatcher;

final class ResourcePriorityManager implements Module {
	private $storage;
	private $printed = array();
	public function __construct( PriorityElementsStorage $storage ) {
		$this->storage = $storage;
	}

	public function register() {
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'prioritize_image' ), 30, 3 );
		add_action( 'wp_head', array( $this, 'print_browser_hints' ), 1 );
		add_filter( 'wp_headers', array( $this, 'add_link_headers' ) );
	}

	public function prioritize_image( $attr, $attachment, $size ) {
		if ( ! $this->enabled() || is_admin() ) {
			return $attr;
		}
		static $promoted = 0;
		$limit           = max( 1, min( 3, absint( get_option( 'nbh_performance_lcp_preload_limit', 1 ) ) ) );
		if ( $promoted >= $limit ) {
			return $attr;
		}
		$report     = $this->report();
		$candidates = array_merge( (array) ( $report['lcp'] ?? array() ), (array) ( $report['images'] ?? array() ) );
		$urls       = array_filter( array( $attr['src'] ?? '', wp_get_attachment_image_url( $attachment->ID, 'full' ) ) );
		foreach ( $urls as $url ) {
			if ( $this->lcp_excluded( $url . ' ' . ( isset( $attr['class'] ) ? $attr['class'] : '' ) ) ) {
				return $attr;
			}
		}
		$is_candidate = false;
		foreach ( $candidates as $candidate ) {
			$candidate_url = isset( $candidate['url'] ) ? esc_url_raw( $candidate['url'] ) : '';
			foreach ( $urls as $url ) {
				if ( $candidate_url && $this->same_resource( $candidate_url, $url ) ) {
					$is_candidate = true;
					break 2;
				}
			}
		}
		if ( ! $is_candidate && empty( $candidates ) ) {
			$is_candidate = has_post_thumbnail() && (int) get_post_thumbnail_id() === (int) $attachment->ID;
		}
		$is_candidate = (bool) apply_filters( 'nbh_performance_resource_priority_image_candidate', $is_candidate, $attachment, $size, $attr, $report );
		if ( ! $is_candidate ) {
			return $attr;
		}
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
		$attr['decoding']      = 'async';
		++$promoted;
		return $attr;
	}

	public function print_browser_hints() {
		if ( ! $this->enabled() ) {
			return;
		}
		$resources = $this->resources();
		foreach ( $resources['preconnect'] as $origin ) {
			if ( isset( $this->printed[ 'preconnect:' . $origin ] ) ) {
				continue;
			}
			$this->printed[ 'preconnect:' . $origin ] = true;
			printf( '<link rel="preconnect" href="%s" crossorigin>' . "\n", esc_url( $origin ) );
			printf( '<link rel="dns-prefetch" href="//%s">' . "\n", esc_attr( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) );
		}
		foreach ( $resources['preload'] as $resource ) {
			$key = 'preload:' . $resource['url'];
			if ( isset( $this->printed[ $key ] ) ) {
				continue;
			}
			$this->printed[ $key ] = true;
			$attributes            = sprintf( 'rel="preload" href="%s" as="%s"', esc_url( $resource['url'] ), esc_attr( $resource['as'] ) );
			if ( ! empty( $resource['type'] ) ) {
				$attributes .= ' type="' . esc_attr( $resource['type'] ) . '"';
			}
			if ( 'font' === $resource['as'] ) {
				$attributes .= ' crossorigin';
			}
			if ( 'image' === $resource['as'] && ! empty( $resource['high'] ) ) {
				$attributes .= ' fetchpriority="high"';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<link ' . $attributes . '>' . "\n";
		}
	}

	public function add_link_headers( $headers ) {
		if ( ! $this->enabled() || headers_sent() ) {
			return $headers;
		}
		$links = array();
		foreach ( array_slice( $this->resources()['preload'], 0, 4 ) as $resource ) {
			$link = sprintf( '<%s>; rel=preload; as=%s', esc_url_raw( $resource['url'] ), sanitize_key( $resource['as'] ) );
			if ( ! empty( $resource['type'] ) ) {
				$link .= '; type=' . sanitize_text_field( $resource['type'] );
			}
			if ( 'font' === $resource['as'] ) {
				$link .= '; crossorigin';
			}
			$links[] = $link;
		}
		if ( $links ) {
			$generated       = implode( ', ', $links );
			$headers['Link'] = ! empty( $headers['Link'] ) ? rtrim( (string) $headers['Link'], ', ' ) . ', ' . $generated : $generated;
		}
		return $headers;
	}

	private function resources() {
		$report           = $this->report();
		$preload          = array();
		$preconnect       = array();
		$image_limit      = max( 1, min( 3, absint( get_option( 'nbh_performance_lcp_preload_limit', 1 ) ) ) );
		$image_candidates = array_merge( (array) ( $report['lcp'] ?? array() ), (array) ( $report['images'] ?? array() ), (array) ( $report['backgrounds'] ?? array() ) );
		foreach ( $image_candidates as $candidate ) {
			$url = isset( $candidate['url'] ) ? esc_url_raw( $candidate['url'] ) : '';
			if ( ! $this->same_origin( $url ) || $this->lcp_excluded( $url ) ) {
				continue;
			}
			$preload[] = array(
				'url'  => $url,
				'as'   => 'image',
				'type' => $this->mime_from_url( $url ),
				'high' => true,
			);
			if ( count(
				array_filter(
					$preload,
					static function ( $item ) {
						return 'image' === $item['as'];
					}
				)
			) >= $image_limit ) {
				break;
			}
		}
		if ( get_option( 'nbh_performance_font_preload_enabled', 0 ) ) {
			$font_limit = max( 1, min( 4, absint( get_option( 'nbh_performance_font_preload_limit', 2 ) ) ) );
			foreach ( (array) ( $report['fonts'] ?? array() ) as $candidate ) {
				$url = isset( $candidate['url'] ) ? esc_url_raw( $candidate['url'] ) : '';
				if ( ! $this->same_origin( $url ) ) {
					continue;
				}
				$preload[] = array(
					'url'  => $url,
					'as'   => 'font',
					'type' => $this->mime_from_url( $url ) ? $this->mime_from_url( $url ) : 'font/woff2',
					'high' => false,
				);
				if ( count(
					array_filter(
						$preload,
						static function ( $item ) {
							return 'font' === $item['as'];
						}
					)
				) >= $font_limit ) {
					break;
				}
			}
		}
		foreach ( $this->manual_resources() as $manual ) {
			$preload[] = $manual;
		}
		if ( get_option( 'nbh_performance_automatic_preconnect_enabled', 1 ) ) {
			$limit = max( 0, min( 6, absint( get_option( 'nbh_performance_preconnect_limit', 3 ) ) ) );
			foreach ( (array) ( $report['origins'] ?? array() ) as $candidate ) {
				$origin = isset( $candidate['url'] ) ? esc_url_raw( $candidate['url'] ) : '';
				if ( ! $origin || strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
					continue;
				}
				$preconnect[] = $origin;
				if ( count( $preconnect ) >= $limit ) {
					break;
				}
			}
		}
		$seen    = array();
		$preload = array_values(
			array_filter(
				$preload,
				static function ( $item ) use ( &$seen ) {
					$key = strtolower( (string) $item['url'] );
					if ( ! $key || isset( $seen[ $key ] ) ) {
						return false;
					}
					$seen[ $key ] = true;
					return true;
				}
			)
		);
		return array(
			'preload'    => array_slice( $preload, 0, 8 ),
			'preconnect' => array_values( array_unique( $preconnect ) ),
		);
	}

	private function manual_resources() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_priority_preload_urls', '' ) );
		$out   = array();
		foreach ( array_slice( (array) $lines, 0, 4 ) as $line ) {
			$url = esc_url_raw( trim( (string) $line ) );
			if ( ! $this->same_origin( $url ) ) {
				continue;
			}
			$type = $this->mime_from_url( $url );
			$as   = 0 === strpos( $type, 'font/' ) ? 'font' : ( 0 === strpos( $type, 'image/' ) ? 'image' : ( 'text/css' === $type ? 'style' : '' ) );
			if ( ! $as ) {
				continue;
			}
			$out[] = array(
				'url'  => $url,
				'as'   => $as,
				'type' => $type,
				'high' => 'image' === $as,
			);
		}
		return $out;
	}

	private function report() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$url     = $this->storage->normalize_url( home_url( isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/' ) );
		$variant = wp_is_mobile() ? 'mobile' : 'desktop';
		$cache   = $url ? $this->storage->get( $url, $variant ) : array();
		return $cache;
	}
	private function lcp_excluded( $subject ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_lcp_image_exclusions', '' ) );
		return PatternMatcher::matches_any( (string) $subject, array_values( array_filter( array_map( 'trim', (array) $lines ) ) ) );
	}
	private function enabled() {
		return ! get_option( 'nbh_performance_safe_mode', 1 ) && get_option( 'nbh_performance_resource_priority_enabled', 0 );
	}
	private function same_origin( $url ) {
		return $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	private function same_resource( $left, $right ) {
		return untrailingslashit( strtok( (string) $left, '?' ) ) === untrailingslashit( strtok( (string) $right, '?' ) );
	}
	private function mime_from_url( $url ) {
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$map = array(
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'css'   => 'text/css',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
	}
}
