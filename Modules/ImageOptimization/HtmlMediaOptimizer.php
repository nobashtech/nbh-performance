<?php
/** HTML API based lazy loading for raw images, iframes, video and optional backgrounds. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Core\PatternMatcher;

final class HtmlMediaOptimizer implements Module {
	private $image_count = 0;

	public function register() {
		add_action( 'template_redirect', array( $this, 'buffer' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 50 );
	}

	public function buffer() {
		if ( ! $this->enabled() || is_feed() || is_embed() || is_robots() || is_trackback() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( $this, 'optimize' ) );
	}

	public function assets() {
		if ( $this->enabled() && get_option( 'nbh_performance_lazyload_background_images', 0 ) ) {
			wp_enqueue_script( 'nbh-performance-media-lazy', NBH_PERFORMANCE_URL . 'assets/js/media-lazy.js', array(), NBH_PERFORMANCE_VERSION, true );
			wp_script_add_data( 'nbh-performance-media-lazy', 'strategy', 'defer' );
		}
	}

	public function optimize( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) || false !== stripos( $html, '<html amp' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$processor   = new \WP_HTML_Tag_Processor( $html );
		$eager_limit = max( 0, min( 10, absint( get_option( 'nbh_performance_lazyload_eager_images', 2 ) ) ) );
		while ( $processor->next_tag() ) {
			$tag = strtoupper( (string) $processor->get_tag() );
			if ( 'IMG' === $tag ) {
				if ( $this->excluded( $processor ) ) {
					continue;
				}
				++$this->image_count;
				$processor->set_attribute( 'decoding', 'async' );
				if ( $this->image_count <= $eager_limit || 'high' === strtolower( (string) $processor->get_attribute( 'fetchpriority' ) ) ) {
					$processor->set_attribute( 'loading', 'eager' );
					if ( 1 === $this->image_count && null === $processor->get_attribute( 'fetchpriority' ) ) {
						$processor->set_attribute( 'fetchpriority', 'high' );
					}
				} else {
					$processor->set_attribute( 'loading', 'lazy' );
					if ( null === $processor->get_attribute( 'fetchpriority' ) ) {
						$processor->set_attribute( 'fetchpriority', 'low' );
					}
				}
				continue;
			}
			if ( 'IFRAME' === $tag && ! $this->excluded( $processor ) ) {
				$processor->set_attribute( 'loading', 'lazy' );
				if ( null === $processor->get_attribute( 'referrerpolicy' ) ) {
					$processor->set_attribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
				}
				continue;
			}
			if ( 'VIDEO' === $tag && ! $this->excluded( $processor ) && null === $processor->get_attribute( 'autoplay' ) ) {
				$processor->set_attribute( 'preload', (string) get_option( 'nbh_performance_video_preload', 'metadata' ) );
			}
			if ( get_option( 'nbh_performance_lazyload_background_images', 0 ) ) {
				$this->background( $processor );
			}
		}
		return $processor->get_updated_html();
	}

	private function background( \WP_HTML_Tag_Processor $processor ) {
		$style = (string) $processor->get_attribute( 'style' );
		if ( '' === $style || ! preg_match( '/background(?:-image)?\s*:\s*url\(([^)]+)\)/i', $style, $match ) ) {
			return;
		}
		$url = trim( $match[1], " \t\n\r\0\x0B\"'" );
		if ( $this->excluded( $processor, $url ) ) {
			return;
		}
		$processor->set_attribute( 'data-nbh-performance-bg', esc_url_raw( $url ) );
		$processor->set_attribute( 'style', trim( (string) preg_replace( '/background(?:-image)?\s*:\s*url\([^)]+\)\s*;?/i', '', $style, 1 ) ) );
		$processor->add_class( 'nbh-performance-lazy-bg' );
	}

	private function excluded( \WP_HTML_Tag_Processor $processor, $extra = '' ) {
		$class  = (string) $processor->get_attribute( 'class' );
		$source = $processor->get_attribute( 'src' );
		$src    = $extra ? $extra : (string) ( $source ? $source : $processor->get_attribute( 'poster' ) );
		if ( preg_match( '/(?:skip-lazy|no-lazy|eager|woocommerce-product-gallery__image)/i', $class ) ) {
			return true;
		}
		$rules = array_merge( $this->lines( 'nbh_performance_lazyload_excluded_classes' ), $this->lines( 'nbh_performance_media_excluded_urls' ) );
		return PatternMatcher::matches_any( $class, $rules ) || PatternMatcher::matches_any( $src, $rules );
	}

	private function lines( $option ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) get_option( $option, '' ) ) ) ) );
	}
	private function enabled() {
		return ! is_admin() && ! get_option( 'nbh_performance_safe_mode', 1 ) && get_option( 'nbh_performance_lazy_load_enabled', 0 ) && false !== apply_filters( 'nbh_performance_should_optimize_feature', true, 'lazy_load_html' );
	}
}
