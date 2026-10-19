<?php
/** One-click emergency rollback manager. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

final class SafeModeManager {
	const SNAPSHOT_OPTION = 'nbh_performance_safe_mode_snapshot';
	private $toggles;
	public function __construct( FeatureToggleManager $toggles ) {
		$this->toggles = $toggles;
	}
	public function enable() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) && get_option( self::SNAPSHOT_OPTION, array() ) ) {
			return true;
		}
		$snapshot = array();
		foreach ( $this->toggles->groups() as $group ) {
			foreach ( array_keys( $group['features'] ) as $option ) {
				$snapshot[ $option ] = (int) get_option( $option, 0 );
			}
		}
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
		update_option( 'nbh_performance_safe_mode', 1, false );
		foreach ( array_keys( $snapshot ) as $option ) {
			update_option( $option, 0, false );
		}
		do_action( 'nbh_performance_safe_mode_enabled', $snapshot );
		return true;
	}
	public function disable( $restore = false ) {
		update_option( 'nbh_performance_safe_mode', 0, false );
		if ( $restore ) {
			$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
			if ( is_array( $snapshot ) ) {
				$this->toggles->update( $snapshot );
			}
		}
		do_action( 'nbh_performance_safe_mode_disabled', (bool) $restore );
		return true;
	}
	public function status() {
		return array(
			'enabled'            => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
			'snapshot_available' => (bool) get_option( self::SNAPSHOT_OPTION, array() ),
		);
	}
}
