<?php
/** WooCommerce cache, Store API and script safety integration. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;

final class WooCommerceGuard implements Module {
	private $queue;
	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	public function register() {
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'request_cacheable' ), 5, 2 );
		add_filter( 'nbh_performance_runtime_config', array( $this, 'runtime_config' ), 20 );
		add_filter( 'nbh_performance_cache_dependencies', array( $this, 'dependencies' ), 20 );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'protected_scripts' ) );
		add_action( 'template_redirect', array( $this, 'declare_dynamic_request' ), 0 );
		add_action( 'woocommerce_update_product', array( $this, 'product_changed' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'stock_changed' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'stock_changed' ), 20, 1 );
		add_action( 'woocommerce_delete_product_transients', array( $this, 'product_changed' ), 20, 1 );
	}

	/** Prevent page caching for every WooCommerce stateful endpoint. */
	public function request_cacheable( $allowed, $uri ) {
		if ( ! $allowed ) {
			return false;
		}
		if ( $this->is_dynamic_request() ) {
			return false;
		}

		$uri       = strtolower( rawurldecode( (string) $uri ) );
		$fragments = array(
			'/cart',
			'/checkout',
			'/my-account',
			'/order-pay',
			'/order-received',
			'/wc-api/',
			'/wp-json/wc/store/',
			'/wp-json/wc/v',
			'rest_route=/wc/',
			'wc-ajax=',
			'add-to-cart=',
			'remove_item=',
			'undo_item=',
			'key=wc_order_',
			'pay_for_order=',
			'order-pay',
			'order-received',
		);
		foreach ( $fragments as $fragment ) {
			if ( false !== strpos( $uri, $fragment ) ) {
				return false;
			}
		}
		return ! $this->has_session_cookie();
	}

	/** Define the standard WordPress no-cache constant before response capture. */
	public function declare_dynamic_request() {
		if ( $this->is_dynamic_request() && ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the WordPress/cache interoperability flag; a plugin-prefixed constant would not protect these requests.
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public function runtime_config( $config ) {
		$paths = array(
			'/cart',
			'/checkout',
			'/my-account',
			'/order-pay',
			'/order-received',
			'/wc-api/',
			'/wp-json/wc/store/',
			'/wp-json/wc/v3/',
			'/wp-json/wc/v2/',
			'/wp-json/wc/v1/',
		);
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$url  = wc_get_page_permalink( $page );
				$path = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
				if ( '' !== $path ) {
					$paths[] = untrailingslashit( $path );
				}
			}
		}
		$cookies                  = array(
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'woocommerce_session_',
			'woocommerce_recently_viewed',
			'wc_cart_created',
			'wc_fragments_',
			'store_notice',
			'woocommerce_currency',
			'tk_ai',
		);
		$config['bypass_paths']   = array_values( array_unique( array_merge( isset( $config['bypass_paths'] ) ? (array) $config['bypass_paths'] : array(), $paths ) ) );
		$config['bypass_cookies'] = array_values( array_unique( array_merge( isset( $config['bypass_cookies'] ) ? (array) $config['bypass_cookies'] : array(), $cookies ) ) );
		return $config;
	}

	public function dependencies( $dependencies ) {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$post_id        = get_queried_object_id();
			$dependencies[] = 'woocommerce';
			$dependencies[] = 'product-' . absint( $post_id );
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$dependencies[] = 'woocommerce-shop';
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$dependencies[] = 'woocommerce-product-taxonomy';
		}
		return array_values( array_unique( $dependencies ) );
	}

	public function protected_scripts( $handles ) {
		$protected = array(
			'wc-cart-fragments',
			'woocommerce',
			'wc-add-to-cart',
			'wc-checkout',
			'wc-single-product',
			'wc-add-to-cart-variation',
			'wc-country-select',
			'wc-address-i18n',
			'wc-credit-card-form',
			'selectWoo',
			'js-cookie',
			'wc-blocks-checkout',
			'wc-blocks-cart',
			'wc-settings',
		);
		return array_values( array_unique( array_merge( (array) $handles, $protected ) ) );
	}

	public function product_changed( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return;
		}
		$url  = get_permalink( $product_id );
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : get_post_type_archive_link( 'product' );
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'dependencies'  => array( 'home', 'woocommerce', 'woocommerce-shop', 'woocommerce-product-taxonomy', 'product-' . $product_id, 'post-' . $product_id, 'post-type-product' ),
				'urls'          => array_filter( array( $url, $shop ) ),
				'warm_urls'     => array_filter( array( $url, $shop ) ),
				'object_groups' => array( 'posts', 'terms', 'woocommerce' ),
				'reason'        => 'woocommerce_product_changed',
			),
			array(
				'priority'   => 90,
				'unique_key' => 'wc-product|' . $product_id,
			)
		);
	}

	public function stock_changed( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->product_changed( $product->get_id() );
		}
	}

	private function is_dynamic_request() {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification to bypass private/cart/cache requests; no settings or content are modified and public navigation cannot require an admin nonce.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && isset( $_REQUEST['wc-ajax'] ) ) {
			return true;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification to bypass private/cart/cache requests; no settings or content are modified and public navigation cannot require an admin nonce.
		if ( isset( $_GET['wc-ajax'] ) || isset( $_GET['add-to-cart'] ) || isset( $_GET['remove_item'] ) || isset( $_GET['pay_for_order'] ) ) {
			return true;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? strtolower( (string) $GLOBALS['wp']->query_vars['rest_route'] ) : '';
			$route = ltrim( $route, '/' );
			if ( 0 === strpos( $route, 'wc/' ) || 0 === strpos( $route, 'wc-' ) ) {
				return true;
			}
		}
		return $this->has_session_cookie();
	}

	private function has_session_cookie() {
		foreach ( array_keys( (array) $_COOKIE ) as $name ) {
			$name = strtolower( (string) $name );
			foreach ( array( 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_', 'woocommerce_session_', 'wc_cart_created', 'wc_fragments_', 'woocommerce_recently_viewed', 'store_notice', 'woocommerce_currency' ) as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) || $name === $prefix ) {
					return true;
				}
			}
		}
		return false;
	}
}
