<?php
/** Serves size-matched WebP/AVIF variants without replacing thumbnails with full-size files. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageVariantServer {
	public function filter_image_src( $image, $attachment_id, $size, $icon ) {
		unset( $size, $icon );
		if ( ! is_array( $image ) || empty( $image[0] ) || ! $this->enabled() ) {
			return $image;
		}
		$url = $this->preferred_url_for_source( $image[0], $attachment_id );
		if ( $url ) {
			$image[0] = $url;
		}
		return $image;
	}

	public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src, $image_meta );
		if ( ! is_array( $sources ) || ! $this->enabled() ) {
			return $sources;
		}
		foreach ( $sources as &$source ) {
			if ( ! empty( $source['url'] ) ) {
				$preferred = $this->preferred_url_for_source( $source['url'], $attachment_id );
				if ( $preferred ) {
					$source['url'] = $preferred;
				}
			}
		}
		unset( $source );
		return $sources;
	}

	private function preferred_url_for_source( $source_url, $attachment_id ) {
		$mime = $this->accepted_mime();
		if ( ! $mime ) {
			return '';
		}
		$uploads  = wp_get_upload_dir();
		$base_url = trailingslashit( $uploads['baseurl'] );
		if ( 0 !== strpos( (string) $source_url, $base_url ) ) {
			return '';
		}
		$source_path = strtok( substr( (string) $source_url, strlen( $base_url ) ), '?' );
		$manifest    = get_post_meta( absint( $attachment_id ), WebpAvifConverter::META_KEY, true );
		$variant     = '';
		if ( is_array( $manifest ) && ! empty( $manifest['sources'][ $source_path ][ $mime ] ) ) {
			$variant = $manifest['sources'][ $source_path ][ $mime ];
		}
		if ( '' === $variant ) {
			$extension = 'image/avif' === $mime ? 'avif' : 'webp';
			$variant   = preg_replace( '/\.[^.?]+$/', '.' . $extension, $source_path );
		}
		if ( ! is_string( $variant ) || ! $this->relative_file_exists( $variant ) ) {
			return '';
		}
		return $base_url . ltrim( $variant, '/' );
	}

	private function accepted_mime() {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? \NBH\Performance\Modules\Security\RequestInput::server_text( 'HTTP_ACCEPT' ) : '';
		if ( false !== stripos( $accept, 'image/avif' ) ) {
			return 'image/avif';
		}
		if ( false !== stripos( $accept, 'image/webp' ) ) {
			return 'image/webp';
		}
		return '';
	}

	private function relative_file_exists( $relative ) {
		$uploads = wp_get_upload_dir();
		$path    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( (string) $relative, '/' ) );
		$base    = wp_normalize_path( $uploads['basedir'] );
		return 0 === strpos( $path, $base . '/' ) && is_file( $path );
	}

	private function enabled() {
		return ! get_option( 'nbh_performance_safe_mode', 1 ) && (bool) get_option( 'nbh_performance_nextgen_image_serving_enabled', 0 );
	}
}
