<?php
/** Preload & Navigation Hub status and safe run controls. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class PreloadController implements Module {
	/** @var PreloadQueue */
	private $queue;

	/** @var SitemapDiscovery */
	private $sitemaps;

	/** @var PreloadRunStore */
	private $runs;

	/** @var PriorityElementsStorage */
	private $priority;

	public function __construct( PreloadQueue $queue, SitemapDiscovery $sitemaps, PreloadRunStore $runs, ?PriorityElementsStorage $priority = null ) {
		$this->queue    = $queue;
		$this->sitemaps = $sitemaps;
		$this->runs     = $runs;
		$this->priority = $priority ? $priority : new PriorityElementsStorage();
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/start',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/pause',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'pause' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/resume',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/cancel',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/warm-homepage',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'warm_homepage' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/preload/priority-elements/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_priority_elements' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to manage Preload & Navigation.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function status() {
		$safe_mode   = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$cache       = (bool) get_option( 'nbh_performance_cache_enabled', 0 );
		$sitemap     = (bool) get_option( 'nbh_performance_sitemap_preload_enabled', 0 );
		$instant     = (bool) get_option( 'nbh_performance_instant_page', 0 );
		$speculation = (bool) get_option( 'nbh_performance_speculation_rules_enabled', 0 );
		$priority    = (bool) get_option( 'nbh_performance_resource_priority_enabled', 0 );
		$preconnect  = (bool) get_option( 'nbh_performance_automatic_preconnect_enabled', 0 );
		$fonts       = (bool) get_option( 'nbh_performance_font_preload_enabled', 0 );
		$active      = 0;
		foreach ( array( $sitemap, $instant, $speculation, $priority, $preconnect, $fonts ) as $flag ) {
			if ( $flag ) {
				++$active;
			}
		}
		$run        = $this->runs->current();
		$history    = array_slice( (array) get_option( PreloadRunStore::HISTORY_OPTION, array() ), 0, 10 );
		$recent     = array_slice( (array) get_option( 'nbh_performance_preload_recent_results', array() ), 0, 5 );
		$last       = get_option( 'nbh_performance_last_warm_result', array() );
		$paused     = (bool) get_option( 'nbh_performance_preload_paused', 0 );
		$run_status = is_array( $run ) && ! empty( $run['status'] ) ? (string) $run['status'] : 'idle';

		return rest_ensure_response(
			array(
				'safe_mode'            => $safe_mode,
				'cache_enabled'        => $cache,
				'active_features'      => $active,
				'runtime_ready'        => ! $safe_mode && $active > 0,
				'can_start_preload'    => ! $safe_mode && $cache,
				'sitemap_preload'      => $sitemap && ! $safe_mode,
				'instant_page'         => $instant && ! $safe_mode,
				'speculation_rules'    => $speculation && ! $safe_mode,
				'resource_priority'    => $priority && ! $safe_mode,
				'automatic_preconnect' => $preconnect && ! $safe_mode,
				'font_preload'         => $fonts && ! $safe_mode,
				'speculation_mode'     => sanitize_key( (string) get_option( 'nbh_performance_speculation_mode', 'prefetch' ) ),
				'preload_paused'       => $paused,
				'max_urls'             => absint( get_option( 'nbh_performance_preload_max_urls', 500 ) ),
				'batch_size'           => absint( get_option( 'nbh_performance_preload_batch_size', 20 ) ),
				'mobile_variant'       => (bool) get_option( 'nbh_performance_preload_mobile_variant', 0 ),
				'sample_rate'          => absint( get_option( 'nbh_performance_priority_elements_sample_rate', 10 ) ),
				'run'                  => is_array( $run ) ? $run : array(),
				'run_status'           => $run_status,
				'history'              => $history,
				'recent_results'       => $recent,
				'last_warm'            => is_array( $last ) ? $last : array(),
				'priority_elements'    => $this->priority->stats(),
			)
		);
	}

	public function start( WP_REST_Request $request ) {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_cache_enabled', 0 ) ) {
			return new WP_Error( 'nbh_performance_preload_unavailable', __( 'Enable page caching and leave Safe Mode before starting preload.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$current = $this->runs->current();
		if ( is_array( $current ) && in_array( (string) ( isset( $current['status'] ) ? $current['status'] : '' ), array( 'running', 'paused' ), true ) ) {
			return new WP_Error( 'nbh_performance_preload_busy', __( 'A preload run is already in progress. Pause, resume or cancel it first.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$requested_limit_1 = $request->get_param( 'limit' );
		$limit             = max( 1, min( 10000, absint( $requested_limit_1 ? $requested_limit_1 : get_option( 'nbh_performance_preload_max_urls', 500 ) ) ) );
		$records           = $this->sitemaps->discover_records( $limit );
		$run               = $this->runs->start( 'manual', count( $records ) );
		$queued            = $this->queue->enqueue_records( $records, 20, $run['id'] );
		$this->runs->mark_queued( $queued );
		update_option( 'nbh_performance_preload_paused', 0, false );
		return rest_ensure_response(
			array(
				'started' => true,
				'queued'  => $queued,
				'run'     => $this->runs->current(),
				'preload' => $this->status()->get_data(),
			)
		);
	}

	public function pause() {
		update_option( 'nbh_performance_preload_paused', 1, false );
		$run = $this->runs->pause();
		return rest_ensure_response(
			array(
				'run'     => $run,
				'preload' => $this->status()->get_data(),
			)
		);
	}

	public function resume() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_cache_enabled', 0 ) ) {
			return new WP_Error( 'nbh_performance_preload_unavailable', __( 'Enable page caching and leave Safe Mode before resuming preload.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		update_option( 'nbh_performance_preload_paused', 0, false );
		$run = $this->runs->resume();
		return rest_ensure_response(
			array(
				'run'     => $run,
				'preload' => $this->status()->get_data(),
			)
		);
	}

	public function cancel() {
		update_option( 'nbh_performance_preload_paused', 1, false );
		$run = $this->runs->cancel();
		return rest_ensure_response(
			array(
				'run'     => $run,
				'preload' => $this->status()->get_data(),
			)
		);
	}

	public function warm_homepage() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_cache_enabled', 0 ) ) {
			return new WP_Error( 'nbh_performance_preload_unavailable', __( 'Enable page caching and leave Safe Mode before warming the homepage.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$result = CachePrimer::warm( home_url( '/' ), 'desktop' );
		if ( get_option( 'nbh_performance_preload_mobile_variant', 0 ) ) {
			CachePrimer::warm( home_url( '/' ), 'mobile' );
		}
		return rest_ensure_response(
			array(
				'success' => ! empty( $result['success'] ),
				'result'  => $result,
				'preload' => $this->status()->get_data(),
			)
		);
	}

	public function clear_priority_elements() {
		$cleared = $this->priority->clear();
		return rest_ensure_response(
			array(
				'cleared' => (bool) $cleared,
				'preload' => $this->status()->get_data(),
			)
		);
	}
}
