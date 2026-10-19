<?php
/** CDN and edge orchestration module. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Security\PurgeEndpointRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CdnManager implements Module {
	/** @var array<string,EdgeProviderInterface> */
	private $providers;
	/** @var CdnUrlRewriter */
	private $rewriter;
	/** @var ResourceHintInjector */
	private $hints;
	/** @var EdgeExclusionRules */
	private $exclusions;

	public function __construct( array $providers, CdnUrlRewriter $rewriter, ResourceHintInjector $hints, EdgeExclusionRules $exclusions ) {
		$this->providers  = $providers;
		$this->rewriter   = $rewriter;
		$this->hints      = $hints;
		$this->exclusions = $exclusions;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_after_cache_purge', array( $this, 'sync_local_purge' ), 10, 2 );
		add_filter( 'style_loader_src', array( $this->rewriter, 'filter_src' ), 90 );
		add_filter( 'script_loader_src', array( $this->rewriter, 'filter_src' ), 90 );
		add_filter( 'wp_get_attachment_url', array( $this->rewriter, 'filter_src' ), 90 );
		add_filter( 'wp_resource_hints', array( $this->hints, 'filter_hints' ), 20, 2 );
		add_action( 'send_headers', array( $this, 'send_edge_headers' ) );
		add_filter( 'nbh_performance_hub_cdn_status', array( $this, 'status' ) );
	}

	public function routes() {
		$cache_namespace = 'nbh-performance/v1';
		nbh_performance_register_rest_route(
			$cache_namespace,
			'/edge/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		nbh_performance_register_rest_route(
			$cache_namespace,
			'/edge/detect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_detect' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		nbh_performance_register_rest_route(
			$cache_namespace,
			'/edge/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_test' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		nbh_performance_register_rest_route(
			$cache_namespace,
			'/edge/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_connect' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		nbh_performance_register_rest_route(
			$cache_namespace,
			'/edge/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_purge' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			$cache_namespace,
			'/edge/rotate-secret',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_rotate_secret' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	public function admin_permission() {
		return current_user_can( 'manage_options' ) ? true : new WP_Error( 'nbh_performance_edge_forbidden', __( 'You are not allowed to manage CDN settings.', 'nbh-performance' ), array( 'status' => 403 ) );
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'nbh_performance_edge_forbidden', __( 'You are not allowed to purge edge cache.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$limiter = new PurgeEndpointRateLimiter();
		return $limiter->allow( 'edge|' . get_current_user_id() ) ? true : new WP_Error( 'nbh_performance_edge_rate_limited', __( 'Too many edge purge requests.', 'nbh-performance' ), array( 'status' => 429 ) );
	}

	public function rest_status() {
		return new WP_REST_Response( $this->status(), 200 );
	}

	public function rest_detect() {
		return new WP_REST_Response( array( 'detection' => $this->detection() ), 200 );
	}

	public function rest_test( WP_REST_Request $request ) {
		$provider = $this->normalize_provider( (string) $request->get_param( 'provider' ) );
		$result   = $this->test_provider( $provider, $request->get_json_params() ? $request->get_json_params() : $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'CDN connection verified.', 'nbh-performance' ),
				'status'  => $this->status(),
				'choices' => isset( $result['choices'] ) ? $result['choices'] : array(),
			),
			200
		);
	}

	public function rest_connect( WP_REST_Request $request ) {
		$params   = $request->get_json_params() ? $request->get_json_params() : $request->get_params();
		$provider = $this->normalize_provider( isset( $params['provider'] ) ? (string) $params['provider'] : '' );
		$result   = $this->connect_provider( $provider, is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'CDN connected.', 'nbh-performance' ),
				'status'  => $this->status(),
				'choices' => isset( $result['choices'] ) ? $result['choices'] : array(),
			),
			200
		);
	}

	public function rest_rotate_secret() {
		$secret = wp_generate_password( 48, false, false );
		update_option( 'nbh_performance_edge_purge_secret', $secret, false );
		return new WP_REST_Response(
			array(
				'success' => true,
				'secret'  => $secret,
				'message' => __( 'A new custom CDN webhook secret was generated. Copy it now — it will not be shown again.', 'nbh-performance' ),
				'status'  => $this->status(),
			),
			200
		);
	}

	public function rest_purge( WP_REST_Request $request ) {
		$provider = $this->normalize_provider( (string) $request->get_param( 'provider' ) );
		if ( '' === $provider ) {
			$provider = $this->normalize_provider( (string) get_option( 'nbh_performance_edge_provider', 'custom' ) );
		}
		$urls = array_filter( array_map( 'esc_url_raw', (array) $request->get_param( 'urls' ) ) );
		$all  = rest_sanitize_boolean( $request->get_param( 'purge_all' ) );
		if ( ! $all && empty( $urls ) ) {
			$urls = array( home_url( '/' ) );
		}
		$result = $this->purge( $provider, $all ? array() : $urls, $all );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'success'   => true,
				'provider'  => $provider,
				'purge_all' => (bool) $all,
				'urls'      => count( $urls ),
				'message'   => $all ? __( 'Edge cache purged.', 'nbh-performance' ) : __( 'Selected URLs were purged at the edge.', 'nbh-performance' ),
				'status'    => $this->status(),
			),
			200
		);
	}

	public function sync_local_purge( $target, $success ) {
		if ( ! $success || ! get_option( 'nbh_performance_edge_auto_purge_enabled', 0 ) ) {
			return;
		}
		$provider = $this->normalize_provider( (string) get_option( 'nbh_performance_edge_provider', '' ) );
		if ( '' === $provider || ! isset( $this->providers[ $provider ] ) || ! $this->providers[ $provider ]->is_configured() ) {
			return;
		}
		if ( 'all' === $target ) {
			$this->purge( $provider, array(), true );
		} elseif ( is_string( $target ) && $target ) {
			$this->purge( $provider, array( $target ), false );
		}
	}

	public function send_edge_headers() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_edge_cache_headers_enabled', 0 ) || headers_sent() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/' );
		if ( $this->exclusions->is_excluded( $url ) ) {
			header( 'Cache-Control: private, no-store, max-age=0', true );
			header( 'X-NBH-Performance-Edge: BYPASS', true );
			return;
		}
		$ttl = max( 60, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_edge_cache_ttl', HOUR_IN_SECONDS ) ) ) );
		header( 'Cache-Control: public, max-age=0, s-maxage=' . $ttl . ', stale-while-revalidate=30', true );
		header( 'X-NBH-Performance-Edge: CACHE', true );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function status() {
		$provider = $this->normalize_provider( (string) get_option( 'nbh_performance_edge_provider', 'custom' ) );
		if ( '' === $provider ) {
			$provider = 'custom';
		}
		$configured = isset( $this->providers[ $provider ] ) ? (bool) $this->providers[ $provider ]->is_configured() : false;
		$last       = get_option( 'nbh_performance_cdn_last_purge', array() );
		if ( ! is_array( $last ) || empty( $last ) ) {
			$last = get_option( 'nbh_performance_edge_last_purge', array() );
		}
		return array(
			'provider'               => $provider,
			'configured'             => $configured,
			'providers'              => $this->provider_summaries(),
			'rewrite_enabled'        => (bool) get_option( 'nbh_performance_cdn_rewrite_enabled', 0 ),
			'auto_purge_enabled'     => (bool) get_option( 'nbh_performance_edge_auto_purge_enabled', 0 ),
			'edge_headers_enabled'   => (bool) get_option( 'nbh_performance_edge_cache_headers_enabled', 0 ),
			'resource_hints_enabled' => (bool) get_option( 'nbh_performance_resource_hints_enabled', 0 ),
			'cdn_base_url'           => esc_url_raw( (string) get_option( 'nbh_performance_cdn_base_url', '' ) ),
			'edge_cache_ttl'         => absint( get_option( 'nbh_performance_edge_cache_ttl', HOUR_IN_SECONDS ) ),
			'credentials'            => $this->credential_flags(),
			'detection'              => $this->detection(),
			'last_purge'             => is_array( $last ) ? $last : array(),
			'last_connection'        => get_option( 'nbh_performance_cdn_last_connection', array() ),
		);
	}

	/**
	 * @param string               $provider Provider id.
	 * @param array<string,mixed>  $params   Params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function test_provider( $provider, array $params ) {
		if ( ! isset( $this->providers[ $provider ] ) ) {
			return new WP_Error( 'nbh_performance_unknown_edge_provider', __( 'Unknown CDN provider.', 'nbh-performance' ) );
		}
		$client = $this->providers[ $provider ];
		if ( 'cloudflare' === $provider && $client instanceof CloudflareApiClient ) {
			$token = isset( $params['api_token'] ) ? trim( (string) $params['api_token'] ) : '';
			$zones = $client->discover_zones( $token ? $token : (string) get_option( 'nbh_performance_cloudflare_api_token', '' ) );
			if ( is_wp_error( $zones ) ) {
				$test = $client->test_connection( $token );
				return is_wp_error( $test ) ? $test : array( 'choices' => array() );
			}
			return array( 'choices' => $zones );
		}
		if ( 'bunnycdn' === $provider && $client instanceof BunnyCdnClient ) {
			$key   = isset( $params['api_key'] ) ? trim( (string) $params['api_key'] ) : '';
			$zones = $client->discover_pull_zones( $key ? $key : (string) get_option( 'nbh_performance_bunny_api_key', '' ) );
			return is_wp_error( $zones ) ? $zones : array( 'choices' => $zones );
		}
		if ( 'keycdn' === $provider && $client instanceof KeyCdnClient ) {
			$key   = isset( $params['api_key'] ) ? trim( (string) $params['api_key'] ) : '';
			$zones = $client->discover_zones( $key ? $key : (string) get_option( 'nbh_performance_keycdn_api_key', '' ) );
			return is_wp_error( $zones ) ? $zones : array( 'choices' => $zones );
		}
		if ( 'custom' === $provider && $client instanceof CustomCdnClient ) {
			$result = $client->test_connection();
			return is_wp_error( $result ) ? $result : array( 'choices' => array() );
		}
		return new WP_Error( 'nbh_performance_edge_test_unsupported', __( 'This provider cannot be tested yet.', 'nbh-performance' ) );
	}

	/**
	 * @param string               $provider Provider id.
	 * @param array<string,mixed>  $params   Params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function connect_provider( $provider, array $params ) {
		if ( ! isset( $this->providers[ $provider ] ) ) {
			return new WP_Error( 'nbh_performance_unknown_edge_provider', __( 'Unknown CDN provider.', 'nbh-performance' ) );
		}

		if ( isset( $params['cdn_base_url'] ) ) {
			$url = esc_url_raw( trim( (string) $params['cdn_base_url'] ) );
			if ( $url && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
				update_option( 'nbh_performance_cdn_base_url', untrailingslashit( $url ), false );
			} elseif ( '' === trim( (string) $params['cdn_base_url'] ) ) {
				update_option( 'nbh_performance_cdn_base_url', '', false );
			}
		}
		if ( isset( $params['edge_cache_ttl'] ) ) {
			update_option( 'nbh_performance_edge_cache_ttl', max( 60, min( WEEK_IN_SECONDS, absint( $params['edge_cache_ttl'] ) ) ), false );
		}

		if ( 'cloudflare' === $provider ) {
			return $this->connect_cloudflare( $params );
		}
		if ( 'bunnycdn' === $provider ) {
			return $this->connect_bunny( $params );
		}
		if ( 'keycdn' === $provider ) {
			return $this->connect_keycdn( $params );
		}
		return $this->connect_custom( $params );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function connect_cloudflare( array $params ) {
		/** @var CloudflareApiClient $client */
		$client = $this->providers['cloudflare'];
		$token  = isset( $params['api_token'] ) ? trim( (string) $params['api_token'] ) : '';
		if ( '' === $token ) {
			$token = (string) get_option( 'nbh_performance_cloudflare_api_token', '' );
		}
		if ( '' === $token ) {
			return new WP_Error( 'nbh_performance_cloudflare_token_missing', __( 'Enter a Cloudflare API token with Zone Cache Purge and Zone Read permissions.', 'nbh-performance' ) );
		}
		$zone_id = isset( $params['zone_id'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $params['zone_id'] ) : '';
		$choices = array();
		if ( '' === $zone_id ) {
			$discovered = $client->discover_zones( $token );
			if ( is_wp_error( $discovered ) ) {
				return $discovered;
			}
			$choices = $discovered;
			if ( count( $discovered ) > 1 && empty( $params['zone_id'] ) ) {
				return array(
					'message' => __( 'Multiple Cloudflare zones matched. Choose one to finish connecting.', 'nbh-performance' ),
					'choices' => $discovered,
				);
			}
			$zone_id = $discovered[0]['id'];
		}
		update_option( 'nbh_performance_cloudflare_api_token', $token, false );
		update_option( 'nbh_performance_cloudflare_zone_id', $zone_id, false );
		update_option( 'nbh_performance_edge_provider', 'cloudflare', false );
		$test = $client->test_connection( $token );
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		$this->remember_connection( 'cloudflare', true, __( 'Cloudflare connected and verified.', 'nbh-performance' ) );
		return array(
			'message' => __( 'Cloudflare connected. Zone ID was saved automatically.', 'nbh-performance' ),
			'choices' => $choices,
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function connect_bunny( array $params ) {
		/** @var BunnyCdnClient $client */
		$client = $this->providers['bunnycdn'];
		$key    = isset( $params['api_key'] ) ? trim( (string) $params['api_key'] ) : '';
		if ( '' === $key ) {
			$key = (string) get_option( 'nbh_performance_bunny_api_key', '' );
		}
		if ( '' === $key ) {
			return new WP_Error( 'nbh_performance_bunny_key_missing', __( 'Enter your Bunny CDN AccessKey.', 'nbh-performance' ) );
		}
		$zone_id = isset( $params['pull_zone_id'] ) ? absint( $params['pull_zone_id'] ) : 0;
		$choices = array();
		if ( $zone_id <= 0 ) {
			$discovered = $client->discover_pull_zones( $key );
			if ( is_wp_error( $discovered ) ) {
				return $discovered;
			}
			$choices = $discovered;
			if ( count( $discovered ) > 1 && empty( $params['pull_zone_id'] ) ) {
				return array(
					'message' => __( 'Multiple Bunny pull zones found. Choose one to finish connecting.', 'nbh-performance' ),
					'choices' => $discovered,
				);
			}
			$zone_id = absint( $discovered[0]['id'] );
			if ( ! empty( $discovered[0]['cdn_url'] ) && ! get_option( 'nbh_performance_cdn_base_url', '' ) ) {
				update_option( 'nbh_performance_cdn_base_url', untrailingslashit( esc_url_raw( (string) $discovered[0]['cdn_url'] ) ), false );
			}
		}
		update_option( 'nbh_performance_bunny_api_key', $key, false );
		update_option( 'nbh_performance_bunny_pull_zone_id', $zone_id, false );
		update_option( 'nbh_performance_edge_provider', 'bunnycdn', false );
		$test = $client->test_connection( $key );
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		$this->remember_connection( 'bunnycdn', true, __( 'Bunny CDN connected and verified.', 'nbh-performance' ) );
		return array(
			'message' => __( 'Bunny CDN connected. Pull zone was saved automatically.', 'nbh-performance' ),
			'choices' => $choices,
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function connect_keycdn( array $params ) {
		/** @var KeyCdnClient $client */
		$client = $this->providers['keycdn'];
		$key    = isset( $params['api_key'] ) ? trim( (string) $params['api_key'] ) : '';
		if ( '' === $key ) {
			$key = (string) get_option( 'nbh_performance_keycdn_api_key', '' );
		}
		if ( '' === $key ) {
			return new WP_Error( 'nbh_performance_keycdn_key_missing', __( 'Enter your KeyCDN API key.', 'nbh-performance' ) );
		}
		$zone_id = isset( $params['zone_id'] ) ? absint( $params['zone_id'] ) : 0;
		$choices = array();
		if ( $zone_id <= 0 ) {
			$discovered = $client->discover_zones( $key );
			if ( is_wp_error( $discovered ) ) {
				return $discovered;
			}
			$choices = $discovered;
			if ( count( $discovered ) > 1 && empty( $params['zone_id'] ) ) {
				return array(
					'message' => __( 'Multiple KeyCDN zones found. Choose one to finish connecting.', 'nbh-performance' ),
					'choices' => $discovered,
				);
			}
			$zone_id = absint( $discovered[0]['id'] );
			if ( ! empty( $discovered[0]['cdn_url'] ) && ! get_option( 'nbh_performance_cdn_base_url', '' ) ) {
				update_option( 'nbh_performance_cdn_base_url', untrailingslashit( esc_url_raw( (string) $discovered[0]['cdn_url'] ) ), false );
			}
		}
		update_option( 'nbh_performance_keycdn_api_key', $key, false );
		update_option( 'nbh_performance_keycdn_zone_id', $zone_id, false );
		update_option( 'nbh_performance_edge_provider', 'keycdn', false );
		$test = $client->test_connection( $key );
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		$this->remember_connection( 'keycdn', true, __( 'KeyCDN connected and verified.', 'nbh-performance' ) );
		return array(
			'message' => __( 'KeyCDN connected. Zone ID was saved automatically.', 'nbh-performance' ),
			'choices' => $choices,
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed>|WP_Error
	 */
	private function connect_custom( array $params ) {
		$url = isset( $params['webhook_url'] ) ? esc_url_raw( trim( (string) $params['webhook_url'] ) ) : '';
		if ( '' === $url ) {
			$url = esc_url_raw( (string) get_option( 'nbh_performance_edge_purge_webhook_url', '' ) );
		}
		if ( ! $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'nbh_performance_custom_cdn_url', __( 'Enter an HTTPS custom CDN / edge purge webhook URL.', 'nbh-performance' ) );
		}
		$secret = (string) get_option( 'nbh_performance_edge_purge_secret', '' );
		if ( isset( $params['rotate_secret'] ) && rest_sanitize_boolean( $params['rotate_secret'] ) ) {
			$secret = wp_generate_password( 48, false, false );
			update_option( 'nbh_performance_edge_purge_secret', $secret, false );
		} elseif ( strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 48, false, false );
			update_option( 'nbh_performance_edge_purge_secret', $secret, false );
		}
		update_option( 'nbh_performance_edge_purge_webhook_url', $url, false );
		update_option( 'nbh_performance_edge_provider', 'custom', false );
		/** @var CustomCdnClient $client */
		$client = $this->providers['custom'];
		$test   = $client->test_connection();
		if ( is_wp_error( $test ) ) {
			$this->remember_connection( 'custom', false, $test->get_error_message() );
			return $test;
		}
		$this->remember_connection( 'custom', true, __( 'Custom CDN webhook connected and verified.', 'nbh-performance' ) );
		$response = array(
			'message' => __( 'Custom CDN connected. Webhook verified successfully.', 'nbh-performance' ),
			'choices' => array(),
		);
		if ( ! empty( $params['rotate_secret'] ) || isset( $params['include_secret'] ) ) {
			$response['secret'] = $secret;
		}
		return $response;
	}

	/**
	 * @param string             $provider_id Provider.
	 * @param array<int,string>  $urls        URLs.
	 * @param bool               $all         Purge all.
	 * @return true|WP_Error
	 */
	private function purge( $provider_id, array $urls, $all ) {
		if ( ! isset( $this->providers[ $provider_id ] ) ) {
			return new WP_Error( 'nbh_performance_unknown_edge_provider', __( 'Unknown CDN provider.', 'nbh-performance' ) );
		}
		$provider = $this->providers[ $provider_id ];
		$result   = $all ? $provider->purge_all() : $provider->purge_urls( $urls );
		$record   = array(
			'at'        => time(),
			'provider'  => $provider_id,
			'purge_all' => (bool) $all,
			'urls'      => count( $urls ),
			'success'   => ! is_wp_error( $result ),
		);
		if ( is_wp_error( $result ) ) {
			$record['error'] = sanitize_text_field( $result->get_error_message() );
		}
		update_option( 'nbh_performance_cdn_last_purge', $record, false );
		do_action( 'nbh_performance_edge_purge_result', $provider_id, $all, $urls, $result );
		return $result;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function provider_summaries() {
		$labels = array(
			'cloudflare' => 'Cloudflare',
			'bunnycdn'   => 'Bunny CDN',
			'keycdn'     => 'KeyCDN',
			'custom'     => 'Custom CDN',
		);
		$out    = array();
		foreach ( $labels as $id => $label ) {
			$out[ $id ] = array(
				'id'         => $id,
				'label'      => $label,
				'configured' => isset( $this->providers[ $id ] ) ? (bool) $this->providers[ $id ]->is_configured() : false,
				'available'  => isset( $this->providers[ $id ] ),
			);
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function credential_flags() {
		return array(
			'cloudflare_token_set' => '' !== trim( (string) get_option( 'nbh_performance_cloudflare_api_token', '' ) ),
			'cloudflare_zone_id'   => preg_replace( '/[^a-f0-9]/i', '', (string) get_option( 'nbh_performance_cloudflare_zone_id', '' ) ),
			'bunny_key_set'        => '' !== trim( (string) get_option( 'nbh_performance_bunny_api_key', '' ) ),
			'bunny_pull_zone_id'   => absint( get_option( 'nbh_performance_bunny_pull_zone_id', 0 ) ),
			'keycdn_key_set'       => '' !== trim( (string) get_option( 'nbh_performance_keycdn_api_key', '' ) ),
			'keycdn_zone_id'       => absint( get_option( 'nbh_performance_keycdn_zone_id', 0 ) ),
			'webhook_url'          => esc_url_raw( (string) get_option( 'nbh_performance_edge_purge_webhook_url', '' ) ),
			'webhook_secret_set'   => strlen( (string) get_option( 'nbh_performance_edge_purge_secret', '' ) ) >= 32,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function detection() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cf        = defined( 'CLOUDFLARE_PLUGIN_DIR' ) || ! empty( $_SERVER['HTTP_CF_RAY'] );
		$bunny     = defined( 'BUNNYCDN_VERSION' ) || class_exists( 'BunnyCDN' );
		$suggested = '';
		if ( $cf ) {
			$suggested = 'cloudflare';
		} elseif ( $bunny ) {
			$suggested = 'bunnycdn';
		}
		return array(
			'cloudflare' => (bool) $cf,
			'bunnycdn'   => (bool) $bunny,
			'suggested'  => $suggested,
			'message'    => $cf
				? __( 'Cloudflare signals were detected on this site. Connect with an API token to enable automatic zone matching and purge.', 'nbh-performance' )
				: ( $bunny
					? __( 'Bunny CDN was detected. Connect with your AccessKey to auto-select a pull zone.', 'nbh-performance' )
					: __( 'No CDN provider was auto-detected. Choose Cloudflare, Bunny, KeyCDN, or Custom.', 'nbh-performance' ) ),
		);
	}

	/**
	 * @param string $provider Provider.
	 * @param bool   $success  Success.
	 * @param string $message  Message.
	 */
	private function remember_connection( $provider, $success, $message ) {
		update_option(
			'nbh_performance_cdn_last_connection',
			array(
				'at'       => time(),
				'provider' => sanitize_key( $provider ),
				'success'  => (bool) $success,
				'message'  => sanitize_text_field( $message ),
			),
			false
		);
	}

	/**
	 * @param string $provider Raw provider.
	 * @return string
	 */
	private function normalize_provider( $provider ) {
		$provider = sanitize_key( $provider );
		$map      = array(
			'bunny'      => 'bunnycdn',
			'bunny_cdn'  => 'bunnycdn',
			'cloudflare' => 'cloudflare',
			'keycdn'     => 'keycdn',
			'custom'     => 'custom',
		);
		return isset( $map[ $provider ] ) ? $map[ $provider ] : $provider;
	}
}
