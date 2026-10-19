<?php
/** Post-change frontend validation for staging and production-safe smoke tests. @package NBHPerformance */
namespace NBH\Performance\Modules\Validation;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class StagingValidator implements Module {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/validation/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/validation/run',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_run' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}
	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}
	public function status() {
		return rest_ensure_response( get_option( 'nbh_performance_last_staging_validation', array() ) );
	}
	public function rest_run() {
		return rest_ensure_response( $this->run( array( 'source' => 'manual' ) ) );
	}

	/** Run bounded, read-only HTTP smoke and cache-isolation tests. */
	public function run( array $context = array() ) {
		$results              = array();
		$critical_failures    = 0;
		$warnings             = 0;
		$transport_failures   = 0;
		$rollback_recommended = false;
		foreach ( $this->validation_urls() as $url ) {
			$result    = $this->check_url( $url );
			$results[] = $result;
			if ( 'critical' === $result['severity'] ) {
				++$critical_failures;
			}
			if ( 'warning' === $result['severity'] ) {
				++$warnings;
			}
			if ( 'transport_error' === $result['code'] ) {
				++$transport_failures;
			}
			if ( in_array( $result['code'], array( 'fatal_signature', 'server_error', 'invalid_body', 'dynamic_cache_hit', 'origin_mismatch' ), true ) ) {
				$rollback_recommended = true;
			}
		}
		$report = array(
			'passed'               => 0 === $critical_failures,
			'environment'          => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'is_staging'           => $this->is_staging(),
			'critical_failures'    => $critical_failures,
			'warnings'             => $warnings,
			'transport_failures'   => $transport_failures,
			'rollback_recommended' => $rollback_recommended,
			'checked_urls'         => count( $results ),
			'results'              => $results,
			'context'              => $this->sanitize_context( $context ),
			'checked_at'           => time(),
		);
		update_option( 'nbh_performance_last_staging_validation', $report, false );
		do_action( 'nbh_performance_staging_validation_completed', $report );
		return $report;
	}

	public function is_staging() {
		if ( get_option( 'nbh_performance_force_staging_validation', 0 ) ) {
			return true;
		}
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return in_array( $type, array( 'local', 'development', 'staging' ), true );
	}

	private function validation_urls() {
		$urls   = array( home_url( '/' ) );
		$custom = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_validation_urls', '' ) );
		foreach ( (array) $custom as $url ) {
			if ( wp_http_validate_url( $url ) ) {
				$urls[] = esc_url_raw( $url );
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
				$url = wc_get_page_permalink( $page );
				if ( wp_http_validate_url( $url ) ) {
					$urls[] = $url;
				}
			}
		}
		$host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$urls   = array_filter(
			array_unique( $urls ),
			static function ( $url ) use ( $host, $scheme ) {
				return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host && strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) === $scheme;
			}
		);
		return array_slice( array_values( $urls ), 0, 12 );
	}

	private function check_url( $url ) {
		$dynamic = $this->is_dynamic_commerce_url( $url );
		$first   = $this->request( $url, $dynamic );
		if ( is_wp_error( $first ) ) {
			return array(
				'url'      => $url,
				'passed'   => false,
				'severity' => 'critical',
				'code'     => 'transport_error',
				'status'   => 0,
				'message'  => $first->get_error_message(),
				'cache'    => '',
				'probes'   => 1,
			);
		}
		$evaluated = $this->evaluate_response( $url, $first, $dynamic );
		if ( 'critical' === $evaluated['severity'] ) {
			return $evaluated;
		}

		$second = $this->request( $url, $dynamic );
		if ( is_wp_error( $second ) ) {
			$evaluated['passed']   = false;
			$evaluated['severity'] = 'warning';
			$evaluated['code']     = 'second_probe_transport';
			$evaluated['message']  = 'The first response was valid, but the second cache-isolation probe failed.';
			$evaluated['probes']   = 2;
			return $evaluated;
		}
		$second_eval = $this->evaluate_response( $url, $second, $dynamic );
		if ( 'critical' === $second_eval['severity'] ) {
			$second_eval['probes'] = 2;
			return $second_eval;
		}

		$first_cache                     = strtoupper( (string) wp_remote_retrieve_header( $first, 'x-nbh-performance' ) );
		$second_cache                    = strtoupper( (string) wp_remote_retrieve_header( $second, 'x-nbh-performance' ) );
		$evaluated['cache']              = $second_cache;
		$evaluated['probes']             = 2;
		$evaluated['first_cache']        = $first_cache;
		$evaluated['second_cache']       = $second_cache;
		$evaluated['first_body_sha256']  = hash( 'sha256', (string) wp_remote_retrieve_body( $first ) );
		$evaluated['second_body_sha256'] = hash( 'sha256', (string) wp_remote_retrieve_body( $second ) );

		if ( $dynamic && ( 'HIT' === $first_cache || 'HIT' === $second_cache || 'STALE' === $first_cache || 'STALE' === $second_cache ) ) {
			$evaluated['passed']   = false;
			$evaluated['severity'] = 'critical';
			$evaluated['code']     = 'dynamic_cache_hit';
			$evaluated['message']  = 'A WooCommerce transactional route was served from page cache.';
			return $evaluated;
		}
		if ( ! $dynamic && get_option( 'nbh_performance_cache_enabled', 0 ) && ! get_option( 'nbh_performance_safe_mode', 1 ) && ! in_array( $second_cache, array( 'HIT', 'STALE', 'STALE-ERROR', 'REVALIDATING' ), true ) ) {
			$evaluated['severity'] = 'warning';
			$evaluated['code']     = 'cache_probe_miss';
			$evaluated['message']  = 'The page was healthy, but the second request was not served from NBH page cache.';
		}
		return $evaluated;
	}

	private function request( $url, $dynamic ) {
		$headers = array(
			'X-NBH-Validation-Probe' => wp_generate_uuid4(),
		);
		if ( $dynamic ) {
			$headers['Cookie'] = 'woocommerce_cart_hash=nbh-validation; woocommerce_items_in_cart=1; wp_woocommerce_session_nbh=validation';
		}
		$started  = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'headers'             => $headers,
				'user-agent'          => 'NBH Performance Runtime Validator/' . NBH_PERFORMANCE_VERSION,
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$response['nbh_duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		}
		return $response;
	}

	private function evaluate_response( $url, $response, $dynamic ) {
		$status       = (int) wp_remote_retrieve_response_code( $response );
		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$cache        = (string) wp_remote_retrieve_header( $response, 'x-nbh-performance' );
		$fatal        = (bool) preg_match( '/(?:fatal error|uncaught (?:error|exception)|there has been a critical error)/i', substr( $body, 0, 500000 ) );
		$final_url    = (string) wp_remote_retrieve_header( $response, 'x-nbh-final-url' );
		if ( '' !== $final_url && strtolower( (string) wp_parse_url( $final_url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
			return array(
				'url'      => $url,
				'passed'   => false,
				'severity' => 'critical',
				'code'     => 'origin_mismatch',
				'status'   => $status,
				'message'  => 'The request was redirected outside the configured site origin.',
				'cache'    => $cache,
			);
		}
		if ( $status >= 500 || $fatal || '' === trim( $body ) ) {
			return array(
				'url'         => $url,
				'passed'      => false,
				'severity'    => 'critical',
				'code'        => $fatal ? 'fatal_signature' : ( $status >= 500 ? 'server_error' : 'invalid_body' ),
				'status'      => $status,
				'message'     => $fatal ? 'A PHP fatal-error signature was detected.' : 'The page returned an invalid response.',
				'cache'       => $cache,
				'duration_ms' => isset( $response['nbh_duration_ms'] ) ? $response['nbh_duration_ms'] : 0,
			);
		}
		if ( $status >= 400 || false === strpos( $content_type, 'text/html' ) ) {
			return array(
				'url'         => $url,
				'passed'      => false,
				'severity'    => 'warning',
				'code'        => 'non_html_response',
				'status'      => $status,
				'message'     => 'The page did not return a normal HTML response.',
				'cache'       => $cache,
				'duration_ms' => isset( $response['nbh_duration_ms'] ) ? $response['nbh_duration_ms'] : 0,
			);
		}
		if ( $dynamic && in_array( strtoupper( $cache ), array( 'HIT', 'STALE', 'STALE-ERROR' ), true ) ) {
			return array(
				'url'      => $url,
				'passed'   => false,
				'severity' => 'critical',
				'code'     => 'dynamic_cache_hit',
				'status'   => $status,
				'message'  => 'A dynamic WooCommerce route was served from page cache.',
				'cache'    => $cache,
			);
		}
		return array(
			'url'         => $url,
			'passed'      => true,
			'severity'    => 'ok',
			'code'        => 'ok',
			'status'      => $status,
			'message'     => 'Page responded normally.',
			'cache'       => $cache,
			'bytes'       => strlen( $body ),
			'duration_ms' => isset( $response['nbh_duration_ms'] ) ? $response['nbh_duration_ms'] : 0,
		);
	}

	private function is_dynamic_commerce_url( $url ) {
		$path = untrailingslashit( '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		foreach ( array( '/cart', '/checkout', '/my-account', '/order-pay', '/order-received', '/wc-api', '/wp-json/wc/store' ) as $fragment ) {
			if ( false !== stripos( $path, $fragment ) ) {
				return true;
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$dynamic_url  = wc_get_page_permalink( $page );
				$dynamic_path = untrailingslashit( '/' . ltrim( (string) wp_parse_url( $dynamic_url, PHP_URL_PATH ), '/' ) );
				if ( '' !== $dynamic_path && $path === $dynamic_path ) {
					return true;
				}
			}
		}
		return false;
	}

	private function sanitize_context( array $context ) {
		$clean = array();
		foreach ( array_slice( $context, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}
		return $clean;
	}
}
