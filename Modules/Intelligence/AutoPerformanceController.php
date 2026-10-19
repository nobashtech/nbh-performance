<?php
/** Automatic performance profile and blocker assessment. @package NBHPerformance */
namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\AdvancedSettingsManager;
use NBH\Performance\Modules\Hub\FeatureToggleManager;
use NBH\Performance\Modules\Hub\SafeModeManager;
use WP_REST_Request;
use WP_REST_Server;

final class AutoPerformanceController implements Module {
	private $toggles;
	private $settings;
	private $safe_mode;

	public function __construct( FeatureToggleManager $toggles, AdvancedSettingsManager $settings, SafeModeManager $safe_mode ) {
		$this->toggles   = $toggles;
		$this->settings  = $settings;
		$this->safe_mode = $safe_mode;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$permission = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance/assessment',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'assessment' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/performance/apply',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => $permission,
			)
		);
	}

	public function assessment() {
		$blockers = array();
		$warnings = array();
		$score    = 100;

		if ( ! is_ssl() ) {
			$blockers[] = array(
				'id'    => 'https',
				'label' => __( 'HTTPS is not active.', 'nbh-performance' ),
				'owner' => 'hosting',
			);
			$score     -= 20;
		}
		if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			$warnings[] = array(
				'id'    => 'debug',
				'label' => __( 'Public debug display is enabled.', 'nbh-performance' ),
				'owner' => 'wordpress',
			);
			$score     -= 5;
		}
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			$warnings[] = array(
				'id'    => 'environment',
				'label' => __( 'This site is not marked as a production environment.', 'nbh-performance' ),
				'owner' => 'wordpress',
			);
		}
		if ( (int) ini_get( 'memory_limit' ) > 0 && wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) < 134217728 ) {
			$warnings[] = array(
				'id'    => 'memory',
				'label' => __( 'PHP memory is below 128 MB; aggressive background jobs will be throttled.', 'nbh-performance' ),
				'owner' => 'hosting',
			);
			$score     -= 8;
		}
		if ( function_exists( 'is_plugin_active' ) ) {
			$known = array( 'wp-rocket/wp-rocket.php', 'litespeed-cache/litespeed-cache.php', 'w3-total-cache/w3-total-cache.php', 'autoptimize/autoptimize.php', 'perfmatters/perfmatters.php' );
			foreach ( $known as $plugin ) {
				if ( is_plugin_active( $plugin ) ) {
					$blockers[] = array(
						'id'    => 'optimizer-conflict',
						/* translators: %s: other active optimization plugin name. */
						'label' => sprintf( __( 'Another optimization plugin is active: %s', 'nbh-performance' ), $plugin ),
						'owner' => 'plugin',
					);
					$score     -= 20;
				}
			}
		}
		if ( ! wp_next_scheduled( 'nbh_performance_preload_worker' ) && ! defined( 'DISABLE_WP_CRON' ) ) {
			$warnings[] = array(
				'id'    => 'preload-cron',
				'label' => __( 'The preload worker is not currently scheduled.', 'nbh-performance' ),
				'owner' => 'nbh-performance',
			);
			$score     -= 5;
		}

		$external = apply_filters(
			'nbh_performance_performance_blockers',
			array(
				'blockers' => $blockers,
				'warnings' => $warnings,
			)
		);
		if ( is_array( $external ) ) {
			$blockers = isset( $external['blockers'] ) && is_array( $external['blockers'] ) ? $external['blockers'] : $blockers;
			$warnings = isset( $external['warnings'] ) && is_array( $external['warnings'] ) ? $external['warnings'] : $warnings;
		}

		return rest_ensure_response(
			array(
				'target'    => 90,
				'readiness' => max( 0, min( 100, $score ) ),
				'eligible'  => empty( $blockers ),
				'blockers'  => $blockers,
				'warnings'  => $warnings,
				'message'   => empty( $blockers ) ? __( 'The site is eligible for the automatic 90+ target profile. Final PSI scores still depend on hosting, theme, content and third-party services.', 'nbh-performance' ) : __( 'Resolve the listed blockers before enabling the automatic performance profile.', 'nbh-performance' ),
			)
		);
	}

	public function apply( WP_REST_Request $request ) {
		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
		if ( ! in_array( $mode, array( 'safe', 'balanced', 'aggressive' ), true ) ) {
			$mode = 'balanced';
		}

		$assessment = $this->assessment()->get_data();
		if ( ! empty( $assessment['blockers'] ) ) {
			return new \WP_Error(
				'nbh_performance_performance_blocked',
				__( 'Automatic optimization is blocked by the current environment.', 'nbh-performance' ),
				array(
					'status'     => 409,
					'assessment' => $assessment,
				)
			);
		}

		$features = array(
			'nbh_performance_cache_enabled'             => 1,
			'nbh_performance_sitemap_preload_enabled'   => 1,
			'nbh_performance_css_minify_enabled'        => 1,
			'nbh_performance_js_minify_enabled'         => 1,
			'nbh_performance_defer_js_enabled'          => 1,
			'nbh_performance_lazy_load_enabled'         => 1,
			'nbh_performance_font_preload_enabled'      => 1,
			'nbh_performance_heartbeat_control_enabled' => 1,
		);
		$settings = array(
			'nbh_performance_cache_ttl'                   => 43200,
			'nbh_performance_preload_batch_size'          => 'safe' === $mode ? 1 : ( 'aggressive' === $mode ? 6 : 3 ),
			'nbh_performance_delay_timeout'               => 7000,
			'nbh_performance_webp_quality'                => 82,
			'nbh_performance_avif_quality'                => 68,
			'nbh_performance_font_preload_limit'          => 2,
			'nbh_performance_combine_mode'                => 'auto',
			'nbh_performance_speculation_rules_enabled'   => 1,
			'nbh_performance_speculation_mode'            => 'prefetch',
			'nbh_performance_resource_priority_enabled'   => 1,
			'nbh_performance_lcp_preload_limit'           => 1,
			'nbh_performance_asset_inventory_enabled'     => 1,
			'nbh_performance_performance_budgets_enabled' => 1,
		);

		if ( 'safe' !== $mode ) {
			$features['nbh_performance_delay_js_enabled']              = 1;
			$features['nbh_performance_image_conversion_enabled']      = 1;
			$features['nbh_performance_nextgen_image_serving_enabled'] = 1;
			$features['nbh_performance_youtube_facade_enabled']        = 1;
		}
		if ( 'aggressive' === $mode ) {
			$features['nbh_performance_critical_css_enabled'] = 1;
			$features['nbh_performance_unused_css_enabled']   = 1;
			$features['nbh_performance_rum_enabled']          = 1;
		}

		$updated_features = $this->toggles->update( $features );
		$updated_settings = $this->settings->update( $settings );
		$this->safe_mode->disable( false );
		update_option( 'nbh_performance_auto_performance_mode', $mode, false );
		update_option( 'nbh_performance_auto_performance_applied_at', time(), false );
		do_action( 'nbh_performance_auto_performance_applied', $mode, $updated_features, $updated_settings );

		return rest_ensure_response(
			array(
				'applied'    => true,
				'mode'       => $mode,
				'features'   => $updated_features,
				'settings'   => $updated_settings,
				'assessment' => $assessment,
				'next_steps' => array(
					__( 'Purge and preload the cache.', 'nbh-performance' ),
					__( 'Run PageSpeed Insights after the cache is warm.', 'nbh-performance' ),
					__( 'Review any remaining third-party, hosting or theme blockers.', 'nbh-performance' ),
				),
			)
		);
	}
}
