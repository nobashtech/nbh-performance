<?php
/** Performance intelligence REST API and safe actions. @package NBHPerformance */
namespace NBH\Performance\Modules\Intelligence;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Server;
final class IntelligenceController implements Module {
	private $engine;
	private $tracker;
	private $rollback;
	public function __construct( RecommendationEngine $engine, ImpactTracker $tracker, \NBH\Performance\Modules\Compatibility\SelectiveRollbackManager $rollback ) {
		$this->engine   = $engine;
		$this->tracker  = $tracker;
		$this->rollback = $rollback;
	}
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'nbh_performance_intelligence_daily', array( $this->tracker, 'complete_due' ) );
		if ( ! wp_next_scheduled( 'nbh_performance_intelligence_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nbh_performance_intelligence_daily' );
		}
	}
	public function routes() {
		$p = static function () {
				return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'nbh-performance/v1',
			'/intelligence/recommendations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => $p,
			)
		);
		register_rest_route(
			'nbh-performance/v1',
			'/intelligence/action',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'action' ),
				'permission_callback' => $p,
			)
		);
		register_rest_route(
			'nbh-performance/v1',
			'/intelligence/impacts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'impacts' ),
				'permission_callback' => $p,
			)
		);
	}
	public function list_items() {
		return rest_ensure_response(
			array(
				'generated_at'    => time(),
				'recommendations' => $this->engine->recommendations(),
			)
		);
	}
	public function impacts() {
		return rest_ensure_response( array( 'history' => $this->tracker->history() ) );
	}
	public function action( WP_REST_Request $request ) {
		$action  = sanitize_key( (string) $request->get_param( 'action' ) );
		$allowed = array( 'enable_render_path', 'enable_safe_script_delay', 'enable_preload', 'rollback_assets', 'rollback_cache', 'rollback_images', 'rollback_cdn', 'conservative_preload' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return new \WP_Error( 'nbh_performance_invalid_intelligence_action', __( 'Unsupported recommendation action.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$id = $this->tracker->start( $action, $this->tracker->snapshot() );
		switch ( $action ) {
			case 'enable_render_path':
																																																																																																																																							update_option( 'nbh_performance_critical_css_enabled', 1, false );
				break;
			case 'enable_safe_script_delay':
																																																																																																																																							update_option( 'nbh_performance_delay_js_enabled', 1, false );
				break;
			case 'enable_preload':
																																																																																																																																							update_option( 'nbh_performance_sitemap_preload_enabled', 1, false );
				break;
			case 'conservative_preload':
																																																																																																																																							update_option( 'nbh_performance_preload_batch_size', 1, false );
				break;
			case 'rollback_assets':
																																																																																																																																							$this->rollback->rollback( 'assets', 'performance_intelligence' );
				break;
			case 'rollback_cache':
																																																																																																																																							$this->rollback->rollback( 'cache', 'performance_intelligence' );
				break;
			case 'rollback_images':
																																																																																																																																							$this->rollback->rollback( 'images', 'performance_intelligence' );
				break;
			case 'rollback_cdn':
																																																																																																																																							$this->rollback->rollback( 'cdn', 'performance_intelligence' );
				break;
		}
		do_action( 'nbh_performance_intelligence_action_applied', $action, $id );
		return rest_ensure_response(
			array(
				'applied'   => true,
				'action'    => $action,
				'impact_id' => $id,
			)
		);
	}
}
