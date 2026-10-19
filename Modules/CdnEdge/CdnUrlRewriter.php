<?php
/** Safe static-asset CDN URL rewriting. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

final class CdnUrlRewriter {
	private $extensions = array( 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm' );
	public function rewrite( $url ) {
		if ( false === apply_filters( 'nbh_performance_should_optimize_feature', true, 'cdn' ) || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_cdn_rewrite_enabled', 0 ) ) {
			return $url;
		}
		$base = untrailingslashit( esc_url_raw( (string) get_option( 'nbh_performance_cdn_base_url', '' ) ) );
		if ( '' === $base || 'https' !== strtolower( (string) wp_parse_url( $base, PHP_URL_SCHEME ) ) ) {
			return $url;
		}
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) {
			return $url;
		}
		if ( ! apply_filters( 'nbh_performance_should_rewrite_cdn_url', true, $url ) ) {
			return $url;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, (array) apply_filters( 'nbh_performance_cdn_rewrite_extensions', $this->extensions ), true ) ) {
			return $url;
		}
		$query     = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$rewritten = $base . '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' );
		return esc_url_raw( apply_filters( 'nbh_performance_cdn_rewritten_url', $rewritten, $url ) );
	}
	public function filter_src( $src ) {
		return $this->rewrite( $src );
	}
}
