<?php
/**
 * Persistent object cache management, health checks, and fragment cache API.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class ObjectCacheManager implements Module {
	const GROUP          = 'nbh_performance_fragments';
	const DROPIN_MARKER  = 'NBH_PERFORMANCE_OBJECT_CACHE_DROPIN';
	const CONFIG_VERSION = 2;

	/** Register hooks, REST controls, and public fragment-cache integrations. */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
		add_filter( 'nbh_performance_fragment_get', array( $this, 'filter_get' ), 10, 3 );
		add_action( 'nbh_performance_fragment_set', array( $this, 'action_set' ), 10, 4 );
		add_action( 'nbh_performance_fragment_delete', array( $this, 'action_delete' ), 10, 2 );
	}

	/** Register administrator-only object-cache routes. */
	public function routes() {
		$permission = array( $this, 'permission' );
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/object-cache/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/object-cache/test',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_test' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/object-cache/enable',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_enable' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/object-cache/disable',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_disable' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/object-cache/flush',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_flush' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/object-cache/metrics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_metrics' ),
				'permission_callback' => $permission,
			)
		);
	}

	/** @return bool|\WP_Error */
	public function permission( $request = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to manage object cache.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		if ( $request instanceof WP_REST_Request ) {
			return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
		}
		return true;
	}

	/** @return \WP_REST_Response */
	public function rest_status() {
		return rest_ensure_response( $this->status() );
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_test( WP_REST_Request $request ) {
		$config = $this->sanitize_config( $request->get_json_params() );
		$result = $this->test_connection( $config );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'    => true,
				'connection' => $result,
				'config'     => $this->public_config( $config ),
			)
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_enable( WP_REST_Request $request ) {
		$config = $this->sanitize_config( $request->get_json_params() );
		$result = $this->test_connection( $config );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$dropin = $this->dropin_path();
		if ( is_file( $dropin ) && ! $this->is_ours( $dropin ) ) {
			return new WP_Error(
				'nbh_performance_object_cache_conflict',
				__( 'Another plugin or hosting provider already owns object-cache.php. Disable that object cache before enabling NBH Performance Object Cache.', 'nbh-performance' ),
				array( 'status' => 409 )
			);
		}
		if ( ! $this->write_config( $config ) ) {
			return new WP_Error( 'nbh_performance_object_cache_config_write_failed', __( 'NBH Performance could not write the object-cache configuration file.', 'nbh-performance' ), array( 'status' => 500 ) );
		}
		if ( ! $this->install_dropin() ) {
			$this->remove_config();
			return new WP_Error( 'nbh_performance_object_cache_dropin_write_failed', __( 'NBH Performance could not install wp-content/object-cache.php. Check wp-content file permissions.', 'nbh-performance' ), array( 'status' => 500 ) );
		}
		update_option( 'nbh_performance_object_cache_enabled', 1, false );
		update_option( 'nbh_performance_object_cache_provider', $config['provider'], false );
		if ( 'redis' === $config['provider'] ) {
			update_option( 'nbh_performance_object_cache_redis_mode', $config['redis_mode'], false );
			update_option( 'nbh_performance_object_cache_redis_nodes', $config['nodes'], false );
			update_option( 'nbh_performance_object_cache_sentinel_service', $config['sentinel_service'], false );
		}
		update_option(
			'nbh_performance_object_cache_last_test',
			array(
				'success'    => true,
				'tested_at'  => time(),
				'latency_ms' => $result['latency_ms'],
			),
			false
		);
		do_action( 'nbh_performance_object_cache_enabled', $this->public_config( $config ) );
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Object cache enabled. It becomes active on the next WordPress request.', 'nbh-performance' ),
				'status'  => $this->status(),
			)
		);
	}

	/** @return \WP_REST_Response|WP_Error */
	public function rest_disable() {
		$dropin = $this->dropin_path();
		if ( is_file( $dropin ) && ! $this->is_ours( $dropin ) ) {
			return new WP_Error( 'nbh_performance_object_cache_not_owned', __( 'NBH Performance cannot remove object-cache.php because another system owns it.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_file( $dropin ) && ! \NBH\Performance\Modules\Core\Filesystem::unlink( $dropin ) ) {
			return new WP_Error( 'nbh_performance_object_cache_disable_failed', __( 'NBH Performance could not remove wp-content/object-cache.php.', 'nbh-performance' ), array( 'status' => 500 ) );
		}
		$this->remove_config();
		update_option( 'nbh_performance_object_cache_enabled', 0, false );
		delete_option( 'nbh_performance_object_cache_provider' );
		do_action( 'nbh_performance_object_cache_disabled' );
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Object cache disabled. WordPress will use its normal request-level object cache on the next request.', 'nbh-performance' ),
				'status'  => $this->status(),
			)
		);
	}

	/** Return object-cache telemetry and backend health. @return \WP_REST_Response */
	public function rest_metrics() {
		$config = $this->read_config();
		return rest_ensure_response( $this->metrics( $config ) );
	}

	/** @return \WP_REST_Response|WP_Error */
	public function rest_flush() {
		$config = $this->read_config();
		if ( empty( $config ) ) {
			return new WP_Error( 'nbh_performance_object_cache_not_configured', __( 'Object cache is not configured.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$result = $this->invalidate_namespace( $config );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_option( 'nbh_performance_object_cache_last_flush', time(), false );
		do_action( 'nbh_performance_object_cache_flushed', $config['provider'] );
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Persistent object cache flushed safely for this NBH Performance namespace.', 'nbh-performance' ),
				'status'  => $this->status(),
			)
		);
	}

	/** @return array<string,mixed> */
	public function status() {
		$dropin        = $this->dropin_path();
		$dropin_exists = is_file( $dropin );
		$dropin_owned  = $dropin_exists && $this->is_ours( $dropin );
		$config        = $this->read_config();
		$connection    = null;
		if ( $dropin_owned && ! empty( $config ) ) {
			$test       = $this->test_connection( $config );
			$connection = is_wp_error( $test ) ? array(
				'success' => false,
				'message' => $test->get_error_message(),
			) : $test;
		}
		$safe_mode    = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$enabled_flag = (bool) get_option( 'nbh_performance_object_cache_enabled', 0 );
		$last_test    = get_option( 'nbh_performance_object_cache_last_test', array() );
		$last_test    = is_array( $last_test ) ? $last_test : array();
		$ext_object   = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$active       = $ext_object && $dropin_owned && is_array( $connection ) && ! empty( $connection['success'] );
		$extensions   = array(
			'redis'     => extension_loaded( 'redis' ) && class_exists( 'Redis' ),
			'memcached' => extension_loaded( 'memcached' ) && class_exists( 'Memcached' ),
		);
		$provider     = isset( $config['provider'] ) ? (string) $config['provider'] : '';
		$public       = $this->public_config( $config );
		$metrics      = $this->metrics( $config );
		$can_enable   = ! $dropin_exists || $dropin_owned;
		$can_flush    = $dropin_owned && ! empty( $config );
		$can_disable  = $dropin_owned || ( ! $dropin_exists && $enabled_flag );

		return array(
			'active'                    => $active,
			'configured'                => $dropin_owned && ! empty( $config ),
			'enabled_flag'              => $enabled_flag,
			'safe_mode'                 => $safe_mode,
			'runtime_ready'             => $active,
			'wp_using_ext_object_cache' => $ext_object,
			'dropin_exists'             => $dropin_exists,
			'dropin_owned'              => $dropin_owned,
			'dropin_conflict'           => $dropin_exists && ! $dropin_owned,
			'dropin_writable'           => \NBH\Performance\Modules\Core\Filesystem::is_writable( WP_CONTENT_DIR ) || ( $dropin_exists && \NBH\Performance\Modules\Core\Filesystem::is_writable( $dropin ) ),
			'provider'                  => $provider,
			'config'                    => $public,
			'connection'                => $connection,
			'extensions'                => $extensions,
			'recommended_provider'      => ! empty( $extensions['redis'] ) ? 'redis' : ( ! empty( $extensions['memcached'] ) ? 'memcached' : '' ),
			'last_flush'                => absint( get_option( 'nbh_performance_object_cache_last_flush', 0 ) ),
			'last_test'                 => array(
				'success'    => ! empty( $last_test['success'] ),
				'tested_at'  => isset( $last_test['tested_at'] ) ? absint( $last_test['tested_at'] ) : 0,
				'latency_ms' => isset( $last_test['latency_ms'] ) ? (float) $last_test['latency_ms'] : 0,
			),
			'next_request_notice'       => $dropin_owned && ! $ext_object,
			'topology'                  => array(
				'mode'             => isset( $public['redis_mode'] ) ? (string) $public['redis_mode'] : 'standalone',
				'node_count'       => isset( $public['nodes'] ) && is_array( $public['nodes'] ) ? count( $public['nodes'] ) : 0,
				'sentinel_service' => isset( $public['sentinel_service'] ) ? (string) $public['sentinel_service'] : '',
			),
			'capabilities'              => array(
				'bulk_get'        => true,
				'atomic_counters' => true,
				'group_flush'     => true,
				'compression'     => function_exists( 'gzcompress' ),
				'igbinary'        => function_exists( 'igbinary_serialize' ),
				'circuit_breaker' => function_exists( 'apcu_fetch' ),
			),
			'metrics'                   => $metrics,
			'ops_available'             => array(
				'test'    => true,
				'enable'  => $can_enable,
				'flush'   => $can_flush,
				'disable' => $can_disable,
			),
		);
	}

	/** @param array<string,mixed>|null $input Input. @return array<string,mixed> */
	private function sanitize_config( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$provider = isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : 'redis';
		if ( ! in_array( $provider, array( 'redis', 'memcached' ), true ) ) {
			$provider = extension_loaded( 'redis' ) ? 'redis' : 'memcached';
		}
		$default_port = 'redis' === $provider ? 6379 : 11211;
		$host         = isset( $input['host'] ) ? trim( (string) $input['host'] ) : '127.0.0.1';
		$host         = preg_replace( '/[^A-Za-z0-9.\-_:\/\[\]]/', '', $host );
		if ( '' === $host ) {
			$host = '127.0.0.1';
		}
		$port         = isset( $input['port'] ) ? absint( $input['port'] ) : $default_port;
		$port         = max( 0, min( 65535, $port ) );
		$database     = isset( $input['database'] ) ? absint( $input['database'] ) : 0;
		$database     = max( 0, min( 255, $database ) );
		$timeout      = isset( $input['timeout'] ) ? (float) $input['timeout'] : 1.0;
		$timeout      = max( 0.05, min( 5.0, $timeout ) );
		$read_timeout = isset( $input['read_timeout'] ) ? (float) $input['read_timeout'] : 1.0;
		$read_timeout = max( 0.05, min( 10.0, $read_timeout ) );
		$username     = isset( $input['username'] ) ? sanitize_text_field( (string) $input['username'] ) : '';
		$password     = isset( $input['password'] ) ? substr( (string) $input['password'], 0, 512 ) : '';
		if ( '' === $password ) {
			$existing = $this->read_config();
			if ( is_array( $existing ) && isset( $existing['provider'], $existing['password'] ) && $provider === $existing['provider'] ) {
				$password = (string) $existing['password'];
			}
		}
		$prefix = isset( $input['prefix'] ) ? sanitize_key( (string) $input['prefix'] ) : '';
		if ( '' === $prefix ) {
			$host_name = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$prefix    = 'nbh_performance_' . substr( sha1( $host_name . '|' . ABSPATH ), 0, 12 );
		}
		$redis_mode            = isset( $input['redis_mode'] ) ? sanitize_key( (string) $input['redis_mode'] ) : sanitize_key( (string) get_option( 'nbh_performance_object_cache_redis_mode', 'standalone' ) );
		$redis_mode            = in_array( $redis_mode, array( 'standalone', 'sentinel', 'cluster' ), true ) ? $redis_mode : 'standalone';
		$nodes_input           = isset( $input['nodes'] ) ? $input['nodes'] : get_option( 'nbh_performance_object_cache_redis_nodes', array() );
		$nodes                 = $this->sanitize_nodes( is_array( $nodes_input ) ? $nodes_input : preg_split( '/\r\n|\r|\n|,/', (string) $nodes_input ) );
		$sentinel_service      = isset( $input['sentinel_service'] ) ? sanitize_text_field( (string) $input['sentinel_service'] ) : sanitize_text_field( (string) get_option( 'nbh_performance_object_cache_sentinel_service', '' ) );
		$compression_threshold = isset( $input['compression_threshold'] ) ? absint( $input['compression_threshold'] ) : 1024;
		$compression_threshold = max( 256, min( 1048576, $compression_threshold ) );
		$max_object_size       = isset( $input['max_object_size'] ) ? absint( $input['max_object_size'] ) : 1048576;
		$max_object_size       = max( 65536, min( 16777216, $max_object_size ) );
		$failure_threshold     = isset( $input['failure_threshold'] ) ? absint( $input['failure_threshold'] ) : 3;
		$failure_threshold     = max( 1, min( 20, $failure_threshold ) );
		$retry_interval        = isset( $input['retry_interval'] ) ? absint( $input['retry_interval'] ) : 30;
		$retry_interval        = max( 5, min( 600, $retry_interval ) );
		return array(
			'config_version'        => self::CONFIG_VERSION,
			'provider'              => $provider,
			'host'                  => $host,
			'port'                  => $port,
			'database'              => 'redis' === $provider ? $database : 0,
			'username'              => $username,
			'password'              => $password,
			'timeout'               => $timeout,
			'read_timeout'          => $read_timeout,
			'persistent'            => ! isset( $input['persistent'] ) || rest_sanitize_boolean( $input['persistent'] ),
			'tls'                   => 'redis' === $provider && ! empty( $input['tls'] ) && rest_sanitize_boolean( $input['tls'] ),
			'prefix'                => $prefix,
			'redis_mode'            => 'redis' === $provider ? $redis_mode : 'standalone',
			'nodes'                 => 'redis' === $provider ? $nodes : array(),
			'sentinel_service'      => 'redis' === $provider ? $sentinel_service : '',
			'compression'           => ! isset( $input['compression'] ) || rest_sanitize_boolean( $input['compression'] ),
			'compression_threshold' => $compression_threshold,
			'igbinary'              => ! isset( $input['igbinary'] ) || rest_sanitize_boolean( $input['igbinary'] ),
			'max_object_size'       => $max_object_size,
			'failure_threshold'     => $failure_threshold,
			'retry_interval'        => $retry_interval,
		);
	}

	/** @param array<string,mixed> $config Config. @return array<string,mixed>|WP_Error */
	private function test_connection( array $config ) {
		$provider = $config['provider'];
		if ( 'redis' === $provider && ! ( extension_loaded( 'redis' ) && class_exists( 'Redis' ) ) ) {
			return new WP_Error( 'nbh_performance_redis_extension_missing', __( 'The PhpRedis extension is not installed or enabled on this server.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		if ( 'memcached' === $provider && ! ( extension_loaded( 'memcached' ) && class_exists( 'Memcached' ) ) ) {
			return new WP_Error( 'nbh_performance_memcached_extension_missing', __( 'The PECL memcached extension is not installed or enabled on this server.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$started = microtime( true );
		try {
			if ( 'redis' === $provider ) {
				$client = $this->connect_redis_client( $config );
				$mode   = isset( $config['redis_mode'] ) ? $config['redis_mode'] : 'standalone';
				if ( 'cluster' === $mode ) {
					$probe_key = $config['prefix'] . ':health:' . substr( sha1( (string) microtime( true ) ), 0, 12 );
					$client->set( $probe_key, 'ok', 5 );
					if ( 'ok' !== $client->get( $probe_key ) ) {
						throw new \RuntimeException( esc_html__( 'Redis Cluster health probe failed.', 'nbh-performance' ) );
					}
					$client->del( $probe_key );
				} else {
					$client->ping();
				}
				$version = 'cluster' === $mode ? 'Redis Cluster' : ( 'sentinel' === $mode ? 'Redis Sentinel' : '' );
				if ( 'standalone' === $mode && method_exists( $client, 'info' ) ) {
					$version_info = $client->info( 'server' );
					$version      = is_array( $version_info ) && isset( $version_info['redis_version'] ) ? (string) $version_info['redis_version'] : '';
				}
				if ( method_exists( $client, 'close' ) ) {
					$client->close();
				}
			} else {
				$client = new \Memcached();
				$client->setOption( \Memcached::OPT_BINARY_PROTOCOL, true );
				$client->setOption( \Memcached::OPT_CONNECT_TIMEOUT, max( 50, (int) round( $config['timeout'] * 1000 ) ) );
				if ( '' !== $config['username'] && '' !== $config['password'] && method_exists( $client, 'setSaslAuthData' ) ) {
					$client->setSaslAuthData( $config['username'], $config['password'] );
				}
				if ( ! $client->addServer( $config['host'], (int) $config['port'] ) ) {
					throw new \RuntimeException( esc_html__( 'Memcached server configuration failed.', 'nbh-performance' ) );
				}
				$versions = $client->getVersion();
				if ( ! is_array( $versions ) || empty( array_filter( $versions ) ) ) {
					throw new \RuntimeException( esc_html__( 'Memcached did not respond.', 'nbh-performance' ) );
				}
				$version = (string) reset( $versions );
			}
		} catch ( \Throwable $error ) {
			/* translators: %s: connection error message. */
			return new WP_Error( 'nbh_performance_object_cache_connection_failed', sprintf( __( 'Object-cache connection failed: %s', 'nbh-performance' ), $error->getMessage() ), array( 'status' => 400 ) );
		}
		return array(
			'success'    => true,
			'provider'   => $provider,
			'version'    => $version,
			'latency_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
			/* translators: %s: object-cache provider name. */
			'message'    => sprintf( __( '%s connection verified.', 'nbh-performance' ), ucfirst( $provider ) ),
		);
	}

	/** @param array<string,mixed> $config Config. @return true|WP_Error */
	private function invalidate_namespace( array $config ) {
		$test = $this->test_connection( $config );
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		$key = $config['prefix'] . ':meta:namespace';
		try {
			if ( 'redis' === $config['provider'] ) {
				$client  = $this->connect_redis_client( $config );
				$current = (int) $client->get( $key );
				$client->set( $key, (string) max( 2, $current + 1 ) );
				if ( method_exists( $client, 'close' ) ) {
					$client->close();
				}
			} else {
				$client = new \Memcached();
				$client->setOption( \Memcached::OPT_BINARY_PROTOCOL, true );
				if ( '' !== $config['username'] && '' !== $config['password'] && method_exists( $client, 'setSaslAuthData' ) ) {
					$client->setSaslAuthData( $config['username'], $config['password'] );
				}
				$client->addServer( $config['host'], (int) $config['port'] );
				$current = (int) $client->get( $key );
				$client->set( $key, (string) max( 2, $current + 1 ) );
			}
		} catch ( \Throwable $error ) {
			/* translators: %s: connection error message. */
			return new WP_Error( 'nbh_performance_object_cache_flush_failed', sprintf( __( 'Object-cache flush failed: %s', 'nbh-performance' ), $error->getMessage() ), array( 'status' => 500 ) );
		}
		return true;
	}

	/** @param array<int,mixed> $nodes Nodes. @return array<int,array<string,mixed>> */
	private function sanitize_nodes( array $nodes ) {
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

	/** @param array<string,mixed> $config Config. @return \Redis|\RedisCluster */
	private function connect_redis_client( array $config ) {
		$mode         = isset( $config['redis_mode'] ) ? sanitize_key( $config['redis_mode'] ) : 'standalone';
		$timeout      = (float) $config['timeout'];
		$read_timeout = (float) $config['read_timeout'];
		$username     = (string) $config['username'];
		$password     = (string) $config['password'];
		$auth         = '' !== $password ? ( '' !== $username ? array( $username, $password ) : $password ) : null;
		$nodes        = isset( $config['nodes'] ) ? $this->sanitize_nodes( (array) $config['nodes'] ) : array();

		if ( 'cluster' === $mode ) {
			if ( ! class_exists( 'RedisCluster' ) ) {
				throw new \RuntimeException( esc_html__( 'RedisCluster is unavailable in this PhpRedis build.', 'nbh-performance' ) );
			}
			$seeds = array();
			foreach ( $nodes as $node ) {
				$seeds[] = ( ! empty( $config['tls'] ) ? 'tls://' : '' ) . $node['host'] . ':' . $node['port'];
			}
			if ( empty( $seeds ) ) {
				$seeds[] = ( ! empty( $config['tls'] ) ? 'tls://' : '' ) . $config['host'] . ':' . $config['port'];
			}
			return new \RedisCluster( null, $seeds, $timeout, $read_timeout, ! empty( $config['persistent'] ), $auth );
		}

		$host = (string) $config['host'];
		$port = (int) $config['port'];
		if ( 'sentinel' === $mode ) {
			if ( ! class_exists( 'RedisSentinel' ) ) {
				throw new \RuntimeException( esc_html__( 'RedisSentinel is unavailable in this PhpRedis build.', 'nbh-performance' ) );
			}
			$sentinel_nodes = ! empty( $nodes ) ? $nodes : array(
				array(
					'host' => $host,
					'port' => $port,
				),
			);
			$master         = false;
			foreach ( $sentinel_nodes as $node ) {
				try {
					$sentinel  = new \RedisSentinel(
						array(
							'host'           => $node['host'],
							'port'           => $node['port'],
							'connectTimeout' => $timeout,
							'readTimeout'    => $read_timeout,
							'persistent'     => ! empty( $config['persistent'] ),
						)
					);
					$candidate = $sentinel->getMasterAddrByName( (string) $config['sentinel_service'] );
					if ( is_array( $candidate ) && count( $candidate ) >= 2 ) {
						$master = $candidate;
						break;
					}
				} catch ( \Throwable $sentinel_error ) {
					continue;
				}
			}
			if ( ! is_array( $master ) || count( $master ) < 2 ) {
				throw new \RuntimeException( esc_html__( 'Redis Sentinel could not resolve the configured master from any seed node.', 'nbh-performance' ) );
			}
			$host = (string) $master[0];
			$port = (int) $master[1];
		}
		if ( ! empty( $config['tls'] ) && 0 !== strpos( $host, '/' ) && 0 !== strpos( $host, 'tls://' ) ) {
			$host = 'tls://' . $host;
		}
		$client = new \Redis();
		$ok     = ! empty( $config['persistent'] ) ? $client->pconnect( $host, $port, $timeout, 'nbh-performance:' . sha1( $host . ':' . $port . ':' . $config['prefix'] ), 0, $read_timeout ) : $client->connect( $host, $port, $timeout, null, 0, $read_timeout );
		if ( ! $ok ) {
			throw new \RuntimeException( esc_html__( 'Redis refused the connection.', 'nbh-performance' ) );
		}
		if ( null !== $auth && ! $client->auth( $auth ) ) {
			throw new \RuntimeException( esc_html__( 'Redis authentication failed.', 'nbh-performance' ) );
		}
		if ( (int) $config['database'] > 0 && ! $client->select( (int) $config['database'] ) ) {
			throw new \RuntimeException( esc_html__( 'Redis database selection failed.', 'nbh-performance' ) );
		}
		return $client;
	}

	/** @return string */
	private function dropin_path() {
		return WP_CONTENT_DIR . '/object-cache.php';
	}
	/** @return string */
	private function config_path() {
		return WP_CONTENT_DIR . '/nbh-performance-object-cache-config.php';
	}

	/** @param string $path Path. @return bool */
	private function is_ours( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head = file_get_contents( $path, false, null, 0, 4096 );
		return false !== $head && false !== strpos( $head, self::DROPIN_MARKER );
	}

	/** @return bool */
	private function install_dropin() {
		$source = NBH_PERFORMANCE_PATH . 'dropins/object-cache.php';
		if ( ! is_readable( $source ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = \NBH\Performance\Modules\Core\Filesystem::get_contents( $source );
		return false !== $content && $this->atomic_write( $this->dropin_path(), $content, 0644 );
	}

	/** @param array<string,mixed> $config Config. @return bool */
	private function write_config( array $config ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export generates a private PHP configuration array, not debugging output; the file uses restricted permissions and an owned target.
		$content = "<?php\n/** NBH Performance object cache connection settings. */\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $config, true ) . ";\n";
		return $this->atomic_write( $this->config_path(), $content, 0600 );
	}

	/** @return array<string,mixed> */
	private function read_config() {
		$file = $this->config_path();
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$config = include $file;
		return is_array( $config ) ? $config : array();
	}

	/** @return void */
	private function remove_config() {
		$file = $this->config_path();
		if ( is_file( $file ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/** @param string $path Path. @param string $content Content. @param int $mode Mode. @return bool */
	private function atomic_write( $path, $content, $mode ) {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $directory ) ) {
			return false;
		}
		$temp = $path . '.nbh-performance-' . wp_generate_password( 8, false, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = \NBH\Performance\Modules\Core\Filesystem::put_contents( $temp, $content, $mode );
		if ( ! $written ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, $mode );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. Atomic same-filesystem rename is required for publication; WP_Filesystem::move() can unlink/copy and is not an atomic commit. Failure is checked before accepting the new artifact.
		if ( ! @rename( $temp, $path ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $path, $mode );
		return true;
	}

	/** @param array<string,mixed> $config Config. @return array<string,mixed> */
	private function public_config( array $config ) {
		if ( empty( $config ) ) {
			return array(
				'provider'              => 'redis',
				'host'                  => '127.0.0.1',
				'port'                  => 6379,
				'database'              => 0,
				'username'              => '',
				'password_set'          => false,
				'timeout'               => 1,
				'read_timeout'          => 1,
				'persistent'            => true,
				'tls'                   => false,
				'prefix'                => '',
				'redis_mode'            => 'standalone',
				'nodes'                 => array(),
				'sentinel_service'      => '',
				'compression'           => true,
				'compression_threshold' => 1024,
				'igbinary'              => true,
				'max_object_size'       => 1048576,
				'failure_threshold'     => 3,
				'retry_interval'        => 30,
			);
		}
		return array(
			'provider'              => isset( $config['provider'] ) ? $config['provider'] : 'redis',
			'host'                  => isset( $config['host'] ) ? $config['host'] : '127.0.0.1',
			'port'                  => isset( $config['port'] ) ? (int) $config['port'] : 6379,
			'database'              => isset( $config['database'] ) ? (int) $config['database'] : 0,
			'username'              => isset( $config['username'] ) ? $config['username'] : '',
			'password_set'          => ! empty( $config['password'] ),
			'timeout'               => isset( $config['timeout'] ) ? (float) $config['timeout'] : 1,
			'read_timeout'          => isset( $config['read_timeout'] ) ? (float) $config['read_timeout'] : 1,
			'persistent'            => ! empty( $config['persistent'] ),
			'tls'                   => ! empty( $config['tls'] ),
			'prefix'                => isset( $config['prefix'] ) ? $config['prefix'] : '',
			'redis_mode'            => isset( $config['redis_mode'] ) ? $config['redis_mode'] : 'standalone',
			'nodes'                 => isset( $config['nodes'] ) ? array_map(
				static function ( $node ) {
					return array(
						'host' => isset( $node['host'] ) ? $node['host'] : '',
						'port' => isset( $node['port'] ) ? (int) $node['port'] : 0,
					);
				},
				(array) $config['nodes']
			) : array(),
			'sentinel_service'      => isset( $config['sentinel_service'] ) ? $config['sentinel_service'] : '',
			'compression'           => ! empty( $config['compression'] ),
			'compression_threshold' => isset( $config['compression_threshold'] ) ? (int) $config['compression_threshold'] : 1024,
			'igbinary'              => ! empty( $config['igbinary'] ),
			'max_object_size'       => isset( $config['max_object_size'] ) ? (int) $config['max_object_size'] : 1048576,
			'failure_threshold'     => isset( $config['failure_threshold'] ) ? (int) $config['failure_threshold'] : 3,
			'retry_interval'        => isset( $config['retry_interval'] ) ? (int) $config['retry_interval'] : 30,
		);
	}

	/** Read telemetry without exposing credentials. @param array<string,mixed> $config Config. @return array<string,mixed> */
	private function metrics( array $config ) {
		if ( empty( $config ) ) {
			return array( 'available' => false );
		}
		$key    = $config['prefix'] . ':meta:telemetry';
		$raw    = false;
		$server = array();
		try {
			if ( 'redis' === $config['provider'] ) {
				$client = $this->connect_redis_client( $config );
				$raw    = $client->get( $key );
				if ( method_exists( $client, 'info' ) && 'standalone' === $config['redis_mode'] ) {
					$info   = $client->info();
					$server = array(
						'memory_used'       => isset( $info['used_memory'] ) ? (int) $info['used_memory'] : 0,
						'memory_peak'       => isset( $info['used_memory_peak'] ) ? (int) $info['used_memory_peak'] : 0,
						'evicted_keys'      => isset( $info['evicted_keys'] ) ? (int) $info['evicted_keys'] : 0,
						'connected_clients' => isset( $info['connected_clients'] ) ? (int) $info['connected_clients'] : 0,
					);
				}
				if ( method_exists( $client, 'close' ) ) {
					$client->close();
				}
			} else {
				$client = new \Memcached();
				$client->setOption( \Memcached::OPT_BINARY_PROTOCOL, true );
				$client->addServer( $config['host'], (int) $config['port'] );
				$raw    = $client->get( $key );
				$stats  = $client->getStats();
				$first  = is_array( $stats ) ? reset( $stats ) : array();
				$server = is_array( $first ) ? array(
					'bytes'            => isset( $first['bytes'] ) ? (int) $first['bytes'] : 0,
					'limit_maxbytes'   => isset( $first['limit_maxbytes'] ) ? (int) $first['limit_maxbytes'] : 0,
					'evictions'        => isset( $first['evictions'] ) ? (int) $first['evictions'] : 0,
					'curr_connections' => isset( $first['curr_connections'] ) ? (int) $first['curr_connections'] : 0,
				) : array();
			}
		} catch ( \Throwable $error ) {
			return array(
				'available' => false,
				'message'   => $error->getMessage(),
			);
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$telemetry = is_string( $raw ) ? json_decode( $raw, true ) : array();
		return array(
			'available'   => true,
			'request'     => is_array( $telemetry ) ? $telemetry : array(),
			'server'      => $server,
			'observed_at' => time(),
		);
	}

	/** @param array $tests Tests. @return array */
	public function register_site_health_test( $tests ) {
		$tests['direct']['nbh_performance_object_cache'] = array(
			'label' => __( 'NBH Performance persistent object cache', 'nbh-performance' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	/** @return array<string,mixed> */
	public function site_health_test() {
		$status = $this->status();
		if ( $status['dropin_conflict'] ) {
			$label       = __( 'Another persistent object-cache drop-in is installed', 'nbh-performance' );
			$state       = 'recommended';
			$description = __( 'NBH Performance will not overwrite an object-cache.php file owned by another plugin or your hosting provider.', 'nbh-performance' );
		} elseif ( $status['active'] ) {
			/* translators: %s: object-cache provider name. */
			$label       = sprintf( __( '%s object cache is active', 'nbh-performance' ), ucfirst( $status['provider'] ) );
			$state       = 'good';
			$description = __( 'Persistent object caching is reducing repeated database work.', 'nbh-performance' );
		} else {
			$label       = __( 'No persistent object cache is active', 'nbh-performance' );
			$state       = 'recommended';
			$description = __( 'Redis or Memcached can reduce repeated database work on dynamic WordPress sites.', 'nbh-performance' );
		}
		return array(
			'label'       => $label,
			'status'      => $state,
			'badge'       => array(
				'label' => 'NBH Performance',
				'color' => 'blue',
			),
			'description' => sprintf( '<p>%s</p>', esc_html( $description ) ),
			'actions'     => '',
			'test'        => 'nbh_performance_object_cache',
		);
	}

	/** @param mixed $default_value Default. @param string $key Key. @param string $cache_namespace Namespace. @return mixed */
	public function filter_get( $default_value, $key, $cache_namespace = 'default' ) {
		$found = false;
		$value = wp_cache_get( $this->key( $key, $cache_namespace ), self::GROUP, false, $found );
		return $found ? $value : $default_value;
	}

	/** @param string $key Key. @param mixed $value Value. @param int $ttl TTL. @param string $cache_namespace Namespace. */
	public function action_set( $key, $value, $ttl = 300, $cache_namespace = 'default' ) {
		wp_cache_set( $this->key( $key, $cache_namespace ), $value, self::GROUP, max( 1, absint( $ttl ) ) );
	}
	/** @param string $key Key. @param string $cache_namespace Namespace. */
	public function action_delete( $key, $cache_namespace = 'default' ) {
		wp_cache_delete( $this->key( $key, $cache_namespace ), self::GROUP );
	}
	/** @param string $key Key. @param string $cache_namespace Namespace. @return string */
	private function key( $key, $cache_namespace ) {
		return hash( 'sha256', sanitize_key( $cache_namespace ) . '|' . (string) $key . '|' . get_current_blog_id() );
	}
}
