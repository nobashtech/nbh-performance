<?php
/** About & Updates Hub status and safe update operations. @package NBHPerformance */
namespace NBH\Performance\Modules\Licensing;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Release\SupplyChainController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class LicenseUpdatesController implements Module {
	/** @var LicenseManager */
	private $licenses;

	/** @var AccountConnector */
	private $account;

	/** @var UpdateRollbackManager */
	private $rollback;

	/** @var SupplyChainController */
	private $supply_chain;

	public function __construct( LicenseManager $licenses, AccountConnector $account, UpdateRollbackManager $rollback, SupplyChainController $supply_chain ) {
		$this->licenses     = $licenses;
		$this->account      = $account;
		$this->rollback     = $rollback;
		$this->supply_chain = $supply_chain;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/license-hub/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/license-hub/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_rollback' ),
				'permission_callback' => array( $this, 'permission_updates' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to view plugin update information.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function permission_updates( WP_REST_Request $request ) {
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to change update settings.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function status() {
		$license  = $this->licenses->status();
		$account  = $this->account->status();
		$rollback = $this->rollback->hub_status();
		$supply   = $this->supply_chain->status();
		$supply   = is_object( $supply ) && method_exists( $supply, 'get_data' ) ? $supply->get_data() : (array) $supply;
		$status   = isset( $license['status'] ) ? sanitize_key( (string) $license['status'] ) : 'inactive';
		$tier     = isset( $license['tier'] ) ? sanitize_key( (string) $license['tier'] ) : 'free';
		$active   = in_array( $status, array( 'active', 'valid', 'grace' ), true );

		return rest_ensure_response(
			array(
				'license'       => $license,
				'account'       => $account,
				'rollback'      => $rollback,
				'supply_chain'  => $supply,
				'plugin'        => array(
					'version' => defined( 'NBH_PERFORMANCE_VERSION' ) ? NBH_PERFORMANCE_VERSION : '',
					'build'   => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
				),
				'runtime_ready' => $active,
				'active'        => $active,
				'tier'          => $tier,
				'modules'       => array(
					array(
						'id'       => 'critical_css',
						'label'    => __( 'Critical CSS', 'nbh-performance' ),
						'unlocked' => $active,
					),
					array(
						'id'       => 'unused_css',
						'label'    => __( 'Unused CSS Removal', 'nbh-performance' ),
						'unlocked' => $active,
					),
					array(
						'id'       => 'rum',
						'label'    => __( 'Real User Monitoring', 'nbh-performance' ),
						'unlocked' => $active,
					),
					array(
						'id'       => 'headless_preload',
						'label'    => __( 'Headless Preload', 'nbh-performance' ),
						'unlocked' => $active,
					),
					array(
						'id'       => 'advanced_script_delay',
						'label'    => __( 'Advanced Script Delay', 'nbh-performance' ),
						'unlocked' => $active,
					),
				),
				'is_agency'     => ! empty( $license['is_agency'] ),
				'safe_mode'     => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
			)
		);
	}

	public function restore_rollback() {
		$result = $this->rollback->request_manual_restore();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['license_hub'] = $this->status()->get_data();
		return rest_ensure_response( $result );
	}
}
