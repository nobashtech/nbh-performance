<?php
/** Conservative in-place image compression with backup precondition. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageCompressionManager {
	private $validator;
	private $profile;
	public function __construct( ?ImageOutputValidator $validator = null, ?ImageOptimizationProfile $profile = null ) {
		$this->validator = $validator ? $validator : new ImageOutputValidator();
		$this->profile   = $profile ? $profile : new ImageOptimizationProfile();
	}
	public function compress_files( $attachment_id, array $files ) {
		$results = array();
		foreach ( array_unique( $files ) as $file ) {
			$results[] = $this->compress_file( absint( $attachment_id ), $file );
		}
		return $results;
	}

	private function compress_file( $attachment_id, $file ) {
		if ( ! is_file( $file ) ) {
			return array(
				'file'   => $file,
				'status' => 'missing',
				'saved'  => 0,
			);
		}
		$type = wp_check_filetype( $file );
		$mime = isset( $type['type'] ) ? $type['type'] : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array(
				'file'   => $file,
				'status' => 'unsupported',
				'saved'  => 0,
			);
		}
		$before    = filesize( $file );
		$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		$temp      = trailingslashit( dirname( $file ) ) . '.nbh-performance-' . wp_generate_password( 12, false, false ) . '.' . $extension;
		$editor    = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $temp );
			return array(
				'file'   => $file,
				'status' => 'editor_failed',
				'saved'  => 0,
			);
		}
		$quality = $this->profile->quality( $mime );
		$editor->set_quality( max( 40, min( 100, $quality ) ) );
		$result  = $editor->save( $temp, $mime );
		$created = ! is_wp_error( $result ) && ! empty( $result['path'] ) ? $result['path'] : $temp;
		if ( is_wp_error( $result ) || ! is_file( $created ) ) {
			wp_delete_file( $temp );
			return array(
				'file'   => $file,
				'status' => 'save_failed',
				'saved'  => 0,
			);
		}
		$after      = filesize( $created );
		$validation = $this->validator->validate( $file, $created, $mime );
		if ( is_wp_error( $validation ) ) {
			wp_delete_file( $created );
			return array(
				'file'   => $file,
				'status' => 'validation_failed',
				'saved'  => 0,
				'error'  => $validation->get_error_code(),
			);
		}
		$profile = $this->profile->current();
		$minimum = max( 512, (int) floor( $before * max( 0.005, (float) $profile['minimum_saving'] ) ) );
		if ( false === $before || false === $after || $after >= $before - $minimum ) {
			wp_delete_file( $created );
			return array(
				'file'   => $file,
				'status' => 'not_beneficial',
				'saved'  => 0,
			);
		}
		$replacement = $file . '.nbh-replacement';
		if ( ! copy( $created, $replacement ) ) {
			wp_delete_file( $created );
			return array(
				'file'   => $file,
				'status' => 'replace_failed',
				'saved'  => 0,
			);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem rename is required for publication; WP_Filesystem::move() can unlink/copy and is not an atomic commit. Failure is checked before accepting the new artifact.
		if ( ! rename( $replacement, $file ) ) {
			wp_delete_file( $replacement );
			wp_delete_file( $created );
			return array(
				'file'   => $file,
				'status' => 'atomic_replace_failed',
				'saved'  => 0,
			);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}
		wp_delete_file( $created );
		do_action( 'nbh_performance_image_source_compressed', $attachment_id, $file, $before, $after );
		return array(
			'file'   => $file,
			'status' => 'compressed',
			'before' => $before,
			'after'  => $after,
			'saved'  => $before - $after,
		);
	}
}
