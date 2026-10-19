<?php
/** CSS & JavaScript Hub status and safe maintenance operations. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\CriticalCss\BrowserJobStore;
use NBH\Performance\Modules\CriticalCss\CriticalCssQueue;
use NBH\Performance\Modules\CriticalCss\CriticalCssStorage;
use NBH\Performance\Modules\CriticalCss\UsedCssStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class OptimizationController implements Module {
	/** @var AssetOptimizer */
	private $optimizer;

	/** @var AssetBundleCompiler */
	private $bundles;

	/** @var AssetHealthMonitor */
	private $health;

	/** @var CriticalCssStorage */
	private $critical;

	/** @var UsedCssStorage */
	private $used;

	/** @var CriticalCssQueue */
	private $queue;

	/** @var BrowserJobStore */
	private $jobs;

	public function __construct( AssetOptimizer $optimizer, AssetBundleCompiler $bundles, AssetHealthMonitor $health, CriticalCssStorage $critical, UsedCssStorage $used, CriticalCssQueue $queue, BrowserJobStore $jobs ) {
		$this->optimizer = $optimizer;
		$this->bundles   = $bundles;
		$this->health    = $health;
		$this->critical  = $critical;
		$this->used      = $used;
		$this->queue     = $queue;
		$this->jobs      = $jobs;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/optimization/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/optimization/assets/flush',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'flush_assets' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/optimization/critical/flush',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'flush_critical' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/optimization/used/flush',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'flush_used' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/optimization/critical/queue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'queue_critical' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/optimization/health/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume_health' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nbh_performance_forbidden', __( 'You are not allowed to manage CSS and JavaScript optimization.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		return \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function status() {
		$safe_mode = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$css       = (bool) get_option( 'nbh_performance_css_minify_enabled', 0 );
		$js        = (bool) get_option( 'nbh_performance_js_minify_enabled', 0 );
		$defer     = (bool) get_option( 'nbh_performance_defer_js_enabled', 0 );
		$delay     = (bool) get_option( 'nbh_performance_delay_js_enabled', 0 );
		$critical  = (bool) get_option( 'nbh_performance_critical_css_enabled', 0 );
		$unused    = (bool) get_option( 'nbh_performance_unused_css_enabled', 0 );
		$visual    = (bool) get_option( 'nbh_performance_visual_regression_guard_enabled', 0 );
		$active    = 0;
		foreach ( array( $css, $js, $defer, $delay, $critical, $unused ) as $flag ) {
			if ( $flag ) {
				++$active;
			}
		}
		$assets   = $this->optimizer->status();
		$endpoint = '';
		$secret   = (string) get_option( 'nbh_performance_generator_secret', '' );

		return rest_ensure_response(
			array(
				'safe_mode'       => $safe_mode,
				'active_features' => $active,
				'runtime_ready'   => ! $safe_mode && $active > 0 && empty( $assets['emergency_rollback'] ),
				'css_minify'      => $css && ! $safe_mode,
				'js_minify'       => $js && ! $safe_mode,
				'defer_js'        => $defer && ! $safe_mode,
				'delay_js'        => $delay && ! $safe_mode,
				'critical_css'    => $critical && ! $safe_mode,
				'unused_css'      => $unused && ! $safe_mode,
				'visual_guard'    => $visual,
				'script_manager'  => (bool) get_option( 'nbh_performance_script_manager_enabled', 0 ),
				'delay_timeout'   => absint( get_option( 'nbh_performance_delay_timeout', 5000 ) ),
				'assets'          => $assets,
				'critical'        => $this->critical->status(),
				'used'            => $this->used->status(),
				'browser_jobs'    => $this->jobs->stats(),
				'visual_failures' => count( (array) get_option( 'nbh_performance_visual_regression_failures', array() ) ),
				'engine'          => array(
					'configured'      => ( \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::available() || (bool) has_filter( 'nbh_performance_local_headless_generate' ) ),
					'endpoint'        => $endpoint,
					'mode'            => \NBH\Performance\Modules\CriticalCss\PhpCssGenerator::MODE,
					'visual_verified' => false,
					'secret_ready'    => false,
					'endpoint_https'  => false,
				),
				'recent_css'      => array_slice( array_values( (array) get_option( 'nbh_performance_browser_css_status', array() ) ), -5 ),
			)
		);
	}

	public function flush_assets() {
		$result = $this->optimizer->flush_cache();
		$this->bundles->flush_manifests();
		$result['optimization'] = $this->status()->get_data();
		return rest_ensure_response( $result );
	}

	public function flush_critical() {
		$result                 = $this->critical->flush_all();
		$result['optimization'] = $this->status()->get_data();
		return rest_ensure_response( $result );
	}

	public function flush_used() {
		$result                 = $this->used->flush_all();
		$result['optimization'] = $this->status()->get_data();
		return rest_ensure_response( $result );
	}

	public function queue_critical() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) ) {
			return new WP_Error( 'nbh_performance_safe_mode', __( 'Turn off Safe Mode before queueing Critical CSS.', 'nbh-performance' ), array( 'status' => 409 ) );
		}
		$this->queue->queue_key_pages();
		return rest_ensure_response(
			array(
				'success'      => true,
				'queued'       => true,
				'optimization' => $this->status()->get_data(),
			)
		);
	}

	public function resume_health() {
		$result                 = $this->health->clear_and_resume();
		$result['optimization'] = $this->status()->get_data();
		return rest_ensure_response( $result );
	}
}
