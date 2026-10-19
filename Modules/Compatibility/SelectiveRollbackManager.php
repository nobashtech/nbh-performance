<?php
/** Selective compatibility rollback controller. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Server;

final class SelectiveRollbackManager implements Module {
	private $diagnosis;
	private $groups = array(
		'cache'  => array( 'nbh_performance_cache_enabled', 'nbh_performance_mobile_cache_enabled', 'nbh_performance_sitemap_preload_enabled' ),
		'assets' => array( 'nbh_performance_css_minify_enabled', 'nbh_performance_js_minify_enabled', 'nbh_performance_defer_js_enabled', 'nbh_performance_delay_js_enabled', 'nbh_performance_critical_css_enabled', 'nbh_performance_unused_css_enabled' ),
		'images' => array( 'nbh_performance_image_conversion_enabled', 'nbh_performance_nextgen_image_serving_enabled', 'nbh_performance_lazy_load_enabled' ),
		'cdn'    => array( 'nbh_performance_cdn_rewrite_enabled', 'nbh_performance_edge_auto_purge_enabled' ),
	);
	public function __construct( ConflictDiagnosis $diagnosis ) {
		$this->diagnosis = $diagnosis;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_init', array( $this, 'automatic_recovery' ), 20 );
	}
	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/compatibility/recover',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'recover' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
	public function recover( WP_REST_Request $request ) {
		$group = sanitize_key( (string) $request->get_param( 'group' ) );
		if ( ! isset( $this->groups[ $group ] ) ) {
			return new \WP_Error( 'nbh_performance_invalid_recovery_group', __( 'Invalid recovery group.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'group'     => $group,
				'disabled'  => $this->disable_group( $group ),
				'diagnosis' => $this->diagnosis->diagnose(),
			)
		);
	}
	public function automatic_recovery() {
		if ( ! get_option( 'nbh_performance_automatic_conflict_recovery', 1 ) ) {
			return;
		}
		foreach ( $this->diagnosis->diagnose() as $issue ) {
			if ( 'critical' === $issue['severity'] && ! empty( $issue['recoverable'] ) && isset( $this->groups[ $issue['group'] ] ) ) {
				$this->disable_group( $issue['group'] );
			}
		}
	}
	public function rollback( $group, $reason = '' ) {
		if ( ! isset( $this->groups[ $group ] ) ) {
			return array();
		}
		$disabled = $this->disable_group( $group );
		if ( $disabled && $reason ) {
			update_option( 'nbh_performance_last_selective_rollback_reason', sanitize_key( $reason ), false );
		}
		return $disabled;
	}
	private function disable_group( $group ) {
		$disabled = array();
		foreach ( $this->groups[ $group ] as $option ) {
			if ( get_option( $option, 0 ) ) {
				update_option( $option, 0, false );
				$disabled[] = $option;
			}
		}
		if ( $disabled ) {
			update_option(
				'nbh_performance_last_selective_rollback',
				array(
					'group'   => $group,
					'options' => $disabled,
					'time'    => time(),
				),
				false
			);
					do_action( 'nbh_performance_selective_rollback', $group, $disabled );
		}
		return $disabled;
	}
}
