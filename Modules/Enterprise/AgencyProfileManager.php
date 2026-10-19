<?php
/**
 * Reusable agency optimization profiles.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Enterprise;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\AdvancedSettingsManager;
use NBH\Performance\Modules\Hub\ConfigurationTransactionManager;
use NBH\Performance\Modules\Hub\FeatureToggleManager;
use NBH\Performance\Modules\Licensing\LicenseManager;
use NBH\Performance\Modules\Security\AuditLogger;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class AgencyProfileManager implements Module {
	const OPTION = 'nbh_performance_agency_profiles';

	/** @var FeatureToggleManager */
	private $toggles;
	/** @var AdvancedSettingsManager */
	private $settings;
	/** @var ConfigurationTransactionManager */
	private $transactions;
	/** @var AuditLogger */
	private $audit;
	/** @var LicenseManager */
	private $licenses;

	public function __construct( FeatureToggleManager $toggles, AdvancedSettingsManager $settings, ConfigurationTransactionManager $transactions, AuditLogger $audit, LicenseManager $licenses ) {
		$this->toggles      = $toggles;
		$this->settings     = $settings;
		$this->transactions = $transactions;
		$this->audit        = $audit;
		$this->licenses     = $licenses;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/profiles',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_list' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/profiles/save',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_save' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/profiles/apply',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_apply' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/profiles/delete',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'rest_delete' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}

	public function rest_list() {
		return rest_ensure_response(
			array(
				'profiles' => array_values( $this->all() ),
				'active'   => sanitize_key( (string) get_option( 'nbh_performance_agency_active_profile', '' ) ),
			)
		);
	}

	public function rest_save( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'nbh_performance_profile_name_required', __( 'Enter a profile name.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$id              = sanitize_key( (string) $request->get_param( 'id' ) );
		$id              = '' !== $id ? $id : sanitize_key( $name . '-' . substr( wp_generate_uuid4(), 0, 8 ) );
		$profiles        = $this->all();
		$profile         = array(
			'id'          => $id,
			'name'        => $name,
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'features'    => $this->feature_values(),
			'settings'    => $this->setting_values(),
			'created_at'  => isset( $profiles[ $id ]['created_at'] ) ? absint( $profiles[ $id ]['created_at'] ) : time(),
			'updated_at'  => time(),
			'build'       => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
		);
		$profiles[ $id ] = $profile;
		update_option( self::OPTION, array_slice( $profiles, -50, null, true ), false );
		$this->audit->record( 'agency_profile_saved', array( 'profile_id' => $id ), 'info' );
		return rest_ensure_response(
			array(
				'success' => true,
				'profile' => $profile,
			)
		);
	}

	public function rest_apply( WP_REST_Request $request ) {
		$id       = sanitize_key( (string) $request->get_param( 'id' ) );
		$profiles = $this->all();
		if ( empty( $profiles[ $id ] ) ) {
			return new \WP_Error( 'nbh_performance_profile_missing', __( 'The selected agency profile does not exist.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		$profile  = $profiles[ $id ];
		$options  = array_merge( array_keys( $profile['features'] ), array_keys( $profile['settings'] ) );
		$tx_id    = $this->transactions->begin( 'agency_profile', $options );
		$features = $this->toggles->update( (array) $profile['features'] );
		$settings = $this->settings->update( (array) $profile['settings'] );
		$this->transactions->finish( 'agency_profile', array_merge( $features, $settings ) );
		update_option( 'nbh_performance_agency_active_profile', $id, false );
		$this->audit->record(
			'agency_profile_applied',
			array(
				'profile_id'     => $id,
				'transaction_id' => $tx_id,
			),
			'warning'
		);
		return rest_ensure_response(
			array(
				'success'        => true,
				'profile'        => $profile,
				'transaction_id' => $tx_id,
				'features'       => $features,
				'settings'       => $settings,
			)
		);
	}

	public function rest_delete( WP_REST_Request $request ) {
		$id       = sanitize_key( (string) $request->get_param( 'id' ) );
		$profiles = $this->all();
		if ( empty( $profiles[ $id ] ) ) {
			return new \WP_Error( 'nbh_performance_profile_missing', __( 'The selected agency profile does not exist.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		unset( $profiles[ $id ] );
		update_option( self::OPTION, $profiles, false );
		if ( get_option( 'nbh_performance_agency_active_profile', '' ) === $id ) {
			delete_option( 'nbh_performance_agency_active_profile' );
		}
		$this->audit->record( 'agency_profile_deleted', array( 'profile_id' => $id ), 'warning' );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Return summary for dashboard. */
	public function summary() {
		$profiles = $this->all();
		return array(
			'count'  => count( $profiles ),
			'active' => sanitize_key( (string) get_option( 'nbh_performance_agency_active_profile', '' ) ),
			'latest' => $profiles ? end( $profiles ) : array(),
		);
	}

	private function all() {
		$profiles = get_option( self::OPTION, array() );
		return is_array( $profiles ) ? $profiles : array();
	}

	private function feature_values() {
		$values = array();
		foreach ( $this->toggles->groups() as $group ) {
			foreach ( $group['features'] as $option => $definition ) {
				unset( $definition );
				$values[ $option ] = (bool) get_option( $option, 0 );
			}
		}
		return $values;
	}

	private function setting_values() {
		$values = array();
		foreach ( $this->settings->schema() as $section ) {
			foreach ( $section['fields'] as $option => $definition ) {
				if ( $this->secret_option( $option ) ) {
					continue;
				}
				$values[ $option ] = get_option( $option, $definition['default'] );
			}
		}
		return $values;
	}

	private function secret_option( $option ) {
		return (bool) preg_match( '/(?:token|secret|password|api_key|license)/i', (string) $option );
	}
}
