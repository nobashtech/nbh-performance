<?php
/** Plugin detection utilities. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

final class PluginDetector {
	/** @return bool */
	public function active( $plugin ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $plugin, $active, true ) ) {
			return true;
		}
		if ( is_multisite() ) {
			return isset( get_site_option( 'active_sitewide_plugins', array() )[ $plugin ] );
		}
		return false;
	}
	/** @return bool */
	public function any( array $plugins ) {
		foreach ( $plugins as $plugin ) {
			if ( $this->active( $plugin ) ) {
				return true;
			}
		}
		return false;
	}
}
