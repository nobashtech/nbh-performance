<?php
/** Administrator media optimization operations and status reporting. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class ImageOptimizationController implements Module {
	private $pipeline;
	private $processors;
	private $queue;
	private $batches;
	private $profile;
	private $offload;

	public function __construct( ImageOptimizationPipeline $pipeline, ImageProcessorRegistry $processors, JobQueue $queue, ImageBatchManager $batches, ImageOptimizationProfile $profile, MediaOffloadGuard $offload ) {
		$this->pipeline   = $pipeline;
		$this->processors = $processors;
		$this->queue      = $queue;
		$this->batches    = $batches;
		$this->profile    = $profile;
		$this->offload    = $offload;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/convert',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'convert' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/queue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/batch/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_start' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/batch/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_pause' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/batch/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_resume' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/batch/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_cancel' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/images/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to optimize images.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function status() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM %i WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN (%s,%s,%s,%s,%s)", $wpdb->posts, 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$optimized = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN (%s,%s,%s,%s,%s)", $wpdb->posts, $wpdb->postmeta, ImageOptimizationPipeline::COMPLETE_META, 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$savings            = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM %i pm WHERE pm.meta_key = %s', $wpdb->postmeta, '_nbh_performance_image_source_bytes_saved' ) );
		$caps               = $this->processors->capabilities();
		$conversion_enabled = (bool) get_option( 'nbh_performance_image_conversion_enabled', 0 );
		$serving_enabled    = (bool) get_option( 'nbh_performance_nextgen_image_serving_enabled', 0 );
		$safe_mode          = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$webp_enabled       = (bool) get_option( 'nbh_performance_generate_webp', 1 );
		$avif_enabled       = (bool) get_option( 'nbh_performance_generate_avif', 1 );
		$offload            = $this->offload->detected_provider();

		return rest_ensure_response(
			array(
				'total_eligible'      => $total,
				'converted'           => $optimized,
				'remaining'           => max( 0, $total - $optimized ),
				'progress_percent'    => $total > 0 ? (int) round( ( $optimized / $total ) * 100 ) : 100,
				'webp_enabled'        => $webp_enabled,
				'avif_enabled'        => $avif_enabled,
				'webp_supported'      => ! empty( $caps['cwebp'] ) || ! empty( $caps['imagick'] ) || ! empty( $caps['gd'] ) || ! empty( $caps['libvips'] ) || ! empty( $caps['wordpress_editor'] ),
				'avif_supported'      => ! empty( $caps['avifenc'] ) || ! empty( $caps['imagick'] ) || ! empty( $caps['libvips'] ),
				'originals_preserved' => true,
				'source_bytes_saved'  => $savings,
				'capabilities'        => $caps,
				'processors'          => $this->processor_labels( $caps ),
				'last_batch'          => get_option( 'nbh_performance_image_last_batch', array() ),
				'batch'               => $this->batches->state(),
				'queue'               => $this->queue->stats(),
				'profile'             => $this->profile->current(),
				'offload_provider'    => $offload,
				'offload_warning'     => $offload ? __( 'A media offload plugin was detected. NBH Performance only converts images with a readable local source file.', 'nbh-performance' ) : '',
				'conversion_enabled'  => $conversion_enabled,
				'serving_enabled'     => $serving_enabled,
				'safe_mode'           => $safe_mode,
				'serving_active'      => $conversion_enabled && $serving_enabled && ! $safe_mode,
				'batch_size'          => max( 1, min( 25, absint( get_option( 'nbh_performance_image_batch_size', 10 ) ) ) ),
				'convert_on_upload'   => (bool) get_option( 'nbh_performance_convert_on_upload', 0 ),
			)
		);
	}

	public function convert( WP_REST_Request $request ) {
		$force            = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$requested_mode_1 = $request->get_param( 'mode' );
		$mode             = sanitize_key( (string) ( $requested_mode_1 ? $requested_mode_1 : ( $force ? 'rebuild' : 'pending' ) ) );
		if ( ! in_array( $mode, array( 'pending', 'rebuild' ), true ) ) {
			$mode = $force ? 'rebuild' : 'pending';
		}
		$force = $force || 'rebuild' === $mode;
		$limit = max( 1, min( 25, absint( get_option( 'nbh_performance_image_batch_size', 10 ) ) ) );
		$ids   = $this->candidate_ids( $limit, $mode );

		$summary = array(
			'processed'    => 0,
			'converted'    => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'force'        => $force,
			'mode'         => $mode,
			'results'      => array(),
			'completed_at' => time(),
		);

		foreach ( $ids as $attachment_id ) {
			++$summary['processed'];
			if ( ! $this->offload->local_source_available( $attachment_id ) ) {
				++$summary['skipped'];
				$summary['results'][] = array(
					'attachment_id' => absint( $attachment_id ),
					'status'        => 'remote_source',
					'formats'       => array(),
				);
				continue;
			}
			$result = $this->pipeline->process( $attachment_id, $force );
			if ( is_wp_error( $result ) ) {
				++$summary['failed'];
				$status  = $result->get_error_code();
				$formats = array();
			} else {
				$status  = isset( $result['status'] ) ? sanitize_key( $result['status'] ) : 'processed';
				$formats = isset( $result['formats'] ) ? $result['formats'] : array();
				if ( in_array( $status, array( 'converted', 'reused', 'processed' ), true ) ) {
					++$summary['converted'];
				} elseif ( in_array( $status, array( 'excluded', 'unsupported_mime', 'no_format_enabled', 'animated_unsupported', 'remote_source' ), true ) ) {
					++$summary['skipped'];
				} else {
					++$summary['failed'];
				}
			}
			$summary['results'][] = array(
				'attachment_id' => absint( $attachment_id ),
				'status'        => sanitize_key( $status ),
				'formats'       => array_values( (array) $formats ),
			);
		}

		update_option( 'nbh_performance_image_last_batch', $summary, false );
		$status                      = $this->status()->get_data();
		$summary['remaining']        = isset( $status['remaining'] ) ? absint( $status['remaining'] ) : 0;
		$summary['converted_total']  = isset( $status['converted'] ) ? absint( $status['converted'] ) : 0;
		$summary['total_eligible']   = isset( $status['total_eligible'] ) ? absint( $status['total_eligible'] ) : 0;
		$summary['progress_percent'] = isset( $status['progress_percent'] ) ? absint( $status['progress_percent'] ) : 0;
		$summary['repeat_required']  = 'pending' === $mode && $summary['remaining'] > 0 && $summary['processed'] > 0;
		$summary['offload_provider'] = $this->offload->detected_provider();
		return rest_ensure_response( $summary );
	}

	public function queue( WP_REST_Request $request ) {
		$force             = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$requested_mode_2  = $request->get_param( 'mode' );
		$mode              = sanitize_key( (string) ( $requested_mode_2 ? $requested_mode_2 : ( $force ? 'rebuild' : 'pending' ) ) );
		$requested_limit_3 = $request->get_param( 'limit' );
		$limit             = max( 1, min( 500, absint( $requested_limit_3 ? $requested_limit_3 : 100 ) ) );
		$ids               = $this->candidate_ids( $limit, $mode );
		$jobs              = array();
		foreach ( $ids as $id ) {
			$jobs[] = $this->queue->enqueue(
				'image_optimize',
				array(
					'attachment_id' => absint( $id ),
					'force'         => $force || 'rebuild' === $mode,
				),
				array(
					'priority'     => 35,
					'max_attempts' => 3,
					'unique_key'   => 'image|' . absint( $id ) . '|' . ( ( $force || 'rebuild' === $mode ) ? 'force' : 'normal' ),
				)
			);
		}
		return rest_ensure_response(
			array(
				'queued'  => count( array_filter( $jobs ) ),
				'job_ids' => array_values( array_filter( array_map( 'absint', $jobs ) ) ),
				'mode'    => $mode,
				'queue'   => $this->queue->stats(),
			)
		);
	}

	public function batch_start( WP_REST_Request $request ) {
		$force             = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$requested_mode_4  = $request->get_param( 'mode' );
		$mode              = sanitize_key( (string) ( $requested_mode_4 ? $requested_mode_4 : ( $force ? 'rebuild' : 'pending' ) ) );
		$requested_limit_5 = $request->get_param( 'limit' );
		$limit             = max( 1, min( 10000, absint( $requested_limit_5 ? $requested_limit_5 : 1000 ) ) );
		$ids               = $this->candidate_ids( $limit, $mode );
		$state             = $this->batches->start( $ids, $force || 'rebuild' === $mode );
		$state['mode']     = $mode;
		return rest_ensure_response( $state );
	}

	public function batch_pause() {
		return rest_ensure_response( $this->batches->pause() );
	}

	public function batch_resume() {
		return rest_ensure_response( $this->batches->resume() );
	}

	public function batch_cancel() {
		return rest_ensure_response( $this->batches->cancel() );
	}

	public function restore( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'nbh_performance_invalid_attachment', __( 'The requested attachment is invalid.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		$result = $this->pipeline->restore( $id );
		return rest_ensure_response( $result );
	}

	/** @param string $mode pending|rebuild */
	private function candidate_ids( $limit, $mode ) {
		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ),
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( 'rebuild' === $mode ) {
			$args['order'] = 'DESC';
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Administrator-triggered, bounded attachment-ID batches need the per-image completion metadata; get_posts() uses the WordPress query API with no_found_rows and explicit limits.
			$args['meta_query'] = array(
				array(
					'key'     => ImageOptimizationPipeline::COMPLETE_META,
					'compare' => 'EXISTS',
				),
			);
		} else {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Administrator-triggered, bounded attachment-ID batches need the per-image completion metadata; get_posts() uses the WordPress query API with no_found_rows and explicit limits.
			$args['meta_query'] = array(
				array(
					'key'     => ImageOptimizationPipeline::COMPLETE_META,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new \WP_Query( $args );
		return array_map( 'absint', (array) $query->posts );
	}


	/** @param array<string,mixed> $caps Processor capability map. @return array<int,string> */
	private function processor_labels( array $caps ) {
		$labels = array();
		$map    = array(
			'cwebp'            => 'cwebp',
			'avifenc'          => 'avifenc',
			'libvips'          => 'libvips',
			'imagick'          => 'Imagick',
			'gd'               => 'GD',
			'wordpress_editor' => 'WordPress editor',
			'jpegoptim'        => 'jpegoptim',
			'optipng'          => 'optipng',
			'pngquant'         => 'pngquant',
		);
		foreach ( $map as $key => $label ) {
			if ( ! empty( $caps[ $key ] ) ) {
				$labels[] = $label;
			}
		}
		return array_values( array_unique( $labels ) );
	}
}
