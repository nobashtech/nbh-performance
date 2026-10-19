<?php
/**
 * REST token and ingestion endpoints for privacy-safe browser observations.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Response;

final class PriorityElementsController implements Module {
	private $storage;
	private $token;

	public function __construct( PriorityElementsStorage $storage, PriorityElementsToken $token ) {
		$this->storage = $storage;
		$this->token   = $token;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_priority_elements_prune', array( $this->storage, 'prune' ) );
		if ( ! wp_next_scheduled( 'nbh_performance_priority_elements_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'nbh_performance_priority_elements_prune' );
		}
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/priority-elements/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/priority-elements/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** Issue a fresh token so long-lived cached HTML never contains stale credentials. */
	public function token( WP_REST_Request $request ) {
		if ( ! $this->enabled() || ! $this->same_origin_browser_request( $request ) || ! $this->rate_limit( 'token', 60 ) ) {
			return new \WP_Error( 'nbh_performance_priority_token_denied', __( 'Priority Elements token request was denied.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$url = $this->storage->normalize_url( $request->get_param( 'url' ) );
		if ( ! $url ) {
			return new \WP_Error( 'nbh_performance_priority_invalid_url', __( 'Priority Elements requires a same-origin page URL.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$token = $this->token->issue( $url );
		if ( ! $token ) {
			return new \WP_Error( 'nbh_performance_priority_token_failed', __( 'Priority Elements token could not be created.', 'nbh-performance' ), array( 'status' => 500 ) );
		}
		$response = new WP_REST_Response(
			array(
				'url'        => $url,
				'token'      => $token,
				'expires_in' => 600,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/** Accept one bounded anonymous technical report. */
	public function receive( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new \WP_Error( 'nbh_performance_priority_disabled', __( 'Priority Elements is disabled.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		if ( strlen( $request->get_body() ) > 65536 ) {
			return new \WP_Error( 'nbh_performance_priority_payload_too_large', __( 'Priority Elements payload is too large.', 'nbh-performance' ), array( 'status' => 413 ) );
		}
		if ( ! $this->same_origin_browser_request( $request ) || ! $this->rate_limit( 'report', 120 ) ) {
			return new \WP_Error( 'nbh_performance_priority_rate_limited', __( 'Priority Elements report was denied.', 'nbh-performance' ), array( 'status' => 429 ) );
		}

		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'nbh_performance_priority_invalid_json', __( 'Invalid Priority Elements payload.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$url = isset( $data['url'] ) ? $this->storage->normalize_url( $data['url'] ) : '';
		if ( ! $url || ! $this->token->consume( $url, isset( $data['token'] ) ? $data['token'] : '' ) ) {
			return new \WP_Error( 'nbh_performance_priority_unauthorized', __( 'Priority Elements token is invalid, expired, or already used.', 'nbh-performance' ), array( 'status' => 403 ) );
		}

		$variant = isset( $data['variant'] ) && 'mobile' === sanitize_key( $data['variant'] ) ? 'mobile' : 'desktop';
		$report  = array(
			'lcp'         => $this->sanitize_items( $data['lcp'] ?? array(), false ),
			'images'      => $this->sanitize_items( $data['images'] ?? array(), false ),
			'backgrounds' => $this->sanitize_items( $data['backgrounds'] ?? array(), false ),
			'fonts'       => $this->sanitize_items( $data['fonts'] ?? array(), false ),
			'origins'     => $this->sanitize_items( $data['origins'] ?? array(), true ),
			'viewport'    => is_array( $data['viewport'] ?? null ) ? $data['viewport'] : array(),
		);
		$stored  = $this->storage->record( $url, $variant, $report );
		return rest_ensure_response( array( 'stored' => (bool) $stored ) );
	}

	/** Sanitize same-origin assets or public external origins. */
	private function sanitize_items( $items, $origin_only ) {
		$out       = array();
		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = strtolower( (string) ( $home['host'] ?? '' ) );
		$home_port = absint( $home['port'] ?? 0 );
		foreach ( array_slice( (array) $items, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = isset( $item['url'] ) ? esc_url_raw( substr( (string) $item['url'], 0, 2048 ) ) : '';
			if ( ! $url ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
				continue;
			}
			$host = strtolower( (string) ( $parts['host'] ?? '' ) );
			$port = absint( $parts['port'] ?? 0 );
			if ( $origin_only ) {
				if ( ! $this->public_origin_host( $host ) ) {
					continue;
				}
				$url = strtolower( (string) $parts['scheme'] ) . '://' . $host . ( $port ? ':' . $port : '' );
			} elseif ( $host !== $home_host || $port !== $home_port ) {
				continue;
			} else {
				$url = $this->sanitize_resource_url( $parts );
			}

			$key = substr( sanitize_text_field( (string) ( $item['key'] ?? $url ) ), 0, 300 );
			if ( '' === $key ) {
				$key = $url;
			}
			$out[] = array(
				'key'       => $key,
				'url'       => $url,
				'selector'  => substr( sanitize_text_field( (string) ( $item['selector'] ?? '' ) ), 0, 200 ),
				'type'      => sanitize_key( (string) ( $item['type'] ?? '' ) ),
				'score'     => max( 1, min( 100, absint( $item['score'] ?? 1 ) ) ),
				'last_seen' => time(),
			);
		}
		return $out;
	}

	private function sanitize_resource_url( array $parts ) {
		$url = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$url .= ':' . absint( $parts['port'] );
		}
		$url .= isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		if ( ! empty( $parts['query'] ) && strlen( (string) $parts['query'] ) <= 512 ) {
			parse_str( (string) $parts['query'], $query );
			foreach ( array_keys( $query ) as $key ) {
				if ( preg_match( '/^(?:utm_|fbclid$|gclid$|msclkid$)/i', (string) $key ) ) {
					unset( $query[ $key ] );
				}
			}
			if ( $query ) {
				ksort( $query );
				$url = add_query_arg( array_map( 'sanitize_text_field', $query ), $url );
			}
		}
		return esc_url_raw( $url );
	}

	private function public_origin_host( $host ) {
		$host = strtolower( trim( (string) $host, '[]' ) );
		if ( ! $host || 'localhost' === $host || false !== strpos( $host, '.local' ) || false !== strpos( $host, '.internal' ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host );
	}

	/** Require browser-origin evidence for public token/report endpoints. */
	private function same_origin_browser_request( WP_REST_Request $request ) {
		$origin  = trim( (string) $request->get_header( 'origin' ) );
		$referer = trim( (string) $request->get_header( 'referer' ) );
		$source  = $origin ? $origin : $referer;
		if ( ! $source ) {
			return (bool) apply_filters( 'nbh_performance_priority_elements_allow_missing_origin', false, $request );
		}
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $source );
		return is_array( $home ) && is_array( $test )
			&& strtolower( (string) ( $home['scheme'] ?? '' ) ) === strtolower( (string) ( $test['scheme'] ?? '' ) )
			&& strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $test['host'] ?? '' ) )
			&& absint( $home['port'] ?? 0 ) === absint( $test['port'] ?? 0 );
	}

	private function enabled() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_resource_priority_enabled', 0 ) ) {
			return false;
		}
		if ( get_option( 'nbh_performance_priority_elements_respect_dnt', 1 ) ) {
			$dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
			$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';
			if ( '1' === $dnt || '1' === $gpc ) {
				return false;
			}
		}
		return (bool) apply_filters( 'nbh_performance_priority_elements_consent_granted', true );
	}

	private function rate_limit( $scope, $limit ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'nbh_performance_pe_' . sanitize_key( $scope ) . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$count = absint( get_transient( $key ) );
		if ( $count >= absint( $limit ) ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
