<?php
/**
 * Enterprise public page-cache orchestration.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Compatibility\CompatibilityManager;
use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use NBH\Performance\Modules\Security\PurgeEndpointRateLimiter;
use NBH\Performance\Modules\Reliability\RuntimeReliabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class PageCacheManager implements Module {
	private $compatibility;
	private $eligibility;
	private $writer;
	private $index;
	private $variants;
	private $preload;
	private $lock;
	private $metadata;
	private $capturing         = false;
	private $finalized         = false;
	private $context           = array();
	private $lock_handle       = false;
	private $refresh_lock_file = '';

	public function __construct( CompatibilityManager $compatibility, RequestEligibility $eligibility, CacheWriter $writer, CacheIndex $index, CacheVariantResolver $variants, PreloadQueue $preload, CacheLock $lock, CacheResponseMetadata $metadata ) {
		$this->compatibility = $compatibility;
		$this->eligibility   = $eligibility;
		$this->writer        = $writer;
		$this->index         = $index;
		$this->variants      = $variants;
		$this->preload       = $preload;
		$this->lock          = $lock;
		$this->metadata      = $metadata;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'start_capture' ), PHP_INT_MAX );
		add_action( 'save_post', array( $this, 'purge_after_content_change' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'purge_before_content_delete' ), 20, 2 );
		add_action( 'edited_terms', array( $this, 'purge_after_term_change' ), 20, 2 );
		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'update_option_nbh_performance_cache_enabled', array( $this, 'cache_setting_changed' ), 20, 2 );
		add_action( 'nbh_performance_warm_home', array( $this, 'warm_home' ) );
		add_action( 'nbh_performance_css_artifacts_updated', array( $this, 'purge_url' ) );
		add_action( 'nbh_performance_request_full_purge', array( $this, 'requested_full_purge' ), 10, 1 );
	}

	public function register_routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/cache/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( $this, 'can_manage_status' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/cache/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_purge' ),
				'permission_callback' => array( $this, 'can_purge' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/cache/repair',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_repair' ),
				'permission_callback' => array( $this, 'can_purge' ),
			)
		);
	}

	/** Hub status for the dedicated Page Cache workspace. @param WP_REST_Request $request Request. @return bool|WP_Error */
	public function can_manage_status( WP_REST_Request $request ) {
		if ( ! CapabilityGuard::can_manage() || ! NonceGuard::verify_rest( $request ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to view page-cache status.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** @return WP_REST_Response */
	public function rest_status() {
		return new WP_REST_Response( $this->status(), 200 );
	}

	/** @return array<string,mixed> */
	public function status() {
		$dropin        = WP_CONTENT_DIR . '/advanced-cache.php';
		$dropin_exists = is_file( $dropin );
		$installer     = new DropInInstaller();
		$dropin_owned  = $dropin_exists && $installer->is_ours( $dropin );
		$runtime       = WP_CONTENT_DIR . '/cache/nbh-performance/runtime.json';
		$pages         = WP_CONTENT_DIR . '/cache/nbh-performance/pages';
		$enabled       = (bool) get_option( 'nbh_performance_cache_enabled', false );
		$safe_mode     = (bool) get_option( 'nbh_performance_safe_mode', true );
		$mobile        = (bool) get_option( 'nbh_performance_mobile_cache_enabled', false );
		$stats         = CacheStatistics::reconcile( $this->index );
		$last_warm     = get_option( 'nbh_performance_last_warm_result', array() );
		$last_write    = get_option( 'nbh_performance_last_cache_write', array() );
		$last_warm     = is_array( $last_warm ) ? $last_warm : array();
		$last_write    = is_array( $last_write ) ? $last_write : array();
		$wp_cache      = defined( 'WP_CACHE' ) && WP_CACHE;
		$runtime_ready = $enabled && ! $safe_mode && ! is_multisite() && $dropin_owned && $wp_cache && is_readable( $runtime );
		$ttl           = max( 60, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_cache_ttl', HOUR_IN_SECONDS ) ) ) );

		return array(
			'enabled'          => $enabled,
			'mobile_enabled'   => $mobile,
			'safe_mode'        => $safe_mode,
			'multisite'        => is_multisite(),
			'runtime_ready'    => $runtime_ready,
			'wp_cache'         => $wp_cache,
			'dropin_exists'    => $dropin_exists,
			'dropin_owned'     => $dropin_owned,
			'dropin_conflict'  => $dropin_exists && ! $dropin_owned,
			'runtime_readable' => is_readable( $runtime ),
			'pages_writable'   => is_dir( $pages ) ? \NBH\Performance\Modules\Core\Filesystem::is_writable( $pages ) : \NBH\Performance\Modules\Core\Filesystem::is_writable( WP_CONTENT_DIR ),
			'ttl'              => $ttl,
			'stats'            => array(
				'files'      => isset( $stats['files'] ) ? absint( $stats['files'] ) : 0,
				'bytes'      => isset( $stats['bytes'] ) ? absint( $stats['bytes'] ) : 0,
				'updated_at' => isset( $stats['updated_at'] ) ? absint( $stats['updated_at'] ) : 0,
			),
			'last_warm'        => array(
				'success'    => ! empty( $last_warm['success'] ),
				'checked_at' => isset( $last_warm['checked_at'] ) ? absint( $last_warm['checked_at'] ) : ( isset( $last_warm['at'] ) ? absint( $last_warm['at'] ) : 0 ),
				'message'    => isset( $last_warm['message'] ) ? sanitize_text_field( (string) $last_warm['message'] ) : '',
			),
			'last_write'       => array(
				'success'    => ! empty( $last_write['success'] ),
				'checked_at' => isset( $last_write['checked_at'] ) ? absint( $last_write['checked_at'] ) : ( isset( $last_write['at'] ) ? absint( $last_write['at'] ) : 0 ),
				'url'        => isset( $last_write['url'] ) ? esc_url_raw( (string) $last_write['url'] ) : '',
			),
			'ops_available'    => array(
				'purge'  => true,
				'repair' => ! is_multisite(),
				'warm'   => $enabled && ! $safe_mode && ! is_multisite(),
			),
		);
	}

	/** Begin one locked cache-generation transaction. */
	public function start_capture() {
		if ( is_multisite() || ! get_option( 'nbh_performance_cache_enabled', false ) || get_option( 'nbh_performance_safe_mode', true ) || RuntimeReliabilityManager::is_degraded() || ! $this->eligibility->is_request_cacheable() ) {
			return;
		}

		$this->context = $this->build_context();
		if ( empty( $this->context['file'] ) ) {
			return;
		}

		$revalidation_handle = isset( $GLOBALS['nbh_performance_revalidation_lock'] ) ? $GLOBALS['nbh_performance_revalidation_lock'] : false;
		$revalidation_cache  = isset( $GLOBALS['nbh_performance_revalidation_cache_file'] ) ? wp_normalize_path( $GLOBALS['nbh_performance_revalidation_cache_file'] ) : '';
		if ( is_resource( $revalidation_handle ) && wp_normalize_path( $this->context['file'] ) === $revalidation_cache ) {
			$this->lock_handle       = $revalidation_handle;
			$this->refresh_lock_file = isset( $GLOBALS['nbh_performance_revalidation_file'] ) ? wp_normalize_path( $GLOBALS['nbh_performance_revalidation_file'] ) : '';
		} else {
			if ( is_resource( $revalidation_handle ) ) {
				$this->lock->release( $revalidation_handle, isset( $GLOBALS['nbh_performance_revalidation_file'] ) ? $GLOBALS['nbh_performance_revalidation_file'] : '' );
			}
			$this->lock_handle = $this->lock->acquire( $this->context['file'] );
			if ( false !== $this->lock_handle ) {
				$this->refresh_lock_file = $this->context['file'] . '.lock';
			}
		}

		if ( false === $this->lock_handle ) {
			return;
		}

		$this->capturing = true;
		register_shutdown_function( array( $this, 'shutdown_handler' ) );
		if ( get_option( 'nbh_performance_debug_headers', false ) && ! headers_sent() ) {
			header( 'X-NBH-Performance: ' . ( defined( 'NBH_PERFORMANCE_REVALIDATING' ) ? 'REVALIDATING' : 'MISS' ), true );
		}
		ob_start( array( $this, 'capture_callback' ) );
	}

	/** Commit or abort the cache-generation transaction at final output. */
	public function capture_callback( $html, $phase = 0 ) {
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ! $this->capturing ) {
			return $html;
		}

		$this->finalized = true;
		$status          = http_response_code();
		$status          = false === $status ? 0 : (int) $status;

		try {
			if ( $this->eligibility->is_response_cacheable() ) {
				$content     = apply_filters( 'nbh_performance_before_cache_write', $html, $this->context['url'], $this->context['file'] );
				$is_redirect = in_array( $status, array( 301, 308 ), true );
				if ( is_string( $content ) && ( $is_redirect || '' !== trim( $content ) ) ) {
					$metadata = $this->metadata->build( $content, $this->context['url'], $this->context['variants'], $this->resolve_ttl() );
					$written  = $this->writer->write_entry( $this->context['file'], $content, $metadata );
					if ( false !== $written ) {
						$this->index->record( $this->context['key'], $this->context['file'], $this->context['url'], $this->dependencies(), $this->context['variants'], $metadata );
						$stats = CacheStatistics::reconcile( $this->index );
						update_option(
							'nbh_performance_last_cache_write',
							array(
								'success'     => true,
								'file'        => wp_normalize_path( $this->context['file'] ),
								'url'         => $this->context['url'],
								'bytes'       => strlen( $content ),
								'compression' => isset( $written['compression'] ) ? $written['compression'] : array(),
								'expires_at'  => $metadata['expires_at'],
								'cache'       => $stats,
								'written_at'  => time(),
							),
							false
						);
					} else {
						$this->store_write_failure( 'write_failed' );
					}
				}
			} elseif ( $status >= 500 ) {
				$this->writer->mark_failure( $this->context['file'], 'origin_http_error', $status );
				$this->store_write_failure( 'origin_http_error', $status );
			}
		} catch ( \Throwable $exception ) {
			$this->writer->mark_failure( $this->context['file'], 'cache_exception', $status );
			$this->store_write_failure( 'cache_exception', $status, $exception->getMessage() );
		}

		$this->release_generation_lock();
		return $html;
	}

	/** Record fatal generation failures and release crash-safe refresh locks. */
	public function shutdown_handler() {
		if ( $this->finalized || ! $this->capturing ) {
			return;
		}
		$error = error_get_last();
		$fatal = is_array( $error ) && in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
		if ( ! $fatal ) {
			return;
		}

		if ( ! empty( $this->context['file'] ) ) {
			$this->writer->mark_failure( $this->context['file'], 'fatal_generation_error', 500 );
			$this->store_write_failure( 'fatal_generation_error', 500 );
		}
		$this->release_generation_lock();
	}

	public function can_purge( WP_REST_Request $request ) {
		if ( ! CapabilityGuard::can_manage() || ! NonceGuard::verify_rest( $request ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to purge this cache.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$limiter = new PurgeEndpointRateLimiter();
		return $limiter->allow( get_current_user_id() . '|' . $this->request_ip() ) ? true : new WP_Error( 'nbh_performance_rate_limited', __( 'Too many purge requests. Try again shortly.', 'nbh-performance' ), array( 'status' => 429 ) );
	}

	public function rest_purge( WP_REST_Request $request ) {
		$url    = esc_url_raw( (string) $request->get_param( 'url' ) );
		$result = '' === $url ? $this->purge_all() : $this->purge_url( $url );
		return new WP_REST_Response( array( 'success' => $result ), $result ? 200 : 500 );
	}

	/** Repair bootstrap, runtime configuration, index orphans and homepage cache. */
	public function rest_repair() {
		$bootstrap = ( new CacheBootstrapManager() )->ensure();
		$runtime   = ( new RuntimeConfigManager() )->write();
		$orphans   = $this->index->repair_orphans( 5000 );
		$warmed    = $this->warm_home();
		$stats     = CacheStatistics::reconcile( $this->index, true );
		$success   = ! empty( $bootstrap['directories_ready'] ) && ! empty( $bootstrap['dropin_installed'] ) && $runtime;
		return new WP_REST_Response(
			array(
				'success'    => $success,
				'bootstrap'  => $bootstrap,
				'runtime'    => $runtime,
				'orphans'    => $orphans,
				'warmed'     => $warmed,
				'cache'      => $stats,
				'last_warm'  => get_option( 'nbh_performance_last_warm_result', array() ),
				'last_write' => get_option( 'nbh_performance_last_cache_write', array() ),
			),
			$success ? 200 : 500
		);
	}

	public function purge_after_content_change( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === get_post_status( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$dependencies = array( 'home', 'post-' . $post_id, 'post-type-' . $post->post_type, 'author-' . $post->post_author );
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_id ) {
					$dependencies[] = 'term-' . $term_id;
				}
			}
		}
		$this->index->purge_dependencies( $dependencies );
		$this->preload->enqueue( array_filter( array( home_url( '/' ), get_permalink( $post_id ), get_post_type_archive_link( $post->post_type ) ) ), 5 );
	}

	/** Purge dependencies while the deleted post object is still available. */
	public function purge_before_content_delete( $post_id, $post = null ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}
		if ( ! is_object( $post ) ) {
			$post = get_post( $post_id );
		}
		$dependencies = array( 'home', 'post-' . $post_id );
		$warm_urls    = array( home_url( '/' ) );
		if ( is_object( $post ) ) {
			$dependencies[] = 'post-type-' . sanitize_key( $post->post_type );
			$dependencies[] = 'author-' . absint( $post->post_author );
			$archive        = get_post_type_archive_link( $post->post_type );
			if ( $archive ) {
				$warm_urls[] = $archive;
			}
		}
		$this->index->purge_dependencies( array_values( array_unique( $dependencies ) ) );
		$this->preload->enqueue( array_values( array_unique( array_filter( $warm_urls ) ) ), 10 );
	}

	public function purge_after_term_change( $term_id, $taxonomy ) {
		$this->index->purge_dependencies( array( 'home', 'term-' . absint( $term_id ), 'taxonomy-' . sanitize_key( $taxonomy ) ) );
		$term_link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $term_link ) ) {
			$this->preload->enqueue( array( home_url( '/' ), $term_link ), 10 );
		}
	}

	public function purge_all() {
		do_action( 'nbh_performance_before_cache_purge', 'all' );
		$result = $this->delete_tree_contents( WP_CONTENT_DIR . '/cache/nbh-performance/pages' );
		$this->index->clear();
		CacheStatistics::reset();
		$this->schedule_warm_home();
		do_action( 'nbh_performance_after_cache_purge', 'all', $result );
		return $result;
	}

	/** Purge every device, language, currency and custom-cookie variant for a URL. */
	public function purge_url( $url ) {
		if ( ! wp_http_validate_url( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
			return false;
		}
		$entries = $this->index->entries_for_url( $url );
		$result  = true;
		foreach ( $entries as $entry ) {
			if ( ! CacheEntryFiles::delete( isset( $entry['file'] ) ? $entry['file'] : '' ) ) {
				$result = false;
			}
			$this->index->remove( isset( $entry['key'] ) ? $entry['key'] : '' );
		}
		CacheStatistics::reconcile( $this->index );
		do_action( 'nbh_performance_after_cache_purge', $url, $result );
		return $result;
	}

	private function build_context() {
		$url      = $this->current_url();
		$variants = $this->variants->current();
		$keygen   = new CacheKeyGenerator();
		$key      = $keygen->generate( $url, $variants );
		$file     = $keygen->path( WP_CONTENT_DIR . '/cache/nbh-performance/pages', $key );
		return compact( 'url', 'variants', 'key', 'file' );
	}

	private function resolve_ttl() {
		$ttl = max( 60, min( WEEK_IN_SECONDS, absint( get_option( 'nbh_performance_cache_ttl', HOUR_IN_SECONDS ) ) ) );
		if ( is_singular() ) {
			$override = absint( get_post_meta( get_queried_object_id(), '_nbh_performance_cache_ttl', true ) );
			if ( $override >= 60 ) {
				$ttl = min( WEEK_IN_SECONDS, $override );
			}
		}
		return max( 60, min( WEEK_IN_SECONDS, absint( apply_filters( 'nbh_performance_cache_entry_ttl', $ttl, $this->context['url'] ) ) ) );
	}

	private function dependencies() {
		$dependencies = array( 'home' );
		if ( is_singular() ) {
			$post_id        = get_queried_object_id();
			$post           = get_post( $post_id );
			$dependencies[] = 'post-' . $post_id;
			if ( $post ) {
				$dependencies[] = 'post-type-' . $post->post_type;
				$dependencies[] = 'author-' . $post->post_author;
			}
		} elseif ( is_post_type_archive() ) {
			$dependencies[] = 'post-type-' . sanitize_key( (string) get_query_var( 'post_type' ) );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$object = get_queried_object();
			if ( isset( $object->term_id ) ) {
				$dependencies[] = 'term-' . $object->term_id;
				$dependencies[] = 'taxonomy-' . sanitize_key( $object->taxonomy );
			}
		} elseif ( is_author() ) {
			$dependencies[] = 'author-' . get_queried_object_id();
		}
		return array_values( array_unique( (array) apply_filters( 'nbh_performance_cache_dependencies', $dependencies ) ) );
	}

	private function release_generation_lock() {
		if ( false !== $this->lock_handle ) {
			$this->lock->release( $this->lock_handle, $this->refresh_lock_file );
		}
		$this->lock_handle       = false;
		$this->refresh_lock_file = '';
		unset( $GLOBALS['nbh_performance_revalidation_lock'], $GLOBALS['nbh_performance_revalidation_file'], $GLOBALS['nbh_performance_revalidation_cache_file'] );
	}

	private function store_write_failure( $reason, $status = 0, $detail = '' ) {
		update_option(
			'nbh_performance_last_cache_write',
			array(
				'success'    => false,
				'file'       => isset( $this->context['file'] ) ? wp_normalize_path( $this->context['file'] ) : '',
				'url'        => isset( $this->context['url'] ) ? $this->context['url'] : '',
				'error'      => sanitize_key( (string) $reason ),
				'status'     => absint( $status ),
				'detail'     => sanitize_text_field( substr( (string) $detail, 0, 300 ) ),
				'written_at' => time(),
			),
			false
		);
	}

	private function delete_tree_contents( $root ) {
		if ( ! is_dir( $root ) ) {
			return true;
		}
		$root_real = realpath( $root );
		$base_real = realpath( WP_CONTENT_DIR . '/cache/nbh-performance' );
		if ( false === $root_real || false === $base_real || 0 !== strpos( $root_real, $base_real . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root_real, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$item->isDir() ? \NBH\Performance\Modules\Core\Filesystem::rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
		}
		return true;
	}

	public function warm_home() {
		if ( ! get_option( 'nbh_performance_cache_enabled', false ) || get_option( 'nbh_performance_safe_mode', true ) ) {
			return false;
		}
		$result = CachePrimer::warm( home_url( '/' ) );
		CacheStatistics::reconcile( $this->index );
		return ! empty( $result['success'] );
	}

	public function cache_setting_changed( $old_value, $new_value ) {
		if ( ! $old_value && $new_value ) {
			$this->preload->enqueue( array( home_url( '/' ) ), 1 );
			$this->schedule_warm_home();
		}
	}

	private function schedule_warm_home() {
		if ( ! wp_next_scheduled( 'nbh_performance_warm_home' ) ) {
			wp_schedule_single_event( time() + 2, 'nbh_performance_warm_home' );
		}
		$this->warm_home();
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/';
		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	public function requested_full_purge( $reason = '' ) {
		$result = $this->purge_all();
		do_action( 'nbh_performance_full_purge_request_completed', sanitize_key( (string) $reason ), $result );
		return $result;
	}

	private function request_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}
}
