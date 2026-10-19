<?php
/** Image processor capability registry and secure binary adapter. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class ImageProcessorRegistry {
	private $runner;
	public function __construct( SafeProcessRunner $runner ) {
		$this->runner = $runner;
	}
	public function capabilities() {
		return array(
			'wordpress_editor' => function_exists( 'wp_get_image_editor' ),
			'imagick'          => extension_loaded( 'imagick' ),
			'gd'               => extension_loaded( 'gd' ),
			'libvips'          => $this->runner->available( 'vips' ),
			'cwebp'            => $this->runner->available( 'cwebp' ),
			'avifenc'          => $this->runner->available( 'avifenc' ),
			'jpegoptim'        => $this->runner->available( 'jpegoptim' ),
			'optipng'          => $this->runner->available( 'optipng' ),
			'pngquant'         => $this->runner->available( 'pngquant' ),
			'gifsicle'         => $this->runner->available( 'gifsicle' ),
		);
	}
	public function convert( $source, $target, $mime, $quality ) {
		if ( 'image/webp' === $mime && $this->runner->available( 'cwebp' ) ) {
			return $this->runner->run( 'cwebp', array( '-quiet', '-mt', '-q', (string) $quality, $source, '-o', $target ) );
		}
		if ( 'image/avif' === $mime && $this->runner->available( 'avifenc' ) ) {
			return $this->runner->run( 'avifenc', array( '--jobs', 'all', '--min', (string) max( 0, 100 - $quality ), '--max', (string) max( 0, 100 - $quality ), $source, $target ), 120 );
		}
		if ( $this->runner->available( 'vips' ) ) {
			return $this->runner->run( 'vips', array( 'copy', $source, $target . '[Q=' . (int) $quality . ',strip]' ), 120 );
		}
		return array(
			'ok'     => false,
			'code'   => -1,
			'stderr' => 'No external processor available.',
		);
	}
}
