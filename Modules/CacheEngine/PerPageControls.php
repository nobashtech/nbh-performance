<?php
/** Per-post/page optimization controls. @package NBHPerformance */
namespace NBH\Performance\Modules\CacheEngine;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;

final class PerPageControls implements Module {
	const NONCE_ACTION = 'nbh_performance_per_page_controls';
	const NONCE_NAME   = 'nbh_performance_per_page_nonce';
	private $queue;

	private $keys = array(
		'_nbh_performance_disable_page_cache',
		'_nbh_performance_disable_mobile_cache',
		'_nbh_performance_disable_css_optimization',
		'_nbh_performance_disable_critical_css',
		'_nbh_performance_disable_unused_css',
		'_nbh_performance_disable_js_optimization',
		'_nbh_performance_disable_delay_js',
		'_nbh_performance_disable_defer_js',
		'_nbh_performance_disable_lazyload',
		'_nbh_performance_disable_cdn',
		'_nbh_performance_force_preload',
		'_nbh_performance_cache_ttl',
	);

	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'save_post', array( $this, 'save' ), 30, 2 );
		add_action( 'deleted_post', array( $this, 'rebuild_runtime_rules' ), 30, 0 );
		add_action( 'update_option_permalink_structure', array( $this, 'rebuild_runtime_rules' ), 30, 0 );
		add_action( 'init', array( $this, 'maybe_build_runtime_rules' ), 5 );
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'should_cache_request' ), 20, 2 );
		add_filter( 'nbh_performance_should_optimize_feature', array( $this, 'should_optimize_feature' ), 20, 2 );
		add_filter( 'nbh_performance_script_loading_strategy', array( $this, 'script_strategy' ), 20, 3 );
		add_filter( 'nbh_performance_cdn_rewritten_url', array( $this, 'cdn_url' ), 20, 2 );
		add_filter( 'nbh_performance_edge_exclude_url', array( $this, 'edge_excluded' ), 20, 2 );
		add_filter( 'nbh_performance_runtime_config', array( $this, 'runtime_config' ) );
	}

	public function add_meta_boxes() {
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( ! $post_type->show_ui || 'attachment' === $post_type->name ) {
				continue;
			}
			add_meta_box( 'nbh-performance-per-page', __( 'NBH Performance', 'nbh-performance' ), array( $this, 'render' ), $post_type->name, 'side', 'default' );
		}
	}

	public function admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $screen->post_type ) || 'attachment' === $screen->post_type ) {
			return;
		}
		$post_type = get_post_type_object( $screen->post_type );
		if ( ! $post_type || ! $post_type->public || ! $post_type->show_ui ) {
			return;
		}
		wp_enqueue_style( 'nbh-performance-per-page', NBH_PERFORMANCE_URL . 'assets/css/per-page-controls.css', array(), NBH_PERFORMANCE_VERSION );
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$groups = array(
			'cache'      => array(
				'title'       => __( 'Caching', 'nbh-performance' ),
				'description' => __( 'Choose how this page is stored and refreshed.', 'nbh-performance' ),
				'options'     => array(
					'_nbh_performance_disable_page_cache' => array(
						'label' => __( 'Skip page cache', 'nbh-performance' ),
						'help'  => __( 'Always show a fresh, uncached version of this page.', 'nbh-performance' ),
					),
					'_nbh_performance_disable_mobile_cache' => array(
						'label' => __( 'Use one cache for all devices', 'nbh-performance' ),
						'help'  => __( 'Serve the same cached page to desktop and mobile visitors.', 'nbh-performance' ),
					),
					'_nbh_performance_force_preload'      => array(
						'label' => __( 'Warm cache after update', 'nbh-performance' ),
						'help'  => __( 'Automatically prepare a fresh cached copy after this page is saved.', 'nbh-performance' ),
					),
				),
			),
			'css'        => array(
				'title'       => __( 'CSS', 'nbh-performance' ),
				'description' => __( 'Protect this page from CSS changes when needed.', 'nbh-performance' ),
				'options'     => array(
					'_nbh_performance_disable_css_optimization' => array(
						'label' => __( 'Skip all CSS optimization', 'nbh-performance' ),
						'help'  => __( 'Leave every stylesheet unchanged on this page.', 'nbh-performance' ),
					),
					'_nbh_performance_disable_critical_css' => array(
						'label' => __( 'Skip Critical CSS', 'nbh-performance' ),
						'help'  => __( 'Do not create or use above-the-fold CSS for this page.', 'nbh-performance' ),
					),
					'_nbh_performance_disable_unused_css' => array(
						'label' => __( 'Keep all CSS rules', 'nbh-performance' ),
						'help'  => __( 'Do not remove CSS that appears unused on this page.', 'nbh-performance' ),
					),
				),
			),
			'javascript' => array(
				'title'       => __( 'JavaScript', 'nbh-performance' ),
				'description' => __( 'Keep important interactions working exactly as expected.', 'nbh-performance' ),
				'options'     => array(
					'_nbh_performance_disable_js_optimization' => array(
						'label' => __( 'Skip all JavaScript optimization', 'nbh-performance' ),
						'help'  => __( 'Leave every script unchanged on this page.', 'nbh-performance' ),
					),
					'_nbh_performance_disable_delay_js' => array(
						'label' => __( 'Do not delay JavaScript', 'nbh-performance' ),
						'help'  => __( 'Run scripts normally instead of waiting for visitor interaction.', 'nbh-performance' ),
					),
					'_nbh_performance_disable_defer_js' => array(
						'label' => __( 'Do not defer JavaScript', 'nbh-performance' ),
						'help'  => __( 'Keep the original loading order for scripts on this page.', 'nbh-performance' ),
					),
				),
			),
			'delivery'   => array(
				'title'       => __( 'Media & delivery', 'nbh-performance' ),
				'description' => __( 'Control image loading and CDN delivery for this page.', 'nbh-performance' ),
				'options'     => array(
					'_nbh_performance_disable_lazyload' => array(
						'label' => __( 'Load media immediately', 'nbh-performance' ),
						'help'  => __( 'Do not lazy-load images, videos or iframes on this page.', 'nbh-performance' ),
					),
					'_nbh_performance_disable_cdn'      => array(
						'label' => __( 'Do not use the CDN', 'nbh-performance' ),
						'help'  => __( 'Serve this page’s files directly from your website.', 'nbh-performance' ),
					),
				),
			),
		);

		$active_count = 0;
		foreach ( $groups as $group ) {
			foreach ( $group['options'] as $key => $option ) {
				if ( get_post_meta( $post->ID, $key, true ) ) {
					++$active_count;
				}
			}
		}
		$ttl = absint( get_post_meta( $post->ID, '_nbh_performance_cache_ttl', true ) );
		if ( $ttl ) {
			++$active_count;
		}

		echo '<div class="np-page-settings">';
		echo '<div class="np-page-settings-summary">';
		echo '<div><span class="np-page-settings-eyebrow">' . esc_html__( 'Page-specific controls', 'nbh-performance' ) . '</span><p>' . esc_html__( 'Override global performance settings only for this page.', 'nbh-performance' ) . '</p></div>';
		/* translators: %d: number of active per-page overrides. */
		echo '<span class="np-page-settings-count' . ( $active_count ? ' is-active' : '' ) . '">' . esc_html( $active_count ? sprintf( _n( '%d active', '%d active', $active_count, 'nbh-performance' ), $active_count ) : __( 'Using defaults', 'nbh-performance' ) ) . '</span>';
		echo '</div>';

		foreach ( $groups as $group_id => $group ) {
			echo '<section class="np-page-settings-group np-page-settings-group-' . esc_attr( $group_id ) . '">';
			echo '<div class="np-page-settings-group-head"><strong>' . esc_html( $group['title'] ) . '</strong><small>' . esc_html( $group['description'] ) . '</small></div>';
			foreach ( $group['options'] as $key => $option ) {
				$checked  = (bool) get_post_meta( $post->ID, $key, true );
				$field_id = 'np-page-' . sanitize_html_class( str_replace( '_nbh_performance_', '', $key ) );
				echo '<label class="np-page-toggle" for="' . esc_attr( $field_id ) . '">';
				echo '<span class="np-page-toggle-copy"><strong>' . esc_html( $option['label'] ) . '</strong><small>' . esc_html( $option['help'] ) . '</small></span>';
				echo '<span class="np-page-switch"><input id="' . esc_attr( $field_id ) . '" type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $checked, true, false ) . '><span class="np-page-switch-track" aria-hidden="true"><span></span></span></span>';
				echo '</label>';
			}
			echo '</section>';
		}

		echo '<section class="np-page-settings-group np-page-settings-lifespan">';
		echo '<div class="np-page-settings-group-head"><strong>' . esc_html__( 'Custom cache lifespan', 'nbh-performance' ) . '</strong><small>' . esc_html__( 'Set how long this page stays cached before it refreshes.', 'nbh-performance' ) . '</small></div>';
		echo '<label class="np-page-number-field" for="nbh-performance-cache-ttl"><span>' . esc_html__( 'Lifespan', 'nbh-performance' ) . '</span><span class="np-page-number-control"><input id="nbh-performance-cache-ttl" type="number" min="60" max="86400" step="60" name="_nbh_performance_cache_ttl" value="' . esc_attr( $ttl ? $ttl : '' ) . '" placeholder="' . esc_attr__( 'Global default', 'nbh-performance' ) . '"><span>' . esc_html__( 'seconds', 'nbh-performance' ) . '</span></span></label>';
		echo '<p class="np-page-settings-footnote">' . esc_html__( 'Leave this empty to use the global cache lifespan. Changes apply after the page is updated.', 'nbh-performance' ) . '</p>';
		echo '</section>';
		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array_filter(
			$this->keys,
			static function ( $key ) {
				return '_nbh_performance_cache_ttl' !== $key;
			}
		) as $key ) {
			isset( $_POST[ $key ] ) ? update_post_meta( $post_id, $key, 1 ) : delete_post_meta( $post_id, $key );
		}
		$ttl = isset( $_POST['_nbh_performance_cache_ttl'] ) ? absint( $_POST['_nbh_performance_cache_ttl'] ) : 0;
		if ( $ttl >= 60 ) {
			update_post_meta( $post_id, '_nbh_performance_cache_ttl', min( DAY_IN_SECONDS, $ttl ) );
		} else {
			delete_post_meta( $post_id, '_nbh_performance_cache_ttl' );
		}
		$this->rebuild_runtime_rules();
		( new RuntimeConfigManager() )->write();
		if ( get_post_meta( $post_id, '_nbh_performance_force_preload', true ) && 'publish' === $post->post_status ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				$this->queue->enqueue(
					'warm_url',
					array( 'url' => $url ),
					array(
						'delay'      => 2,
						'priority'   => 60,
						'unique_key' => 'per-page-warm|' . $post_id,
					)
				);
			}
		}
	}

	public function should_cache_request( $allowed ) {
		$post_id = $this->current_post_id();
		if ( $post_id && get_post_meta( $post_id, '_nbh_performance_disable_page_cache', true ) ) {
			return false;
		}
		return $allowed;
	}

	public function should_optimize_feature( $allowed, $feature ) {
		$post_id = $this->current_post_id();
		if ( ! $post_id ) {
			return $allowed;
		}
		$feature = sanitize_key( $feature );
		if ( get_post_meta( $post_id, '_nbh_performance_disable_css_optimization', true ) && in_array( $feature, array( 'css_minify', 'critical_css', 'unused_css', 'async_css' ), true ) ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_critical_css', true ) && in_array( $feature, array( 'critical_css', 'async_css' ), true ) ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_unused_css', true ) && 'unused_css' === $feature ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_js_optimization', true ) && in_array( $feature, array( 'js_minify', 'script_loading', 'delay_js', 'defer_js' ), true ) ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_delay_js', true ) && 'delay_js' === $feature ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_defer_js', true ) && 'defer_js' === $feature ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_lazyload', true ) && 'lazy_load' === $feature ) {
			return false;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_cdn', true ) && 'cdn' === $feature ) {
			return false;
		}
		return $allowed;
	}

	public function script_strategy( $strategy ) {
		$post_id = $this->current_post_id();
		if ( ! $post_id ) {
			return $strategy;
		}
		if ( get_post_meta( $post_id, '_nbh_performance_disable_js_optimization', true ) ) {
			return 'default';
		}
		if ( 'delay' === $strategy && get_post_meta( $post_id, '_nbh_performance_disable_delay_js', true ) ) {
			return 'default';
		}
		if ( 'defer' === $strategy && get_post_meta( $post_id, '_nbh_performance_disable_defer_js', true ) ) {
			return 'default';
		}
		return $strategy;
	}

	public function cdn_url( $rewritten, $original ) {
		$post_id = $this->current_post_id();
		return $post_id && get_post_meta( $post_id, '_nbh_performance_disable_cdn', true ) ? $original : $rewritten;
	}

	public function edge_excluded( $excluded ) {
		$post_id = $this->current_post_id();
		return $excluded || ( $post_id && get_post_meta( $post_id, '_nbh_performance_disable_page_cache', true ) );
	}


	public function runtime_config( $config ) {
		$rules                                   = get_option(
			'nbh_performance_per_page_runtime_rules',
			array(
				'excluded_paths'                => array(),
				'mobile_variant_disabled_paths' => array(),
				'ttl_overrides'                 => array(),
			)
		);
		$config['excluded_paths']                = array_values( array_unique( array_merge( isset( $config['excluded_paths'] ) ? (array) $config['excluded_paths'] : array(), isset( $rules['excluded_paths'] ) ? (array) $rules['excluded_paths'] : array() ) ) );
		$config['mobile_variant_disabled_paths'] = isset( $rules['mobile_variant_disabled_paths'] ) ? array_values( array_unique( array_map( 'strval', (array) $rules['mobile_variant_disabled_paths'] ) ) ) : array();
		$config['ttl_overrides']                 = isset( $rules['ttl_overrides'] ) && is_array( $rules['ttl_overrides'] ) ? $rules['ttl_overrides'] : array();
		return $config;
	}

	public function maybe_build_runtime_rules() {
		if ( false === get_option( 'nbh_performance_per_page_runtime_rules', false ) ) {
			$this->rebuild_runtime_rules();
		}
	}

	public function rebuild_runtime_rules() {
		global $wpdb;
		$excluded        = array();
		$mobile_disabled = array();
		$ttls            = array();
		$last_id         = 0;
		$batch           = 500;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live cache-index/runtime-manifest state is required for invalidation; it cannot depend on the cache being invalidated.
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID WHERE p.post_status = \'publish\' AND p.ID > %d AND pm.meta_key IN (%s,%s,%s) ORDER BY p.ID ASC LIMIT %d', $wpdb->posts, $wpdb->postmeta, $last_id, '_nbh_performance_disable_page_cache', '_nbh_performance_disable_mobile_cache', '_nbh_performance_cache_ttl', $batch )
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			foreach ( (array) $ids as $post_id ) {
				$post_id = absint( $post_id );
				$last_id = max( $last_id, $post_id );
				$url     = get_permalink( $post_id );
				$path    = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
				if ( '' === $path ) {
					continue;
				}
				if ( get_post_meta( $post_id, '_nbh_performance_disable_page_cache', true ) ) {
					$excluded[] = $path;
				}
				if ( get_post_meta( $post_id, '_nbh_performance_disable_mobile_cache', true ) ) {
					$mobile_disabled[] = $path;
				}
				$ttl = absint( get_post_meta( $post_id, '_nbh_performance_cache_ttl', true ) );
				if ( $ttl >= 60 ) {
					$ttls[ $path ] = min( DAY_IN_SECONDS, $ttl );
				}
			}
			$row_count = count( (array) $ids );
		} while ( $row_count === $batch );
		update_option(
			'nbh_performance_per_page_runtime_rules',
			array(
				'excluded_paths'                => array_values( array_unique( $excluded ) ),
				'mobile_variant_disabled_paths' => array_values( array_unique( $mobile_disabled ) ),
				'ttl_overrides'                 => $ttls,
				'updated_at'                    => time(),
			),
			false
		);
	}
	private function current_post_id() {
		return is_singular() ? absint( get_queried_object_id() ) : 0;
	}
}
