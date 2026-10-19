<?php
/** Phase 8 compatibility platform API and shared rules. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class CompatibilityPlatform implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'nbh_performance_compatibility_profiles', array( $this, 'profiles' ) );
		add_filter( 'nbh_performance_asset_exclusion_patterns', array( $this, 'global_asset_rules' ) );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'global_protected_scripts' ) );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/compatibility/integrations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}
	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}
	public function status() {
		return rest_ensure_response(
			array(
				'summary'      => $this->registry->summary(),
				'integrations' => $this->registry->all(),
				'checked_at'   => time(),
			)
		);
	}
	public function profiles( $profiles ) {
		return array_merge( (array) $profiles, $this->registry->all() );
	}
	public function global_asset_rules( $patterns ) {
		return array_values( array_unique( array_merge( (array) $patterns, array( '/wp-admin/', '/wp-json/', 'admin-ajax.php', 'wc-ajax=', 'elementor-preview=', 'et_fb=', 'fl_builder', 'ct_builder=', 'bricks=run' ) ) ) );
	}
	public function global_protected_scripts( $handles ) {
		return array_values( array_unique( array_merge( (array) $handles, array( 'jquery', 'jquery-core', 'jquery-migrate', 'wp-hooks', 'wp-i18n', 'wp-api-fetch', 'wp-element', 'wp-components', 'wp-data', 'wp-compose' ) ) ) );
	}
}
