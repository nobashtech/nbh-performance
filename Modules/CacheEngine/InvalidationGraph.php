<?php
/** Event-to-cache dependency invalidation graph. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;

final class InvalidationGraph implements Module {
	private $queue;
	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	public function register() {
		add_action( 'save_post', array( $this, 'post_changed' ), 60, 2 );
		add_action( 'before_delete_post', array( $this, 'post_deleted' ), 20, 1 );
		add_action( 'set_object_terms', array( $this, 'terms_assigned' ), 20, 6 );
		add_action( 'edited_term', array( $this, 'term_changed' ), 20, 3 );
		add_action( 'delete_term', array( $this, 'term_deleted' ), 20, 5 );
		add_action( 'wp_update_nav_menu', array( $this, 'menu_changed' ), 20, 1 );
		add_action( 'customize_save_after', array( $this, 'global_layout_changed' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'global_layout_changed' ), 20, 2 );
		add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'global_layout_changed' ), 20, 2 );
		add_action( 'comment_post', array( $this, 'comment_changed' ), 20, 2 );
		add_action( 'transition_comment_status', array( $this, 'comment_status_changed' ), 20, 3 );
		add_action( 'acf/save_post', array( $this, 'acf_changed' ), 30, 1 );
		add_action( 'elementor/document/after_save', array( $this, 'elementor_changed' ), 20, 2 );
		add_filter( 'nbh_performance_cache_dependencies', array( $this, 'add_global_dependencies' ) );
	}

	public function post_changed( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === get_post_status( $post_id ) ) {
			return;
		}
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		if ( in_array( $post->post_type, array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'elementor_library' ), true ) ) {
			$this->global_layout_changed();
			return;
		}
		$dependencies = array( 'home', 'post-' . $post_id, 'post-type-' . $post->post_type, 'author-' . $post->post_author );
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$dependencies[] = 'taxonomy-' . sanitize_key( $taxonomy );
			$terms          = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_id ) {
					$dependencies[] = 'term-' . absint( $term_id );
				}
			}
		}
		$urls = array_filter( array( get_permalink( $post_id ), get_post_type_archive_link( $post->post_type ), home_url( '/' ) ) );
		$this->enqueue( $dependencies, $urls, $urls, 'post_changed_' . $post_id, array( 'posts', 'terms' ) );
	}

	public function post_deleted( $post_id ) {
		$this->post_changed( $post_id, get_post( $post_id ) );
	}
	public function terms_assigned( $object_id ) {
		if ( get_post( $object_id ) ) {
			$this->post_changed( $object_id );
		}
	}

	public function term_changed( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		$link = get_term_link( (int) $term_id, $taxonomy );
		$urls = is_wp_error( $link ) ? array( home_url( '/' ) ) : array( home_url( '/' ), $link );
		$this->enqueue( array( 'home', 'term-' . absint( $term_id ), 'taxonomy-' . sanitize_key( $taxonomy ) ), $urls, $urls, 'term_changed_' . absint( $term_id ), array( 'terms', 'posts' ) );
	}
	public function term_deleted( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		$this->term_changed( $term_id, 0, $taxonomy );
	}

	public function menu_changed( $menu_id ) {
		$this->enqueue( array( 'home', 'menu-' . absint( $menu_id ), 'global-layout' ), array(), array( home_url( '/' ) ), 'menu_changed_' . absint( $menu_id ), array( 'posts', 'terms', 'options' ) );
	}
	public function global_layout_changed() {
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'all'           => true,
				'warm_urls'     => array( home_url( '/' ) ),
				'reason'        => 'global_layout_changed',
				'object_groups' => array( 'options', 'posts', 'terms' ),
			),
			array(
				'priority'   => 90,
				'unique_key' => 'global-layout|' . gmdate( 'YmdHi' ),
			)
		);
	}
	public function comment_changed( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->post_changed( $comment->comment_post_ID );
		}
	}
	public function comment_status_changed( $new_status, $old_status, $comment ) {
		unset( $new_status, $old_status );
		if ( $comment instanceof \WP_Comment ) {
			$this->post_changed( $comment->comment_post_ID );
		}
	}
	public function acf_changed( $post_id ) {
		if ( is_numeric( $post_id ) ) {
			$this->post_changed( absint( $post_id ) );
		} else {
			$this->global_layout_changed();
		}
	}
	public function elementor_changed( $document ) {
		if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
			$this->post_changed( absint( $document->get_main_id() ) );
		}
	}

	public function add_global_dependencies( $dependencies ) {
		$locations = get_nav_menu_locations();
		foreach ( (array) $locations as $menu_id ) {
			if ( $menu_id ) {
				$dependencies[] = 'menu-' . absint( $menu_id );
			}
		}
		$dependencies[] = 'global-layout';
		return array_values( array_unique( $dependencies ) );
	}

	private function enqueue( array $dependencies, array $urls, array $warm_urls, $key, array $object_groups ) {
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'dependencies'  => $dependencies,
				'urls'          => $urls,
				'warm_urls'     => $warm_urls,
				'object_groups' => $object_groups,
				'reason'        => sanitize_key( $key ),
			),
			array(
				'priority'     => 80,
				'max_attempts' => 3,
				'unique_key'   => 'invalidate|' . md5( $key . '|' . wp_json_encode( $dependencies ) ),
			)
		);
	}
}
