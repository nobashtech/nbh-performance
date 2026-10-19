<?php
/** Privacy-safe RUM token and ingestion endpoints. @package NBHPerformance */
namespace NBH\Performance\Modules\Rum;

use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RumIngestionController implements Module {
	private $storage;
	private $limiter;
	private $token;
	public function __construct( RumStorage $storage, RumRateLimiter $limiter, RumToken $token ) {
		$this->storage = $storage;
		$this->limiter = $limiter;
		$this->token   = $token;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/rum/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'issue_token' ),
				'permission_callback' => '__return_true',
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/rum',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ingest' ),
				'permission_callback' => '__return_true',
			)
		);
	}
	public function issue_token( WP_REST_Request $request ) {
		if ( ! get_option( 'nbh_performance_rum_enabled', 0 ) ) {
			return new WP_Error( 'nbh_performance_rum_disabled', __( 'RUM is disabled.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		$error = $this->validate_input( $request, array( 'context', 'path' ) );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$context = $this->context( $request->get_param( 'context' ) );
		$path    = $this->canonical_path( $request->get_param( 'path' ) );
		$hash    = hash( 'sha256', home_url( $path ) );
		$signed  = $this->token->create( $hash, $context );
		return new WP_REST_Response(
			array(
				'urlHash'   => $hash,
				'context'   => $context,
				'timestamp' => $signed['timestamp'],
				'signature' => $signed['signature'],
			),
			200,
			array( 'Cache-Control' => 'no-store, private' )
		);
	}
	public function ingest( WP_REST_Request $request ) {
		if ( ! get_option( 'nbh_performance_rum_enabled', 0 ) || ! $this->limiter->allow() ) {
			return new WP_Error( 'nbh_performance_rum_rejected', __( 'RUM request rejected.', 'nbh-performance' ), array( 'status' => 429 ) );
		}
		$error = $this->validate_input( $request, array( 'urlHash', 'context', 'timestamp', 'signature', 'device', 'connection', 'lcp', 'inp', 'cls', 'ttfb', 'fcp' ) );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$url_hash  = strtolower( sanitize_text_field( (string) $request->get_param( 'urlHash' ) ) );
		$context   = $this->context( $request->get_param( 'context' ) );
		$timestamp = absint( $request->get_param( 'timestamp' ) );
		$signature = sanitize_text_field( (string) $request->get_param( 'signature' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $url_hash ) || ! $this->token->verify( $timestamp, $signature, $url_hash, $context ) ) {
			return new WP_Error( 'nbh_performance_rum_invalid', __( 'Invalid RUM signature.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$record = array(
			'collected_at' => current_time( 'mysql', true ),
			'url_hash'     => $url_hash,
			'context'      => $context,
			'device'       => $this->enum( $request->get_param( 'device' ), array( 'mobile', 'tablet', 'desktop' ), 'desktop' ),
			'connection'   => $this->enum( $request->get_param( 'connection' ), array( 'slow-2g', '2g', '3g', '4g', 'unknown' ), 'unknown' ),
			'lcp'          => $this->metric( $request->get_param( 'lcp' ), 60000 ),
			'inp'          => $this->metric( $request->get_param( 'inp' ), 60000 ),
			'cls'          => $this->metric( $request->get_param( 'cls' ), 10 ),
			'ttfb'         => $this->metric( $request->get_param( 'ttfb' ), 60000 ),
			'fcp'          => $this->metric( $request->get_param( 'fcp' ), 60000 ),
		);
		if ( null === $record['lcp'] && null === $record['inp'] && null === $record['cls'] && null === $record['ttfb'] && null === $record['fcp'] ) {
			return new WP_Error( 'nbh_performance_rum_empty', __( 'No valid performance measurements were supplied.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		if ( ! $this->storage->insert( $record ) ) {
			return new WP_Error( 'nbh_performance_rum_storage_failed', __( 'RUM storage is temporarily unavailable.', 'nbh-performance' ), array( 'status' => 503 ) );
		}
		do_action( 'nbh_performance_rum_recorded', $record );
		return new WP_REST_Response( array( 'accepted' => true ), 202 );
	}
	private function validate_input( WP_REST_Request $request, array $fields ) {
		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value && ! is_scalar( $value ) ) {
				return new WP_Error( 'nbh_performance_rum_invalid_input', __( 'RUM fields must contain scalar values.', 'nbh-performance' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}
	private function canonical_path( $path ) {
		$path = rawurldecode( (string) $path );
		$path = wp_parse_url( $path, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		$path = preg_replace( '#/+#', '/', str_replace( array( "\0", '\\' ), array( '', '/' ), $path ) );
		$path = preg_replace( '#(?:^|/)\.\.(?:/|$)#', '/', $path );
		return '/' . ltrim( (string) $path, '/' );
	}
	private function context( $value ) {
		return $this->enum( $value, array( 'home', 'product', 'single', 'archive', 'search', 'other' ), 'other' );
	}
	private function metric( $value, $max ) {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		return ( $value >= 0 && $value <= $max ) ? $value : null;
	}
	private function enum( $value, array $allowed, $fallback ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
