<?php
/**
 * Creates next-generation variants for originals and every generated WordPress image size.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Core\PatternMatcher;

final class WebpAvifConverter {
	const MAX_SOURCE_BYTES = 26214400;
	const META_KEY         = '_nbh_performance_image_variants_v2';

	/** @var ImageProcessorRegistry|null */
	private $processors;
	private $validator;
	private $profile;

	public function __construct( ?ImageProcessorRegistry $processors = null, ?ImageOutputValidator $validator = null, ?ImageOptimizationProfile $profile = null ) {
		$this->processors = $processors;
		$this->validator  = $validator ? $validator : new ImageOutputValidator();
		$this->profile    = $profile ? $profile : new ImageOptimizationProfile();
	}

	/** Convert the original and all generated attachment sizes. */
	public function convert_attachment( $attachment_id, $force = false, $metadata = null ) {
		$attachment_id = absint( $attachment_id );
		$source        = get_attached_file( $attachment_id );
		if ( ! $source || ! is_file( $source ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return array(
				'status'    => 'invalid_source',
				'converted' => false,
				'reused'    => false,
				'formats'   => array(),
				'sources'   => 0,
			);
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true ) ) {
			return array(
				'status'    => 'unsupported_mime',
				'converted' => false,
				'reused'    => false,
				'formats'   => array(),
				'sources'   => 0,
			);
		}
		if ( $this->is_excluded( $attachment_id, $source ) ) {
			return array(
				'status'    => 'excluded',
				'converted' => false,
				'reused'    => false,
				'formats'   => array(),
				'sources'   => 0,
			);
		}

		$formats = $this->enabled_formats();
		if ( empty( $formats ) ) {
			return array(
				'status'    => 'no_format_enabled',
				'converted' => false,
				'reused'    => false,
				'formats'   => array(),
				'sources'   => 0,
			);
		}

		$files      = $this->attachment_files( $attachment_id, $source, $metadata );
		$manifest   = array(
			'version'      => 2,
			'generated_at' => time(),
			'sources'      => array(),
		);
		$converted  = false;
		$reused     = false;
		$format_set = array();

		foreach ( $files as $file ) {
			if ( ! $this->eligible_file( $file, $attachment_id ) ) {
				continue;
			}
			$relative = $this->relative_upload_path( $file );
			if ( '' === $relative ) {
				continue;
			}
			foreach ( $formats as $format ) {
				$result = $this->create_variant( $file, $format, $attachment_id, (bool) $force );
				if ( empty( $result['path'] ) ) {
					continue;
				}
				$manifest['sources'][ $relative ][ $format ] = $result['path'];
				$converted                                   = $converted || ! empty( $result['converted'] );
				$reused                                      = $reused || ! empty( $result['reused'] );
				$format_set[ $format ]                       = true;
			}
		}

		if ( ! empty( $manifest['sources'] ) ) {
			update_post_meta( $attachment_id, self::META_KEY, $manifest );
			// Remove the unsafe v1 original-only mapping after a successful v2 conversion.
			delete_post_meta( $attachment_id, '_nbh_performance_image_variants' );
			return array(
				'status'    => $converted ? 'converted' : 'reused',
				'converted' => $converted,
				'reused'    => $reused,
				'formats'   => array_keys( $format_set ),
				'sources'   => count( $manifest['sources'] ),
				'variants'  => $manifest['sources'],
			);
		}

		return array(
			'status'    => 'conversion_failed',
			'converted' => false,
			'reused'    => false,
			'formats'   => array(),
			'sources'   => 0,
		);
	}

	/** Hook after WordPress has finished creating all intermediate sizes. */
	public function convert_generated_metadata( $metadata, $attachment_id ) {
		$this->convert_attachment( $attachment_id, false, $metadata );
		return $metadata;
	}


	/** Return total bytes used by generated variants for an attachment. */
	public function variant_bytes( $attachment_id ) {
		$manifest = get_post_meta( absint( $attachment_id ), self::META_KEY, true );
		if ( ! is_array( $manifest ) || empty( $manifest['sources'] ) ) {
			return 0;
		}
		$uploads = wp_get_upload_dir();
		$total   = 0;
		foreach ( $manifest['sources'] as $formats ) {
			foreach ( (array) $formats as $relative ) {
				$path = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( (string) $relative, '/' ) );
				if ( is_file( $path ) ) {
					$size   = filesize( $path );
					$total += false === $size ? 0 : $size;
				}
			}
		}
		return $total;
	}

	/** Delete all generated variants while preserving WordPress source images. */
	public function delete_attachment_variants( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$manifest      = get_post_meta( $attachment_id, self::META_KEY, true );
		$uploads       = wp_get_upload_dir();
		$base          = wp_normalize_path( $uploads['basedir'] );
		$deleted       = 0;
		if ( is_array( $manifest ) && ! empty( $manifest['sources'] ) ) {
			foreach ( $manifest['sources'] as $formats ) {
				foreach ( (array) $formats as $relative ) {
					$path = wp_normalize_path( trailingslashit( $base ) . ltrim( (string) $relative, '/' ) );
					if ( 0 === strpos( $path, $base . '/' ) && is_file( $path ) ) {
						wp_delete_file( $path );
						if ( ! is_file( $path ) ) {
							++$deleted;
						}
					}
				}
			}
		}
		delete_post_meta( $attachment_id, self::META_KEY );
		delete_post_meta( $attachment_id, '_nbh_performance_image_variants' );
		return $deleted;
	}

	private function enabled_formats() {
		$capabilities = $this->processors ? $this->processors->capabilities() : array();
		$formats      = array();
		if ( get_option( 'nbh_performance_generate_webp', 1 ) ) {
			$formats[] = 'image/webp';
		}
		$avif_supported = ! empty( $capabilities['avifenc'] ) || wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
		if ( get_option( 'nbh_performance_generate_avif', 1 ) && $avif_supported ) {
			$formats[] = 'image/avif';
		}
		return $formats;
	}

	private function attachment_files( $attachment_id, $source, $metadata = null ) {
		$files = array( wp_normalize_path( $source ) );
		if ( ! is_array( $metadata ) ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		$base_dir = dirname( wp_normalize_path( $source ) );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $base_dir . '/' . wp_basename( $size['file'] );
				}
			}
		}
		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) ) {
			$files[] = $base_dir . '/' . wp_basename( $metadata['original_image'] );
		}
		return array_values( array_unique( array_filter( $files, 'is_file' ) ) );
	}

	private function eligible_file( $source, $attachment_id ) {
		$max_bytes = absint( get_option( 'nbh_performance_image_max_source_mb', 25 ) ) * MB_IN_BYTES;
		$max_bytes = $max_bytes > 0 ? $max_bytes : self::MAX_SOURCE_BYTES;
		$size      = filesize( $source );
		if ( false === $size || $size > (int) apply_filters( 'nbh_performance_image_max_source_bytes', $max_bytes, $attachment_id, $source ) ) {
			return false;
		}
		$info = wp_check_filetype( $source );
		return in_array( isset( $info['type'] ) ? $info['type'] : '', array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true );
	}

	private function create_variant( $source, $mime, $attachment_id, $force ) {
		$extension = 'image/avif' === $mime ? 'avif' : 'webp';
		$target    = preg_replace( '/\.[^.]+$/', '.' . $extension, $source );
		if ( ! is_string( $target ) || $target === $source ) {
			return array();
		}
		if ( ! $force && is_file( $target ) && filemtime( $target ) >= filemtime( $source ) ) {
			return array(
				'path'      => $this->relative_upload_path( $target ),
				'converted' => false,
				'reused'    => true,
			);
		}
		$default_quality = $this->profile->quality( $mime );
		$quality         = (int) apply_filters( 'nbh_performance_image_quality', $default_quality, $mime, $attachment_id );
		$created         = '';
		if ( $this->processors ) {
			$external = $this->processors->convert( $source, $target, $mime, $quality );
			if ( ! empty( $external['ok'] ) && is_file( $target ) && filesize( $target ) > 0 ) {
				$created = $target;
			}
		}
		if ( '' === $created ) {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				do_action( 'nbh_performance_image_conversion_failed', $attachment_id, $mime, $editor, $source );
				return array();
			}
			$editor->set_quality( $quality );
			$result = $editor->save( $target, $mime );
			if ( is_wp_error( $result ) || empty( $result['path'] ) ) {
				do_action( 'nbh_performance_image_conversion_failed', $attachment_id, $mime, $result, $source );
				return array();
			}
			$created = $result['path'];
		}

		$validation = $this->validator->validate( $source, $created, $mime );
		if ( is_wp_error( $validation ) ) {
			wp_delete_file( $created );
			do_action( 'nbh_performance_image_conversion_failed', $attachment_id, $mime, $validation, $source );
			return array();
		}

		$source_size = filesize( $source );
		$target_size = is_file( $created ) ? filesize( $created ) : false;
		$keep_larger = (bool) apply_filters( 'nbh_performance_keep_larger_image_variant', false, $source, $created, $mime );
		if ( ! $keep_larger && false !== $source_size && false !== $target_size && $target_size >= $source_size ) {
			wp_delete_file( $created );
			do_action( 'nbh_performance_image_variant_skipped', $attachment_id, $mime, $source, 'not_smaller' );
			return array();
		}
		do_action( 'nbh_performance_image_converted', $attachment_id, $mime, $created, 'v2', $source );
		return array(
			'path'       => $this->relative_upload_path( $created ),
			'converted'  => true,
			'reused'     => false,
			'validation' => $validation,
		);
	}

	private function is_excluded( $attachment_id, $source ) {
		$rules = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_image_conversion_exclusions', '' ) );
		$rules = array_values( array_filter( array_map( 'trim', (array) $rules ) ) );
		$url   = (string) wp_get_attachment_url( $attachment_id );
		return PatternMatcher::matches_any( $url, $rules ) || PatternMatcher::matches_any( $source, $rules );
	}

	private function relative_upload_path( $path ) {
		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( $uploads['basedir'] );
		$path    = wp_normalize_path( $path );
		if ( 0 !== strpos( $path, $basedir . '/' ) ) {
			return '';
		}
		return ltrim( substr( $path, strlen( $basedir ) ), '/' );
	}
}
