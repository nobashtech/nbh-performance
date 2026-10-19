<?php
/** Full attachment optimization pipeline and status ledger. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageOptimizationPipeline {
	const STATUS_META   = '_nbh_performance_image_optimization_status_v2';
	const COMPLETE_META = '_nbh_performance_image_optimized_v2';
	private $converter;
	private $backup;
	private $compression;

	public function __construct( WebpAvifConverter $converter, ImageBackupManager $backup, ImageCompressionManager $compression ) {
		$this->converter   = $converter;
		$this->backup      = $backup;
		$this->compression = $compression;
	}

	public function process( $attachment_id, $force = false, $metadata = null ) {
		$attachment_id = absint( $attachment_id );
		$files         = $this->attachment_files( $attachment_id, $metadata );
		if ( empty( $files ) ) {
			return new \WP_Error( 'nbh_performance_image_files_missing', __( 'No attachment files were found.', 'nbh-performance' ) );
		}
		$before      = $this->total_bytes( $files );
		$compression = array();
		if ( get_option( 'nbh_performance_compress_original_images', 0 ) ) {
			if ( ! $this->backup->has_backup( $attachment_id ) ) {
				$backup = $this->backup->backup( $attachment_id, $files );
				if ( is_wp_error( $backup ) ) {
					return $backup;
				}
			}
			$compression = $this->compression->compress_files( $attachment_id, $files );
		}
		$variants      = $this->converter->convert_attachment( $attachment_id, $force, $metadata );
		$after         = $this->total_bytes( $files );
		$variant_bytes = $this->converter->variant_bytes( $attachment_id );
		$status        = array(
			'status'              => is_wp_error( $variants ) ? 'failed' : ( isset( $variants['status'] ) ? sanitize_key( $variants['status'] ) : 'processed' ),
			'processed_at'        => time(),
			'source_bytes_before' => $before,
			'source_bytes_after'  => $after,
			'source_bytes_saved'  => max( 0, $before - $after ),
			'variant_bytes'       => $variant_bytes,
			'compression'         => $compression,
			'formats'             => is_array( $variants ) && isset( $variants['formats'] ) ? array_values( $variants['formats'] ) : array(),
			'backup_available'    => $this->backup->has_backup( $attachment_id ),
		);
		update_post_meta( $attachment_id, self::STATUS_META, $status );
		update_post_meta( $attachment_id, '_nbh_performance_image_source_bytes_saved', absint( $status['source_bytes_saved'] ) );
		if ( in_array( $status['status'], array( 'converted', 'reused' ), true ) ) {
			update_post_meta( $attachment_id, self::COMPLETE_META, 1 );
		} else {
			delete_post_meta( $attachment_id, self::COMPLETE_META );
		}
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}
		return array_merge( $variants, $status );
	}

	/** Hook after WordPress has finished creating all intermediate sizes. */
	public function process_generated_metadata( $metadata, $attachment_id ) {
		$this->process( $attachment_id, false, is_array( $metadata ) ? $metadata : null );
		return $metadata;
	}

	/** Hook after an attachment is edited. */
	public function process_attachment( $attachment_id ) {
		$this->process( $attachment_id, true );
	}

	public function restore( $attachment_id ) {
		$source  = $this->backup->restore( $attachment_id );
		$deleted = $this->converter->delete_attachment_variants( $attachment_id );
		delete_post_meta( absint( $attachment_id ), self::STATUS_META );
		delete_post_meta( absint( $attachment_id ), self::COMPLETE_META );
		delete_post_meta( absint( $attachment_id ), '_nbh_performance_image_source_bytes_saved' );
		do_action( 'nbh_performance_image_restored', absint( $attachment_id ), $source, $deleted );
		return array(
			'source'           => $source,
			'variants_deleted' => $deleted,
		);
	}


	/** Remove generated variants, source backups, and status metadata for deleted attachments. */
	public function delete_artifacts( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$variants      = $this->converter->delete_attachment_variants( $attachment_id );
		$backups       = $this->backup->delete_backup( $attachment_id );
		delete_post_meta( $attachment_id, self::STATUS_META );
		delete_post_meta( $attachment_id, self::COMPLETE_META );
		delete_post_meta( $attachment_id, '_nbh_performance_image_source_bytes_saved' );
		return array(
			'variants' => $variants,
			'backups'  => $backups,
		);
	}

	public function status( $attachment_id ) {
		$status = get_post_meta( absint( $attachment_id ), self::STATUS_META, true );
		return is_array( $status ) ? $status : array();
	}

	public function attachment_files( $attachment_id, $metadata = null ) {
		$source = get_attached_file( absint( $attachment_id ) );
		if ( ! $source || ! is_file( $source ) ) {
			return array();
		}
		$files = array( wp_normalize_path( $source ) );
		$meta  = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( absint( $attachment_id ) );
		$base  = dirname( wp_normalize_path( $source ) );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $base . '/' . wp_basename( $size['file'] );
				}
			}
		}
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$files[] = $base . '/' . wp_basename( $meta['original_image'] );
		}
		return array_values( array_unique( array_filter( $files, 'is_file' ) ) );
	}

	private function total_bytes( array $files ) {
		$total = 0;
		foreach ( $files as $file ) {
			$size   = is_file( $file ) ? filesize( $file ) : 0;
			$total += false === $size ? 0 : $size;
		}
		return $total;
	}
}
