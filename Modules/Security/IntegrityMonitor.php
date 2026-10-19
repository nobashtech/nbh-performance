<?php
/** Runtime verification of the signed NBH Performance release. @package NBHPerformance */
namespace NBH\Performance\Modules\Security;

final class IntegrityMonitor {
	const TRANSIENT = 'nbh_performance_integrity_report';

	/** @return array<string,mixed> */
	public function verify( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$report = ( new IntegrityVerifier() )->verify( $force );
		set_transient( self::TRANSIENT, $report, HOUR_IN_SECONDS );
		return $report;
	}
}
