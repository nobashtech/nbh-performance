<?php
/** License REST endpoints. @package NBHPerformance */
namespace NBH\Performance\Modules\Licensing;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Server;
final class LicenseController implements Module {
	private $licenses;
	public function __construct( LicenseManager $licenses ) {
		$this->licenses = $licenses;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes() {
		$p = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/license/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $p,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/license/refresh',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'refresh' ),
				'permission_callback' => $p,
			)
		);
	}
	public function status() {
		return rest_ensure_response( $this->licenses->status() );
	}
	public function refresh() {
		$r = $this->licenses->refresh();
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}
}
