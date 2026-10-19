<?php
/** Privacy-safe support report exporter. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Performance\PriorityElementsStorage;
use NBH\Performance\Modules\PerformanceIntelligence\LabTestStorage;
use NBH\Performance\Modules\Core\PatternMatcher;

final class SupportReportExporter {
	private $scanner;
	private $health;
	private $logger;
	private $runbook;
	public function __construct( ProductionReadinessScanner $scanner, ?OperationsHealthMonitor $health = null, ?OperationalLogger $logger = null, ?RecoveryRunbook $runbook = null ) {
		$this->scanner = $scanner;
		$this->health  = $health;
		$this->logger  = $logger;
		$this->runbook = $runbook;
	}
	public function build() {
		global $wp_version;
		$report     = array(
			'generated_at'              => gmdate( 'c' ),
			'nbh-performance'           => array(
				'version'       => NBH_PERFORMANCE_VERSION,
				'safe_mode'     => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
				'cache_enabled' => (bool) get_option( 'nbh_performance_cache_enabled', 0 ),
			),
			'wordpress'                 => array(
				'version'   => (string) $wp_version,
				'multisite' => is_multisite(),
				'locale'    => determine_locale(),
			),
			'php'                       => array(
				'version'      => PHP_VERSION,
				'sapi'         => PHP_SAPI,
				'memory_limit' => (string) ini_get( 'memory_limit' ),
			),
			'environment'               => array(
				'https'        => is_ssl(),
				'wp_cache'     => defined( 'WP_CACHE' ) && WP_CACHE,
				'object_cache' => wp_using_ext_object_cache(),
			),
			'cache_stats'               => (array) get_option( 'nbh_performance_cache_stats', array() ),
			'asset_inventory'           => (array) get_option( 'nbh_performance_asset_inventory_snapshot', array() ),
			'performance_budget'        => (array) get_option( 'nbh_performance_performance_budget_last', array() ),
			'reliability'               => array(
				'jobs'                 => ( new JobStore() )->stats(),
				'compatibility_matrix' => (array) get_option( 'nbh_performance_compatibility_matrix', array() ),
				'last_validation'      => (array) get_option( 'nbh_performance_last_staging_validation', array() ),
				'latest_transaction'   => $this->latest_transaction(),
			),
			'performance_intelligence'  => array(
				'lab_testing_enabled'   => (bool) get_option( 'nbh_performance_lab_testing_enabled', 0 ),
				'latest_result_count'   => count( ( new LabTestStorage() )->latest( 30 ) ),
				'open_regression_count' => count(
					array_filter(
						(array) get_option( 'nbh_performance_performance_regression_alerts', array() ),
						static function ( $alert ) {
							return empty( $alert['acknowledged'] );
						}
					)
				),
				'impact_record_count'   => count( (array) get_option( 'nbh_performance_performance_change_impacts', array() ) ),
				'frequency'             => sanitize_key( get_option( 'nbh_performance_lab_test_frequency', 'weekly' ) ),
			),
			'browser_intelligence'      => array(
				'priority_elements'    => ( new PriorityElementsStorage() )->stats(),
				'critical_css_enabled' => (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ),
				'unused_css_enabled'   => (bool) get_option( 'nbh_performance_unused_css_enabled', 0 ),
				'visual_guard_enabled' => (bool) get_option( 'nbh_performance_visual_regression_guard_enabled', 1 ),
				'visual_failure_count' => count( (array) get_option( 'nbh_performance_visual_regression_failures', array() ) ),
				'generator_configured' => ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) ),
			),
			'enterprise'                => array(
				'edge_html_enabled'        => (bool) get_option( 'nbh_performance_edge_html_cache_enabled', 0 ),
				'edge_webhook_configured'  => (bool) get_option( 'nbh_performance_edge_purge_webhook_url', '' ),
				'edge_last_purge'          => (array) get_option( 'nbh_performance_edge_last_purge', array() ),
				'cache_tag_stats'          => (array) get_option( 'nbh_performance_cache_tag_stats', array() ),
				'ai_gateway_configured'    => (bool) get_option( 'nbh_performance_ai_gateway_token_hash', '' ),
				'ai_gateway_scope'         => sanitize_key( (string) get_option( 'nbh_performance_ai_gateway_scope', 'read' ) ),
				'ai_gateway_write_enabled' => (bool) get_option( 'nbh_performance_ai_gateway_write_enabled', 0 ),
				'ai_pending_approvals'     => $this->pending_ai_approvals(),
				'agency_reports_enabled'   => (bool) get_option( 'nbh_performance_agency_reports_enabled', 1 ),
				'redis_topology'           => sanitize_key( (string) get_option( 'nbh_performance_object_cache_redis_mode', 'standalone' ) ),
				'redis_node_count'         => count( (array) get_option( 'nbh_performance_object_cache_redis_nodes', array() ) ),
			),
			'operations'                => $this->health ? $this->health->snapshot() : array(),
			'recent_operational_events' => $this->logger ? $this->logger->recent( 50 ) : array(),
			'recovery_runbook'          => $this->runbook ? $this->runbook->steps() : array(),
			'product_quality'           => array(
				'accessibility_target' => 'WCAG 2.2 AA',
				'text_domain'          => 'nbh-performance',
				'locale'               => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
				'rtl'                  => is_rtl(),
				'onboarding_completed' => (bool) get_option( 'nbh_performance_onboarding_completed', 0 ),
				'recovery_center'      => (array) get_option( 'nbh_performance_recovery_center_state', array() ),
			),
			'readiness'                 => $this->scanner->scan(),
		);
		$exclusions = $this->diagnostic_exclusions();
		foreach ( array_keys( $report ) as $section ) {
			if ( PatternMatcher::matches_any( $section, $exclusions ) ) {
				unset( $report[ $section ] );
			}
		}
		return $report;
	}


	private function diagnostic_exclusions() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_diagnostic_exclusions', '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	private function pending_ai_approvals() {
		$approvals = get_option( 'nbh_performance_ai_gateway_approvals', array() );
		if ( ! is_array( $approvals ) ) {
			return 0;
		}
		return count(
			array_filter(
				$approvals,
				static function ( $approval ) {
					return is_array( $approval ) && 'pending' === ( isset( $approval['status'] ) ? $approval['status'] : '' ) && absint( isset( $approval['expires_at'] ) ? $approval['expires_at'] : 0 ) >= time();
				}
			)
		);
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
			'updated_count'   => isset( $record['updated'] ) && is_array( $record['updated'] ) ? count( $record['updated'] ) : 0,
			'validation'      => isset( $record['validation'] ) && is_array( $record['validation'] ) ? $record['validation'] : array(),
			'rollback_reason' => isset( $record['rollback_reason'] ) ? sanitize_text_field( $record['rollback_reason'] ) : '',
		);
	}
}
