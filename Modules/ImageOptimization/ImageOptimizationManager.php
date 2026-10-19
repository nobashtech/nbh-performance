<?php
/** @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Core\Module;

final class ImageOptimizationManager implements Module {
	private $pipeline;
	private $server;
	private $lazy_load;
	private $responsive;

	public function __construct( ImageOptimizationPipeline $pipeline, ImageVariantServer $server, LazyLoadManager $lazy_load, ResponsiveImageGenerator $responsive ) {
		$this->pipeline   = $pipeline;
		$this->server     = $server;
		$this->lazy_load  = $lazy_load;
		$this->responsive = $responsive;
	}

	public function register() {
		if ( false === apply_filters( 'nbh_performance_should_optimize_feature', true, 'image_conversion' ) ) {
			return;
		}
		if ( ! get_option( 'nbh_performance_safe_mode', 1 ) && get_option( 'nbh_performance_image_conversion_enabled', 0 ) && get_option( 'nbh_performance_convert_on_upload', 1 ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this->pipeline, 'process_generated_metadata' ), 30, 2 );
			add_action( 'edit_attachment', array( $this->pipeline, 'process_attachment' ), 30 );
		}
		add_action( 'delete_attachment', array( $this->pipeline, 'delete_artifacts' ), 20 );
		add_filter( 'wp_get_attachment_image_src', array( $this->server, 'filter_image_src' ), 20, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this->server, 'filter_srcset' ), 20, 5 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this->lazy_load, 'filter_attributes' ), 20, 3 );
		add_filter( 'intermediate_image_sizes_advanced', array( $this->responsive, 'filter_sizes' ) );
		add_filter( 'wp_calculate_image_sizes', array( $this->responsive, 'filter_sizes_attribute' ), 20, 2 );
	}
}
