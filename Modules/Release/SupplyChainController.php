<?php
/** Supply-chain status without exposing secrets or local paths. @package NBHPerformance */
namespace NBH\Performance\Modules\Release;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Server;

final class SupplyChainController implements Module {
	public function register() {
		add_action(
			'rest_api_init',
			function () {
				nbh_performance_register_rest_route(
					'nbh-performance/v1',
					'/release/supply-chain',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'status' ),
						'permission_callback' => static function () {
							return current_user_can( 'update_plugins' );
						},
					)
				);
			}
		);
	}
	public function status() {
		$sbom       = NBH_PERFORMANCE_PATH . 'sbom.cyclonedx.json';
		$provenance = NBH_PERFORMANCE_PATH . 'build-provenance.json';
		return rest_ensure_response(
			array(
				'plugin_version'        => NBH_PERFORMANCE_VERSION,
				'integrity_manifest'    => is_file( NBH_PERFORMANCE_PATH . 'release-integrity.json' ),
				'sbom_present'          => is_file( $sbom ),
				'sbom_sha256'           => is_file( $sbom ) ? hash_file( 'sha256', $sbom ) : '',
				'provenance_present'    => is_file( $provenance ),
				'provenance_sha256'     => is_file( $provenance ) ? hash_file( 'sha256', $provenance ) : '',
				'licensing_provider'    => 'local',
				'package_hash_required' => defined( 'NBH_PERFORMANCE_RELEASE_INTEGRITY_ENFORCED' ) && NBH_PERFORMANCE_RELEASE_INTEGRITY_ENFORCED,
				'allowed_package_hosts' => array( 'downloads.wordpress.org' ),
			)
		);
	}
}
