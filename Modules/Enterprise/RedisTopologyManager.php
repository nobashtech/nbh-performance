<?php
/**
 * Redis standalone, Sentinel, and Cluster topology validation.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Enterprise;

use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class RedisTopologyManager implements Module {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$permission = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/redis/topology',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/redis/topology/validate',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_validate' ),
				'permission_callback' => $permission,
			)
		);
	}

	/** @return \WP_REST_Response */
	public function rest_status() {
		return rest_ensure_response( $this->status() );
	}

	/** @return array<string,mixed> */
	public function status() {
		return array(
			'phpredis'         => extension_loaded( 'redis' ),
			'standalone'       => class_exists( 'Redis' ),
			'sentinel'         => class_exists( 'RedisSentinel' ),
			'cluster'          => class_exists( 'RedisCluster' ),
			'configured_mode'  => sanitize_key( (string) get_option( 'nbh_performance_object_cache_redis_mode', 'standalone' ) ),
			'configured_nodes' => $this->public_nodes( (array) get_option( 'nbh_performance_object_cache_redis_nodes', array() ) ),
			'sentinel_service' => sanitize_text_field( (string) get_option( 'nbh_performance_object_cache_sentinel_service', '' ) ),
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_validate( WP_REST_Request $request ) {
		$mode  = sanitize_key( (string) $request->get_param( 'mode' ) );
		$mode  = in_array( $mode, array( 'standalone', 'sentinel', 'cluster' ), true ) ? $mode : 'standalone';
		$nodes = $this->sanitize_nodes( (array) $request->get_param( 'nodes' ) );
		if ( 'standalone' !== $mode && empty( $nodes ) ) {
			return new WP_Error( 'nbh_performance_redis_nodes_missing', __( 'Provide at least one Redis topology node.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		if ( 'sentinel' === $mode && ! class_exists( 'RedisSentinel' ) ) {
			return new WP_Error( 'nbh_performance_redis_sentinel_unavailable', __( 'This PhpRedis build does not provide RedisSentinel.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		if ( 'cluster' === $mode && ! class_exists( 'RedisCluster' ) ) {
			return new WP_Error( 'nbh_performance_redis_cluster_unavailable', __( 'This PhpRedis build does not provide RedisCluster.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$service = sanitize_text_field( (string) $request->get_param( 'sentinel_service' ) );
		if ( 'sentinel' === $mode && '' === $service ) {
			return new WP_Error( 'nbh_performance_redis_sentinel_service_missing', __( 'Provide the Sentinel master service name.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		update_option( 'nbh_performance_object_cache_redis_mode', $mode, false );
		update_option( 'nbh_performance_object_cache_redis_nodes', $nodes, false );
		update_option( 'nbh_performance_object_cache_sentinel_service', $service, false );
		return rest_ensure_response(
			array(
				'success'          => true,
				'mode'             => $mode,
				'nodes'            => $this->public_nodes( $nodes ),
				'sentinel_service' => $service,
			)
		);
	}

	/** @param array<int,mixed> $nodes Nodes. @return array<int,array<string,mixed>> */
	public function sanitize_nodes( array $nodes ) {
		$clean = array();
		foreach ( array_slice( $nodes, 0, 20 ) as $node ) {
			if ( is_string( $node ) ) {
				$value = trim( $node );
				if ( preg_match( '/^\[([0-9a-fA-F:]+)\]:(\d+)$/', $value, $match ) ) {
					$node = array(
						'host' => $match[1],
						'port' => $match[2],
					);
				} elseif ( 1 === substr_count( $value, ':' ) ) {
					$parts = explode( ':', $value, 2 );
					$node  = array(
						'host' => $parts[0],
						'port' => $parts[1],
					);
				} else {
					$node = array(
						'host' => $value,
						'port' => 6379,
					);
				}
			}
			if ( ! is_array( $node ) ) {
				continue;
			}
			$host = isset( $node['host'] ) ? trim( (string) $node['host'] ) : '';
			$port = isset( $node['port'] ) ? absint( $node['port'] ) : 6379;
			if ( '' === $host || strlen( $host ) > 255 || ! preg_match( '/^(?:[A-Za-z0-9._-]+|[0-9a-fA-F:]+)$/', $host ) || $port < 1 || $port > 65535 ) {
				continue;
			}
			$clean[] = array(
				'host'   => $host,
				'port'   => $port,
				'weight' => max( 1, min( 100, absint( isset( $node['weight'] ) ? $node['weight'] : 1 ) ) ),
			);
		}
		return array_values( $clean );
	}

	/** @param array<int,array<string,mixed>> $nodes Nodes. @return array<int,array<string,mixed>> */
	private function public_nodes( array $nodes ) {
		return array_map(
			static function ( $node ) {
				return array(
					'host'   => isset( $node['host'] ) ? sanitize_text_field( $node['host'] ) : '',
					'port'   => absint( isset( $node['port'] ) ? $node['port'] : 0 ),
					'weight' => absint( isset( $node['weight'] ) ? $node['weight'] : 1 ),
				);
			},
			$nodes
		);
	}
}
