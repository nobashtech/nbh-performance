<?php
/**
 * Controls WordPress Heartbeat frequency without disabling critical screens.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ServerOptimization;

use NBH\Performance\Modules\Core\Module;

final class HeartbeatController implements Module {
	/** Register hooks. */
	public function register() {
		add_filter( 'heartbeat_settings', array( $this, 'filter_settings' ) );
		add_action( 'init', array( $this, 'maybe_disable_heartbeat' ), 20 );
	}

	/**
	 * Adjust Heartbeat interval.
	 *
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public function filter_settings( $settings ) {
		if ( $this->is_safe_mode() || ! get_option( 'nbh_performance_heartbeat_control_enabled', 0 ) ) {
			return $settings;
		}

		$context  = $this->context();
		$interval = $this->interval_for_context( $context );

		if ( $interval > 0 ) {
			$settings['interval'] = $interval;
		}

		return $settings;
	}

	/** Disable Heartbeat only in explicitly selected non-critical contexts. */
	public function maybe_disable_heartbeat() {
		if ( $this->is_safe_mode() || ! get_option( 'nbh_performance_heartbeat_control_enabled', 0 ) ) {
			return;
		}

		$context = $this->context();
		$mode    = (string) get_option( 'nbh_performance_heartbeat_' . $context . '_mode', 'reduce' );

		if ( 'disable' !== $mode || $this->is_critical_screen() ) {
			return;
		}

		wp_deregister_script( 'heartbeat' );
	}

	/** @return string */
	private function context() {
		if ( is_admin() ) {
			return 'editor' === $this->admin_screen_type() ? 'editor' : 'admin';
		}

		return 'frontend';
	}

	/** @return string */
	private function admin_screen_type() {
		global $pagenow;
		return in_array( $pagenow, array( 'post.php', 'post-new.php', 'site-editor.php' ), true ) ? 'editor' : 'admin';
	}

	/** @return bool */
	private function is_critical_screen() {
		if ( 'editor' === $this->admin_screen_type() ) {
			return true;
		}

		global $pagenow;
		return in_array( $pagenow, array( 'plugin-install.php', 'update-core.php', 'update.php' ), true );
	}

	/**
	 * @param string $context Context name.
	 * @return int
	 */
	private function interval_for_context( $context ) {
		$defaults = array(
			'frontend' => 120,
			'admin'    => 60,
			'editor'   => 30,
		);
		$interval = (int) get_option( 'nbh_performance_heartbeat_' . $context . '_interval', $defaults[ $context ] );
		$interval = max( 15, min( 300, $interval ) );

		return (int) apply_filters( 'nbh_performance_heartbeat_interval', $interval, $context );
	}

	/** @return bool */
	private function is_safe_mode() {
		return (bool) get_option( 'nbh_performance_safe_mode', 1 );
	}
}
