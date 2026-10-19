<?php
/** Add missing width and height attributes to local WordPress images. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class ImageDimensionManager implements Module {
	public function register() {
		add_filter( 'wp_content_img_tag', array( $this, 'add_dimensions' ), 20, 3 );
	}

	public function add_dimensions( $filtered_image, $context, $attachment_id ) {
		unset( $context );
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_add_missing_image_dimensions', 0 ) ) {
			return $filtered_image;
		}
		if ( preg_match( '/\s(?:width|height)=["\']/i', $filtered_image ) ) {
			return $filtered_image;
		}
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id && preg_match( '/wp-image-(\d+)/', $filtered_image, $matches ) ) {
			$attachment_id = absint( $matches[1] );
		}
		if ( ! $attachment_id ) {
			return $filtered_image;
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return $filtered_image;
		}
		$dimensions = sprintf( ' width="%d" height="%d"', absint( $metadata['width'] ), absint( $metadata['height'] ) );
		return preg_replace( '/<img\b/i', '<img' . $dimensions, $filtered_image, 1 );
	}
}
