<?php
/**
 * Reliable Critical CSS and Used CSS generation coordinator.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CriticalCss;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;

final class CriticalCssQueue implements Module {
	private $context;
	private $generator;
	private $storage;
	private $used_storage;
	private $lock;
	private $unused_css;
	private $jobs;

	public function __construct( CriticalCssContext $context, HeadlessGeneratorClient $generator, CriticalCssStorage $storage, UsedCssStorage $used_storage, GenerationLock $lock, UnusedCssRemover $unused_css, JobQueue $jobs ) {
		$this->context      = $context;
		$this->generator    = $generator;
		$this->storage      = $storage;
		$this->used_storage = $used_storage;
		$this->lock         = $lock;
		$this->unused_css   = $unused_css;
		$this->jobs         = $jobs;
	}

	public function register() {
		add_action( 'init', array( $this, 'start_php_generation' ), 15 );
		add_action( 'save_post', array( $this, 'queue_post' ), 30, 2 );
		add_action( 'switch_theme', array( $this, 'queue_key_pages' ) );
		add_action( 'customize_save_after', array( $this, 'queue_key_pages' ) );
		add_action( 'upgrader_process_complete', array( $this, 'queue_key_pages' ), 30 );
		add_action( 'activated_plugin', array( $this, 'queue_key_pages' ), 30 );
		add_action( 'deactivated_plugin', array( $this, 'queue_key_pages' ), 30 );
		add_action( 'nbh_performance_browser_css_missing', array( $this, 'queue_missing_artifact' ), 10, 2 );
		add_action( 'nbh_performance_feature_toggles_updated', array( $this, 'maybe_queue_after_feature_change' ) );
	}

	/** Seed enabled PHP generation once per build, without an administrator page visit. */
	public function start_php_generation() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || PhpCssGenerator::is_source_request() || get_option( 'nbh_performance_safe_mode', 1 ) || ( ! get_option( 'nbh_performance_critical_css_enabled', 0 ) && ! get_option( 'nbh_performance_unused_css_enabled', 0 ) ) || NBH_PERFORMANCE_BUILD === get_option( 'nbh_performance_php_css_build', '' ) ) {
			return;
		}
		update_option( 'nbh_performance_php_css_build', NBH_PERFORMANCE_BUILD, false );
		$this->queue_key_pages();
	}

	public function queue_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ! is_object( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		foreach ( array_filter( array( get_permalink( $post_id ), home_url( '/' ) ) ) as $url ) {
			$this->enqueue( $url, 'content-update' );
		}
	}

	public function queue_key_pages() {
		$this->enqueue( home_url( '/' ), 'global-design-change' );
		$posts = get_posts(
			array(
				'post_type'     => array( 'page', 'post' ),
				'post_status'   => 'publish',
				'numberposts'   => 10,
				'orderby'       => 'modified',
				'order'         => 'DESC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		foreach ( $posts as $post_id ) {
			$this->enqueue( get_permalink( $post_id ), 'global-design-change', 30 );
		}
	}

	public function maybe_queue_after_feature_change( array $updated ) {
		if ( ! empty( $updated['nbh_performance_critical_css_enabled'] ) || ! empty( $updated['nbh_performance_unused_css_enabled'] ) ) {
			$this->queue_key_pages();
		}
	}

	public function queue_missing_artifact( $url, $reason = 'missing-or-stale' ) {
		$key = 'nbh_css_missing_' . substr( hash( 'sha256', strtolower( (string) $url ) ), 0, 32 );
		if ( get_transient( $key ) ) {
			return;
		}
		if ( $this->enqueue( $url, sanitize_key( $reason ) ) ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	public function enqueue( $url, $reason = 'manual', $delay = 0 ) {
		if ( PhpCssGenerator::is_source_request() ) {
			return false; }
		$url = esc_url_raw( $url );
		if ( ! $this->same_origin( $url ) ) {
			return false;
		}
		$post_id = url_to_postid( $url );
		if ( $post_id && $this->both_disabled_for_page( $post_id ) ) {
			return false;
		}
		$ids = array();
		foreach ( array( 'desktop', 'mobile' ) as $variant ) {
			$unique = 'critical-css:' . hash( 'sha256', strtolower( $url ) . '|' . $variant );
			$ids[]  = $this->jobs->enqueue(
				'critical_css_generate',
				array(
					'url'     => $url,
					'variant' => $variant,
					'reason'  => sanitize_key( $reason ),
				),
				array(
					'priority'     => 25,
					'max_attempts' => 4,
					'delay'        => absint( $delay ),
					'unique_key'   => $unique,
				)
			);
		}
		return (bool) array_filter( $ids );
	}

	/** Generate one URL/device item. Called only by the reliable job worker. */
	public function generate_item( $url, $variant, $reason = 'background' ) {
		$url     = esc_url_raw( $url );
		$variant = 'mobile' === sanitize_key( $variant ) ? 'mobile' : 'desktop';
		if ( ! $this->same_origin( $url ) ) {
			return new \WP_Error( 'nbh_performance_invalid_css_url', __( 'The CSS generation URL must use this site origin.', 'nbh-performance' ) );
		}

		$requested = $this->requested_features( $url );
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ( ! $requested['critical_css'] && ! $requested['used_css'] ) ) {
			return array(
				'success' => true,
				'skipped' => 'browser_css_disabled_or_safe_mode',
			);
		}

		$token = $this->lock->acquire( $url, $variant );
		if ( ! $token ) {
			return new \WP_Error( 'nbh_performance_css_generation_locked', __( 'This URL is already being processed.', 'nbh-performance' ) );
		}

		$request_id = '';
		try {
			$context = $this->context->resolve();
			$result  = $this->generator->generate( $url, $context, $variant, $requested );
			if ( is_wp_error( $result ) ) {
				$unsupported = array( 'nbh_performance_php_css_no_sources', 'nbh_performance_php_css_unsupported', 'nbh_performance_php_css_import', 'nbh_performance_php_css_inline_limit', 'nbh_performance_php_css_complex_source' );
				if ( in_array( $result->get_error_code(), $unsupported, true ) ) {
					$status = (array) get_option( 'nbh_performance_browser_css_status', array() );
					$status[ hash( 'sha256', strtolower( $url ) . '|' . $variant ) ] = array(
						'url'          => $url,
						'variant'      => $variant,
						'status'       => 'original-styles-kept',
						'message'      => $result->get_error_message(),
						'engine'       => PhpCssGenerator::MODE,
						'generated_at' => time(),
					);
					update_option( 'nbh_performance_browser_css_status', array_slice( $status, -200, null, true ), false );
					return array(
						'success' => true,
						'skipped' => $result->get_error_code(),
						'message' => $result->get_error_message(),
					);
				}
				return $result;
			}
			if ( ! is_array( $result ) ) {
				return new \WP_Error( 'nbh_performance_invalid_css_result', __( 'The browser engine returned an invalid CSS result.', 'nbh-performance' ) );
			}
			$request_id = sanitize_text_field( (string) ( $result['request_id'] ?? '' ) );
			if ( ! empty( $result['pending'] ) ) {
				$this->record_pending( $url, $variant, $reason, $request_id );
				return array(
					'success'    => true,
					'pending'    => true,
					'request_id' => $request_id,
					'url'        => $url,
					'variant'    => $variant,
				);
			}

			$critical_css = isset( $result['critical_css'] ) ? (string) $result['critical_css'] : '';
			$used_css     = isset( $result['used_css'] ) ? (string) $result['used_css'] : '';
			if ( $requested['critical_css'] && '' === trim( $critical_css ) ) {
				$this->generator->fail( $request_id, 'critical_css_missing', isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array() );
				return new \WP_Error( 'nbh_performance_invalid_css_result', __( 'The browser engine did not return Critical CSS.', 'nbh-performance' ) );
			}
			if ( $requested['used_css'] && '' === trim( $used_css ) ) {
				$this->generator->fail( $request_id, 'used_css_missing', isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array() );
				return new \WP_Error( 'nbh_performance_invalid_used_css_result', __( 'The browser engine did not return Used CSS.', 'nbh-performance' ) );
			}

			$critical_stored = false;
			if ( $requested['critical_css'] ) {
				if ( ! $this->storage->put( $url, $critical_css, $context, $variant, PhpCssGenerator::validates_result( $result ) ? $result : array() ) ) {
					$this->generator->fail( $request_id, 'critical_css_store_failed' );
					return new \WP_Error( 'nbh_performance_critical_css_store_failed', __( 'Critical CSS could not be stored.', 'nbh-performance' ) );
				}
				$critical_stored = true;
				do_action( 'nbh_performance_critical_css_generated', $url, $critical_css, $context, $variant );
			}

			$used_stored = $requested['used_css'] ? $this->store_used_css( $url, $context, $variant, $result ) : false;
			if ( $requested['used_css'] && ! $used_stored ) {
				$this->generator->fail( $request_id, 'used_css_validation_failed', isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array() );
				return new \WP_Error( 'nbh_performance_used_css_validation_failed', __( 'Used CSS was blocked by the safety validator.', 'nbh-performance' ) );
			}

			$this->record_success( $url, $variant, $reason, $result, $used_stored );
			$this->generator->complete( $request_id, $result );
			do_action( 'nbh_performance_css_artifacts_updated', $url );
			return array(
				'success'             => true,
				'url'                 => $url,
				'variant'             => $variant,
				'request_id'          => $request_id,
				'critical_css_stored' => $critical_stored,
				'critical_bytes'      => $critical_stored ? strlen( $critical_css ) : 0,
				'used_css_stored'     => (bool) $used_stored,
				'used_bytes'          => $used_stored ? strlen( $used_css ) : 0,
			);
		} catch ( \Throwable $error ) {
			if ( $request_id ) {
				$this->generator->fail( $request_id, 'unexpected_exception' );
			}
			return new \WP_Error( 'nbh_performance_browser_css_exception', $error->getMessage() );
		} finally {
			$this->lock->release( $url, $variant, $token );
		}
	}

	/** Determine exactly which artifacts may be generated for one URL. */
	private function requested_features( $url ) {
		$post_id = url_to_postid( $url );
		return array(
			'critical_css' => (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ) && ! ( $post_id && get_post_meta( $post_id, '_nbh_performance_disable_critical_css', true ) ),
			'used_css'     => (bool) get_option( 'nbh_performance_unused_css_enabled', 0 ) && ! ( $post_id && get_post_meta( $post_id, '_nbh_performance_disable_unused_css', true ) ),
		);
	}

	private function both_disabled_for_page( $post_id ) {
		$critical = get_post_meta( $post_id, '_nbh_performance_disable_critical_css', true );
		$used     = get_post_meta( $post_id, '_nbh_performance_disable_unused_css', true );
		return ( ! get_option( 'nbh_performance_critical_css_enabled', 0 ) || $critical ) && ( ! get_option( 'nbh_performance_unused_css_enabled', 0 ) || $used );
	}

	private function store_used_css( $url, $context, $variant, array $result ) {
		$metrics  = isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array();
		$full_css = isset( $result['full_css'] ) ? (string) $result['full_css'] : '';
		$used_css = isset( $result['used_css'] ) ? (string) $result['used_css'] : '';
		if ( '' === trim( $used_css ) && '' !== trim( $full_css ) && ! empty( $result['used_selectors'] ) ) {
			$used_css = $this->unused_css->process( $full_css, (array) $result['used_selectors'], $metrics );
		}
		if ( ! PhpCssGenerator::validates_result( $result ) && ! $this->unused_css->validate_generated( $full_css, $used_css, $metrics ) ) {
			$this->record_visual_failure( $url, $variant, $metrics, (string) ( $result['request_id'] ?? '' ) );
			return false;
		}
		$assets = isset( $result['stylesheets'] ) && is_array( $result['stylesheets'] ) ? $result['stylesheets'] : array();
		if ( $this->used_storage->put( $url, $used_css, $assets, $context, $variant, PhpCssGenerator::validates_result( $result ) ? $result : array() ) ) {
			do_action( 'nbh_performance_used_css_generated', $url, $used_css, $context, $variant, $assets );
			return true;
		}
		return false;
	}

	private function record_visual_failure( $url, $variant, array $metrics, $request_id = '' ) {
		$failures = (array) get_option( 'nbh_performance_visual_regression_failures', array() );
		array_unshift(
			$failures,
			array(
				'url'        => esc_url_raw( $url ),
				'variant'    => sanitize_key( $variant ),
				'request_id' => sanitize_text_field( $request_id ),
				'metrics'    => $this->bounded_metrics( $metrics ),
				'failed_at'  => time(),
			)
		);
		update_option( 'nbh_performance_visual_regression_failures', array_slice( $failures, 0, 50 ), false );
		do_action( 'nbh_performance_visual_regression_blocked_used_css', $url, $variant, $metrics );
	}

	private function record_pending( $url, $variant, $reason, $request_id ) {
		$status = (array) get_option( 'nbh_performance_browser_css_status', array() );
		$status[ hash( 'sha256', strtolower( $url ) . '|' . $variant ) ] = array(
			'url'          => esc_url_raw( $url ),
			'variant'      => sanitize_key( $variant ),
			'reason'       => sanitize_key( $reason ),
			'request_id'   => sanitize_text_field( $request_id ),
			'pending'      => true,
			'generated_at' => time(),
		);
		update_option( 'nbh_performance_browser_css_status', array_slice( $status, -200, null, true ), false );
	}

	private function record_success( $url, $variant, $reason, array $result, $used ) {
		$status = (array) get_option( 'nbh_performance_browser_css_status', array() );
		$status[ hash( 'sha256', strtolower( $url ) . '|' . $variant ) ] = array(
			'url'             => esc_url_raw( $url ),
			'variant'         => sanitize_key( $variant ),
			'reason'          => sanitize_key( $reason ),
			'request_id'      => sanitize_text_field( (string) ( $result['request_id'] ?? '' ) ),
			'used_css_stored' => (bool) $used,
			'engine'          => isset( $result['engine'] ) ? sanitize_key( $result['engine'] ) : 'browser',
			'visual_verified' => ! PhpCssGenerator::validates_result( $result ),
			'pending'         => false,
			'metrics'         => isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $this->bounded_metrics( $result['metrics'] ) : array(),
			'generated_at'    => time(),
		);
		update_option( 'nbh_performance_browser_css_status', array_slice( $status, -200, null, true ), false );
	}

	private function bounded_metrics( array $metrics ) {
		$allowed = array( 'visual_difference', 'missing_nodes', 'visible_node_delta', 'dom_node_delta', 'page_height_delta', 'original_bytes', 'used_bytes', 'critical_bytes', 'coverage', 'layout_shift', 'viewport_width', 'viewport_height', 'baseline_sha256', 'optimized_sha256', 'engine_version', 'duration_ms' );
		$out     = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $metrics ) ) {
				continue;
			}
			$value       = $metrics[ $key ];
			$out[ $key ] = is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 128 ) : ( is_numeric( $value ) ? (float) $value : (bool) $value );
		}
		return $out;
	}

	private function same_origin( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		if ( ! is_array( $home ) || ! is_array( $test ) || ! empty( $test['query'] ) || ! empty( $test['fragment'] ) || ! empty( $test['user'] ) || ! empty( $test['pass'] ) ) {
			return false;
		}
		return strtolower( (string) ( $home['scheme'] ?? '' ) ) === strtolower( (string) ( $test['scheme'] ?? '' ) )
			&& strtolower( (string) ( $home['host'] ?? '' ) ) === strtolower( (string) ( $test['host'] ?? '' ) )
			&& absint( $home['port'] ?? 0 ) === absint( $test['port'] ?? 0 );
	}
}
