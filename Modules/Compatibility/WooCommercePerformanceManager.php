<?php
/** First-class WooCommerce performance and safety integration. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use WP_REST_Server;

final class WooCommercePerformanceManager implements Module {
	private $queue;
	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	public function register() {
		add_filter( 'nbh_performance_delay_compatibility_rules', array( $this, 'delay_rules' ) );
		add_action( 'save_post_product_variation', array( $this, 'variation_changed' ), 30, 3 );
		add_action( 'edited_product_cat', array( $this, 'taxonomy_changed' ), 30 );
		add_action( 'delete_product_cat', array( $this, 'taxonomy_changed' ), 30 );
		add_action( 'save_post_shop_coupon', array( $this, 'coupon_changed' ), 30, 3 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/woocommerce/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static function ( $request ) {
					return current_user_can( 'manage_options' ) && \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
				},
			)
		);
	}

	public function status() {
		global $wpdb;
		$active   = class_exists( 'WooCommerce' );
		$gateways = array();
		if ( $active && function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( 'yes' === $gateway->enabled ) {
					$gateways[] = sanitize_key( $gateway->id );
				}
			}
		}
		$table = $wpdb->prefix . 'woocommerce_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$sessions = $this->table_exists( $table ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : 0;
		return rest_ensure_response(
			array(
				'active'                                => $active,
				'version'                               => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'gateways'                              => $gateways,
				'sessions'                              => $sessions,
				'currency'                              => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'currency_cache_variant'                => (bool) get_option( 'nbh_performance_woocommerce_currency_variant', 0 ),
				'cart_fragments_disabled_outside_store' => (bool) get_option( 'nbh_performance_disable_woocommerce_cart_fragments', 0 ),
				'protected_pages'                       => array_filter( array( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'cart' ) : '', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'checkout' ) : '', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '' ) ),
			)
		);
	}


	public function delay_rules( $rules ) {
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			$rules = array_merge( (array) $rules, array( 'wc-', 'woocommerce', 'stripe', 'paypal', 'square', 'klarna', 'afterpay', 'payment', 'checkout' ) );
		}
		return array_values( array_unique( $rules ) );
	}

	public function variation_changed( $post_id, $post, $update ) {
		unset( $post, $update );
		$parent_id = wp_get_post_parent_id( $post_id );
		if ( $parent_id ) {
			$this->invalidate( $parent_id, 'variation_changed' );
		}
	}
	public function taxonomy_changed( $term_id ) {
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'dependencies' => array( 'woocommerce', 'woocommerce-shop', 'taxonomy-product_cat', 'term-' . absint( $term_id ) ),
				'reason'       => 'woocommerce_taxonomy_changed',
			),
			array(
				'priority'   => 80,
				'unique_key' => 'wc-term|' . absint( $term_id ),
			)
		);
	}
	public function coupon_changed( $post_id, $post, $update ) {
		unset( $post, $update );
		if ( $post_id ) {
			$this->queue->enqueue(
				'cache_invalidate',
				array(
					'dependencies' => array( 'woocommerce', 'woocommerce-shop' ),
					'reason'       => 'woocommerce_coupon_changed',
				),
				array(
					'priority'   => 70,
					'unique_key' => 'wc-coupon|' . absint( $post_id ),
				)
			);
		}
	}

	private function invalidate( $product_id, $reason ) {
		$url = get_permalink( $product_id );
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'dependencies' => array( 'woocommerce', 'woocommerce-shop', 'product-' . absint( $product_id ), 'post-' . absint( $product_id ), 'post-type-product' ),
				'urls'         => array_filter( array( $url ) ),
				'warm_urls'    => array_filter( array( $url ) ),
				'reason'       => $reason,
			),
			array(
				'priority'   => 90,
				'unique_key' => 'wc-product-extra|' . absint( $product_id ),
			)
		);
	}

	private function currency_cookie_names() {
		$defaults = array( 'woocommerce_currency', 'aelia_cs_selected_currency', 'wmc_current_currency', 'woocs_current_currency' );
		$custom   = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_woocommerce_currency_cookies', '' ) );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $defaults, (array) $custom ) ) ) ) );
	}
	private function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		return (string) $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
