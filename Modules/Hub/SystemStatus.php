<?php
/**
 * Actionable administrator system status and compatibility notices.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\CacheEngine\DropInInstaller;
use NBH\Performance\Modules\Compatibility\CompatibilityRemediationManager;
use NBH\Performance\Modules\Core\Module;

final class SystemStatus implements Module {
	/** @var CompatibilityRemediationManager */
	private $remediation;

	/** @var DropInInstaller */
	private $dropin;

	public function __construct( CompatibilityRemediationManager $remediation, DropInInstaller $dropin ) {
		$this->remediation = $remediation;
		$this->dropin      = $dropin;
	}

	public function register() {
		add_action( 'admin_init', array( $this, 'reconcile_dropin_status' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/** Clear stale conflict state after another cache plugin/drop-in is removed. */
	public function reconcile_dropin_status() {
		if ( current_user_can( 'manage_options' ) ) {
			$this->dropin->reconcile();
		}
	}

	public function assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$asset_version = defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : NBH_PERFORMANCE_VERSION;
		wp_enqueue_style( 'nbh-performance-admin-notices', NBH_PERFORMANCE_URL . 'assets/css/admin-notices.css', array(), $asset_version );
		wp_enqueue_script( 'nbh-performance-admin-i18n-runtime', NBH_PERFORMANCE_URL . 'assets/js/admin-i18n-runtime.js', array( 'wp-i18n' ), $asset_version, true );
		wp_enqueue_script( 'nbh-performance-admin-notices', NBH_PERFORMANCE_URL . 'assets/js/admin-notices.js', array( 'wp-i18n', 'nbh-performance-admin-i18n-runtime' ), $asset_version, true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'nbh-performance-admin-notices', 'nbh-performance', NBH_PERFORMANCE_PATH . 'languages' );
		}
	}

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = $this->remediation->consume_result();
		if ( $result ) {
			$this->render_notice(
				array(
					'id'      => 'remediation-result',
					'tone'    => ! empty( $result['success'] ) ? 'success' : 'error',
					'title'   => ! empty( $result['success'] ) ? __( 'Compatibility repair completed', 'nbh-performance' ) : __( 'Compatibility repair needs attention', 'nbh-performance' ),
					'message' => isset( $result['message'] ) ? (string) $result['message'] : __( 'NBH Performance finished the requested action.', 'nbh-performance' ),
					'detail'  => ! empty( $result['success'] ) ? __( 'The site configuration was rechecked after the change.', 'nbh-performance' ) : __( 'No unverified file replacement was performed. Review the guidance and retry after correcting permissions or ownership.', 'nbh-performance' ),
					'actions' => array(),
				)
			);
		}

		foreach ( $this->remediation->issues() as $issue ) {
			$this->render_notice( $issue );
		}
	}

	/** @param array<string,mixed> $issue Notice data. */
	private function render_notice( array $issue ) {
		$tone    = isset( $issue['tone'] ) ? sanitize_key( $issue['tone'] ) : 'warning';
		$title   = isset( $issue['title'] ) ? (string) $issue['title'] : __( 'NBH Performance needs attention', 'nbh-performance' );
		$message = isset( $issue['message'] ) ? (string) $issue['message'] : '';
		$detail  = isset( $issue['detail'] ) ? (string) $issue['detail'] : '';
		$actions = isset( $issue['actions'] ) && is_array( $issue['actions'] ) ? $issue['actions'] : array();
		$icon    = 'success' === $tone ? 'yes-alt' : ( 'error' === $tone ? 'warning' : 'info-outline' );
		$id      = isset( $issue['id'] ) ? sanitize_key( (string) $issue['id'] ) : sanitize_key( $title );
		$popup   = $actions && in_array( $tone, array( 'warning', 'error' ), true );
		$class   = 'notice nbh-performance-notice nbh-performance-notice--' . $tone . ' is-dismissible';
		if ( $popup ) {
			$class .= ' nbh-performance-popup-issue';
		}

		echo '<div class="' . esc_attr( $class ) . '" data-nbh-issue-id="' . esc_attr( $id ) . '">';
		echo '<div class="nbh-performance-notice__icon"><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span></div>';
		echo '<div class="nbh-performance-notice__content">';
		echo '<strong class="nbh-performance-notice__title">' . esc_html( $title ) . '</strong>';
		if ( '' !== $message ) {
			echo '<p class="nbh-performance-notice__message">' . esc_html( $message ) . '</p>';
		}
		if ( '' !== $detail ) {
			echo '<p class="nbh-performance-notice__detail">' . esc_html( $detail ) . '</p>';
		}
		if ( $actions ) {
			echo '<div class="nbh-performance-notice__actions">';
			foreach ( $actions as $action ) {
				if ( empty( $action['url'] ) || empty( $action['label'] ) ) {
					continue;
				}
				$style   = isset( $action['style'] ) && 'primary' === $action['style'] ? 'button button-primary' : 'button button-secondary';
				$class   = $style;
				$confirm = ! empty( $action['confirm_title'] ) || ! empty( $action['confirm_message'] );
				if ( $confirm ) {
					$class .= ' nbh-performance-confirm-action';
				}
				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( (string) $action['url'] ) . '"';
				if ( $confirm ) {
					echo ' data-confirm-title="' . esc_attr( isset( $action['confirm_title'] ) ? (string) $action['confirm_title'] : '' ) . '"';
					echo ' data-confirm-message="' . esc_attr( isset( $action['confirm_message'] ) ? (string) $action['confirm_message'] : '' ) . '"';
					echo ' data-confirm-label="' . esc_attr( (string) $action['label'] ) . '"';
				}
				echo '>' . esc_html( (string) $action['label'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div></div>';
	}
}
