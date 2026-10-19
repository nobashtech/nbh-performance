<?php
/**
 * Environment-aware Smart Optimize plans.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\Compatibility\ConflictDiagnosis;
use NBH\Performance\Modules\PerformanceIntelligence\UnifiedMetricsService;
use NBH\Performance\Modules\ServerOptimization\ServerCapabilityReport;

final class SmartOptimizePlanBuilder {
	/** @var ServerCapabilityReport */
	private $server;
	/** @var ConflictDiagnosis */
	private $conflicts;
	/** @var UnifiedMetricsService */
	private $metrics;
	/** @var RecommendationEngine */
	private $recommendations;
	/** @var RumInsightEngine */
	private $rum_insights;

	public function __construct( ServerCapabilityReport $server, ConflictDiagnosis $conflicts, UnifiedMetricsService $metrics, RecommendationEngine $recommendations, RumInsightEngine $rum_insights ) {
		$this->server          = $server;
		$this->conflicts       = $conflicts;
		$this->metrics         = $metrics;
		$this->recommendations = $recommendations;
		$this->rum_insights    = $rum_insights;
	}

	/** Build a safe, explainable plan. */
	public function build( $mode = 'balanced' ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( 'safe', 'balanced', 'maximum' ), true ) ) {
			$mode = 'balanced';
		}

		$server       = $this->server->report();
		$conflicts    = $this->conflicts->diagnose();
		$metrics      = $this->metrics->snapshot();
		$rum_insights = $this->rum_insights->analyze();
		$woocommerce  = class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
		$constrained  = isset( $server['hosting']['tier'] ) && 'constrained' === $server['hosting']['tier'];
		$browser      = ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) );
		$image_ready  = function_exists( 'wp_get_image_editor' );
		$blockers     = array();
		$warnings     = array();

		foreach ( (array) $conflicts as $issue ) {
			$severity = isset( $issue['severity'] ) ? sanitize_key( $issue['severity'] ) : 'info';
			$item     = array(
				'id'      => isset( $issue['id'] ) ? sanitize_key( $issue['id'] ) : 'compatibility',
				'message' => isset( $issue['message'] ) ? sanitize_text_field( $issue['message'] ) : __( 'A compatibility issue needs review.', 'nbh-performance' ),
				'owner'   => 'compatibility',
			);
			if ( 'critical' === $severity ) {
				$blockers[] = $item;
			} elseif ( 'warning' === $severity ) {
				$warnings[] = $item;
			}
		}

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( ! is_ssl() && ! in_array( $environment, array( 'local', 'development' ), true ) ) {
			$blockers[] = array(
				'id'      => 'https',
				'message' => __( 'HTTPS must be enabled before automatic optimization.', 'nbh-performance' ),
				'owner'   => 'hosting',
			);
		}
		if ( $constrained ) {
			$warnings[] = array(
				'id'      => 'constrained-hosting',
				'message' => __( 'Background concurrency will be reduced for this hosting environment.', 'nbh-performance' ),
				'owner'   => 'hosting',
			);
		}
		if ( 'maximum' === $mode && ! $browser ) {
			$blockers[] = array(
				'id'      => 'browser-engine',
				'message' => __( 'Maximum mode needs a configured browser engine for Critical CSS and Used CSS. Choose Balanced, or configure the engine first.', 'nbh-performance' ),
				'owner'   => 'nbh-performance',
			);
		}
		if ( ! $image_ready ) {
			$warnings[] = array(
				'id'      => 'image-editor',
				'message' => __( 'WordPress did not expose an image editor, so image conversion will remain disabled.', 'nbh-performance' ),
				'owner'   => 'hosting',
			);
		}

		$features = array(
			'nbh_performance_cache_enabled'             => 1,
			'nbh_performance_sitemap_preload_enabled'   => 1,
			'nbh_performance_css_minify_enabled'        => 1,
			'nbh_performance_js_minify_enabled'         => 1,
			'nbh_performance_lazy_load_enabled'         => 1,
			'nbh_performance_responsive_images_enabled' => 1,
			'nbh_performance_font_preload_enabled'      => 1,
			'nbh_performance_heartbeat_control_enabled' => 1,
			'nbh_performance_speculation_rules_enabled' => 1,
			'nbh_performance_resource_priority_enabled' => 1,
		);
		$settings = array(
			'nbh_performance_cache_ttl'                   => $woocommerce ? 3600 : 43200,
			'nbh_performance_preload_batch_size'          => $constrained ? 1 : ( 'maximum' === $mode ? 6 : 3 ),
			'nbh_performance_preload_request_timeout'     => $constrained ? 8 : 12,
			'nbh_performance_webp_quality'                => 82,
			'nbh_performance_avif_quality'                => 68,
			'nbh_performance_font_preload_limit'          => 2,
			'nbh_performance_lcp_preload_limit'           => 1,
			'nbh_performance_lazyload_eager_images'       => $woocommerce ? 3 : 2,
			'nbh_performance_speculation_mode'            => 'prefetch',
			'nbh_performance_asset_inventory_enabled'     => 1,
			'nbh_performance_performance_budgets_enabled' => 1,
			'nbh_performance_smart_rollback_enabled'      => 1,
		);

		if ( 'safe' !== $mode ) {
			$features['nbh_performance_defer_js_enabled']       = 1;
			$features['nbh_performance_youtube_facade_enabled'] = 1;
			$features['nbh_performance_rum_enabled']            = 1;
			if ( $image_ready ) {
				$features['nbh_performance_image_conversion_enabled']      = 1;
				$features['nbh_performance_nextgen_image_serving_enabled'] = 1;
			}
		}
		if ( 'maximum' === $mode ) {
			$features['nbh_performance_delay_js_enabled'] = 1;
			if ( $browser ) {
				$features['nbh_performance_critical_css_enabled']            = 1;
				$features['nbh_performance_unused_css_enabled']              = 1;
				$features['nbh_performance_visual_regression_guard_enabled'] = 1;
			}
		}
		if ( $woocommerce && 'maximum' !== $mode ) {
			$features['nbh_performance_delay_js_enabled'] = 0;
		}

		$steps = array(
			array(
				'id'     => 'baseline',
				'title'  => __( 'Capture current performance evidence', 'nbh-performance' ),
				'status' => 'planned',
			),
			array(
				'id'     => 'configure',
				'title'  => __( 'Apply environment-safe optimization settings', 'nbh-performance' ),
				'status' => 'planned',
			),
			array(
				'id'     => 'purge',
				'title'  => __( 'Purge and warm important page cache entries', 'nbh-performance' ),
				'status' => 'planned',
			),
			array(
				'id'     => 'validate',
				'title'  => __( 'Validate public and WooCommerce routes', 'nbh-performance' ),
				'status' => 'planned',
			),
			array(
				'id'     => 'measure',
				'title'  => __( 'Measure before-and-after impact', 'nbh-performance' ),
				'status' => 'planned',
			),
			array(
				'id'     => 'protect',
				'title'  => __( 'Roll back automatically when a critical regression is verified', 'nbh-performance' ),
				'status' => 'planned',
			),
		);

		return array(
			'mode'            => $mode,
			'eligible'        => empty( $blockers ),
			'generated_at'    => time(),
			'site_type'       => $woocommerce ? 'woocommerce' : 'wordpress',
			'hosting_tier'    => isset( $server['hosting']['tier'] ) ? sanitize_key( $server['hosting']['tier'] ) : 'unknown',
			'browser_engine'  => $browser,
			'features'        => $features,
			'settings'        => $settings,
			'blockers'        => $blockers,
			'warnings'        => $warnings,
			'steps'           => $steps,
			'before'          => $metrics,
			'recommendations' => array_slice( $this->recommendations->recommendations(), 0, 8 ),
			'rum_insights'    => $rum_insights,
			'safeguards'      => array(
				'configuration_snapshot'  => true,
				'frontend_validation'     => true,
				'woocommerce_protection'  => $woocommerce,
				'visual_regression_guard' => $browser,
				'automatic_rollback'      => true,
			),
		);
	}
}
