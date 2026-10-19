<?php
/**
 * Privacy-conscious YouTube facade.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ThirdPartyScripts;

use NBH\Performance\Modules\Core\PatternMatcher;

final class YouTubeFacade {
	/** @var ConsentManager */
	private $consent;

	/**
	 * Constructor.
	 *
	 * @param ConsentManager $consent Consent manager.
	 */
	public function __construct( ConsentManager $consent ) {
		$this->consent = $consent;
	}

	/**
	 * Replace eligible YouTube embeds with a local facade.
	 *
	 * @param string $html Embed HTML.
	 * @return string
	 */
	public function filter_embed( $html ) {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_youtube_facade_enabled', 0 ) || is_admin() || false === stripos( $html, 'youtube' ) ) {
			return $html;
		}

		if ( ! preg_match( '#<iframe[^>]+src=("|\')([^"\']+)(?:\1)[^>]*>.*?</iframe>#is', $html, $matches ) ) {
			return $html;
		}

		$url = $this->normalize_url( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || PatternMatcher::matches_any( $url, $this->media_exclusions() ) ) {
			return $html;
		}

		if ( $this->consent->is_granted( 'external_media' ) && get_option( 'nbh_performance_youtube_autoload_after_consent', 0 ) ) {
			return $html;
		}

		$label = (string) apply_filters( 'nbh_performance_youtube_facade_label', __( 'Load YouTube video', 'nbh-performance' ), $url );
		return sprintf(
			'<div class="nbh-performance-media-facade" data-nbh-performance-media="youtube" data-nbh-performance-src="%1$s"><button type="button" class="nbh-performance-media-facade__button" aria-label="%2$s"><img src="%3$s" width="96" height="68" alt="" loading="lazy" decoding="async"><span>%2$s</span></button><noscript>%4$s</noscript></div>',
			esc_url( $url ),
			esc_attr( $label ),
			esc_url( NBH_PERFORMANCE_URL . 'assets/images/youtube-facade.svg' ),
			wp_kses_post( $html )
		);
	}

	private function media_exclusions() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_media_excluded_urls', '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	/**
	 * Normalize and validate a YouTube embed URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url  = esc_url_raw( $url, array( 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! in_array( $host, array( 'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com' ), true ) ) {
			return '';
		}
		return set_url_scheme( $url, 'https' );
	}
}
