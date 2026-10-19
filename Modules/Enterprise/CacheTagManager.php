<?php
/**
 * Cache-tag orchestration for targeted page, object, and edge invalidation.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Enterprise;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use NBH\Performance\Modules\Security\AuditLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class CacheTagManager implements Module {
	const STATS_OPTION = 'nbh_performance_cache_tag_stats';

	/** @var JobQueue */
	private $queue;

	/** @var AuditLogger */
	private $audit;

	public function __construct( JobQueue $queue, AuditLogger $audit ) {
		$this->queue = $queue;
		$this->audit = $audit;
	}

	public function register() {
		add_filter( 'nbh_performance_cache_dependencies', array( $this, 'add_context_tags' ), 30 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_invalidation_graph_completed', array( $this, 'record_invalidation' ), 10, 2 );
		add_action( 'nbh_performance_edge_inbound_purge_verified', array( $this, 'queue_inbound_purge' ), 10, 1 );
	}

	public function routes() {
		$permission = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/cache-tags/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/cache-tags/purge',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_purge' ),
				'permission_callback' => $permission,
			)
		);
	}

	/** @param array<int,string> $dependencies Existing dependencies. @return array<int,string> */
	public function add_context_tags( $dependencies ) {
		$dependencies = is_array( $dependencies ) ? $dependencies : array();
		return array_values( array_unique( array_merge( $dependencies, $this->current_tags() ) ) );
	}

	/** @return array<int,string> */
	public function current_tags() {
		$tags = array( 'site-' . get_current_blog_id(), 'site-wide' );
		if ( is_front_page() || is_home() ) {
			$tags[] = 'home';
		}
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$post    = get_post( $post_id );
			$tags[]  = 'post-' . absint( $post_id );
			if ( $post ) {
				$tags[] = 'post-type-' . sanitize_key( $post->post_type );
				$tags[] = 'author-' . absint( $post->post_author );
				foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
					$tags[] = 'taxonomy-' . sanitize_key( $taxonomy );
					$terms  = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term_id ) {
							$tags[] = 'term-' . absint( $term_id );
						}
					}
				}
			}
		} elseif ( is_post_type_archive() ) {
			$tags[] = 'post-type-' . sanitize_key( (string) get_query_var( 'post_type' ) );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$object = get_queried_object();
			if ( isset( $object->term_id ) ) {
				$tags[] = 'term-' . absint( $object->term_id );
				$tags[] = 'taxonomy-' . sanitize_key( $object->taxonomy );
			}
		} elseif ( is_author() ) {
			$tags[] = 'author-' . absint( get_queried_object_id() );
		}

		return $this->normalize_tags( apply_filters( 'nbh_performance_enterprise_cache_tags', $tags ) );
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_purge( WP_REST_Request $request ) {
		$tags = $this->normalize_tags( (array) $request->get_param( 'tags' ) );
		if ( empty( $tags ) ) {
			return new WP_Error( 'nbh_performance_cache_tags_missing', __( 'Provide at least one cache tag.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		if ( count( $tags ) > 50 ) {
			return new WP_Error( 'nbh_performance_cache_tags_limit', __( 'A maximum of 50 cache tags can be purged per request.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$job_id = $this->queue_purge( $tags, 'admin' );
		$this->audit->record(
			'enterprise_cache_tags_queued',
			array(
				'tag_count' => count( $tags ),
				'job_id'    => (string) $job_id,
			)
		);
		do_action( 'nbh_performance_edge_tag_purge_requested', $tags, 'admin' );
		return rest_ensure_response(
			array(
				'success' => true,
				'job_id'  => $job_id,
				'tags'    => $tags,
			)
		);
	}


	/** Queue a verified inbound edge invalidation.
	 *
	 * @param array<int,string> $tags Tags.
	 */
	public function queue_inbound_purge( $tags ) {
		$tags = $this->normalize_tags( (array) $tags );
		if ( empty( $tags ) ) {
			return;
		}
		$job_id = $this->queue_purge( $tags, 'edge_inbound' );
		$this->audit->record(
			'enterprise_inbound_cache_tags_queued',
			array(
				'tag_count' => count( $tags ),
				'job_id'    => (string) $job_id,
			)
		);
	}

	/** Queue a cache-tag invalidation job.
	 *
	 * @param array<int,string> $tags Tags.
	 * @param string            $source Source.
	 * @return int|string|false
	 */
	private function queue_purge( array $tags, $source ) {
		return $this->queue->enqueue(
			'cache_invalidate',
			array(
				'dependencies'  => $tags,
				'object_groups' => array( 'posts', 'terms', 'options' ),
				'reason'        => 'enterprise_cache_tag_' . sanitize_key( $source ),
			),
			array(
				'priority'     => 95,
				'max_attempts' => 3,
				'unique_key'   => 'cache-tags|' . sanitize_key( $source ) . '|' . md5( implode( '|', $tags ) . '|' . gmdate( 'YmdHi' ) ),
			)
		);
	}

	/** @return \WP_REST_Response */
	public function rest_status() {
		return rest_ensure_response( $this->status() );
	}

	/** @return array<string,mixed> */
	public function status() {
		$stats = get_option( self::STATS_OPTION, array() );
		return array(
			'enabled'        => true,
			'current_tags'   => $this->current_tags(),
			'last_purge_at'  => is_array( $stats ) ? absint( isset( $stats['last_purge_at'] ) ? $stats['last_purge_at'] : 0 ) : 0,
			'last_tag_count' => is_array( $stats ) ? absint( isset( $stats['last_tag_count'] ) ? $stats['last_tag_count'] : 0 ) : 0,
			'total_purges'   => is_array( $stats ) ? absint( isset( $stats['total_purges'] ) ? $stats['total_purges'] : 0 ) : 0,
		);
	}

	/** @param array<string,mixed> $payload Payload. @param bool $success Success. */
	public function record_invalidation( $payload, $success ) {
		if ( empty( $payload['dependencies'] ) || ! is_array( $payload['dependencies'] ) ) {
			return;
		}
		$stats                   = get_option( self::STATS_OPTION, array() );
		$stats                   = is_array( $stats ) ? $stats : array();
		$stats['last_purge_at']  = time();
		$stats['last_tag_count'] = count( $payload['dependencies'] );
		$stats['last_success']   = (bool) $success;
		$stats['total_purges']   = absint( isset( $stats['total_purges'] ) ? $stats['total_purges'] : 0 ) + 1;
		update_option( self::STATS_OPTION, $stats, false );
	}

	/** @param array<int,mixed> $tags Tags. @return array<int,string> */
	public function normalize_tags( $tags ) {
		$clean = array();
		foreach ( (array) $tags as $tag ) {
			$tag = strtolower( trim( (string) $tag ) );
			$tag = preg_replace( '/[^a-z0-9_-]+/', '-', str_replace( array( '.', ':' ), '-', $tag ) );
			$tag = trim( (string) $tag, '-' );
			if ( '' !== $tag && strlen( $tag ) <= 80 ) {
				$clean[] = $tag;
			}
		}
		return array_values( array_unique( array_slice( $clean, 0, 100 ) ) );
	}
}
