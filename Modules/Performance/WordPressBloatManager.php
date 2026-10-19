<?php
/** WordPress and WooCommerce bloat controls with conservative defaults. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class WordPressBloatManager implements Module {
	public function register() {
		add_action( 'init', array( $this, 'apply' ), 1 );
	}

	public function apply() {
		if ( $this->enabled( 'nbh_performance_disable_emojis' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'tiny_mce_plugins', array( $this, 'remove_tinymce_emoji' ) );
			add_filter( 'wp_resource_hints', array( $this, 'remove_emoji_dns_prefetch' ), 10, 2 );
		}
		if ( $this->enabled( 'nbh_performance_disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
			add_filter( 'embed_oembed_discover', '__return_false' );
			add_filter( 'tiny_mce_plugins', array( $this, 'remove_tinymce_wpembed' ) );
			add_action( 'wp_footer', array( $this, 'dequeue_wp_embed' ), 1 );
		}
		if ( $this->enabled( 'nbh_performance_remove_query_strings' ) ) {
			add_filter( 'style_loader_src', array( $this, 'remove_version_query' ), 15 );
			add_filter( 'script_loader_src', array( $this, 'remove_version_query' ), 15 );
		}
		if ( $this->enabled( 'nbh_performance_disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		}
		if ( $this->enabled( 'nbh_performance_remove_head_links' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
			remove_action( 'wp_head', 'wp_generator' );
		}
		if ( $this->enabled( 'nbh_performance_disable_rest_api_links' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		if ( $this->enabled( 'nbh_performance_disable_rss_links' ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
		if ( $this->enabled( 'nbh_performance_disable_dashicons_guests' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_dashicons' ), 100 );
		}
		if ( $this->enabled( 'nbh_performance_disable_jquery_migrate' ) ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}
		if ( $this->enabled( 'nbh_performance_remove_comment_author_urls' ) ) {
			add_filter( 'get_comment_author_url', '__return_empty_string' );
			add_filter( 'comment_form_default_fields', array( $this, 'remove_comment_url_field' ) );
		}
		if ( $this->enabled( 'nbh_performance_disable_self_pingbacks' ) ) {
			add_action( 'pre_ping', array( $this, 'disable_self_pingbacks' ) );
		}
		if ( $this->enabled( 'nbh_performance_disable_block_library_css' ) || $this->enabled( 'nbh_performance_disable_global_styles' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_block_styles' ), 1000 );
		}
		if ( $this->enabled( 'nbh_performance_disable_woocommerce_cart_fragments' ) || $this->enabled( 'nbh_performance_disable_woocommerce_scripts_non_shop' ) || $this->enabled( 'nbh_performance_disable_password_strength_meter' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_commerce_bloat' ), 1000 );
		}
	}

	public function remove_tinymce_emoji( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}
	public function remove_tinymce_wpembed( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpembed' ) ) : array();
	}
	public function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type ) {
			return $urls;
		}
		return array_values(
			array_filter(
				$urls,
				static function ( $url ) {
					// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- This removes the core emoji DNS-prefetch hint; it does not enqueue, download or offload any remote file.
					return false === strpos( (string) $url, 's.w.org/images/core/emoji/' );
				}
			)
		);
	}
	public function dequeue_wp_embed() {
		wp_dequeue_script( 'wp-embed' );
	}
	public function remove_version_query( $src ) {
		return false === strpos( (string) $src, 'ver=' ) ? $src : remove_query_arg( 'ver', $src );
	}
	public function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
	public function dequeue_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}
	public function remove_jquery_migrate( $scripts ) {
		if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}
		$jquery = $scripts->registered['jquery'];
		if ( ! empty( $jquery->deps ) ) {
			$jquery->deps = array_diff( $jquery->deps, array( 'jquery-migrate' ) );
		}
	}
	public function remove_comment_url_field( $fields ) {
		unset( $fields['url'] );
		return $fields;
	}

	public function disable_self_pingbacks( &$links ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( (array) $links as $key => $link ) {
			if ( $home && wp_parse_url( $link, PHP_URL_HOST ) === $home ) {
				unset( $links[ $key ] );
			}
		}
	}

	public function dequeue_block_styles() {
		if ( $this->enabled( 'nbh_performance_disable_block_library_css' ) ) {
			foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'wc-blocks-style' ) as $handle ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
		if ( $this->enabled( 'nbh_performance_disable_global_styles' ) ) {
			foreach ( array( 'global-styles', 'classic-theme-styles' ) as $handle ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}

	public function dequeue_commerce_bloat() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$commerce = ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() );
		if ( ! $commerce && $this->enabled( 'nbh_performance_disable_woocommerce_cart_fragments' ) ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
		if ( ! $commerce && $this->enabled( 'nbh_performance_disable_woocommerce_scripts_non_shop' ) ) {
			foreach ( array( 'woocommerce', 'wc-add-to-cart', 'wc-cart-fragments', 'wc-single-product', 'selectWoo', 'zoom', 'flexslider', 'photoswipe', 'photoswipe-ui-default' ) as $handle ) {
				wp_dequeue_script( $handle );
			}
			foreach ( array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-inline' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}
		if ( ! $commerce && $this->enabled( 'nbh_performance_disable_password_strength_meter' ) ) {
			wp_dequeue_script( 'password-strength-meter' );
			wp_dequeue_script( 'zxcvbn-async' );
		}
	}

	private function enabled( $option ) {
		return ! get_option( 'nbh_performance_safe_mode', 1 ) && (bool) get_option( $option, 0 );
	}
}
