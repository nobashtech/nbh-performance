<?php
/**
 * Release and retention REST controls.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Release;

use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;

final class ReleaseController implements Module {
	/** @var DataRetentionPolicy */
	private $policy;

	public function __construct( DataRetentionPolicy $policy ) {
		$this->policy = $policy;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'nbh-performance/v1',
			'/release/retention',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'allowed' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'allowed' ),
				),
			)
		);
	}

	public function allowed() {
		return current_user_can( 'manage_options' );
	}

	public function status() {
		return rest_ensure_response( $this->policy->status() );
	}

	public function update( WP_REST_Request $request ) {
		$policy = (string) $request->get_param( 'policy' );
		if ( ! $this->policy->set( $policy ) ) {
			return new WP_Error( 'nbh_performance_invalid_retention_policy', __( 'Invalid data-retention policy.', 'nbh-performance' ), array( 'status' => 400 ) );
		}

		update_option( 'nbh_performance_uninstall_remove_cache', (int) rest_sanitize_boolean( $request->get_param( 'remove_cache_files' ) ), false );
		update_option( 'nbh_performance_uninstall_remove_dropin', (int) rest_sanitize_boolean( $request->get_param( 'remove_drop_in' ) ), false );

		do_action( 'nbh_performance_retention_policy_updated', $this->policy->status() );
		return rest_ensure_response( $this->policy->status() );
	}
}
