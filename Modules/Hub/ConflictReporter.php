<?php
/** Runtime conflict and ownership reporting. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\Compatibility\ConflictDiagnosis;
final class ConflictReporter {
	private $diagnosis;
	public function __construct( ConflictDiagnosis $diagnosis ) {
		$this->diagnosis = $diagnosis;
	}
	public function report() {
		$items = array();
		foreach ( $this->diagnosis->diagnose() as $issue ) {
			$items[] = array(
				'severity'    => $issue['severity'],
				'message'     => $issue['message'],
				'id'          => $issue['id'],
				'group'       => $issue['group'],
				'recoverable' => $issue['recoverable'],
			);
		}
		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			$items[] = array(
				'severity'    => 'warning',
				'message'     => __( 'WP_CACHE is not enabled; early page-cache serving is unavailable.', 'nbh-performance' ),
				'id'          => 'wp-cache-disabled',
				'group'       => 'cache',
				'recoverable' => false,
			);
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$items[] = array(
				'severity'    => 'info',
				'message'     => __( 'WooCommerce protection is active for cart, checkout, account and session requests.', 'nbh-performance' ),
				'id'          => 'woocommerce-protected',
				'group'       => 'commerce',
				'recoverable' => false,
			);
		}
		if ( is_multisite() ) {
			$items[] = array(
				'severity'    => 'warning',
				'message'     => __( 'Page caching is single-site only in this build. On multisite, page cache stays fail-closed until a network-aware early runtime ships.', 'nbh-performance' ),
				'id'          => 'multisite-page-cache',
				'group'       => 'cache',
				'recoverable' => false,
			);
		}
		return apply_filters( 'nbh_performance_conflict_report', $items );
	}
}
