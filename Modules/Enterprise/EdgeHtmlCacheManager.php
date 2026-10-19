<?php
/**
 * Provider-neutral full-page edge cache headers and signed purge webhook.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Enterprise;

use NBH\Performance\Modules\CacheEngine\RequestEligibility;
use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Security\AuditLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class EdgeHtmlCacheManager implements Module {
	/** @var RequestEligibility */
	private $eligibility;
	/** @var CacheTagManager */
	private $tags;
	/** @var AuditLogger */
	private $audit;

	public function __construct( RequestEligibility $eligibility, CacheTagManager $tags, AuditLogger $audit ) {
		$this->eligibility = $eligibility;
		$this->tags        = $tags;
		$this->audit       = $audit;
	}

	public function register() {
		add_action( 'send_headers', array( $this, 'send_edge_headers' ), 99 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_edge_tag_purge_requested', array( $this, 'dispatch_purge' ), 10, 2 );
		add_action( 'nbh_performance_after_cache_purge', array( $this, 'after_page_purge' ), 20, 2 );
	}

	public function routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/edge/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => $admin,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/edge/test',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_test' ),
				'permission_callback' => $admin,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/edge/purge-webhook',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_inbound_purge' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function send_edge_headers() {
		if ( ! get_option( 'nbh_performance_edge_html_cache_enabled', 0 ) || get_option( 'nbh_performance_safe_mode', 1 ) || headers_sent() || ! $this->eligibility->is_request_cacheable() ) {
			return;
		}
		$ttl  = max( 60, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_edge_html_ttl', HOUR_IN_SECONDS ) ) ) );
		$swr  = max( 0, min( DAY_IN_SECONDS, absint( get_option( 'nbh_performance_edge_stale_while_revalidate', 300 ) ) ) );
		$sie  = max( 0, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_edge_stale_if_error', DAY_IN_SECONDS ) ) ) );
		$tags = array_slice( $this->tags->current_tags(), 0, 32 );
		header( 'Cache-Control: public, max-age=0, s-maxage=' . $ttl . ', stale-while-revalidate=' . $swr . ', stale-if-error=' . $sie, true );
		header( 'Surrogate-Control: max-age=' . $ttl . ', stale-while-revalidate=' . $swr . ', stale-if-error=' . $sie, true );
		header( 'Surrogate-Key: ' . implode( ' ', $tags ), true );
		header( 'X-NBH-Performance-Edge: eligible', true );
	}

	/** @return \WP_REST_Response */
	public function rest_status() {
		return rest_ensure_response( $this->status() );
	}

	/** @return array<string,mixed> */
	public function status() {
		return array(
			'enabled'            => (bool) get_option( 'nbh_performance_edge_html_cache_enabled', 0 ),
			'ttl'                => absint( get_option( 'nbh_performance_edge_html_ttl', HOUR_IN_SECONDS ) ),
			'swr'                => absint( get_option( 'nbh_performance_edge_stale_while_revalidate', 300 ) ),
			'sie'                => absint( get_option( 'nbh_performance_edge_stale_if_error', DAY_IN_SECONDS ) ),
			'webhook_configured' => (bool) get_option( 'nbh_performance_edge_purge_webhook_url', '' ),
			'secret_configured'  => strlen( (string) get_option( 'nbh_performance_edge_purge_secret', '' ) ) >= 32,
			'last_purge'         => get_option( 'nbh_performance_edge_last_purge', array() ),
		);
	}

	/** @return \WP_REST_Response|WP_Error */
	public function rest_test() {
		$url = esc_url_raw( (string) get_option( 'nbh_performance_edge_purge_webhook_url', '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'nbh_performance_edge_webhook_missing', __( 'Configure an HTTPS edge purge webhook first.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$result = $this->send_signed_request(
			$url,
			array(
				'action'    => 'test',
				'site'      => home_url( '/' ),
				'timestamp' => time(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'   => true,
				'http_code' => $result,
			)
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_inbound_purge( WP_REST_Request $request ) {
		$verified = $this->verify_inbound_request( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$tags = $this->tags->normalize_tags( (array) $request->get_param( 'tags' ) );
		if ( empty( $tags ) ) {
			return new WP_Error( 'nbh_performance_edge_tags_missing', __( 'No valid edge-cache tags were supplied.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		do_action( 'nbh_performance_edge_inbound_purge_verified', $tags );
		$this->audit->record( 'edge_inbound_purge_verified', array( 'tag_count' => count( $tags ) ) );
		return rest_ensure_response(
			array(
				'success' => true,
				'tags'    => $tags,
			)
		);
	}

	/** @param array<int,string> $tags Tags. @param string $source Source. */
	public function dispatch_purge( $tags, $source = 'internal' ) {
		$url = esc_url_raw( (string) get_option( 'nbh_performance_edge_purge_webhook_url', '' ) );
		if ( '' === $url ) {
			return;
		}
		$payload = array(
			'action'    => 'purge_tags',
			'site'      => home_url( '/' ),
			'tags'      => $this->tags->normalize_tags( $tags ),
			'source'    => sanitize_key( $source ),
			'timestamp' => time(),
		);
		$result  = $this->send_signed_request( $url, $payload );
		$record  = array(
			'at'        => time(),
			'tags'      => count( $payload['tags'] ),
			'success'   => ! is_wp_error( $result ),
			'http_code' => is_wp_error( $result ) ? 0 : $result,
		);
		if ( is_wp_error( $result ) ) {
			$record['error'] = sanitize_text_field( $result->get_error_message() );
		}
		update_option( 'nbh_performance_edge_last_purge', $record, false );
		$this->audit->record(
			'edge_tag_purge_dispatched',
			array(
				'tag_count' => count( $payload['tags'] ),
				'success'   => $record['success'] ? 'yes' : 'no',
			),
			$record['success'] ? 'info' : 'warning'
		);
	}

	/** @param string $target Target. @param bool $success Success. */
	public function after_page_purge( $target, $success ) {
		if ( ! $success || ! get_option( 'nbh_performance_edge_html_cache_enabled', 0 ) ) {
			return;
		}
		$tags = 'all' === $target ? array( 'site-wide', 'site-' . get_current_blog_id() ) : array( 'site-' . get_current_blog_id(), 'url-' . substr( sha1( (string) $target ), 0, 20 ) );
		$this->dispatch_purge( $tags, 'page_cache' );
	}

	/** @param string $url URL. @param array<string,mixed> $payload Payload. @return int|WP_Error */
	private function send_signed_request( $url, array $payload ) {
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'nbh_performance_edge_https_required', __( 'The edge purge webhook must use HTTPS.', 'nbh-performance' ) );
		}
		$secret = (string) get_option( 'nbh_performance_edge_purge_secret', '' );
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error( 'nbh_performance_edge_secret_missing', __( 'Configure a 32-character or longer edge purge secret.', 'nbh-performance' ) );
		}
		$body      = wp_json_encode( $payload );
		$timestamp = time();
		$nonce     = wp_generate_password( 24, false, false );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret );
		$response  = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'                => 'application/json',
					'X-NBH-Performance-Timestamp' => (string) $timestamp,
					'X-NBH-Performance-Nonce'     => $nonce,
					'X-NBH-Performance-Signature' => $signature,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		/* translators: %d: HTTP response status code. */
		return $code >= 200 && $code < 300 ? $code : new WP_Error( 'nbh_performance_edge_webhook_failed', sprintf( __( 'The edge webhook returned HTTP %d.', 'nbh-performance' ), $code ) );
	}

	/** @param WP_REST_Request $request Request. @return true|WP_Error */
	private function verify_inbound_request( WP_REST_Request $request ) {
		$secret    = (string) get_option( 'nbh_performance_edge_purge_secret', '' );
		$timestamp = absint( $request->get_header( 'x-nbh-performance-timestamp' ) );
		$nonce     = sanitize_text_field( (string) $request->get_header( 'x-nbh-performance-nonce' ) );
		$signature = strtolower( sanitize_text_field( (string) $request->get_header( 'x-nbh-performance-signature' ) ) );
		$body      = (string) $request->get_body();
		if ( strlen( $secret ) < 32 || abs( time() - $timestamp ) > 300 || '' === $nonce || strlen( $nonce ) > 100 || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'nbh_performance_edge_signature_invalid', __( 'Invalid or expired edge purge signature.', 'nbh-performance' ), array( 'status' => 401 ) );
		}
		$key = 'nbh_performance_edge_nonce_' . md5( $nonce );
		if ( get_transient( $key ) ) {
			return new WP_Error( 'nbh_performance_edge_replay', __( 'This edge purge request has already been used.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'nbh_performance_edge_signature_invalid', __( 'Invalid edge purge signature.', 'nbh-performance' ), array( 'status' => 401 ) );
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}
