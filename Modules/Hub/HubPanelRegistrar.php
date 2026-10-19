<?php
/** NBH Performance control center, standalone admin, and toolbar registration. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\CacheEngine\PageCacheManager;
use NBH\Performance\Modules\Core\Module;

final class HubPanelRegistrar implements Module {
	/** @var PageCacheManager */
	private $page_cache;

	/** @var string */
	private $hook_suffix = '';

	public function __construct( PageCacheManager $page_cache ) {
		$this->page_cache = $page_cache;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_action( 'admin_head', array( $this, 'toolbar_styles' ) );
		add_action( 'admin_head', array( $this, 'plugin_favicon' ), 1 );
		add_action( 'wp_head', array( $this, 'toolbar_styles' ) );
		add_action( 'admin_post_nbh_performance_quick_action', array( $this, 'quick_action' ) );
		add_action( 'admin_notices', array( $this, 'quick_action_notice' ) );
		add_filter( 'plugin_action_links_' . NBH_PERFORMANCE_BASENAME, array( $this, 'plugin_action_links' ), 999 );
		add_action( 'swiftpress_hub_register_panels', array( $this, 'register_hub_panel' ) );
	}

	public function menu() {
		$this->hook_suffix = (string) add_options_page(
			__( 'NBH Performance', 'nbh-performance' ),
			__( 'NBH Performance', 'nbh-performance' ),
			'manage_options',
			'nbh-performance',
			array( $this, 'render' )
		);
	}

	public function plugin_action_links( $links ) {
		$organized = array(
			'nbh-performance-dashboard' => '<a href="' . esc_url( $this->dashboard_url() ) . '">' . esc_html__( 'Dashboard', 'nbh-performance' ) . '</a>',
			'nbh-performance-help'      => '<a href="' . esc_url( $this->dashboard_url( 'help' ) ) . '">' . esc_html__( 'Help', 'nbh-performance' ) . '</a>',
		);

		foreach ( (array) $links as $key => $link ) {
			$key_text = (string) $key;
			if ( false !== strpos( $key_text, 'activate-license' ) || false !== strpos( $key_text, 'opt-in-or-opt-out' ) ) {
				continue;
			}
			$organized[ $key ] = $link;
		}

		return $organized;
	}

	private function dashboard_url( $fragment = '' ) {
		$url = admin_url( 'options-general.php?page=nbh-performance' );
		return $fragment ? $url . '#' . sanitize_key( $fragment ) : $url;
	}

	public function register_hub_panel( $registry ) {
		$panel = array(
			'id'              => 'nbh-performance',
			'title'           => __( 'NBH Performance', 'nbh-performance' ),
			'capability'      => 'manage_options',
			'render_callback' => array( $this, 'render' ),
		);
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
			$registry->register( $panel );
		}
		do_action( 'nbh_performance_hub_panel_registered', $panel, $registry );
	}

	public function assets( $hook ) {
		if ( $this->hook_suffix !== (string) $hook && 'settings_page_nbh-performance' !== (string) $hook && false === strpos( (string) $hook, 'swiftpress' ) ) {
			return;
		}
		$asset_version = defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : NBH_PERFORMANCE_VERSION;
		wp_enqueue_style( 'nbh-performance-hub-fonts', NBH_PERFORMANCE_URL . 'assets/css/hub-fonts.css', array(), $asset_version );
		wp_enqueue_style( 'nbh-performance-hub', NBH_PERFORMANCE_URL . 'assets/css/hub-dashboard.css', array( 'nbh-performance-hub-fonts' ), $asset_version );
		wp_enqueue_style( 'nbh-performance-hub-theme', NBH_PERFORMANCE_URL . 'assets/css/hub-theme-v2.css', array( 'nbh-performance-hub' ), $asset_version );
		wp_enqueue_script( 'nbh-performance-hub-quality-runtime', NBH_PERFORMANCE_URL . 'assets/js/hub-quality-runtime.js', array( 'wp-i18n' ), $asset_version, true );
		wp_enqueue_script( 'nbh-performance-hub-catalog', NBH_PERFORMANCE_URL . 'assets/js/hub-catalog.js', array( 'wp-i18n' ), $asset_version, true );
		wp_enqueue_script( 'nbh-performance-hub-product-quality', NBH_PERFORMANCE_URL . 'assets/js/hub-product-quality.js', array( 'wp-element', 'wp-i18n', 'nbh-performance-hub-quality-runtime' ), $asset_version, true );
		wp_enqueue_script( 'nbh-performance-hub', NBH_PERFORMANCE_URL . 'assets/js/hub-dashboard.js', array( 'wp-api-fetch', 'wp-element', 'wp-i18n', 'nbh-performance-hub-catalog', 'nbh-performance-hub-product-quality' ), $asset_version, true );
		wp_localize_script(
			'nbh-performance-hub',
			'nbhPerformanceHub',
			array(
				'root'             => esc_url_raw( rest_url( 'nbh-performance/v1' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'scriptManagerUrl' => esc_url_raw( $this->dashboard_url( 'script_manager' ) ),
				'logoUrl'          => $this->brand_asset_url( 'nbh-performance-logo.svg' ),
				'iconUrl'          => $this->brand_asset_url( 'nbh-performance-icon.svg' ),
				'faviconUrl'       => $this->brand_asset_url( 'nbh-performance-icon.svg' ),
				'locale'           => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
				'isRtl'            => is_rtl(),
				'siteName'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'nbh-performance-hub-quality-runtime', 'nbh-performance', NBH_PERFORMANCE_PATH . 'languages' );
			wp_set_script_translations( 'nbh-performance-hub-catalog', 'nbh-performance', NBH_PERFORMANCE_PATH . 'languages' );
			wp_set_script_translations( 'nbh-performance-hub-product-quality', 'nbh-performance', NBH_PERFORMANCE_PATH . 'languages' );
			wp_set_script_translations( 'nbh-performance-hub', 'nbh-performance', NBH_PERFORMANCE_PATH . 'languages' );
		}
	}



	/** Version local brand files so browsers immediately pick up an updated logo. */
	private function brand_asset_url( $filename ) {
		$path    = NBH_PERFORMANCE_PATH . 'assets/images/' . $filename;
		$version = is_file( $path ) ? (string) filemtime( $path ) : NBH_PERFORMANCE_VERSION;
		return esc_url_raw( add_query_arg( 'ver', $version, NBH_PERFORMANCE_URL . 'assets/images/' . $filename ) );
	}


	public function toolbar_styles() {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<style id="nbh-performance-admin-bar-css">#wpadminbar #wp-admin-bar-nbh-performance>.ab-item{display:flex;align-items:center;gap:6px}#wpadminbar #wp-admin-bar-nbh-performance .nbh-performance-toolbar-icon{width:20px;height:20px;margin:0;object-fit:contain;border-radius:0}#wpadminbar #wp-admin-bar-nbh-performance:hover>.ab-item .nbh-performance-toolbar-icon{opacity:.86}</style>';
	}

	public function plugin_favicon() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || 'settings_page_nbh-performance' !== (string) $screen->id ) {
			return;
		}
		echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( $this->brand_asset_url( 'nbh-performance-favicon-32.png' ) ) . '">';
		echo '<link rel="icon" type="image/svg+xml" sizes="any" href="' . esc_url( $this->brand_asset_url( 'nbh-performance-icon.svg' ) ) . '">';
	}

	public function admin_bar( $admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'nbh-performance',
				'title' => '<img class="nbh-performance-toolbar-icon" src="' . esc_url( $this->brand_asset_url( 'nbh-performance-icon-white.svg' ) ) . '" alt=""><span class="ab-label">' . esc_html__( 'NBH Performance', 'nbh-performance' ) . '</span>',
				'href'  => $this->dashboard_url(),
				'meta'  => array( 'class' => 'nbh-performance-admin-bar' ),
			)
		);

		$this->add_toolbar_link( $admin_bar, 'nbh-performance-dashboard', __( 'Dashboard', 'nbh-performance' ), $this->dashboard_url() );

		$this->add_toolbar_link( $admin_bar, 'nbh-performance-performance', __( 'Performance', 'nbh-performance' ), $this->dashboard_url( 'caching' ) );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-insights', __( 'Performance Insights', 'nbh-performance' ), $this->dashboard_url( 'insights' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-caching', __( 'Page Cache', 'nbh-performance' ), $this->dashboard_url( 'caching' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-object-cache', __( 'Object Cache', 'nbh-performance' ), $this->dashboard_url( 'object_cache' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-optimization', __( 'CSS & JavaScript', 'nbh-performance' ), $this->dashboard_url( 'optimization' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-media', __( 'Media Delivery', 'nbh-performance' ), $this->dashboard_url( 'media' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-images', __( 'Image Conversion', 'nbh-performance' ), $this->dashboard_url( 'images' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-preload', __( 'Preload & Navigation', 'nbh-performance' ), $this->dashboard_url( 'preload' ), 'nbh-performance-performance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-cdn', __( 'CDN & Edge Cache', 'nbh-performance' ), $this->dashboard_url( 'cdn' ), 'nbh-performance-performance' );

		$this->add_toolbar_link( $admin_bar, 'nbh-performance-maintenance', __( 'Maintenance', 'nbh-performance' ), $this->dashboard_url( 'tools' ) );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-database', __( 'Database Cleanup', 'nbh-performance' ), $this->dashboard_url( 'database' ), 'nbh-performance-maintenance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-script-manager', __( 'Script Manager', 'nbh-performance' ), $this->dashboard_url( 'script_manager' ), 'nbh-performance-maintenance' );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-tools', __( 'Tools', 'nbh-performance' ), $this->dashboard_url( 'tools' ), 'nbh-performance-maintenance' );

		$this->add_toolbar_link( $admin_bar, 'nbh-performance-license', __( 'About & Updates', 'nbh-performance' ), $this->dashboard_url( 'license' ) );
		$this->add_toolbar_link( $admin_bar, 'nbh-performance-help', __( 'Help', 'nbh-performance' ), $this->dashboard_url( 'help' ) );

		$admin_bar->add_node(
			array(
				'id'     => 'nbh-performance-purge-all',
				'parent' => 'nbh-performance',
				'title'  => __( 'Clear all cache', 'nbh-performance' ),
				'href'   => $this->quick_action_url( 'purge_all' ),
				'meta'   => array( 'class' => 'nbh-performance-toolbar-action' ),
			)
		);

		if ( ! is_admin() ) {
			$admin_bar->add_node(
				array(
					'id'     => 'nbh-performance-purge-current',
					'parent' => 'nbh-performance',
					'title'  => __( 'Purge this page', 'nbh-performance' ),
					'href'   => $this->quick_action_url( 'purge_current' ),
				)
			);
		}
	}

	private function add_toolbar_link( $admin_bar, $id, $title, $href, $parent_id = 'nbh-performance' ) {
		$admin_bar->add_node(
			array(
				'id'     => $id,
				'parent' => $parent_id,
				'title'  => $title,
				'href'   => $href,
			)
		);
	}

	private function quick_action_url( $task ) {
		$url = add_query_arg(
			array(
				'action' => 'nbh_performance_quick_action',
				'task'   => sanitize_key( $task ),
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'nbh_performance_quick_action_' . sanitize_key( $task ) );
	}

	public function quick_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this NBH Performance action.', 'nbh-performance' ), 403 );
		}

		$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
		check_admin_referer( 'nbh_performance_quick_action_' . $task );

		$success = false;
		$message = __( 'NBH Performance action could not be completed.', 'nbh-performance' );

		if ( 'purge_all' === $task ) {
			$success = $this->page_cache->purge_all();
			$message = $success ? __( 'NBH Performance cleared the entire page cache successfully.', 'nbh-performance' ) : __( 'NBH Performance could not clear the page cache.', 'nbh-performance' );
		} elseif ( 'purge_current' === $task ) {
			$target  = wp_get_referer();
			$success = $target ? $this->page_cache->purge_url( $target ) : false;
			$message = $success ? __( 'NBH Performance cleared the cache for this page.', 'nbh-performance' ) : __( 'NBH Performance could not clear the cache for this page.', 'nbh-performance' );
		}

		set_transient(
			'nbh_performance_toolbar_notice_' . get_current_user_id(),
			array(
				'success' => (bool) $success,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = $this->dashboard_url();
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function quick_action_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = 'nbh_performance_toolbar_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$tone  = ! empty( $notice['success'] ) ? 'success' : 'error';
		$title = ! empty( $notice['success'] ) ? __( 'Action completed', 'nbh-performance' ) : __( 'Action could not be completed', 'nbh-performance' );
		$icon  = ! empty( $notice['success'] ) ? 'yes-alt' : 'warning';
		echo '<div class="notice nbh-performance-notice nbh-performance-notice--' . esc_attr( $tone ) . ' is-dismissible"><div class="nbh-performance-notice__icon"><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span></div><div class="nbh-performance-notice__content"><strong class="nbh-performance-notice__title">' . esc_html( $title ) . '</strong><p class="nbh-performance-notice__message">' . esc_html( (string) $notice['message'] ) . '</p></div></div>';
	}

	public function render() {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="wrap nbh-performance-admin-wrap"><div id="nbh-performance-hub-root" aria-live="polite"><div class="np-app-loading" role="status"><span class="np-loader" aria-hidden="true"></span><strong>' . esc_html__( 'Loading NBH Performance', 'nbh-performance' ) . '</strong><p>' . esc_html__( 'Preparing your performance workspace…', 'nbh-performance' ) . '</p></div></div></div>';
		}
	}
}
