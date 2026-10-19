<?php
/** Dedicated Script Manager administration screen (redirects into the Hub). @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class ScriptManagerAdminPage implements Module {
	/** @var string */
	private $hook_suffix = '';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ), 1 );
	}

	public function menu() {
		// Keep the legacy slug for bookmarks and toolbar links; content lives in the Hub.
		$this->hook_suffix = (string) add_submenu_page( null, __( 'NBH Performance Script Manager', 'nbh-performance' ), __( 'NBH Performance Script Manager', 'nbh-performance' ), 'manage_options', 'nbh-performance-script-manager', array( $this, 'render' ) );
	}

	public function maybe_redirect() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'nbh-performance-script-manager' !== $page ) {
			return;
		}
		$args = array( 'page' => 'nbh-performance' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['inspect_url'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['inspect_url'] = esc_url_raw( wp_unslash( $_GET['inspect_url'] ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?' . http_build_query( $args ) ) . '#script_manager' );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><p>' . esc_html__( 'Opening Script Manager in NBH Performance…', 'nbh-performance' ) . '</p></div>';
	}
}
