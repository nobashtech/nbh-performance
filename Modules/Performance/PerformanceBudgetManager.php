<?php
/**
 * Page-level performance budget telemetry and regression guard.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class PerformanceBudgetManager implements Module {
	/** Register hooks. */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'measure_assets' ), PHP_INT_MAX );
		add_filter( 'nbh_performance_performance_budget_report', array( $this, 'report' ) );
	}

	/** Store bounded counts for diagnostics. */
	public function measure_assets() {
		if ( is_admin() || ! get_option( 'nbh_performance_performance_budgets_enabled', 0 ) ) {
			return;
		}
		$scripts          = count( (array) wp_scripts()->queue );
		$styles           = count( (array) wp_styles()->queue );
		$report           = array(
			'captured_at' => time(),
			'scripts'     => $scripts,
			'styles'      => $styles,
			'limits'      => array(
				'scripts' => max( 5, absint( get_option( 'nbh_performance_budget_script_count', 30 ) ) ),
				'styles'  => max( 5, absint( get_option( 'nbh_performance_budget_style_count', 20 ) ) ),
			),
		);
		$report['passed'] = $scripts <= $report['limits']['scripts'] && $styles <= $report['limits']['styles'];
		update_option( 'nbh_performance_performance_budget_last', $report, false );
		do_action( 'nbh_performance_performance_budget_measured', $report );
	}

	/** @param mixed $default_value Default. @return array<string, mixed> */
	public function report( $default_value = array() ) {
		$report = get_option( 'nbh_performance_performance_budget_last', array() );
		return is_array( $report ) ? $report : (array) $default_value;
	}
}
