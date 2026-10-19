<?php
/** Privacy-safe entitlement and quota status. @package NBHPerformance */
namespace NBH\Performance\Modules\Licensing;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Server;

final class EntitlementController implements Module {
	private $licenses;
	public function __construct( LicenseManager $licenses ) {
		$this->licenses = $licenses;
	}
	public function register() {
		add_action(
			'rest_api_init',
			function () {
				nbh_performance_register_rest_route(
					'nbh-performance/v1',
					'/license/entitlements',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'status' ),
						'permission_callback' => static function () {
							return current_user_can( 'manage_options' );
						},
					)
				);
			}
		);
	}
	public function status() {
		$status = $this->licenses->status();
		return rest_ensure_response(
			array(
				'plan'                  => isset( $status['plan'] ) ? $status['plan'] : $status['tier'],
				'entitlements'          => isset( $status['entitlements'] ) ? $status['entitlements'] : array(),
				'quotas'                => isset( $status['quotas'] ) ? $status['quotas'] : array(),
				'credential_expires_at' => isset( $status['credential_expires_at'] ) ? absint( $status['credential_expires_at'] ) : 0,
				'status'                => $status['status'],
			)
		);
	}
}
