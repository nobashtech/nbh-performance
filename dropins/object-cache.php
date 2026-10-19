<?php
/**
 * NBH Performance enterprise persistent object-cache drop-in.
 *
 * Self-contained because WordPress loads object-cache.php before plugins.
 * Supports Redis standalone/Sentinel/Cluster and Memcached with resilient
 * fallback, circuit breaking, compression, igbinary, bulk operations,
 * atomic counters, multisite isolation, group flushing, and telemetry.
 *
 * @package NBHPerformance
 */

// NBH_PERFORMANCE_OBJECT_CACHE_DROPIN

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NBH_Performance_Object_Cache', false ) ) {
	final class NBH_Performance_Object_Cache {
		private $cache                 = array();
		private $global_groups         = array();
		private $non_persistent_groups = array();
		private $client                = null;
		private $provider              = '';
		private $config                = array();
		private $connected             = false;
		private $blog_id               = 1;
		private $namespace_version     = null;
		private $group_versions        = array();
		private $errors                = 0;
		private $bytes_read            = 0;
		private $bytes_written         = 0;
		private $backend_time_ms       = 0.0;
		public $cache_hits             = 0;
		public $cache_misses           = 0;

		public function __construct() {
			$this->blog_id               = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
			$this->global_groups         = array_fill_keys( array( 'blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts', 'networks', 'rss', 'site-details', 'site-lookup', 'site-options', 'site-transient', 'sites', 'useremail', 'userlogins', 'usermeta', 'users', 'blog_meta' ), true );
			$this->non_persistent_groups = array_fill_keys( array( 'counts', 'plugins', 'theme_json' ), true );
			$this->load_config();
			$this->connect();
		}

		public function __destruct() {
			$this->persist_telemetry();
		}

		private function load_config() {
			$file           = WP_CONTENT_DIR . '/nbh-performance-object-cache-config.php';
			$config         = is_readable( $file ) ? include $file : array();
			$this->config   = is_array( $config ) ? $config : array();
			$this->provider = isset( $this->config['provider'] ) ? (string) $this->config['provider'] : '';
		}

		private function connect() {
			if ( $this->breaker_open() ) {
				return;
			}
			if ( 'redis' === $this->provider && ( class_exists( 'Redis', false ) || class_exists( 'RedisCluster', false ) ) ) {
				$this->connect_redis();
				return;
			}
			if ( 'memcached' === $this->provider && class_exists( 'Memcached', false ) ) {
				$this->connect_memcached();
			}
		}

		private function connect_redis() {
			try {
				$mode         = isset( $this->config['redis_mode'] ) ? (string) $this->config['redis_mode'] : 'standalone';
				$host         = isset( $this->config['host'] ) ? (string) $this->config['host'] : '127.0.0.1';
				$port         = isset( $this->config['port'] ) ? (int) $this->config['port'] : 6379;
				$timeout      = $this->timeout();
				$read_timeout = isset( $this->config['read_timeout'] ) ? max( 0.05, (float) $this->config['read_timeout'] ) : 1.0;
				$persistent   = ! empty( $this->config['persistent'] );
				$tls          = ! empty( $this->config['tls'] );
				$username     = isset( $this->config['username'] ) ? (string) $this->config['username'] : '';
				$password     = isset( $this->config['password'] ) ? (string) $this->config['password'] : '';
				$auth         = '' !== $password ? ( '' !== $username ? array( $username, $password ) : $password ) : null;
				$nodes        = isset( $this->config['nodes'] ) && is_array( $this->config['nodes'] ) ? $this->config['nodes'] : array();

				if ( 'cluster' === $mode ) {
					if ( ! class_exists( 'RedisCluster', false ) ) {
						return;
					}
					$seeds = array();
					foreach ( $nodes as $node ) {
						if ( is_array( $node ) && ! empty( $node['host'] ) ) {
							$seeds[] = ( $tls ? 'tls://' : '' ) . $node['host'] . ':' . ( isset( $node['port'] ) ? (int) $node['port'] : 6379 );
						}
					}
					if ( empty( $seeds ) ) {
						$seeds[] = ( $tls ? 'tls://' : '' ) . $host . ':' . $port;
					}
					$this->client = new RedisCluster( null, $seeds, $timeout, $read_timeout, $persistent, $auth );
					$this->client->ping( $seeds[0] );
					$this->connected = true;
					$this->breaker_success();
					return;
				}

				if ( 'sentinel' === $mode ) {
					if ( ! class_exists( 'RedisSentinel', false ) ) {
						return;
					}
					$master    = false;
					$sentinels = ! empty( $nodes ) ? $nodes : array(
						array(
							'host' => $host,
							'port' => $port,
						),
					);
					foreach ( $sentinels as $node ) {
						try {
							$s         = new RedisSentinel(
								array(
									'host'           => (string) $node['host'],
									'port'           => isset( $node['port'] ) ? (int) $node['port'] : 26379,
									'connectTimeout' => $timeout,
									'readTimeout'    => $read_timeout,
									'persistent'     => $persistent,
								)
							);
							$candidate = $s->getMasterAddrByName( isset( $this->config['sentinel_service'] ) ? (string) $this->config['sentinel_service'] : '' );
							if ( is_array( $candidate ) && count( $candidate ) >= 2 ) {
								$master = $candidate;
								break;
							}
						} catch ( Throwable $e ) {
							continue;
						}
					}
					if ( ! $master ) {
						throw new RuntimeException( 'Redis Sentinel master unavailable.' );
					}
					$host = (string) $master[0];
					$port = (int) $master[1];
				}

				if ( $tls && 0 !== strpos( $host, '/' ) && 0 !== strpos( $host, 'tls://' ) ) {
					$host = 'tls://' . $host;
				}
				$r  = new Redis();
				$id = 'nbh-performance:' . sha1( $host . ':' . $port . ':' . $this->prefix() );
				$ok = $persistent ? $r->pconnect( $host, $port, $timeout, $id, 0, $read_timeout ) : $r->connect( $host, $port, $timeout, null, 0, $read_timeout );
				if ( ! $ok ) {
					throw new RuntimeException( 'Redis connection refused.' );
				}
				if ( null !== $auth && ! $r->auth( $auth ) ) {
					throw new RuntimeException( 'Redis authentication failed.' );
				}
				$db = isset( $this->config['database'] ) ? (int) $this->config['database'] : 0;
				if ( $db > 0 && ! $r->select( $db ) ) {
					throw new RuntimeException( 'Redis database selection failed.' );
				}
				if ( defined( 'Redis::OPT_READ_TIMEOUT' ) ) {
					$r->setOption( Redis::OPT_READ_TIMEOUT, $read_timeout );
				}
				if ( defined( 'Redis::OPT_TCP_KEEPALIVE' ) ) {
					$r->setOption( Redis::OPT_TCP_KEEPALIVE, 1 );
				}
				$r->ping();
				$this->client    = $r;
				$this->connected = true;
				$this->breaker_success();
			} catch ( Throwable $e ) {
				$this->backend_failure();
			}
		}

		private function connect_memcached() {
			try {
				$host = isset( $this->config['host'] ) ? (string) $this->config['host'] : '127.0.0.1';
				$port = isset( $this->config['port'] ) ? (int) $this->config['port'] : 11211;
				$id   = ! empty( $this->config['persistent'] ) ? 'nbh-performance:' . sha1( $host . ':' . $port . ':' . $this->prefix() ) : '';
				$m    = '' !== $id ? new Memcached( $id ) : new Memcached();
				$m->setOption( Memcached::OPT_BINARY_PROTOCOL, true );
				$m->setOption( Memcached::OPT_CONNECT_TIMEOUT, max( 50, (int) round( $this->timeout() * 1000 ) ) );
				$m->setOption( Memcached::OPT_RETRY_TIMEOUT, 1 );
				$m->setOption( Memcached::OPT_TCP_NODELAY, true );
				if ( defined( 'Memcached::OPT_SERVER_FAILURE_LIMIT' ) ) {
					$m->setOption( Memcached::OPT_SERVER_FAILURE_LIMIT, $this->failure_threshold() );
				}
				if ( empty( $m->getServerList() ) && ! $m->addServer( $host, $port ) ) {
					throw new RuntimeException( 'Memcached server configuration failed.' );
				}
				$user = isset( $this->config['username'] ) ? (string) $this->config['username'] : '';
				$pass = isset( $this->config['password'] ) ? (string) $this->config['password'] : '';
				if ( '' !== $user && '' !== $pass && method_exists( $m, 'setSaslAuthData' ) ) {
					$m->setSaslAuthData( $user, $pass );
				}
				$v = $m->getVersion();
				if ( ! is_array( $v ) || empty( array_filter( $v ) ) ) {
					throw new RuntimeException( 'Memcached did not respond.' );
				}
				$this->client    = $m;
				$this->connected = true;
				$this->breaker_success();
			} catch ( Throwable $e ) {
				$this->backend_failure();
			}
		}

		private function timeout() {
			return isset( $this->config['timeout'] ) ? max( 0.05, (float) $this->config['timeout'] ) : 1.0;
		}
		private function failure_threshold() {
			return isset( $this->config['failure_threshold'] ) ? max( 1, (int) $this->config['failure_threshold'] ) : 3;
		}
		private function retry_interval() {
			return isset( $this->config['retry_interval'] ) ? max( 5, (int) $this->config['retry_interval'] ) : 30;
		}
		private function breaker_key() {
			return 'nbh_oc_breaker_' . sha1( $this->prefix() );
		}
		private function breaker_open() {
			if ( ! function_exists( 'apcu_fetch' ) ) {
				return false;
			}
			$state = apcu_fetch( $this->breaker_key() );
			return is_array( $state ) && ! empty( $state['open_until'] ) && time() < (int) $state['open_until'];
		}
		private function breaker_success() {
			if ( function_exists( 'apcu_delete' ) ) {
				apcu_delete( $this->breaker_key() );
			}
		}
		private function backend_failure() {
			$this->connected = false;
			$this->client    = null;
			++$this->errors;
			if ( function_exists( 'apcu_fetch' ) && function_exists( 'apcu_store' ) ) {
				$state    = apcu_fetch( $this->breaker_key() );
				$failures = is_array( $state ) ? (int) $state['failures'] + 1 : 1;
				apcu_store(
					$this->breaker_key(),
					array(
						'failures'   => $failures,
						'open_until' => $failures >= $this->failure_threshold() ? time() + $this->retry_interval() : 0,
					),
					$this->retry_interval() * 2
				);
			}
		}
		private function prefix() {
			$p = isset( $this->config['prefix'] ) ? (string) $this->config['prefix'] : 'nbh-performance';
			return preg_replace( '/[^A-Za-z0-9_.:-]/', '_', $p );
		}
		private function is_global_group( $g ) {
			return isset( $this->global_groups[ $g ] );
		}
		private function is_non_persistent_group( $g ) {
			return ! $this->connected || isset( $this->non_persistent_groups[ $g ] );
		}
		private function normalize_group( $g ) {
			return '' === (string) $g ? 'default' : (string) $g;
		}

		private function timed( $callback ) {
			$s = microtime( true );
			try {
				return $callback();
			} finally {
				$this->backend_time_ms += ( microtime( true ) - $s ) * 1000;
			}
		}
		private function namespace_version() {
			if ( null !== $this->namespace_version ) {
				return $this->namespace_version;
			}
			$k = $this->prefix() . ':meta:namespace';
			$v = $this->backend_get_raw( $k );
			if ( ! is_numeric( $v ) || (int) $v < 1 ) {
				$v = 1;
				$this->backend_set_raw( $k, '1', 0 );
			}
			$this->namespace_version = (int) $v;
			return $this->namespace_version;
		}
		private function group_version( $g ) {
			if ( isset( $this->group_versions[ $g ] ) ) {
				return $this->group_versions[ $g ];
			}
			$k = $this->prefix() . ':meta:group:' . sha1( $g );
			$v = $this->backend_get_raw( $k );
			if ( ! is_numeric( $v ) || (int) $v < 1 ) {
				$v = 1;
				$this->backend_set_raw( $k, '1', 0 );
			}
			$this->group_versions[ $g ] = (int) $v;
			return $this->group_versions[ $g ];
		}
		private function cache_key( $key, $group ) {
			$group = $this->normalize_group( $group );
			$scope = $this->is_global_group( $group ) ? 'global' : 'blog:' . $this->blog_id;
			return $this->prefix() . ':data:' . sha1( $this->namespace_version() . '|' . $this->group_version( $group ) . '|' . $scope . '|' . $group . '|' . (string) $key );
		}
		private function local_key( $key, $group ) {
			$group = $this->normalize_group( $group );
			return ( $this->is_global_group( $group ) ? 'global' : 'blog:' . $this->blog_id ) . '|' . $group . '|' . (string) $key;
		}

		private function prepare_value( $value ) {
			if ( is_int( $value ) ) {
				$payload = 'I1:' . $value;
			} elseif ( function_exists( 'igbinary_serialize' ) && ! empty( $this->config['igbinary'] ) ) {
				$payload = 'IG1:' . igbinary_serialize( $value );
			} else {
				if ( is_object( $value ) ) {
					$value = clone $value;
				}
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The WordPress object-cache contract must round-trip PHP objects and arrays from the configured private backend; JSON would change those values. No HTTP/request payload is unserialized.
				$payload = 'SC1:' . serialize( $value );
			}
				$threshold = isset( $this->config['compression_threshold'] ) ? max( 0, (int) $this->config['compression_threshold'] ) : 1024;
			if ( ! empty( $this->config['compression'] ) && $threshold > 0 && strlen( $payload ) >= $threshold && function_exists( 'gzcompress' ) ) {
				$z = gzcompress( $payload, 3 );
				if ( false !== $z && strlen( $z ) < strlen( $payload ) ) {
							$payload = 'GZ1:' . $z;
				}
			}
				$max = isset( $this->config['max_object_size'] ) ? max( 1024, (int) $this->config['max_object_size'] ) : 1048576;
				return strlen( $payload ) > $max ? false : $payload;
		}
		private function restore_value( $payload, &$ok ) {
			$ok = false;
			if ( ! is_string( $payload ) ) {
				return false;
			}
			if ( 0 === strpos( $payload, 'GZ1:' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A corrupt private cache value is treated as a miss; decoding warnings must not corrupt the page response.
				$payload = function_exists( 'gzuncompress' ) ? @gzuncompress( substr( $payload, 4 ) ) : false;
				if ( ! is_string( $payload ) ) {
					return false;
				}
			}
			if ( 0 === strpos( $payload, 'I1:' ) ) {
				$ok = true;
				return (int) substr( $payload, 3 );
			}
			if ( 0 === strpos( $payload, 'IG1:' ) && function_exists( 'igbinary_unserialize' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A corrupt private cache value is treated as a miss; decoding warnings must not corrupt the page response.
				$v  = @igbinary_unserialize( substr( $payload, 4 ) );
				$ok = true;
				return is_object( $v ) ? clone $v : $v;
			}
			if ( 0 !== strpos( $payload, 'SC1:' ) ) {
				return false;
			}
			$s = substr( $payload, 4 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- A corrupt private cache value is treated as a miss; decoding warnings must not corrupt the page response. The WordPress object-cache contract must round-trip PHP objects and arrays from the configured private backend; JSON would change those values. No HTTP/request payload is unserialized.
			$v = @unserialize( $s );
			if ( false === $v && 'b:0;' !== $s ) {
				return false;
			}
			$ok = true;
			return is_object( $v ) ? clone $v : $v;
		}

		private function backend_get_raw( $key ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				$v = $this->timed(
					function () use ( $key ) {
						if ( 'redis' === $this->provider ) {
							return $this->client->get( $key );
						}
						$v = $this->client->get( $key );
						return Memcached::RES_SUCCESS === $this->client->getResultCode() ? $v : false;
					}
				);
				if ( is_string( $v ) ) {
					$this->bytes_read += strlen( $v );
				}
				return $v;
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}
		private function backend_set_raw( $key, $value, $expiration ) {
			if ( ! $this->connected || false === $value ) {
				return false;
			}
			try {
				$this->bytes_written += strlen( (string) $value );
				return (bool) $this->timed(
					function () use ( $key, $value, $expiration ) {
						if ( 'redis' === $this->provider ) {
							return $expiration > 0 ? $this->client->setex( $key, $expiration, $value ) : $this->client->set( $key, $value );
						}
						return $this->client->set( $key, $value, $expiration );
					}
				);
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}
		private function backend_add_raw( $key, $value, $expiration ) {
			if ( ! $this->connected || false === $value ) {
				return false;
			}
			try {
				return (bool) $this->timed(
					function () use ( $key, $value, $expiration ) {
						if ( 'redis' === $this->provider ) {
							$opts = array( 'nx' );
							if ( $expiration > 0 ) {
								$opts['ex'] = $expiration;
							}
							return $this->client->set( $key, $value, $opts );
						}
						return $this->client->add( $key, $value, $expiration );
					}
				);
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}
		private function backend_replace_raw( $key, $value, $expiration ) {
			if ( ! $this->connected || false === $value ) {
				return false;
			}
			try {
				return (bool) $this->timed(
					function () use ( $key, $value, $expiration ) {
						if ( 'redis' === $this->provider ) {
							$opts = array( 'xx' );
							if ( $expiration > 0 ) {
								$opts['ex'] = $expiration;
							}
							return $this->client->set( $key, $value, $opts );
						}
						return $this->client->replace( $key, $value, $expiration );
					}
				);
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}
		private function backend_delete_raw( $key ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				return (bool) $this->timed(
					function () use ( $key ) {
						return 'redis' === $this->provider ? $this->client->del( $key ) : $this->client->delete( $key );
					}
				);
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}
		private function backend_release_lock( $key, $token ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				if ( 'redis' === $this->provider ) {
					$script = "if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) else return 0 end";
					return (bool) $this->client->eval( $script, array( $key, (string) $token ), 1 );
				}
				$current = $this->client->get( $key );
				return is_string( $current ) && hash_equals( (string) $token, $current ) ? (bool) $this->client->delete( $key ) : false;
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}

		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
				return false;
			}
			$group = $this->normalize_group( $group );
			$local = $this->local_key( $key, $group );
			if ( array_key_exists( $local, $this->cache ) ) {
				return false;
			}
			if ( $this->is_non_persistent_group( $group ) ) {
				$this->cache[ $local ] = is_object( $data ) ? clone $data : $data;
				return true;
			}
			$ok = $this->backend_add_raw( $this->cache_key( $key, $group ), $this->prepare_value( $data ), max( 0, (int) $expire ) );
			if ( $ok ) {
				$this->cache[ $local ] = is_object( $data ) ? clone $data : $data;
			}
			return $ok;
		}
		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			$group                 = $this->normalize_group( $group );
			$local                 = $this->local_key( $key, $group );
			$this->cache[ $local ] = is_object( $data ) ? clone $data : $data;
			if ( $this->is_non_persistent_group( $group ) ) {
				return true;
			}
			return $this->backend_set_raw( $this->cache_key( $key, $group ), $this->prepare_value( $data ), max( 0, (int) $expire ) );
		}
		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			$group = $this->normalize_group( $group );
			$local = $this->local_key( $key, $group );
			if ( $this->is_non_persistent_group( $group ) ) {
				if ( ! array_key_exists( $local, $this->cache ) ) {
					return false;
				}
				$this->cache[ $local ] = is_object( $data ) ? clone $data : $data;
				return true;
			}
			$ok = $this->backend_replace_raw( $this->cache_key( $key, $group ), $this->prepare_value( $data ), max( 0, (int) $expire ) );
			if ( $ok ) {
				$this->cache[ $local ] = is_object( $data ) ? clone $data : $data;
			}
			return $ok;
		}
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$group = $this->normalize_group( $group );
			$local = $this->local_key( $key, $group );
			if ( ! $force && array_key_exists( $local, $this->cache ) ) {
				$found = true;
				++$this->cache_hits;
				$v = $this->cache[ $local ];
				return is_object( $v ) ? clone $v : $v;
			}
			if ( $this->is_non_persistent_group( $group ) ) {
				$found = false;
				++$this->cache_misses;
				return false;
			}
			$payload = $this->backend_get_raw( $this->cache_key( $key, $group ) );
			$ok      = false;
			$v       = $this->restore_value( $payload, $ok );
			if ( ! $ok ) {
				$found = false;
				++$this->cache_misses;
				return false;
			}
			$this->cache[ $local ] = is_object( $v ) ? clone $v : $v;
			$found                 = true;
			++$this->cache_hits;
			return $v;
		}

		/**
		 * Opt-in dogpile protection for plugin/application cache misses.
		 * Standard wp_cache_get() semantics stay unchanged; callers with an
		 * expensive fallback can use nbh_performance_cache_get_guarded().
		 */
		public function guarded_get( $key, $group, $fallback, $ttl = 3600, $lock_ttl = 10, $wait_ms = 250 ) {
			$found = false;
			$value = $this->get( $key, $group, false, $found );
			if ( $found ) {
				return $value;
			}
			if ( ! is_callable( $fallback ) ) {
				return false;
			}
			$group    = $this->normalize_group( $group );
			$ttl      = max( 1, (int) $ttl );
			$lock_ttl = max( 2, min( 300, (int) $lock_ttl ) );
			$wait_ms  = max( 0, min( 1000, (int) $wait_ms ) );
			if ( $this->is_non_persistent_group( $group ) ) {
				$fresh = call_user_func( $fallback );
				$this->set( $key, $fresh, $group, $ttl );
				return $fresh;
			}
			try {
				$token = bin2hex( random_bytes( 16 ) );
			} catch ( Throwable $e ) {
				$token = sha1( uniqid( '', true ) . microtime( true ) );
			}
			$lock_key = $this->prefix() . ':lock:' . sha1( $this->cache_key( $key, $group ) );
			if ( $this->backend_add_raw( $lock_key, $token, $lock_ttl ) ) {
				try {
					$fresh = call_user_func( $fallback );
					$this->set( $key, $fresh, $group, $ttl );
					return $fresh;
				} finally {
					$this->backend_release_lock( $lock_key, $token );
				}
			}
			$deadline = microtime( true ) + ( $wait_ms / 1000 );
			do {
				usleep( 50000 );
				$found = false;
				$value = $this->get( $key, $group, true, $found );
				if ( $found ) {
					return $value;
				}
			} while ( microtime( true ) < $deadline );
			// Availability wins after the bounded wait; do not serialize all traffic
			// behind a failed or very slow recomputation.
			$fresh = call_user_func( $fallback );
			$this->set( $key, $fresh, $group, $ttl );
			return $fresh;
		}
		public function delete( $key, $group = 'default', $deprecated = false ) {
			unset( $deprecated ); // WordPress object-cache API compatibility.
			$group = $this->normalize_group( $group );
			unset( $this->cache[ $this->local_key( $key, $group ) ] );
			return $this->is_non_persistent_group( $group ) ? true : $this->backend_delete_raw( $this->cache_key( $key, $group ) );
		}

		public function incr( $key, $offset = 1, $group = 'default' ) {
			$group = $this->normalize_group( $group );
			if ( $this->is_non_persistent_group( $group ) ) {
				$found = false;
				$v     = $this->get( $key, $group, false, $found );
				if ( ! $found || ! is_numeric( $v ) ) {
					return false;
				}
				$v = max( 0, (int) $v + (int) $offset );
				return $this->set( $key, $v, $group ) ? $v : false;
			}
			$backend = $this->cache_key( $key, $group ); try {
				if ( 'redis' === $this->provider ) {
					$script = "local v=redis.call('GET',KEYS[1]); if not v or string.sub(v,1,3)~='I1:' then return false end; local n=tonumber(string.sub(v,4)); n=n+tonumber(ARGV[1]); if n<0 then n=0 end; redis.call('SET',KEYS[1],'I1:'..n); return n";
					$v      = $this->client->eval( $script, array( $backend, (int) $offset ), 1 );
				} else {
					$attempt = 0;
					$v       = false;do {
						$this->client->get(
							$backend,
							null,
							$cas
						);
						if ( Memcached::RES_SUCCESS !== $this->client->getResultCode() ) {
							return false;
						}
						$raw = $this->client->get( $backend );
						if ( ! is_string( $raw ) || 0 !== strpos( $raw, 'I1:' ) ) {
							return false;
						}
						$v  = max( 0, (int) substr( $raw, 3 ) + (int) $offset );
						$ok = $this->client->cas( $cas, $backend, 'I1:' . $v );
						++$attempt;
					} while ( ! $ok && $attempt < 5 );
					if ( ! $ok ) {
						return false;
					}
				}
					unset( $this->cache[ $this->local_key( $key, $group ) ] );
					return (int) $v;
			} catch ( Throwable $e ) {
				$this->backend_failure();
				return false;
			}
		}
		public function decr( $key, $offset = 1, $group = 'default' ) {
			return $this->incr( $key, -1 * (int) $offset, $group );
		}

		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$keys    = (array) $keys;
			$group   = $this->normalize_group( $group );
			$out     = array();
			$pending = array();
			foreach ( $keys as $key ) {
				$local = $this->local_key( $key, $group );
				if ( ! $force && array_key_exists( $local, $this->cache ) ) {
					$out[ $key ] = is_object( $this->cache[ $local ] ) ? clone $this->cache[ $local ] : $this->cache[ $local ];
					++$this->cache_hits;
				} elseif ( $this->is_non_persistent_group( $group ) ) {
					$out[ $key ] = false;
					++$this->cache_misses;
				} else {
					$pending[ $this->cache_key( $key, $group ) ] = $key;
				}
			}
			if ( $pending && $this->connected ) {
				try {
					$raw = 'redis' === $this->provider ? $this->client->mget( array_keys( $pending ) ) : $this->client->getMulti( array_keys( $pending ) );
					foreach ( $pending as $backend => $original ) {
						$payload          = 'redis' === $this->provider ? array_shift( $raw ) : ( isset( $raw[ $backend ] ) ? $raw[ $backend ] : false );
						$ok               = false;
						$v                = $this->restore_value( $payload, $ok );
						$out[ $original ] = $ok ? $v : false;
						if ( $ok ) {
							$this->cache[ $this->local_key( $original, $group ) ] = is_object( $v ) ? clone $v : $v;
							++$this->cache_hits;
						} else {
							++$this->cache_misses;
						}
					}
				} catch ( Throwable $e ) {
					$this->backend_failure();
					foreach ( $pending as $original ) {
						$out[ $original ] = false;
					}
				}
			}
			foreach ( $keys as $k ) {
				if ( ! array_key_exists( $k, $out ) ) {
					$out[ $k ] = false;
				}
			}
			return $out;
		}
		public function set_multiple( $data, $group = 'default', $expire = 0 ) {
			$results = array();
			foreach ( (array) $data as $k => $v ) {
				$results[ $k ] = $this->set( $k, $v, $group, $expire );
			}
			return $results;
		}
		public function add_multiple( $data, $group = 'default', $expire = 0 ) {
			$results = array();
			foreach ( (array) $data as $k => $v ) {
				$results[ $k ] = $this->add( $k, $v, $group, $expire );
			}
			return $results;
		}
		public function delete_multiple( $keys, $group = 'default' ) {
			$results = array();
			foreach ( (array) $keys as $k ) {
				$results[ $k ] = $this->delete( $k, $group );
			}
			return $results;
		}
		public function flush() {
			$this->cache          = array();
			$this->group_versions = array();
			if ( ! $this->connected ) {
				return true;
			}
			$v  = $this->namespace_version() + 1;
			$ok = $this->backend_set_raw( $this->prefix() . ':meta:namespace', (string) $v, 0 );
			if ( $ok ) {
				$this->namespace_version = $v;
			}
			return $ok;
		}
		public function flush_group( $group ) {
			$group = $this->normalize_group( $group );
			foreach ( array_keys( $this->cache ) as $l ) {
				if ( false !== strpos( $l, '|' . $group . '|' ) ) {
					unset( $this->cache[ $l ] );
				}
			}
			if ( $this->is_non_persistent_group( $group ) ) {
				return true;
			}
			$v  = $this->group_version( $group ) + 1;
			$ok = $this->backend_set_raw( $this->prefix() . ':meta:group:' . sha1( $group ), (string) $v, 0 );
			if ( $ok ) {
				$this->group_versions[ $group ] = $v;
			}
			return $ok;
		}
		public function add_global_groups( $groups ) {
			foreach ( (array) $groups as $g ) {
				$this->global_groups[ (string) $g ] = true;
			}
		}
		public function add_non_persistent_groups( $groups ) {
			foreach ( (array) $groups as $g ) {
				$this->non_persistent_groups[ (string) $g ] = true;
			}
		}
		public function switch_to_blog( $blog_id ) {
			$this->blog_id = (int) $blog_id;
			return true;
		}
		public function reset() {
			$this->cache = array();
			return true;
		}
		public function close() {
			$this->persist_telemetry();
			try {
				if ( $this->connected && 'redis' === $this->provider && method_exists( $this->client, 'close' ) ) {
					$this->client->close();
				}
			} catch ( Throwable $e ) {
				++$this->errors;
			}
			$this->connected = false;
			return true;
		}
		public function stats() {
			echo '<p>NBH Performance Object Cache: ' . esc_html( $this->connected ? $this->provider : 'memory fallback' ) . '; hits ' . (int) $this->cache_hits . '; misses ' . (int) $this->cache_misses . '</p>';
		}

		private function persist_telemetry() {
			if ( ! $this->connected ) {
				return;
			}
			$total   = $this->cache_hits + $this->cache_misses;
			$payload = array(
				'timestamp'       => time(),
				'provider'        => $this->provider,
				'hits'            => $this->cache_hits,
				'misses'          => $this->cache_misses,
				'hit_rate'        => $total ? round( ( $this->cache_hits / $total ) * 100, 2 ) : 0,
				'errors'          => $this->errors,
				'bytes_read'      => $this->bytes_read,
				'bytes_written'   => $this->bytes_written,
				'backend_time_ms' => round( $this->backend_time_ms, 2 ),
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Self-contained pre-WordPress cache runtime; wp_json_encode() is not available during early bootstrap.
			$this->backend_set_raw( $this->prefix() . ':meta:telemetry', json_encode( $payload ), 300 );
		}
	}
}

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress loads one self-contained drop-in before plugins; its cache class and required wp_cache_* entry points must be available together. Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_init() {
	global $wp_object_cache;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_cache_init() must initialize the core $wp_object_cache global with this owned drop-in implementation.
	$wp_object_cache = new NBH_Performance_Object_Cache();
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, $expire );
}
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $data, $group, $expire );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, $expire );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, $expire );
}
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group, $expire );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}
function nbh_performance_cache_get_guarded( $key, $group, $fallback, $ttl = 3600, $lock_ttl = 10, $wait_ms = 250 ) {
	global $wp_object_cache;
	return $wp_object_cache->guarded_get( $key, $group, $fallback, $ttl, $lock_ttl, $wait_ms );
}
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_delete( $key, $group = '', $deprecated = false ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group, $deprecated );
}
function wp_cache_delete_multiple( array $keys, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}
function wp_cache_flush_group( $group ) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group( $group );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_close() {
	global $wp_object_cache;
	return $wp_object_cache->close();
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}
function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	return $wp_object_cache->switch_to_blog( $blog_id );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Required WordPress object-cache drop-in function name; renaming it breaks the core cache API.
function wp_cache_reset() {
	global $wp_object_cache;
	return $wp_object_cache->reset();
}
function wp_cache_supports( $feature ) {
	return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group', 'guarded_get' ), true );
}
function wp_cache_flush_runtime() {
	global $wp_object_cache;
	return $wp_object_cache->reset();
}
