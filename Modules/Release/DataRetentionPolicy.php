<?php
/**
 * Uninstall data-retention policy.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Release;

final class DataRetentionPolicy {
	const OPTION = 'nbh_performance_uninstall_data_policy';

	/**
	 * Return the configured policy.
	 *
	 * @return string
	 */
	public function get() {
		$policy  = (string) get_option( self::OPTION, 'retain' );
		$allowed = $this->purge_allowed() ? array( 'retain', 'delete_settings', 'delete_all' ) : array( 'retain' );

		return in_array( $policy, $allowed, true ) ? $policy : 'retain';
	}

	/**
	 * Persist a validated policy.
	 *
	 * @param string $policy Requested policy.
	 * @return bool
	 */
	public function set( $policy ) {
		$policy  = sanitize_key( $policy );
		$allowed = $this->purge_allowed() ? array( 'retain', 'delete_settings', 'delete_all' ) : array( 'retain' );

		if ( ! in_array( $policy, $allowed, true ) ) {
			return false;
		}

		return update_option( self::OPTION, $policy, false );
	}

	/**
	 * Public status payload.
	 *
	 * @return array<string,mixed>
	 */
	public function status() {
		return array(
			'policy'              => $this->get(),
			'remove_cache_files'  => (bool) get_option( 'nbh_performance_uninstall_remove_cache', 1 ),
			'remove_drop_in'      => (bool) get_option( 'nbh_performance_uninstall_remove_dropin', 1 ),
			'available_policies'  => $this->purge_allowed() ? array( 'retain', 'delete_settings', 'delete_all' ) : array( 'retain' ),
			'purge_allowed'       => $this->purge_allowed(),
			'persistence_message' => __( 'NBH Performance settings are retained after uninstall and plugin replacement.', 'nbh-performance' ),
		);
	}

	/**
	 * Whether destructive uninstall controls were explicitly enabled.
	 *
	 * @return bool
	 */
	private function purge_allowed() {
		return defined( 'NBH_PERFORMANCE_ALLOW_UNINSTALL_DATA_PURGE' ) && true === NBH_PERFORMANCE_ALLOW_UNINSTALL_DATA_PURGE;
	}
}
