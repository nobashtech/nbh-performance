<?php
/** Hub dashboard REST controller. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\CacheEngine\CacheIndex;
use NBH\Performance\Modules\CacheEngine\CacheStatistics;
use NBH\Performance\Modules\CacheEngine\CachePrimer;
use NBH\Performance\Modules\CacheEngine\DropInInstaller;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;
use NBH\Performance\Modules\Compatibility\CompatibilityRemediationManager;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Server;

final class DashboardController implements Module {
	private $toggles;
	private $settings;
	private $safe_mode;
	private $conflicts;
	private $licenses;
	private $remediation;
	private $product_quality;
	public function __construct( FeatureToggleManager $toggles, AdvancedSettingsManager $settings, SafeModeManager $safe_mode, ConflictReporter $conflicts, \NBH\Performance\Modules\Licensing\LicenseManager $licenses, CompatibilityRemediationManager $remediation, ProductQualityManager $product_quality ) {
		$this->toggles         = $toggles;
		$this->settings        = $settings;
		$this->safe_mode       = $safe_mode;
		$this->conflicts       = $conflicts;
		$this->licenses        = $licenses;
		$this->remediation     = $remediation;
		$this->product_quality = $product_quality;
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
			'/hub/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/toggles',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'toggles' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/settings',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'settings' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/safe-mode',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'safe_mode' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/onboarding',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'onboarding' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/onboarding/reset',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reset_onboarding' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/config/snapshot',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'create_snapshot' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/config/restore',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'restore_snapshot' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/config/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_configuration' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/config/import',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'import_configuration' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/config/reset',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reset_configuration' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/recovery/stabilize',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'recovery_stabilize' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/hub/recovery/resume',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'recovery_resume' ),
				'permission_callback' => $permission,
			)
		);
	}
	public function status() {
		try {
			$license_status = $this->license_status();
			$agency_access  = ! empty( $license_status['is_agency'] );
			$features       = $this->filtered_features( $agency_access );
			$settings       = $this->filtered_settings( $agency_access );
			$cache_stats    = CacheStatistics::reconcile( new CacheIndex() );
			if ( 0 === (int) $cache_stats['files'] && get_option( 'nbh_performance_cache_enabled', false ) && ! get_option( 'nbh_performance_safe_mode', true ) && ! get_transient( 'nbh_performance_dashboard_warm_lock' ) ) {
				set_transient( 'nbh_performance_dashboard_warm_lock', 1, MINUTE_IN_SECONDS );
				CachePrimer::warm( home_url( '/' ) );
				$cache_stats = CacheStatistics::reconcile( new CacheIndex() );
			}
			return rest_ensure_response(
				array(
					'version'                => NBH_PERFORMANCE_VERSION,
					'build'                  => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
					'platform'               => array(
						'product'            => defined( 'NBH_PERFORMANCE_PRODUCT_URL' ) ? NBH_PERFORMANCE_PRODUCT_URL : '',
						'account'            => defined( 'NBH_PERFORMANCE_ACCOUNT_URL' ) ? NBH_PERFORMANCE_ACCOUNT_URL : '',
						'licensing_provider' => 'local',
					),
					'safe_mode'              => $this->safe_mode->status(),
					'features'               => $features,
					'settings'               => $settings,
					'cache'                  => $cache_stats,
					'cache_bootstrap'        => get_option( 'nbh_performance_cache_bootstrap_status', array() ),
					'cache_health'           => $this->cache_health(),
					'server_rules'           => get_option( 'nbh_performance_server_rules_status', array() ),
					'environment'            => array(
						'is_multisite'         => is_multisite(),
						'page_cache_supported' => ! is_multisite(),
						'page_cache_sku'       => 'single_site',
						'page_cache_message'   => is_multisite()
							? __( 'Page caching is single-site only in this build and remains fail-closed on multisite.', 'nbh-performance' )
							: '',
					),
					'conflicts'              => $this->dashboard_conflicts(),
					'last_cleanup'           => get_option( 'nbh_performance_database_last_cleanup', array() ),
					'image_library'          => $this->image_library_status(),
					'rum_enabled'            => (bool) get_option( 'nbh_performance_rum_enabled', 0 ),
					'auto_performance'       => array(
						'mode'       => sanitize_key( (string) get_option( 'nbh_performance_auto_performance_mode', '' ) ),
						'applied_at' => absint( get_option( 'nbh_performance_auto_performance_applied_at', 0 ) ),
					),
					'phase_c'                => array(
						'smart_optimize'     => get_option( 'nbh_performance_smart_optimize_last_run', array() ),
						'automatic_rollback' => array(
							'enabled'            => (bool) get_option( 'nbh_performance_smart_rollback_enabled', 1 ),
							'production_enabled' => (bool) get_option( 'nbh_performance_smart_rollback_production_enabled', 0 ),
							'window_minutes'     => absint( get_option( 'nbh_performance_smart_rollback_window_minutes', 360 ) ),
							'last'               => current( (array) get_option( 'nbh_performance_automatic_rollback_history', array() ) ),
						),
						'agency_profiles'    => array(
							'count'  => count( (array) get_option( 'nbh_performance_agency_profiles', array() ) ),
							'active' => sanitize_key( (string) get_option( 'nbh_performance_agency_active_profile', '' ) ),
						),
						'account'            => $this->account_connection(),
						'proof'              => $this->soft_launch_proof(),
						'first_win'          => get_option( 'nbh_performance_soft_launch_first_win', array() ),
					),
					'license'                => $license_status,
					'configuration_snapshot' => $this->snapshot_status(),
					'reliability'            => array(
						'jobs'               => ( new JobStore() )->stats(),
						'validation'         => get_option( 'nbh_performance_last_staging_validation', array() ),
						'compatibility'      => get_option( 'nbh_performance_compatibility_matrix', array() ),
						'latest_transaction' => $this->latest_transaction(),
						'settings_contract'  => get_option( SettingsRegistry::REPORT_OPTION, array() ),
					),
					'browser_intelligence'   => array(
						'priority_elements'      => ( new PriorityElementsStorage() )->stats(),
						'generator_configured'   => ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) ),
						'generator_endpoint'     => '',
						'generator_secret_ready' => false,
						'generator_status'       => $this->browser_generator_status(),
						'visual_guard_enabled'   => (bool) get_option( 'nbh_performance_visual_regression_guard_enabled', 1 ),
						'visual_failures'        => array_slice( (array) get_option( 'nbh_performance_visual_regression_failures', array() ), 0, 10 ),
						'css_status'             => array_slice( array_values( (array) get_option( 'nbh_performance_browser_css_status', array() ) ), -20 ),
					),
					'recovery_runbook'       => ( new \NBH\Performance\Modules\Diagnostics\RecoveryRunbook() )->steps(),
					'cdn'                    => apply_filters(
						'nbh_performance_hub_cdn_status',
						array(
							'provider'        => sanitize_key( (string) get_option( 'nbh_performance_edge_provider', 'custom' ) ),
							'configured'      => false,
							'rewrite_enabled' => (bool) get_option( 'nbh_performance_cdn_rewrite_enabled', 0 ),
							'cdn_base_url'    => esc_url_raw( (string) get_option( 'nbh_performance_cdn_base_url', '' ) ),
						)
					),
					'enterprise'             => $agency_access ? array(
						'edge_html_cache'       => array(
							'enabled'            => (bool) get_option( 'nbh_performance_edge_html_cache_enabled', 0 ),
							'ttl'                => absint( get_option( 'nbh_performance_edge_html_ttl', HOUR_IN_SECONDS ) ),
							'webhook_configured' => (bool) get_option( 'nbh_performance_edge_purge_webhook_url', '' ),
							'last_purge'         => get_option( 'nbh_performance_edge_last_purge', array() ),
						),
						'cache_tags'            => get_option( 'nbh_performance_cache_tag_stats', array() ),
						'ai_gateway'            => array(
							'configured'        => (bool) get_option( 'nbh_performance_ai_gateway_token_hash', '' ),
							'scope'             => sanitize_key( (string) get_option( 'nbh_performance_ai_gateway_scope', 'read' ) ),
							'write_enabled'     => (bool) get_option( 'nbh_performance_ai_gateway_write_enabled', 0 ),
							'pending_approvals' => count(
								array_filter(
									(array) get_option( 'nbh_performance_ai_gateway_approvals', array() ),
									static function ( $item ) {
										return is_array( $item ) && 'pending' === ( isset( $item['status'] ) ? $item['status'] : '' );
									}
								)
							),
						),
						'object_cache_topology' => array(
							'mode'  => sanitize_key( (string) get_option( 'nbh_performance_object_cache_redis_mode', 'standalone' ) ),
							'nodes' => count( (array) get_option( 'nbh_performance_object_cache_redis_nodes', array() ) ),
						),
						'network'               => is_multisite(),
					) : array(),
					'onboarding'             => array(
						'completed'           => (bool) get_option( 'nbh_performance_onboarding_completed', 0 ),
						'profile'             => sanitize_key( (string) get_option( 'nbh_performance_onboarding_profile', '' ) ),
						'completed_at'        => absint( get_option( 'nbh_performance_onboarding_completed_at', 0 ) ),
						'recommended_profile' => $this->recommended_onboarding_profile(),
					),
					'release_readiness'      => $this->release_readiness(),
					'product_quality'        => $this->product_quality->status(),
				)
			);
		} catch ( \Throwable $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[NBH Performance] Dashboard status error: ' . $exception->getMessage() );

			// Keep the settings workspace usable even when a non-essential status service fails.
			$features       = array();
			$settings       = array();
			$license_status = array(
				'status'    => 'inactive',
				'tier'      => 'free',
				'plan'      => 'free',
				'is_agency' => false,
			);
			try {
				$license_status = $this->license_status();
			} catch ( \Throwable $license_exception ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[NBH Performance] License status recovery error: ' . $license_exception->getMessage() );
			}
			$agency_access = ! empty( $license_status['is_agency'] );
			try {
				$features = $this->filtered_features( $agency_access );
			} catch ( \Throwable $feature_exception ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[NBH Performance] Feature schema recovery error: ' . $feature_exception->getMessage() );
			}
			try {
				$settings = $this->filtered_settings( $agency_access );
			} catch ( \Throwable $settings_exception ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[NBH Performance] Settings schema recovery error: ' . $settings_exception->getMessage() );
			}

			return rest_ensure_response(
				array(
					'version'         => NBH_PERFORMANCE_VERSION,
					'build'           => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
					'safe_mode'       => $this->safe_mode->status(),
					'features'        => $features,
					'settings'        => $settings,
					'cache'           => array(
						'files'      => 0,
						'bytes'      => 0,
						'updated_at' => time(),
					),
					'conflicts'       => array(),
					'license'         => $license_status,
					'onboarding'      => array(
						'completed'    => false,
						'profile'      => '',
						'completed_at' => 0,
					),
					'dashboard_error' => array(
						'message' => __( 'Some dashboard services could not be loaded. NBH Performance started in recovery mode.', 'nbh-performance' ),
						'detail'  => current_user_can( 'manage_options' ) ? sanitize_text_field( $exception->getMessage() ) : '',
					),
				)
			);
		}
	}


	/** Return actionable compatibility issues for the dashboard. */
	private function dashboard_conflicts() {
		$items                 = array();
		$seen                  = array();
		$has_cache_remediation = false;
		$has_asset_remediation = false;
		foreach ( $this->remediation->issues() as $issue ) {
			$id          = isset( $issue['id'] ) ? sanitize_key( (string) $issue['id'] ) : wp_generate_uuid4();
			$seen[ $id ] = true;
			if ( in_array( $id, array( 'advanced-cache-dropin', 'multiple-page-cache-plugins', 'wp-cache-disabled', 'dropin-install-error' ), true ) ) {
				$has_cache_remediation = true;
			}
			if ( 0 === strpos( $id, 'optimizer-' ) || 'asset-health-rollback' === $id ) {
				$has_asset_remediation = true;
			}
			$items[] = array(
				'id'          => $id,
				'severity'    => isset( $issue['tone'] ) ? sanitize_key( (string) $issue['tone'] ) : 'warning',
				'title'       => isset( $issue['title'] ) ? (string) $issue['title'] : __( 'Compatibility notice', 'nbh-performance' ),
				'message'     => isset( $issue['message'] ) ? (string) $issue['message'] : '',
				'description' => isset( $issue['detail'] ) ? (string) $issue['detail'] : '',
				'actions'     => isset( $issue['actions'] ) && is_array( $issue['actions'] ) ? $issue['actions'] : array(),
			);
		}
		foreach ( $this->conflicts->report() as $issue ) {
			if ( $has_cache_remediation && isset( $issue['group'] ) && 'cache' === $issue['group'] ) {
				continue;
			}
			if ( $has_asset_remediation && isset( $issue['group'] ) && 'assets' === $issue['group'] ) {
				continue;
			}
			$id = isset( $issue['id'] ) ? sanitize_key( (string) $issue['id'] ) : '';
			if ( '' !== $id && isset( $seen[ $id ] ) ) {
				continue;
			}
			$items[] = $issue;
		}
		return $items;
	}

	private function image_library_status() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM %i WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN (%s,%s,%s,%s,%s)", $wpdb->posts, 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$converted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN (%s,%s,%s,%s,%s)", $wpdb->posts, $wpdb->postmeta, \NBH\Performance\Modules\ImageOptimization\ImageOptimizationPipeline::COMPLETE_META, 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit administrator progress/maintenance inventory needs current attachment or session totals while background workers modify them.
		$savings = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM %i pm WHERE pm.meta_key = %s', $wpdb->postmeta, '_nbh_performance_image_source_bytes_saved' ) );
		return array(
			'total_eligible'     => $total,
			'converted'          => $converted,
			'remaining'          => max( 0, $total - $converted ),
			'progress_percent'   => $total > 0 ? (int) round( ( $converted / $total ) * 100 ) : 100,
			'source_bytes_saved' => $savings,
			'webp_enabled'       => (bool) get_option( 'nbh_performance_generate_webp', 1 ),
			'avif_enabled'       => (bool) get_option( 'nbh_performance_generate_avif', 1 ),
			'conversion_enabled' => (bool) get_option( 'nbh_performance_image_conversion_enabled', 0 ),
			'serving_enabled'    => (bool) get_option( 'nbh_performance_nextgen_image_serving_enabled', 0 ),
			'safe_mode'          => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
			'queue'              => ( new JobStore() )->stats(),
		);
	}
	public function toggles( WP_REST_Request $request ) {
		$values = $request->get_param( 'values' );
		if ( ! is_array( $values ) ) {
			return new \WP_Error( 'nbh_performance_invalid_toggles', __( 'Invalid feature toggle payload.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$valid = $this->toggles->validate_values( $values );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$agency_access = $this->licenses->has_agency_access();
		$blocked       = $this->reject_agency_values( $values, 'features', $agency_access );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
		$compatibility = $this->remediation->preflight_toggles( $values );
		if ( is_wp_error( $compatibility ) ) {
			return $compatibility;
		}
		return rest_ensure_response(
			array(
				'updated'  => $this->toggles->update( $values ),
				'features' => $this->filtered_features( $agency_access ),
			)
		);
	}
	public function settings( WP_REST_Request $request ) {
		$values = $request->get_param( 'values' );
		if ( ! is_array( $values ) ) {
			return new \WP_Error( 'nbh_performance_invalid_settings', __( 'Invalid settings payload.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$valid = $this->settings->validate_values( $values );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$agency_access = $this->licenses->has_agency_access();
		$blocked       = $this->reject_agency_values( $values, 'settings', $agency_access );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
		return rest_ensure_response(
			array(
				'updated'  => $this->settings->update( $values ),
				'settings' => $this->filtered_settings( $agency_access ),
			)
		);
	}

	/** Return the normalized license status used by the dashboard. */
	private function license_status() {
		$status = $this->licenses->status();
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$status['is_agency'] = ! empty( $status['is_agency'] );
		return $status;
	}

	/** Hide Agency sections unless an active Agency entitlement is present. */
	private function filtered_features( $agency_access ) {
		$features = $this->toggles->state();
		if ( ! $agency_access ) {
			unset( $features['enterprise'] );
		}
		return $features;
	}

	/** Hide Agency settings unless an active Agency entitlement is present. */
	private function filtered_settings( $agency_access ) {
		$settings = $this->settings->state();
		if ( ! $agency_access ) {
			unset( $settings['enterprise'] );
		}
		return $settings;
	}

	/** All bundled Agency settings are available to authorized administrators. */
	private function reject_agency_values( array $values, $type, $agency_access ) {
		return true;
	}

	private function release_readiness() {
		$checks     = array();
		$checks[]   = $this->readiness_check( 'Page cache bootstrap', defined( 'WP_CACHE' ) && WP_CACHE && ( new DropInInstaller() )->is_ours( WP_CONTENT_DIR . '/advanced-cache.php' ), 'Enable WP_CACHE and let NBH Performance own advanced-cache.php.' );
		$checks[]   = $this->readiness_check( 'Safe Mode', ! (bool) get_option( 'nbh_performance_safe_mode', true ), 'Turn off Safe Mode only after staging validation passes.', 'warning' );
		$checks[]   = $this->readiness_check( 'Browser API', ( ! get_option( 'nbh_performance_critical_css_enabled', 0 ) && ! get_option( 'nbh_performance_unused_css_enabled', 0 ) ) || ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) ), 'Enable PHP DOM and the bundled CSS parser for conservative background generation.' );
		$validation = (array) get_option( 'nbh_performance_last_staging_validation', array() );
		$checks[]   = $this->readiness_check( 'Frontend validation', ! empty( $validation['passed'] ), 'Run desktop, mobile, form, and commerce validation on staging.' );
		$matrix     = (array) get_option( 'nbh_performance_compatibility_matrix', array() );
		$checks[]   = $this->readiness_check( 'Compatibility matrix', ! empty( $matrix['passed'] ), 'Run the compatibility matrix and resolve critical findings.' );
		$jobs       = ( new JobStore() )->stats();
		$checks[]   = $this->readiness_check( 'Background queue', empty( $jobs['failed'] ) && empty( $jobs['paused'] ), 'Resume workers and clear failed background jobs.' );
		$smart      = (array) get_option( 'nbh_performance_smart_optimize_last_run', array() );
		$smart_done = in_array( isset( $smart['status'] ) ? (string) $smart['status'] : '', array( 'complete', 'needs_review' ), true );
		$checks[]   = $this->readiness_check( 'Smart Optimize proof', $smart_done, 'Run Smart Optimize once and keep a measured before/after recovery point.', 'warning' );
		$first_win  = (array) get_option( 'nbh_performance_soft_launch_first_win', array() );
		$checks[]   = $this->readiness_check( 'First-win evidence', ! empty( $first_win['at'] ), 'Complete onboarding, then finish one Smart Optimize run to capture launch proof.', 'warning' );
		$passed     = count(
			array_filter(
				$checks,
				static function ( $check ) {
					return ! empty( $check['passed'] );
				}
			)
		);
		$total      = count( $checks );
		$score      = $total ? (int) round( ( $passed / $total ) * 100 ) : 0;
		return array(
			'score'        => $score,
			'passed'       => $passed,
			'total'        => $total,
			'status'       => 100 === $score ? 'ready' : ( $score >= 70 ? 'review' : 'not_ready' ),
			'checks'       => $checks,
			'generated_at' => time(),
		);
	}

	private function readiness_check( $label, $passed, $recommendation, $severity = 'critical' ) {
		return array(
			'label'          => (string) $label,
			'passed'         => (bool) $passed,
			'severity'       => $passed ? 'success' : sanitize_key( $severity ),
			'recommendation' => $passed ? '' : (string) $recommendation,
		);
	}

	public function onboarding( WP_REST_Request $request ) {
		$profile = sanitize_key( (string) $request->get_param( 'profile' ) );
		$skip    = rest_sanitize_boolean( $request->get_param( 'skip' ) );
		$allowed = array( 'business', 'woocommerce', 'shared', 'maximum', 'custom' );
		if ( ! $skip && ! in_array( $profile, $allowed, true ) ) {
			return new \WP_Error( 'nbh_performance_invalid_profile', __( 'Select a valid optimization profile.', 'nbh-performance' ), array( 'status' => 400 ) );
		}

		if ( ! $skip ) {
			$profiles        = $this->onboarding_profiles();
			$config          = isset( $profiles[ $profile ] ) ? $profiles[ $profile ] : $profiles['business'];
			$custom_features = $request->get_param( 'features' );
			$custom_settings = $request->get_param( 'settings' );
			if ( is_array( $custom_features ) ) {
				$config['features'] = array_merge( $config['features'], $custom_features );
			}
			if ( is_array( $custom_settings ) ) {
				$config['settings'] = array_merge( $config['settings'], $custom_settings );
			}
			$this->toggles->update( $config['features'] );
			$this->settings->update( $config['settings'] );
			$this->safe_mode->disable( false );
			update_option( 'nbh_performance_onboarding_profile', $profile, false );
		}

		update_option( 'nbh_performance_onboarding_completed', 1, false );
		update_option( 'nbh_performance_onboarding_completed_at', time(), false );
		do_action( 'nbh_performance_onboarding_completed', $profile, $skip );
		return rest_ensure_response( $this->status()->get_data() );
	}


	public function create_snapshot() {
		$snapshot = array(
			'created_at' => time(),
			'version'    => NBH_PERFORMANCE_VERSION,
			'features'   => $this->current_feature_values(),
			'settings'   => $this->current_setting_values(),
		);
		update_option( 'nbh_performance_configuration_snapshot', $snapshot, false );
		do_action( 'nbh_performance_configuration_snapshot_created', $snapshot );
		return rest_ensure_response(
			array(
				'success'  => true,
				'snapshot' => $this->snapshot_status(),
			)
		);
	}

	public function restore_snapshot() {
		$snapshot = get_option( 'nbh_performance_configuration_snapshot', array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot['created_at'] ) ) {
			return new \WP_Error( 'nbh_performance_snapshot_missing', __( 'No configuration snapshot is available.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		if ( isset( $snapshot['features'] ) && is_array( $snapshot['features'] ) ) {
			$this->toggles->update( $snapshot['features'] );
		}
		if ( isset( $snapshot['settings'] ) && is_array( $snapshot['settings'] ) ) {
			$this->settings->update( $snapshot['settings'] );
		}
		update_option( 'nbh_performance_configuration_snapshot_restored_at', time(), false );
		do_action( 'nbh_performance_configuration_snapshot_restored', $snapshot );
		return rest_ensure_response( $this->status()->get_data() );
	}

	public function export_configuration() {
		return rest_ensure_response(
			array(
				'product'     => 'NBH Performance',
				'version'     => NBH_PERFORMANCE_VERSION,
				'exported_at' => time(),
				'features'    => $this->current_feature_values(),
				'settings'    => $this->current_setting_values(),
			)
		);
	}

	public function import_configuration( WP_REST_Request $request ) {
		$configuration = $request->get_param( 'configuration' );
		if ( is_string( $configuration ) ) {
			if ( strlen( $configuration ) > 1048576 ) {
				return new \WP_Error( 'nbh_performance_import_too_large', __( 'The configuration file is larger than the 1 MB safety limit.', 'nbh-performance' ), array( 'status' => 413 ) );
			}
			$configuration = json_decode( $configuration, true );
		}
		$product = is_array( $configuration ) && isset( $configuration['product'] ) ? (string) $configuration['product'] : '';
		if ( ! is_array( $configuration ) || 'NBH Performance' !== $product ) {
			return new \WP_Error( 'nbh_performance_invalid_import', __( 'Select a valid NBH Performance configuration export.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$features = isset( $configuration['features'] ) && is_array( $configuration['features'] ) ? $configuration['features'] : array();
		$settings = isset( $configuration['settings'] ) && is_array( $configuration['settings'] ) ? $configuration['settings'] : array();
		if ( empty( $features ) && empty( $settings ) ) {
			return new \WP_Error( 'nbh_performance_empty_import', __( 'The configuration file does not contain importable settings.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$this->create_snapshot();
		$updated_features = $this->toggles->update( $features );
		$updated_settings = $this->settings->update( $settings );
		update_option( 'nbh_performance_configuration_imported_at', time(), false );
		do_action( 'nbh_performance_configuration_imported', $updated_features, $updated_settings );
		return rest_ensure_response(
			array(
				'success'           => true,
				'features_imported' => count( $updated_features ),
				'settings_imported' => count( $updated_settings ),
				'status'            => $this->status()->get_data(),
			)
		);
	}

	public function reset_configuration() {
		$this->create_snapshot();
		$feature_defaults = array();
		foreach ( $this->toggles->groups() as $group ) {
			foreach ( $group['features'] as $option => $definition ) {
				$feature_defaults[ $option ] = false;
			}
		}
		$setting_defaults = array();
		foreach ( $this->settings->schema() as $section ) {
			foreach ( $section['fields'] as $option => $definition ) {
				$setting_defaults[ $option ] = $definition['default'];
			}
		}
		$this->toggles->update( $feature_defaults );
		$this->settings->update( $setting_defaults );
		$this->safe_mode->enable();
		update_option( 'nbh_performance_configuration_reset_at', time(), false );
		do_action( 'nbh_performance_configuration_reset', $feature_defaults, $setting_defaults );
		return rest_ensure_response(
			array(
				'success'        => true,
				'features_reset' => count( $feature_defaults ),
				'settings_reset' => count( $setting_defaults ),
				'status'         => $this->status()->get_data(),
			)
		);
	}

	private function current_feature_values() {
		$values = array();
		foreach ( $this->toggles->groups() as $group ) {
			foreach ( $group['features'] as $option => $definition ) {
				$values[ $option ] = (bool) get_option( $option, 0 );
			}
		}
		return $values;
	}

	private function current_setting_values() {
		$values = array();
		foreach ( $this->settings->schema() as $section ) {
			foreach ( $section['fields'] as $option => $definition ) {
				$values[ $option ] = get_option( $option, $definition['default'] );
			}
		}
		return $values;
	}

	private function snapshot_status() {
		$snapshot = get_option( 'nbh_performance_configuration_snapshot', array() );
		return array(
			'available'   => is_array( $snapshot ) && ! empty( $snapshot['created_at'] ),
			'created_at'  => is_array( $snapshot ) ? absint( isset( $snapshot['created_at'] ) ? $snapshot['created_at'] : 0 ) : 0,
			'restored_at' => absint( get_option( 'nbh_performance_configuration_snapshot_restored_at', 0 ) ),
		);
	}

	public function reset_onboarding() {
		update_option( 'nbh_performance_onboarding_completed', 0, false );
		update_option( 'nbh_performance_onboarding_completed_at', 0, false );
		delete_option( 'nbh_performance_onboarding_profile' );
		return rest_ensure_response( array( 'reset' => true ) );
	}


	private function recommended_onboarding_profile() {
		if ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ) {
			return 'woocommerce';
		}
		$memory_limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : 0;
		if ( $memory_limit > 0 && $memory_limit < 268435456 ) {
			return 'shared';
		}
		return 'business';
	}

	private function onboarding_profiles() {
		$base = array(
			'nbh_performance_cache_enabled'             => 1,
			'nbh_performance_mobile_cache_enabled'      => 0,
			'nbh_performance_sitemap_preload_enabled'   => 1,
			'nbh_performance_css_minify_enabled'        => 1,
			'nbh_performance_js_minify_enabled'         => 1,
			'nbh_performance_defer_js_enabled'          => 1,
			'nbh_performance_lazy_load_enabled'         => 1,
			'nbh_performance_font_preload_enabled'      => 1,
			'nbh_performance_heartbeat_control_enabled' => 1,
		);
		return array(
			'business'    => array(
				'features' => $base,
				'settings' => array(
					'nbh_performance_cache_ttl'          => 3600,
					'nbh_performance_preload_batch_size' => 3,
					'nbh_performance_webp_quality'       => 82,
					'nbh_performance_avif_quality'       => 70,
					'nbh_performance_font_preload_limit' => 2,
				),
			),
			'woocommerce' => array(
				'features' => array_merge(
					$base,
					array(
						'nbh_performance_delay_js_enabled' => 0,
						'nbh_performance_database_cleanup_enabled' => 1,
					)
				),
				'settings' => array(
					'nbh_performance_cache_ttl'          => 1800,
					'nbh_performance_preload_batch_size' => 2,
					'nbh_performance_delay_timeout'      => 8000,
					'nbh_performance_webp_quality'       => 84,
					'nbh_performance_avif_quality'       => 72,
				),
			),
			'shared'      => array(
				'features' => array_merge(
					$base,
					array(
						'nbh_performance_sitemap_preload_enabled' => 0,
						'nbh_performance_defer_js_enabled' => 0,
					)
				),
				'settings' => array(
					'nbh_performance_cache_ttl'           => 7200,
					'nbh_performance_preload_batch_size'  => 1,
					'nbh_performance_database_batch_size' => 100,
					'nbh_performance_webp_quality'        => 80,
					'nbh_performance_avif_quality'        => 65,
				),
			),
			'maximum'     => array(
				'features' => array_merge(
					$base,
					array(
						'nbh_performance_delay_js_enabled' => 1,
						'nbh_performance_critical_css_enabled' => 1,
						'nbh_performance_unused_css_enabled' => 1,
						'nbh_performance_image_conversion_enabled' => 1,
						'nbh_performance_nextgen_image_serving_enabled' => 1,
						'nbh_performance_youtube_facade_enabled' => 1,
						'nbh_performance_rum_enabled'      => 1,
					)
				),
				'settings' => array(
					'nbh_performance_cache_ttl'          => 43200,
					'nbh_performance_preload_batch_size' => 6,
					'nbh_performance_delay_timeout'      => 6000,
					'nbh_performance_webp_quality'       => 82,
					'nbh_performance_avif_quality'       => 68,
					'nbh_performance_font_preload_limit' => 3,
				),
			),
			'custom'      => array(
				'features' => $base,
				'settings' => array(
					'nbh_performance_cache_ttl'          => 3600,
					'nbh_performance_preload_batch_size' => 3,
				),
			),
		);
	}


	public function recovery_stabilize() {
		$result = $this->product_quality->stabilize();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function recovery_resume() {
		$result = $this->product_quality->resume();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function safe_mode( WP_REST_Request $request ) {
		$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		$restore = rest_sanitize_boolean( $request->get_param( 'restore' ) );
		$enabled ? $this->safe_mode->enable() : $this->safe_mode->disable( $restore );
		return rest_ensure_response( $this->status()->get_data() );
	}
	private function latest_transaction() {
		$transactions = get_option( 'nbh_performance_configuration_transactions', array() );
		if ( ! is_array( $transactions ) || empty( $transactions ) || ! is_array( $transactions[0] ) ) {
			return array();
		}
		$record = $transactions[0];
		return array(
			'id'              => isset( $record['id'] ) ? sanitize_text_field( $record['id'] ) : '',
			'context'         => isset( $record['context'] ) ? sanitize_key( $record['context'] ) : '',
			'status'          => isset( $record['status'] ) ? sanitize_key( $record['status'] ) : '',
			'created_at'      => isset( $record['created_at'] ) ? absint( $record['created_at'] ) : 0,
			'updated_at'      => isset( $record['updated_at'] ) ? absint( $record['updated_at'] ) : 0,
			'rolled_back_at'  => isset( $record['rolled_back_at'] ) ? absint( $record['rolled_back_at'] ) : 0,
			'rollback_reason' => isset( $record['rollback_reason'] ) ? sanitize_text_field( $record['rollback_reason'] ) : '',
			'updated_count'   => isset( $record['updated'] ) && is_array( $record['updated'] ) ? count( $record['updated'] ) : 0,
			'validation'      => isset( $record['validation'] ) && is_array( $record['validation'] ) ? $record['validation'] : array(),
		);
	}

	private function account_connection() {
		return ( new \NBH\Performance\Modules\Licensing\AccountConnector() )->status();
	}

	private function soft_launch_proof() {
		$run   = (array) get_option( 'nbh_performance_smart_optimize_last_run', array() );
		$first = (array) get_option( 'nbh_performance_soft_launch_first_win', array() );
		$proof = isset( $run['proof_summary'] ) && is_array( $run['proof_summary'] ) ? $run['proof_summary'] : ( isset( $first['proof'] ) && is_array( $first['proof'] ) ? $first['proof'] : array() );
		$steps = isset( $run['plan']['steps'] ) && is_array( $run['plan']['steps'] ) ? $run['plan']['steps'] : array();
		return array(
			'status'             => isset( $run['status'] ) ? sanitize_key( (string) $run['status'] ) : 'not_started',
			'mode'               => isset( $run['mode'] ) ? sanitize_key( (string) $run['mode'] ) : '',
			'steps'              => $steps,
			'summary'            => $proof,
			'first_win_at'       => isset( $first['at'] ) ? absint( $first['at'] ) : 0,
			'onboarding_done'    => (bool) get_option( 'nbh_performance_onboarding_completed', 0 ),
			'show_first_win_cta' => (bool) get_option( 'nbh_performance_onboarding_completed', 0 ) && empty( $first['at'] ) && ! in_array( isset( $run['status'] ) ? (string) $run['status'] : '', array( 'applying', 'validating', 'measuring', 'complete' ), true ),
		);
	}

	private function cache_health() {
		$dropin  = WP_CONTENT_DIR . '/advanced-cache.php';
		$runtime = WP_CONTENT_DIR . '/cache/nbh-performance/runtime.json';
		$pages   = WP_CONTENT_DIR . '/cache/nbh-performance/pages';
		return array(
			'enabled'          => (bool) get_option( 'nbh_performance_cache_enabled', false ),
			'safe_mode'        => (bool) get_option( 'nbh_performance_safe_mode', true ),
			'wp_cache'         => defined( 'WP_CACHE' ) && WP_CACHE,
			'dropin_exists'    => is_file( $dropin ),
			'dropin_owned'     => is_file( $dropin ) ? ( new DropInInstaller() )->is_ours( $dropin ) : false,
			'runtime_readable' => is_readable( $runtime ),
			'pages_writable'   => is_dir( $pages ) && \NBH\Performance\Modules\Core\Filesystem::is_writable( $pages ),
			'last_warm'        => get_option( 'nbh_performance_last_warm_result', array() ),
			'last_write'       => get_option( 'nbh_performance_last_cache_write', array() ),
		);
	}

	/** Surface Critical CSS / browser-engine readiness for Hub messaging. */
	private function browser_generator_status() {
		$ready = ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) );
		return array(
			'state'            => $ready ? 'ready' : 'unconfigured',
			'message'          => $ready ? __( 'Built-in PHP background generation is ready. Conservative CSS analysis preserves uncertain rules; no browser screenshot verification is performed.', 'nbh-performance' ) : __( 'Enable the PHP DOM extension and restore the bundled CSS parser to use background CSS generation.', 'nbh-performance' ),
			'configured'       => $ready,
			'endpoint_ready'   => false,
			'secret_ready'     => false,
			'local_fallback'   => $ready,
			'critical_enabled' => (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ),
			'used_css_enabled' => (bool) get_option( 'nbh_performance_unused_css_enabled', 0 ),
		);
	}
}
