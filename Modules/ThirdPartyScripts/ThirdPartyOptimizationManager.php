<?php
/**
 * Third-party optimization module composition.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ThirdPartyScripts;

use NBH\Performance\Modules\Core\Module;

final class ThirdPartyOptimizationManager implements Module {
	/** @var ConsentScriptGuard */
	private $consent_guard;
	/** @var YouTubeFacade */
	private $youtube;
	/** @var ChatWidgetFacade */
	private $chat;

	/** Constructor. */
	public function __construct( ConsentScriptGuard $consent_guard, YouTubeFacade $youtube, ChatWidgetFacade $chat ) {
		$this->consent_guard = $consent_guard;
		$this->youtube       = $youtube;
		$this->chat          = $chat;
	}

	/** Register hooks. */
	public function register() {
		add_filter( 'script_loader_tag', array( $this->consent_guard, 'filter_script_tag' ), 80, 3 );
		add_filter( 'embed_oembed_html', array( $this->youtube, 'filter_embed' ), 30 );
		add_filter( 'video_embed_html', array( $this->youtube, 'filter_embed' ), 30 );
		add_filter( 'script_loader_tag', array( $this->chat, 'filter_script_tag' ), 85, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 998 );
	}

	/** Enqueue external CSP-safe facade loader. */
	public function enqueue_assets() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) ) {
			return;
		}
		if ( get_option( 'nbh_performance_youtube_facade_enabled', 0 ) || get_option( 'nbh_performance_chat_facade_enabled', 0 ) ) {
			wp_enqueue_script( 'nbh-performance-media-facade', NBH_PERFORMANCE_URL . 'assets/js/media-facade.js', array(), NBH_PERFORMANCE_VERSION, true );
			wp_script_add_data( 'nbh-performance-media-facade', 'strategy', 'defer' );
			wp_enqueue_style( 'nbh-performance-media-facade', NBH_PERFORMANCE_URL . 'assets/css/media-facade.css', array(), NBH_PERFORMANCE_VERSION );
		}
	}
}
