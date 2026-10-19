<?php
/** Media Delivery Hub status and safe maintenance operations. @package NBHPerformance */
namespace NBH\Performance\Modules\ThirdPartyScripts;

use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class MediaDeliveryController implements Module {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/media/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to manage media delivery.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function status() {
		$safe_mode  = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$lazy       = (bool) get_option( 'nbh_performance_lazy_load_enabled', 0 );
		$responsive = (bool) get_option( 'nbh_performance_responsive_images_enabled', 0 );
		$youtube    = (bool) get_option( 'nbh_performance_youtube_facade_enabled', 0 );
		$chat       = (bool) get_option( 'nbh_performance_chat_facade_enabled', 0 );
		$consent    = (bool) get_option( 'nbh_performance_consent_mode_enabled', 0 );
		$autoload   = (bool) get_option( 'nbh_performance_youtube_autoload_after_consent', 0 );
		$active     = 0;
		foreach ( array( $lazy, $responsive, $youtube, $chat ) as $flag ) {
			if ( $flag ) {
				++$active;
			}
		}

		return rest_ensure_response(
			array(
				'safe_mode'         => $safe_mode,
				'active_features'   => $active,
				'lazy_load'         => $lazy && ! $safe_mode,
				'responsive_images' => $responsive && ! $safe_mode,
				'youtube_facade'    => $youtube && ! $safe_mode,
				'youtube_autoload'  => $autoload,
				'chat_facade'       => $chat && ! $safe_mode,
				'consent_mode'      => $consent,
				'eager_images'      => absint( get_option( 'nbh_performance_lazyload_eager_images', 2 ) ),
				'background_lazy'   => (bool) get_option( 'nbh_performance_lazyload_background_images', 0 ),
				'video_preload'     => sanitize_key( (string) get_option( 'nbh_performance_video_preload', 'metadata' ) ),
				'add_dimensions'    => (bool) get_option( 'nbh_performance_add_missing_image_dimensions', 0 ),
				'runtime_ready'     => ! $safe_mode && $active > 0,
			)
		);
	}
}
