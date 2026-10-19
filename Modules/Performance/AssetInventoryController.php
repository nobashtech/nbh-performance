<?php
/** Administrator asset inventory API for Script Manager 2.0. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Response;

final class AssetInventoryController implements Module {
	private $graph;
	public function __construct( ?AssetDependencyGraph $graph = null ) {
		$this->graph = $graph ? $graph : new AssetDependencyGraph();
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'capture' ), PHP_INT_MAX - 5 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function capture() {
		if ( is_admin() || ( ! get_option( 'nbh_performance_asset_inventory_enabled', 0 ) && ! get_option( 'nbh_performance_script_manager_enabled', 0 ) ) ) {
			return;
		}
		update_option( 'nbh_performance_asset_inventory_snapshot', $this->inventory(), false );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/assets/inventory',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'response' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public function response( WP_REST_Request $request ) {
		unset( $request );
		$data = get_option( 'nbh_performance_asset_inventory_snapshot', array() );
		return new WP_REST_Response( is_array( $data ) ? $data : array(), 200 );
	}

	private function inventory() {
		return array(
			'captured_at' => time(),
			'url'         => esc_url_raw( $this->current_url() ),
			'scripts'     => $this->items( wp_scripts(), 'script' ),
			'styles'      => $this->items( wp_styles(), 'style' ),
		);
	}

	private function items( $registry, $type ) {
		$items = array();
		foreach ( (array) $registry->queue as $handle ) {
			if ( ! isset( $registry->registered[ $handle ] ) ) {
				continue;
			}
			$item    = $registry->registered[ $handle ];
			$src     = $this->absolute_url( (string) $item->src );
			$meta    = $this->graph->metadata( $registry, $handle );
			$items[] = array(
				'handle'        => sanitize_key( $handle ),
				'type'          => $type,
				'source'        => esc_url_raw( $src ),
				'owner'         => $this->owner( $src ),
				'bytes'         => $this->local_bytes( $src ),
				'dependencies'  => $meta['dependencies'],
				'dependents'    => $meta['dependents'],
				'inline_before' => 'script' === $type ? count( (array) $registry->get_data( $handle, 'before' ) ) : 0,
				'inline_after'  => 'script' === $type ? count( (array) $registry->get_data( $handle, 'after' ) ) : 0,
				'group'         => isset( $item->extra['group'] ) ? absint( $item->extra['group'] ) : 0,
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['owner'] . $a['handle'], $b['owner'] . $b['handle'] );
			}
		);
		return $items;
	}

	private function owner( $src ) {
		if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $src, $match ) ) {
			return 'plugin:' . sanitize_key( $match[1] );
		}
		if ( preg_match( '#/wp-content/themes/([^/]+)/#', $src, $match ) ) {
			return 'theme:' . sanitize_key( $match[1] );
		}
		if ( false !== strpos( $src, '/wp-includes/' ) ) {
			return 'wordpress-core';
		}
		return 'external-or-custom';
	}

	private function absolute_url( $src ) {
		if ( 0 === strpos( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			return home_url( $src );
		}
		return $src;
	}

	private function local_bytes( $src ) {
		$home = trailingslashit( home_url() );
		if ( 0 !== strpos( $src, $home ) ) {
			return 0;
		}
		$relative = strtok( substr( $src, strlen( $home ) ), '?' );
		$path     = wp_normalize_path( ABSPATH . ltrim( $relative, '/' ) );
		if ( 0 !== strpos( $path, wp_normalize_path( ABSPATH ) ) || ! is_file( $path ) ) {
			return 0;
		}
		$size = filesize( $path );
		return false === $size ? 0 : $size;
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/';
		return $scheme . '://' . $host . $uri;
	}
}
