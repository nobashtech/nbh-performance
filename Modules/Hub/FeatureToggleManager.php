<?php
/** Feature toggle registry and secure persistence. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

final class FeatureToggleManager {
	public function groups() {
		return array(
			'caching'        => array(
				'label'    => __( 'Caching', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_cache_enabled'        => array( 'label' => __( 'Page caching', 'nbh-performance' ) ),
					'nbh_performance_mobile_cache_enabled' => array( 'label' => __( 'Mobile cache variant', 'nbh-performance' ) ),
				),
			),
			'optimization'   => array(
				'label'    => __( 'Optimization', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_css_minify_enabled'   => array( 'label' => __( 'CSS minification', 'nbh-performance' ) ),
					'nbh_performance_js_minify_enabled'    => array( 'label' => __( 'JavaScript minification', 'nbh-performance' ) ),
					'nbh_performance_defer_js_enabled'     => array( 'label' => __( 'Defer JavaScript', 'nbh-performance' ) ),
					'nbh_performance_delay_js_enabled'     => array( 'label' => __( 'Delay third-party JavaScript', 'nbh-performance' ) ),
					'nbh_performance_critical_css_enabled' => array( 'label' => __( 'Critical CSS (conservative PHP)', 'nbh-performance' ) ),
					'nbh_performance_unused_css_enabled'   => array( 'label' => __( 'Used CSS (conservative PHP)', 'nbh-performance' ) ),
					'nbh_performance_visual_regression_guard_enabled' => array( 'label' => __( 'Visual regression guard', 'nbh-performance' ) ),
				),
			),
			'media'          => array(
				'label'    => __( 'Media', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_lazy_load_enabled'    => array( 'label' => __( 'Intelligent lazy loading', 'nbh-performance' ) ),
					'nbh_performance_responsive_images_enabled' => array( 'label' => __( 'Responsive image delivery', 'nbh-performance' ) ),
					'nbh_performance_youtube_facade_enabled' => array( 'label' => __( 'YouTube facade', 'nbh-performance' ) ),
					'nbh_performance_youtube_autoload_after_consent' => array( 'label' => __( 'Autoload YouTube after consent', 'nbh-performance' ) ),
					'nbh_performance_chat_facade_enabled'  => array( 'label' => __( 'Chat widget delay', 'nbh-performance' ) ),
					'nbh_performance_consent_mode_enabled' => array( 'label' => __( 'Consent-aware third parties', 'nbh-performance' ) ),
				),
			),
			'images'         => array(
				'label'    => __( 'Image Optimization', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_image_conversion_enabled' => array( 'label' => __( 'WebP/AVIF conversion', 'nbh-performance' ) ),
					'nbh_performance_nextgen_image_serving_enabled' => array( 'label' => __( 'Next-generation image serving', 'nbh-performance' ) ),
				),
			),
			'preload'        => array(
				'label'    => __( 'Preload', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_sitemap_preload_enabled' => array( 'label' => __( 'Sitemap preloading', 'nbh-performance' ) ),
					'nbh_performance_instant_page'         => array( 'label' => __( 'Instant page navigation', 'nbh-performance' ) ),
					'nbh_performance_speculation_rules_enabled' => array( 'label' => __( 'Speculation Rules navigation', 'nbh-performance' ) ),
					'nbh_performance_resource_priority_enabled' => array( 'label' => __( 'Browser Priority Elements', 'nbh-performance' ) ),
					'nbh_performance_automatic_preconnect_enabled' => array( 'label' => __( 'Automatic preconnect', 'nbh-performance' ) ),
					'nbh_performance_font_preload_enabled' => array( 'label' => __( 'Critical font detection', 'nbh-performance' ) ),
				),
			),
			'cdn'            => array(
				'label'    => __( 'CDN', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_cdn_rewrite_enabled' => array( 'label' => __( 'CDN URL rewriting', 'nbh-performance' ) ),
					'nbh_performance_edge_auto_purge_enabled' => array( 'label' => __( 'Automatic edge purge', 'nbh-performance' ) ),
					'nbh_performance_edge_cache_headers_enabled' => array( 'label' => __( 'Edge cache headers', 'nbh-performance' ) ),
					'nbh_performance_resource_hints_enabled' => array( 'label' => __( 'CDN resource hints', 'nbh-performance' ) ),
				),
			),
			'database'       => array(
				'label'    => __( 'Database', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_database_cleanup_enabled' => array( 'label' => __( 'Scheduled safe cleanup', 'nbh-performance' ) ),
				),
			),
			'script_manager' => array(
				'label'    => __( 'Script Manager', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_script_manager_enabled' => array( 'label' => __( 'Per-file Script Manager', 'nbh-performance' ) ),
				),
			),
			'tools'          => array(
				'label'    => __( 'Tools', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_rum_enabled' => array( 'label' => __( 'Real User Monitoring', 'nbh-performance' ) ),
					'nbh_performance_heartbeat_control_enabled' => array( 'label' => __( 'Heartbeat control', 'nbh-performance' ) ),
				),
			),
			'enterprise'     => array(
				'label'    => __( 'Agency', 'nbh-performance' ),
				'features' => array(
					'nbh_performance_edge_html_cache_enabled'  => array( 'label' => __( 'Full-page edge cache headers', 'nbh-performance' ) ),
					'nbh_performance_ai_gateway_write_enabled' => array( 'label' => __( 'Approved AI write operations', 'nbh-performance' ) ),
					'nbh_performance_agency_reports_enabled'   => array( 'label' => __( 'Agency portfolio reports', 'nbh-performance' ) ),
				),
			),
		);
	}

	private function dependencies() {
		return array(
			'nbh_performance_mobile_cache_enabled'         => array( 'nbh_performance_cache_enabled' ),
			'nbh_performance_sitemap_preload_enabled'      => array( 'nbh_performance_cache_enabled' ),
			'nbh_performance_nextgen_image_serving_enabled' => array( 'nbh_performance_image_conversion_enabled' ),
			'nbh_performance_youtube_autoload_after_consent' => array( 'nbh_performance_youtube_facade_enabled' ),
			'nbh_performance_defer_js_enabled'             => array( 'nbh_performance_js_minify_enabled' ),
			'nbh_performance_delay_js_enabled'             => array( 'nbh_performance_js_minify_enabled' ),
			'nbh_performance_critical_css_enabled'         => array( 'nbh_performance_css_minify_enabled' ),
			'nbh_performance_unused_css_enabled'           => array( 'nbh_performance_css_minify_enabled', 'nbh_performance_visual_regression_guard_enabled' ),
			'nbh_performance_automatic_preconnect_enabled' => array( 'nbh_performance_resource_priority_enabled' ),
			'nbh_performance_font_preload_enabled'         => array( 'nbh_performance_resource_priority_enabled' ),
		);
	}

	private function dependents() {
		$out = array();
		foreach ( $this->dependencies() as $feature => $requirements ) {
			foreach ( $requirements as $requirement ) {
				if ( ! isset( $out[ $requirement ] ) ) {
					$out[ $requirement ] = array();
				}
				$out[ $requirement ][] = $feature;
			}
		}
		return $out;
	}

	public function state() {
		$groups = $this->groups();
		foreach ( $groups as &$group ) {
			foreach ( $group['features'] as $option => $definition ) {
				$group['features'][ $option ] = array(
					'label'    => $definition['label'],
					'enabled'  => (bool) get_option( $option, 0 ),
					'tier'     => 'free',
					'requires' => isset( $this->dependencies()[ $option ] ) ? $this->dependencies()[ $option ] : array(),
				);
			}
		}
		return $groups;
	}
	/** Validate a complete feature batch before making changes. */
	public function validate_values( array $values ) {
		foreach ( $values as $value ) {
			if ( ! in_array( $value, array( true, false, 0, 1, 0.0, 1.0, '0', '1', 'true', 'false' ), true ) ) {
				return new \WP_Error( 'nbh_performance_invalid_toggle_value', __( 'Feature values must be true or false.', 'nbh-performance' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	public function update( array $values ) {
		if ( is_wp_error( $this->validate_values( $values ) ) ) {
			return array();
		}
		$definitions = array();
		foreach ( $this->groups() as $group ) {
			$definitions = array_merge( $definitions, $group['features'] );
		}
		$plan = array();
		foreach ( $values as $option => $value ) {
			if ( isset( $definitions[ $option ] ) ) {
				$plan[ $option ] = rest_sanitize_boolean( $value ) ? 1 : 0;
			}
		}
		if ( ! $plan ) {
			return array();
		}
		$dependencies = $this->dependencies();
		$dependents   = $this->dependents();
		// Explicit disables win regardless of JSON key order and propagate transitively.
		$disabled = array_keys( $plan, 0, true );
		for ( $i = 0; isset( $disabled[ $i ] ); ++$i ) {
			foreach ( isset( $dependents[ $disabled[ $i ] ] ) ? $dependents[ $disabled[ $i ] ] : array() as $dependent ) {
				if ( ! isset( $plan[ $dependent ] ) || 0 !== $plan[ $dependent ] ) {
					$plan[ $dependent ] = 0;
					$disabled[]         = $dependent;
				}
			}
		}
		$enabled = array_keys( $plan, 1, true );
		for ( $i = 0; isset( $enabled[ $i ] ); ++$i ) {
			foreach ( isset( $dependencies[ $enabled[ $i ] ] ) ? $dependencies[ $enabled[ $i ] ] : array() as $required ) {
				if ( isset( $definitions[ $required ] ) && ! isset( $plan[ $required ] ) ) {
					$plan[ $required ] = 1;
					$enabled[]         = $required;
				}
			}
		}
		ksort( $plan );
		do_action( 'nbh_performance_before_feature_toggles_updated', $plan );
		$updated = array();
		foreach ( $plan as $option => $value ) {
			update_option( $option, $value, false );
			$updated[ $option ] = (bool) $value;
		}
		do_action( 'nbh_performance_feature_toggles_updated', $updated );
		return $updated;
	}
}
