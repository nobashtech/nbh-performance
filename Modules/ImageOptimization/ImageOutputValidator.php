<?php
/** Validates generated image output before it can replace or represent a source. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageOutputValidator {
	public function validate( $source, $candidate, $expected_mime = '' ) {
		if ( ! is_file( $source ) || ! is_file( $candidate ) ) {
			return new \WP_Error( 'nbh_performance_image_validation_missing', __( 'The source or generated image is missing.', 'nbh-performance' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$source_info = @getimagesize( $source );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$candidate_info = @getimagesize( $candidate );
		if ( ! is_array( $source_info ) || ! is_array( $candidate_info ) ) {
			return new \WP_Error( 'nbh_performance_image_validation_decode', __( 'The generated image could not be decoded safely.', 'nbh-performance' ) );
		}
		if ( absint( $source_info[0] ) !== absint( $candidate_info[0] ) || absint( $source_info[1] ) !== absint( $candidate_info[1] ) ) {
			return new \WP_Error( 'nbh_performance_image_validation_dimensions', __( 'The generated image dimensions do not match the source.', 'nbh-performance' ) );
		}
		$actual_mime = isset( $candidate_info['mime'] ) ? (string) $candidate_info['mime'] : '';
		if ( $expected_mime && $actual_mime && $expected_mime !== $actual_mime ) {
			return new \WP_Error( 'nbh_performance_image_validation_mime', __( 'The generated image format does not match the requested format.', 'nbh-performance' ) );
		}
		$bytes = filesize( $candidate );
		if ( false === $bytes || $bytes < 32 ) {
			return new \WP_Error( 'nbh_performance_image_validation_empty', __( 'The generated image is empty or incomplete.', 'nbh-performance' ) );
		}
		return array(
			'valid'  => true,
			'width'  => absint( $candidate_info[0] ),
			'height' => absint( $candidate_info[1] ),
			'mime'   => $actual_mime,
			'bytes'  => absint( $bytes ),
			'sha256' => hash_file( 'sha256', $candidate ),
		);
	}
}
