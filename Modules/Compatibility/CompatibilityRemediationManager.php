<?php
/**
 * Confirmed, reversible remediation for known compatibility conflicts.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\CacheEngine\CacheBootstrapManager;
use NBH\Performance\Modules\CacheEngine\DropInInstaller;
use NBH\Performance\Modules\Core\Module;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class CompatibilityRemediationManager implements Module {
	/** @var ConflictDiagnosis */
	private $diagnosis;

	/** @var PluginDetector */
	private $detector;

	/** @var DropInInstaller */
	private $dropin;

	/** @var bool Temporarily bypass feature guards while applying a confirmed repair. */
	private $bypass_guard = false;

	private const PENDING_CONFLICT_OPTION = 'nbh_performance_pending_compatibility_conflict';

	/**
	 * Verified optimization products and the NBH feature families they can overlap.
	 *
	 * A product may appear in more than one group. Feature-level changes are only
	 * offered for integrations implemented in apply_external_feature_disable().
	 */
	private const PLUGINS = array(
		'wp-rocket/wp-rocket.php'                         => array(
			'name'   => 'WP Rocket',
			'groups' => array( 'cache', 'assets', 'lazyload' ),
		),
		'w3-total-cache/w3-total-cache.php'               => array(
			'name'   => 'W3 Total Cache',
			'groups' => array( 'cache', 'assets' ),
		),
		'wp-super-cache/wp-cache.php'                     => array(
			'name'   => 'WP Super Cache',
			'groups' => array( 'cache' ),
		),
		'litespeed-cache/litespeed-cache.php'             => array(
			'name'   => 'LiteSpeed Cache',
			'groups' => array( 'cache', 'assets', 'lazyload', 'images' ),
		),
		'flying-press/flying-press.php'                   => array(
			'name'   => 'FlyingPress',
			'groups' => array( 'cache', 'assets', 'lazyload', 'images', 'cdn' ),
		),
		'wp-fastest-cache/wpFastestCache.php'             => array(
			'name'   => 'WP Fastest Cache',
			'groups' => array( 'cache', 'assets' ),
		),
		'breeze/breeze.php'                               => array(
			'name'   => 'Breeze',
			'groups' => array( 'cache', 'assets', 'lazyload', 'cdn' ),
		),
		'wp-hummingbird/wp-hummingbird.php'               => array(
			'name'   => 'Hummingbird',
			'groups' => array( 'cache', 'assets' ),
		),
		'nitropack/main.php'                              => array(
			'name'   => 'NitroPack',
			'groups' => array( 'cache', 'assets', 'lazyload', 'images', 'cdn' ),
		),
		'nitropack/nitropack.php'                         => array(
			'name'   => 'NitroPack',
			'groups' => array( 'cache', 'assets', 'lazyload', 'images', 'cdn' ),
		),
		'cache-enabler/cache-enabler.php'                 => array(
			'name'   => 'Cache Enabler',
			'groups' => array( 'cache' ),
		),
		'sg-cachepress/sg-cachepress.php'                 => array(
			'name'   => 'SiteGround Speed Optimizer',
			'groups' => array( 'cache', 'assets', 'lazyload', 'images' ),
		),
		'autoptimize/autoptimize.php'                     => array(
			'name'   => 'Autoptimize',
			'groups' => array( 'assets', 'lazyload', 'images' ),
		),
		'perfmatters/perfmatters.php'                     => array(
			'name'   => 'Perfmatters',
			'groups' => array( 'assets', 'lazyload', 'cdn' ),
		),
		'wp-asset-clean-up/wpacu.php'                     => array(
			'name'   => 'Asset CleanUp',
			'groups' => array( 'assets' ),
		),
		'jetpack-boost/jetpack-boost.php'                 => array(
			'name'   => 'Jetpack Boost',
			'groups' => array( 'assets', 'lazyload' ),
		),
		'wp-smush-pro/wp-smush.php'                       => array(
			'name'   => 'Smush Pro',
			'groups' => array( 'images', 'lazyload', 'cdn' ),
		),
		'wp-smushit/wp-smush.php'                         => array(
			'name'   => 'Smush',
			'groups' => array( 'images', 'lazyload', 'cdn' ),
		),
		'ewww-image-optimizer/ewww-image-optimizer.php'   => array(
			'name'   => 'EWWW Image Optimizer',
			'groups' => array( 'images', 'lazyload' ),
		),
		'imagify/imagify.php'                             => array(
			'name'   => 'Imagify',
			'groups' => array( 'images' ),
		),
		'shortpixel-image-optimiser/wp-shortpixel.php'    => array(
			'name'   => 'ShortPixel Image Optimizer',
			'groups' => array( 'images' ),
		),
		'shortpixel-adaptive-images/short-pixel-ai.php'   => array(
			'name'   => 'ShortPixel Adaptive Images',
			'groups' => array( 'images', 'lazyload', 'cdn' ),
		),
		'optimole-wp/optimole-wp.php'                     => array(
			'name'   => 'Optimole',
			'groups' => array( 'images', 'lazyload', 'cdn' ),
		),
		'webp-express/webp-express.php'                   => array(
			'name'   => 'WebP Express',
			'groups' => array( 'images' ),
		),
		'webp-converter-for-media/webp-converter-for-media.php' => array(
			'name'   => 'Converter for Media',
			'groups' => array( 'images' ),
		),
		'robin-image-optimizer/robin-image-optimizer.php' => array(
			'name'   => 'Robin Image Optimizer',
			'groups' => array( 'images' ),
		),
	);

	private const GROUP_LABELS = array(
		'cache'    => 'page caching',
		'assets'   => 'CSS and JavaScript optimization',
		'lazyload' => 'lazy loading',
		'images'   => 'image optimization and next-generation formats',
		'cdn'      => 'CDN URL rewriting',
	);

	private const FEATURE_GROUPS = array(
		'nbh_performance_cache_enabled'                 => 'cache',
		'nbh_performance_mobile_cache_enabled'          => 'cache',
		'nbh_performance_css_minify_enabled'            => 'assets',
		'nbh_performance_js_minify_enabled'             => 'assets',
		'nbh_performance_combine_css_enabled'           => 'assets',
		'nbh_performance_combine_js_enabled'            => 'assets',
		'nbh_performance_defer_js_enabled'              => 'assets',
		'nbh_performance_delay_js_enabled'              => 'assets',
		'nbh_performance_critical_css_enabled'          => 'assets',
		'nbh_performance_unused_css_enabled'            => 'assets',
		'nbh_performance_lazy_load_enabled'             => 'lazyload',
		'nbh_performance_responsive_images_enabled'     => 'lazyload',
		'nbh_performance_youtube_facade_enabled'        => 'lazyload',
		'nbh_performance_image_conversion_enabled'      => 'images',
		'nbh_performance_nextgen_image_serving_enabled' => 'images',
		'nbh_performance_cdn_rewrite_enabled'           => 'cdn',
	);

	public function __construct( ConflictDiagnosis $diagnosis, PluginDetector $detector, DropInInstaller $dropin ) {
		$this->diagnosis = $diagnosis;
		$this->detector  = $detector;
		$this->dropin    = $dropin;
	}

	public function register() {
		add_action( 'admin_post_nbh_performance_resolve_issue', array( $this, 'handle' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		foreach ( array_keys( self::FEATURE_GROUPS ) as $option ) {
			add_filter( 'pre_update_option_' . $option, array( $this, 'guard_option_update' ), 10, 3 );
		}
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/compatibility/resolve',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'resolve_rest' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Build actionable administrator issues.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function issues() {
		$issues        = array();
		$pending_issue = $this->pending_conflict_issue();
		if ( $pending_issue ) {
			$issues[] = $pending_issue;
		}
		$cache_enabled = (bool) get_option( 'nbh_performance_cache_enabled', false );
		$dropin_status = $this->dropin->reconcile();
		$active_cache  = $this->active_plugins( 'cache' );

		if ( $cache_enabled && in_array( $dropin_status, array( 'foreign', 'unreadable' ), true ) ) {
			$owner_names = wp_list_pluck( $active_cache, 'name' );
			$owner       = $owner_names ? implode( ', ', $owner_names ) : __( 'a previous caching plugin or hosting cache tool', 'nbh-performance' );
			$readable    = 'unreadable' !== $dropin_status;
			$message     = sprintf(
				/* translators: %s: detected cache owner. */
				__( '%s currently controls WordPress early page caching. NBH Performance paused its page cache and left the existing drop-in untouched.', 'nbh-performance' ),
				$owner
			);
			if ( ! $readable ) {
				$message = __( 'The existing advanced-cache.php file cannot be read safely. NBH Performance left it unchanged and paused page-cache activation.', 'nbh-performance' );
			}

			$actions = array();
			if ( $readable ) {
				$actions[] = $this->action(
					__( 'Switch safely to NBH Performance', 'nbh-performance' ),
					'switch-to-nbh',
					'',
					'primary',
					__( 'Switch page caching to NBH Performance?', 'nbh-performance' ),
					__( 'NBH Performance will deactivate detected page-cache plugins, create and verify a private backup of advanced-cache.php, install its own verified drop-in, and repair WP_CACHE. No file is replaced unless the backup succeeds.', 'nbh-performance' )
				);
			}
			$actions[] = $this->action(
				__( 'Keep the current cache system', 'nbh-performance' ),
				'keep-existing-cache',
				'',
				'secondary',
				__( 'Keep the current page cache?', 'nbh-performance' ),
				__( 'NBH Performance page caching will be turned off. Its image, database, Script Manager, RUM and other non-page-cache features will remain available.', 'nbh-performance' )
			);

			$issues[] = array(
				'id'      => 'advanced-cache-dropin',
				'tone'    => 'error',
				'title'   => __( 'Choose which plugin should control page caching', 'nbh-performance' ),
				'message' => $message,
				'detail'  => $readable
					? __( 'Only one plugin should own advanced-cache.php. Confirm the preferred cache system and NBH Performance will apply the safe, reversible fix.', 'nbh-performance' )
					: __( 'Fix the file permissions or remove the unreadable drop-in through your hosting file manager, then run the repair again.', 'nbh-performance' ),
				'actions' => $actions,
			);
		}

		if ( $cache_enabled && $active_cache && ! in_array( $dropin_status, array( 'foreign', 'unreadable' ), true ) ) {
			$owner_names = implode( ', ', wp_list_pluck( $active_cache, 'name' ) );
			$issues[]    = array(
				'id'      => 'multiple-page-cache-plugins',
				'tone'    => 'warning',
				'title'   => __( 'Two page-cache systems are active', 'nbh-performance' ),
				/* translators: %s: conflicting plugin name or names. */
				'message' => sprintf( __( '%s is active while NBH Performance page caching is enabled. Dual page caching can create stale pages, inconsistent purges, or personalized-content risks.', 'nbh-performance' ), $owner_names ),
				'detail'  => __( 'Choose one page-cache owner. NBH Performance only deactivates another plugin after administrator confirmation and preserves its saved settings.', 'nbh-performance' ),
				'actions' => array(
					$this->action(
						__( 'Use NBH Performance', 'nbh-performance' ),
						'switch-to-nbh',
						'',
						'primary',
						__( 'Use NBH Performance for page caching?', 'nbh-performance' ),
						__( 'Detected page-cache plugins will be deactivated, their saved settings will remain, and NBH Performance will verify its cache bootstrap.', 'nbh-performance' )
					),
					$this->action(
						__( 'Keep the current cache plugin', 'nbh-performance' ),
						'keep-existing-cache',
						'',
						'secondary',
						__( 'Keep the existing page-cache plugin?', 'nbh-performance' ),
						__( 'NBH Performance page caching will be disabled while its other optimization features remain available.', 'nbh-performance' )
					),
				),
			);
		}

		if ( $cache_enabled && ! in_array( $dropin_status, array( 'foreign', 'unreadable' ), true ) && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
			$issues[] = array(
				'id'      => 'wp-cache-disabled',
				'tone'    => 'warning',
				'title'   => __( 'Page cache setup needs one final step', 'nbh-performance' ),
				'message' => __( 'WordPress is not loading the early page-cache bootstrap because WP_CACHE is not enabled in wp-config.php.', 'nbh-performance' ),
				'detail'  => __( 'NBH Performance can back up wp-config.php, enable WP_CACHE, verify cache directories, and install its drop-in after you confirm.', 'nbh-performance' ),
				'actions' => array(
					$this->action(
						__( 'Repair automatically', 'nbh-performance' ),
						'repair-page-cache',
						'',
						'primary',
						__( 'Repair page-cache setup?', 'nbh-performance' ),
						__( 'NBH Performance will safely update WP_CACHE, verify its drop-in and create missing cache directories. Existing files are backed up before changes.', 'nbh-performance' )
					),
				),
			);
		}

		$dropin_error = sanitize_key( (string) get_option( 'nbh_performance_dropin_error', '' ) );
		if ( $cache_enabled && $dropin_error && ! in_array( $dropin_status, array( 'foreign', 'unreadable' ), true ) ) {
			$copy     = array(
				'source_unreadable' => __( 'The packaged NBH Performance cache drop-in cannot be read.', 'nbh-performance' ),
				'copy_failed'       => __( 'WordPress could not create a temporary cache drop-in file.', 'nbh-performance' ),
				'rename_failed'     => __( 'WordPress could not publish the verified cache drop-in, usually because wp-content is not writable.', 'nbh-performance' ),
			);
			$issues[] = array(
				'id'      => 'dropin-install-error',
				'tone'    => 'error',
				'title'   => __( 'NBH Performance could not finish page-cache setup', 'nbh-performance' ),
				'message' => isset( $copy[ $dropin_error ] ) ? $copy[ $dropin_error ] : __( 'The page-cache drop-in could not be installed.', 'nbh-performance' ),
				'detail'  => __( 'No existing cache file was overwritten. Check wp-content permissions, then let NBH Performance retry the verified installation.', 'nbh-performance' ),
				'actions' => array(
					$this->action(
						__( 'Retry safely', 'nbh-performance' ),
						'repair-page-cache',
						'',
						'primary',
						__( 'Retry page-cache installation?', 'nbh-performance' ),
						__( 'NBH Performance will retry only after verifying file ownership and write access.', 'nbh-performance' )
					),
				),
			);
		}

		foreach ( $this->overlap_issues() as $overlap_issue ) {
			$issues[] = $overlap_issue;
		}

		if ( get_option( 'nbh_performance_asset_emergency_rollback' ) ) {
			$issues[] = array(
				'id'      => 'asset-health-rollback',
				'tone'    => 'error',
				'title'   => __( 'A frontend safety rollback is active', 'nbh-performance' ),
				'message' => __( 'NBH Performance detected an unsafe asset result and restored the previous verified CSS and JavaScript manifest.', 'nbh-performance' ),
				'detail'  => __( 'Your site is protected. Review the activity log and exclusions before retrying stronger optimization.', 'nbh-performance' ),
				'actions' => array(
					array(
						'label' => __( 'Review CSS & JavaScript', 'nbh-performance' ),
						'url'   => admin_url( 'options-general.php?page=nbh-performance#optimization' ),
						'style' => 'primary',
					),
				),
			);
		}

		return (array) apply_filters( 'nbh_performance_actionable_admin_issues', $issues );
	}

	/**
	 * Guard every update path, including Smart Optimize and imported profiles.
	 * Dashboard REST requests get a structured 409 response; non-REST callers are
	 * safely prevented and the conflict is queued as an actionable admin popup.
	 *
	 * @param mixed  $new_value New option value.
	 * @param mixed  $old_value Existing option value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function guard_option_update( $new_value, $old_value, $option ) {
		if ( $this->bypass_guard || ! rest_sanitize_boolean( $new_value ) || rest_sanitize_boolean( $old_value ) || empty( self::FEATURE_GROUPS[ $option ] ) ) {
			return $new_value;
		}
		$preflight = $this->preflight_toggles( array( $option => true ) );
		if ( ! is_wp_error( $preflight ) ) {
			delete_option( self::PENDING_CONFLICT_OPTION );
			return $new_value;
		}
		$data = $preflight->get_error_data();
		if ( is_array( $data ) && ! empty( $data['conflict'] ) && is_array( $data['conflict'] ) ) {
			$payload               = $data['conflict'];
			$payload['created_at'] = time();
			update_option( self::PENDING_CONFLICT_OPTION, $payload, false );
		}
		return $old_value;
	}

	/** Convert a blocked background/profile update into an actionable notice. */
	private function pending_conflict_issue() {
		$payload = get_option( self::PENDING_CONFLICT_OPTION, array() );
		if ( ! is_array( $payload ) || empty( $payload['created_at'] ) || ( time() - absint( $payload['created_at'] ) ) > DAY_IN_SECONDS ) {
			delete_option( self::PENDING_CONFLICT_OPTION );
			return array();
		}
		$plugin = isset( $payload['plugin'] ) ? sanitize_text_field( (string) $payload['plugin'] ) : '';
		$groups = $this->sanitize_groups( isset( $payload['groups'] ) ? $payload['groups'] : array() );
		if ( $plugin && isset( self::PLUGINS[ $plugin ] ) && ! $this->detector->active( $plugin ) ) {
			delete_option( self::PENDING_CONFLICT_OPTION );
			return array();
		}

		$actions = array();
		if ( $plugin && $groups && $this->can_disable_all_external_groups( $plugin, $groups ) ) {
			$actions[] = $this->action(
				isset( $payload['choices'][0]['label'] ) ? (string) $payload['choices'][0]['label'] : __( 'Turn off the conflicting feature', 'nbh-performance' ),
				'disable-plugin-features',
				$plugin,
				'primary',
				__( 'Apply the safe compatibility fix?', 'nbh-performance' ),
				__( 'NBH Performance will change only verified conflicting settings, verify the result, and restore the original settings if verification fails.', 'nbh-performance' ),
				$groups
			);
		}
		if ( $plugin ) {
			$actions[] = $this->action(
				/* translators: %s: conflicting plugin name or names. */
				sprintf( __( 'Deactivate %s', 'nbh-performance' ), isset( $payload['plugin_name'] ) ? (string) $payload['plugin_name'] : self::PLUGINS[ $plugin ]['name'] ),
				'deactivate-plugin',
				$plugin,
				$actions ? 'secondary' : 'primary',
				__( 'Deactivate the conflicting plugin?', 'nbh-performance' ),
				__( 'The plugin will be deactivated but its database settings will remain available.', 'nbh-performance' ),
				$groups
			);
		}
		return array(
			'id'      => isset( $payload['id'] ) ? sanitize_key( (string) $payload['id'] ) : 'pending-compatibility-conflict',
			'tone'    => 'warning',
			'title'   => isset( $payload['title'] ) ? (string) $payload['title'] : __( 'An optimization was kept off for safety', 'nbh-performance' ),
			'message' => isset( $payload['message'] ) ? (string) $payload['message'] : __( 'NBH Performance prevented two plugins from managing the same optimization.', 'nbh-performance' ),
			'detail'  => isset( $payload['detail'] ) ? (string) $payload['detail'] : __( 'Choose which product should own this feature before enabling it.', 'nbh-performance' ),
			'actions' => $actions,
		);
	}

	/**
	 * Block an NBH feature from enabling until a conflicting product is resolved.
	 *
	 * @param array<string,mixed> $values Requested feature values.
	 * @return true|WP_Error
	 */
	public function preflight_toggles( array $values ) {
		foreach ( $values as $option => $value ) {
			if ( ! rest_sanitize_boolean( $value ) || empty( self::FEATURE_GROUPS[ $option ] ) ) {
				continue;
			}
			$group = self::FEATURE_GROUPS[ $option ];
			if ( 'cache' === $group ) {
				$cache_conflict = $this->cache_preflight_conflict( $option );
				if ( $cache_conflict ) {
					return $this->conflict_error( $cache_conflict );
				}
				continue;
			}
			foreach ( $this->active_plugins( $group ) as $plugin_file => $plugin ) {
				$state = $this->plugin_feature_state( $plugin_file, $group );
				if ( false === $state ) {
					continue;
				}
				return $this->conflict_error( $this->feature_conflict_payload( $plugin_file, $plugin, $group, $option, $state ) );
			}
		}
		return true;
	}

	/** Resolve a dashboard compatibility choice through the authenticated REST API. */
	public function resolve_rest( WP_REST_Request $request ) {
		$resolution = sanitize_key( (string) $request->get_param( 'resolution' ) );
		$plugin     = sanitize_text_field( (string) $request->get_param( 'plugin' ) );
		$groups     = $this->sanitize_groups( $request->get_param( 'groups' ) );
		$result     = $this->resolve( $resolution, $plugin, $groups );
		$status     = ! empty( $result['success'] ) ? 200 : 409;
		if ( empty( $result['success'] ) ) {
			return new WP_Error(
				'nbh_performance_compatibility_resolution_failed',
				isset( $result['message'] ) ? (string) $result['message'] : __( 'The compatibility repair could not be completed.', 'nbh-performance' ),
				array(
					'status' => $status,
					'result' => $result,
				)
			);
		}
		delete_option( self::PENDING_CONFLICT_OPTION );
		$result['issues'] = $this->issues();
		return rest_ensure_response( $result );
	}

	/** Process a confirmed remediation from an admin notice. */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to repair NBH Performance compatibility issues.', 'nbh-performance' ), 403 );
		}

		$resolution = isset( $_GET['resolution'] ) ? sanitize_key( wp_unslash( $_GET['resolution'] ) ) : '';
		$plugin     = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		$groups_raw = isset( $_GET['groups'] ) ? sanitize_text_field( wp_unslash( $_GET['groups'] ) ) : '';
		$groups     = $this->sanitize_groups( $groups_raw );
		check_admin_referer( $this->nonce_action( $resolution, $plugin, $groups ) );

		$result = $this->resolve( $resolution, $plugin, $groups );
		if ( ! empty( $result['success'] ) ) {
			delete_option( self::PENDING_CONFLICT_OPTION );
		}
		set_transient( 'nbh_performance_remediation_notice_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'options-general.php?page=nbh-performance' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Return a completed remediation result for display once. */
	public function consume_result() {
		$key    = 'nbh_performance_remediation_notice_' . get_current_user_id();
		$result = get_transient( $key );
		if ( is_array( $result ) ) {
			delete_transient( $key );
			return $result;
		}
		return array();
	}

	/**
	 * Execute a verified compatibility resolution.
	 *
	 * @param string        $resolution Resolution identifier.
	 * @param string        $plugin Plugin basename.
	 * @param array<int,string> $groups Feature groups.
	 * @return array<string,mixed>
	 */
	private function resolve( $resolution, $plugin, array $groups ) {
		$this->bypass_guard = true;
		try {
			$result = array(
				'success' => false,
				'message' => __( 'NBH Performance could not complete the requested repair.', 'nbh-performance' ),
			);
			switch ( $resolution ) {
				case 'switch-to-nbh':
					return $this->switch_to_nbh_cache();
				case 'keep-existing-cache':
					update_option( 'nbh_performance_cache_enabled', 0, false );
					update_option( 'nbh_performance_mobile_cache_enabled', 0, false );
					return array(
						'success' => true,
						'message' => __( 'NBH Performance page caching is now off. The existing cache system was left unchanged.', 'nbh-performance' ),
					);
				case 'repair-page-cache':
					return $this->repair_page_cache();
				case 'use-nbh-optimizer':
				case 'deactivate-plugin':
					return $this->deactivate_known_plugin( $plugin );
				case 'keep-existing-optimizer':
					return $this->disable_nbh_groups( array( 'assets' ) );
				case 'disable-plugin-features':
					return $this->disable_external_features( $plugin, $groups );
				case 'keep-plugin-features':
					return $this->disable_nbh_groups( $groups );
			}
			return $result;
		} finally {
			$this->bypass_guard = false;
		}
	}

	/** @return array<int,array<string,mixed>> */
	private function overlap_issues() {
		$issues = array();
		foreach ( self::PLUGINS as $plugin_file => $plugin ) {
			if ( ! $this->detector->active( $plugin_file ) ) {
				continue;
			}
			$groups = array();
			$states = array();
			foreach ( $plugin['groups'] as $group ) {
				if ( 'cache' === $group || ! $this->nbh_group_enabled( $group ) ) {
					continue;
				}
				$state = $this->plugin_feature_state( $plugin_file, $group );
				if ( false === $state ) {
					continue;
				}
				$groups[]         = $group;
				$states[ $group ] = $state;
			}
			if ( ! $groups ) {
				continue;
			}

			$labels       = array_map( array( $this, 'group_label' ), $groups );
			$all_verified = ! in_array( null, $states, true );
			$message      = sprintf(
				/* translators: 1: plugin name, 2: comma-separated optimization features. */
				__( '%1$s and NBH Performance are both configured to manage %2$s. Duplicate optimization can rewrite the same HTML, CSS, JavaScript or image more than once.', 'nbh-performance' ),
				$plugin['name'],
				$this->human_list( $labels )
			);
			$detail = $all_verified
				? __( 'NBH Performance verified the overlapping setting. Choose which product should own it before continuing.', 'nbh-performance' )
				: __( 'NBH Performance detected an active product capable of the same optimization, but its exact setting could not be verified. The conservative choice is to use one owner.', 'nbh-performance' );

			$actions = array();
			if ( $this->can_disable_all_external_groups( $plugin_file, $groups ) ) {
				$actions[] = $this->action(
					/* translators: %s: conflicting plugin name or names. */
					sprintf( __( 'Turn off conflicts in %s', 'nbh-performance' ), $plugin['name'] ),
					'disable-plugin-features',
					$plugin_file,
					'primary',
					/* translators: %s: conflicting plugin name or names. */
					sprintf( __( 'Turn off overlapping features in %s?', 'nbh-performance' ), $plugin['name'] ),
					/* translators: 1: optimization feature labels, 2: conflicting plugin name. */
					sprintf( __( 'NBH Performance will change only the verified %1$s settings in %2$s, keep the plugin active, verify that the overlap is gone, and restore the original settings if verification fails.', 'nbh-performance' ), $this->human_list( $labels ), $plugin['name'] ),
					$groups
				);
			}
			$actions[] = $this->action(
				/* translators: %s: conflicting plugin name or names. */
				sprintf( __( 'Deactivate %s', 'nbh-performance' ), $plugin['name'] ),
				'deactivate-plugin',
				$plugin_file,
				$actions ? 'secondary' : 'primary',
				/* translators: %s: conflicting plugin name or names. */
				sprintf( __( 'Deactivate %s?', 'nbh-performance' ), $plugin['name'] ),
				/* translators: %s: conflicting plugin name or names. */
				sprintf( __( '%s will be deactivated, but its database settings will be preserved. NBH Performance will then recheck the environment.', 'nbh-performance' ), $plugin['name'] ),
				$groups
			);
			$actions[] = $this->action(
				/* translators: %s: conflicting plugin name or names. */
				sprintf( __( 'Keep %s', 'nbh-performance' ), $plugin['name'] ),
				'keep-plugin-features',
				$plugin_file,
				'secondary',
				/* translators: %s: conflicting plugin name or names. */
				sprintf( __( 'Keep %s for these optimizations?', 'nbh-performance' ), $plugin['name'] ),
				__( 'The matching NBH Performance features will be turned off. Other NBH Performance features will remain available.', 'nbh-performance' ),
				$groups
			);

			$issues[] = array(
				'id'      => 'optimizer-overlap-' . sanitize_key( $plugin_file ) . '-' . implode( '-', $groups ),
				'tone'    => 'warning',
				/* translators: %s: conflicting plugin name or names. */
				'title'   => sprintf( __( '%s overlaps with active NBH Performance features', 'nbh-performance' ), $plugin['name'] ),
				'message' => $message,
				'detail'  => $detail,
				'actions' => $actions,
			);
		}
		return $issues;
	}

	/** @return array<string,mixed>|null */
	private function cache_preflight_conflict( $option ) {
		$dropin_status = $this->dropin->reconcile();
		$plugins       = $this->active_plugins( 'cache' );
		if ( ! $plugins && ! in_array( $dropin_status, array( 'foreign', 'unreadable' ), true ) ) {
			return null;
		}
		$plugin_file        = $plugins ? (string) key( $plugins ) : '';
		$plugin             = $plugins ? current( $plugins ) : array( 'name' => __( 'Existing page-cache system', 'nbh-performance' ) );
		$payload            = $this->feature_conflict_payload( $plugin_file, $plugin, 'cache', $option, true );
		$payload['title']   = __( 'Resolve the existing page cache first', 'nbh-performance' );
		$payload['message'] = __( 'NBH Performance cannot enable page caching while another plugin or an unowned advanced-cache.php file controls the same WordPress bootstrap.', 'nbh-performance' );
		$payload['detail']  = __( 'Choose NBH Performance and it will back up the current drop-in, deactivate verified cache plugins, repair WP_CACHE, and enable page caching only after validation succeeds.', 'nbh-performance' );
		$payload['choices'] = array(
			array(
				'resolution' => 'switch-to-nbh',
				'label'      => __( 'Switch safely to NBH Performance', 'nbh-performance' ),
				'style'      => 'primary',
			),
			array(
				'resolution' => 'cancel',
				'label'      => __( 'Keep the current cache system', 'nbh-performance' ),
				'style'      => 'secondary',
			),
		);
		return $payload;
	}

	/** @return array<string,mixed> */
	private function feature_conflict_payload( $plugin_file, array $plugin, $group, $option, $verified_state ) {
		$name    = isset( $plugin['name'] ) ? (string) $plugin['name'] : __( 'Another optimization plugin', 'nbh-performance' );
		$label   = $this->group_label( $group );
		$choices = array();
		if ( $this->supports_external_feature_disable( $plugin_file, $group ) ) {
			$choices[] = array(
				'resolution' => 'disable-plugin-features',
				/* translators: 1: optimization feature label, 2: conflicting plugin name. */
				'label'      => sprintf( __( 'Turn off %1$s in %2$s', 'nbh-performance' ), $label, $name ),
				'style'      => 'primary',
			);
		}
		$choices[] = array(
			'resolution' => 'deactivate-plugin',
			/* translators: %s: conflicting plugin name or names. */
			'label'      => sprintf( __( 'Deactivate %s', 'nbh-performance' ), $name ),
			'style'      => $choices ? 'secondary' : 'primary',
		);
		$choices[] = array(
			'resolution' => 'cancel',
			/* translators: %s: conflicting plugin name or names. */
			'label'      => sprintf( __( 'Keep %s and leave NBH off', 'nbh-performance' ), $name ),
			'style'      => 'ghost',
		);
		return array(
			'id'          => 'feature-conflict-' . sanitize_key( $plugin_file . '-' . $group ),
			'plugin'      => $plugin_file,
			'plugin_name' => $name,
			'groups'      => array( $group ),
			'option'      => $option,
			/* translators: %s: conflicting plugin name or names. */
			'title'       => sprintf( __( 'Resolve %s before enabling this feature', 'nbh-performance' ), $name ),
			/* translators: 1: conflicting plugin name, 2: optimization feature label. */
			'message'     => sprintf( __( '%1$s is already managing %2$s. Enabling the same transformation in NBH Performance can cause duplicate markup, missing images, layout shifts, stale cache, or broken JavaScript.', 'nbh-performance' ), $name, $label ),
			'detail'      => true === $verified_state
				? __( 'The overlapping setting was verified as active. Choose a safe resolution; NBH Performance will not enable its feature until the conflict is cleared.', 'nbh-performance' )
				: __( 'The plugin is capable of the same optimization, but its exact state could not be verified. NBH Performance is blocking the change conservatively.', 'nbh-performance' ),
			'choices'     => $choices,
		);
	}

	private function conflict_error( array $payload ) {
		return new WP_Error(
			'nbh_performance_compatibility_confirmation_required',
			isset( $payload['message'] ) ? (string) $payload['message'] : __( 'Resolve the compatibility conflict before enabling this feature.', 'nbh-performance' ),
			array(
				'status'   => 409,
				'conflict' => $payload,
			)
		);
	}

	private function switch_to_nbh_cache() {
		$prepared = $this->dropin->prepare_takeover();
		if ( empty( $prepared['success'] ) ) {
			return array(
				'success' => false,
				'message' => isset( $prepared['message'] ) ? $prepared['message'] : __( 'NBH Performance could not verify a reversible cache takeover.', 'nbh-performance' ),
			);
		}

		$deactivated = array();
		foreach ( $this->active_plugins( 'cache' ) as $plugin_file => $plugin ) {
			$deactivation = $this->deactivate_plugin_file( $plugin_file );
			if ( ! $deactivation['success'] ) {
				$this->reactivate_plugins( $deactivated );
				return $deactivation;
			}
			$deactivated[] = array(
				'file'    => $plugin_file,
				'name'    => $plugin['name'],
				'network' => ! empty( $deactivation['network'] ),
			);
		}

		$ownership = $this->dropin->take_ownership();
		if ( empty( $ownership['success'] ) ) {
			$this->reactivate_plugins( $deactivated );
			return array(
				'success' => false,
				'message' => isset( $ownership['message'] ) ? $ownership['message'] : __( 'NBH Performance could not safely take ownership of advanced-cache.php.', 'nbh-performance' ),
			);
		}

		update_option( 'nbh_performance_cache_enabled', 1, false );
		$bootstrap = ( new CacheBootstrapManager() )->ensure();
		$ready     = ! empty( $bootstrap['directories_ready'] ) && ! empty( $bootstrap['dropin_installed'] );
		if ( ! $ready ) {
			$this->reactivate_plugins( $deactivated );
			return array(
				'success' => false,
				'message' => __( 'The takeover backup succeeded, but WordPress could not finish the NBH Performance bootstrap. The previously active cache plugin was reactivated when possible.', 'nbh-performance' ),
			);
		}

		$message = __( 'NBH Performance now owns the verified page-cache drop-in and completed the cache bootstrap.', 'nbh-performance' );
		if ( $deactivated ) {
			/* translators: %s: comma-separated deactivated plugin names. */
			$message .= ' ' . sprintf( __( 'Deactivated: %s.', 'nbh-performance' ), implode( ', ', wp_list_pluck( $deactivated, 'name' ) ) );
		}
		return array(
			'success' => true,
			'message' => $message,
		);
	}

	private function repair_page_cache() {
		update_option( 'nbh_performance_cache_enabled', 1, false );
		$bootstrap = ( new CacheBootstrapManager() )->ensure();
		$success   = ! empty( $bootstrap['directories_ready'] ) && ! empty( $bootstrap['dropin_installed'] );
		if ( $success ) {
			return array(
				'success' => true,
				'message' => __( 'NBH Performance repaired the page-cache bootstrap. Reload once so WordPress can apply WP_CACHE.', 'nbh-performance' ),
			);
		}
		return array(
			'success' => false,
			'message' => __( 'Automatic repair was not completed. NBH Performance did not overwrite an unowned file. Check wp-content and wp-config.php permissions or choose which cache plugin should remain active.', 'nbh-performance' ),
		);
	}

	private function deactivate_known_plugin( $plugin_file ) {
		if ( ! isset( self::PLUGINS[ $plugin_file ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'The requested plugin is not in the verified compatibility remediation list.', 'nbh-performance' ),
			);
		}
		$result = $this->deactivate_plugin_file( $plugin_file );
		if ( $result['success'] ) {
			/* translators: %s: conflicting plugin name or names. */
			$result['message'] = sprintf( __( '%s was deactivated. Its saved settings were not deleted. NBH Performance rechecked the active optimization owners.', 'nbh-performance' ), self::PLUGINS[ $plugin_file ]['name'] );
		}
		return $result;
	}

	private function deactivate_plugin_file( $plugin_file ) {
		if ( ! isset( self::PLUGINS[ $plugin_file ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'NBH Performance refused to deactivate an unknown plugin.', 'nbh-performance' ),
			);
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network = is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file );
		deactivate_plugins( $plugin_file, false, $network );
		if ( $this->detector->active( $plugin_file ) ) {
			return array(
				'success' => false,
				/* translators: %s: conflicting plugin name or names. */
				'message' => sprintf( __( '%s could not be deactivated automatically. It may be network-managed or protected by your host.', 'nbh-performance' ), self::PLUGINS[ $plugin_file ]['name'] ),
			);
		}
		return array(
			'success' => true,
			'message' => '',
			'network' => $network,
		);
	}

	/** Attempt to restore plugins when a cache takeover cannot be completed. */
	private function reactivate_plugins( array $plugins ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_reverse( $plugins ) as $plugin ) {
			if ( empty( $plugin['file'] ) ) {
				continue;
			}
			activate_plugin( $plugin['file'], '', ! empty( $plugin['network'] ), true );
		}
	}

	/** Turn off only verified feature switches in another plugin and restore on failed verification. */
	private function disable_external_features( $plugin_file, array $groups ) {
		if ( ! isset( self::PLUGINS[ $plugin_file ] ) || ! $this->detector->active( $plugin_file ) ) {
			return array(
				'success' => false,
				'message' => __( 'The requested optimization plugin is not active or is not in the verified remediation registry.', 'nbh-performance' ),
			);
		}
		$groups = array_values( array_intersect( $groups, self::PLUGINS[ $plugin_file ]['groups'] ) );
		if ( ! $groups ) {
			return array(
				'success' => false,
				'message' => __( 'No supported conflicting feature was selected.', 'nbh-performance' ),
			);
		}
		foreach ( $groups as $group ) {
			if ( ! $this->supports_external_feature_disable( $plugin_file, $group ) ) {
				return array(
					'success' => false,
					/* translators: 1: optimization feature label, 2: conflicting plugin name. */
					'message' => sprintf( __( 'NBH Performance cannot safely change the %1$s setting inside %2$s. Deactivate that plugin or keep its feature and leave the matching NBH feature off.', 'nbh-performance' ), $this->group_label( $group ), self::PLUGINS[ $plugin_file ]['name'] ),
				);
			}
		}

		$snapshot = $this->external_option_snapshot( $plugin_file );
		foreach ( $groups as $group ) {
			if ( ! $this->apply_external_feature_disable( $plugin_file, $group ) ) {
				$this->restore_external_option_snapshot( $snapshot );
				return array(
					'success' => false,
					/* translators: %s: conflicting plugin name or names. */
					'message' => sprintf( __( 'NBH Performance could not safely update %s. The original plugin settings were restored.', 'nbh-performance' ), self::PLUGINS[ $plugin_file ]['name'] ),
				);
			}
		}

		foreach ( $groups as $group ) {
			if ( false !== $this->plugin_feature_state( $plugin_file, $group ) ) {
				$this->restore_external_option_snapshot( $snapshot );
				return array(
					'success' => false,
					/* translators: 1: conflicting plugin name, 2: optimization feature label. */
					'message' => sprintf( __( '%1$s still reports active %2$s after the attempted change. Its original settings were restored, and NBH Performance remained unchanged.', 'nbh-performance' ), self::PLUGINS[ $plugin_file ]['name'], $this->group_label( $group ) ),
				);
			}
		}

		return array(
			'success' => true,
			/* translators: 1: optimization feature labels, 2: conflicting plugin name. */
			'message' => sprintf( __( 'NBH Performance turned off %1$s inside %2$s, kept the plugin active, and verified that the overlap is cleared.', 'nbh-performance' ), $this->human_list( array_map( array( $this, 'group_label' ), $groups ) ), self::PLUGINS[ $plugin_file ]['name'] ),
		);
	}

	private function disable_nbh_groups( array $groups ) {
		$groups  = array_values( array_unique( $groups ) );
		$changed = array();
		foreach ( self::FEATURE_GROUPS as $option => $group ) {
			if ( in_array( $group, $groups, true ) ) {
				update_option( $option, 0, false );
				$changed[] = $group;
			}
		}
		$changed = array_values( array_unique( $changed ) );
		return array(
			'success' => true,
			'message' => $changed
				/* translators: %s: disabled optimization feature labels. */
				? sprintf( __( 'NBH Performance turned off its %s features. The existing optimization plugin was left unchanged.', 'nbh-performance' ), $this->human_list( array_map( array( $this, 'group_label' ), $changed ) ) )
				: __( 'No matching NBH Performance feature needed to be changed.', 'nbh-performance' ),
		);
	}

	private function nbh_group_enabled( $group ) {
		foreach ( self::FEATURE_GROUPS as $option => $option_group ) {
			if ( $group === $option_group && get_option( $option, false ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,array<string,mixed>> */
	private function active_plugins( $group ) {
		$active = array();
		foreach ( self::PLUGINS as $plugin_file => $plugin ) {
			if ( in_array( $group, $plugin['groups'], true ) && $this->detector->active( $plugin_file ) ) {
				$active[ $plugin_file ] = $plugin;
			}
		}
		return $active;
	}

	/** Return true, false, or null when the exact third-party state cannot be verified. */
	private function plugin_feature_state( $plugin_file, $group ) {
		switch ( $plugin_file ) {
			case 'wp-rocket/wp-rocket.php':
				$options = get_option( 'wp_rocket_settings', null );
				if ( 'cache' === $group ) {
					return true;
				}
				if ( ! is_array( $options ) ) {
					return null;
				}
				if ( 'assets' === $group ) {
					return $this->any_enabled( $options, array( 'minify_css', 'minify_js', 'minify_concatenate_css', 'minify_concatenate_js', 'delay_js', 'remove_unused_css', 'async_css' ) );
				}
				if ( 'lazyload' === $group ) {
					return $this->any_enabled( $options, array( 'lazyload', 'lazyload_iframes', 'lazyload_youtube' ) );
				}
				break;

			case 'perfmatters/perfmatters.php':
				$options = get_option( 'perfmatters_options', null );
				if ( ! is_array( $options ) ) {
					return null;
				}
				if ( 'assets' === $group ) {
					return $this->any_enabled( isset( $options['assets'] ) && is_array( $options['assets'] ) ? $options['assets'] : array(), array( 'defer_js', 'delay_js', 'minify_js', 'remove_unused_css', 'minify_css' ) );
				}
				if ( 'lazyload' === $group ) {
					return $this->any_enabled( isset( $options['lazyload'] ) && is_array( $options['lazyload'] ) ? $options['lazyload'] : array(), array( 'lazy_loading', 'lazy_loading_iframes', 'css_background_images', 'elements' ) );
				}
				if ( 'cdn' === $group ) {
					return ! empty( $options['cdn']['enable_cdn'] );
				}
				break;

			case 'flying-press/flying-press.php':
				$options = get_option( 'FLYING_PRESS_CONFIG', null );
				if ( 'cache' === $group ) {
					return true;
				}
				if ( ! is_array( $options ) ) {
					return null;
				}
				if ( 'assets' === $group ) {
					return $this->any_enabled( $options, array( 'css_js_minify', 'css_rucss', 'js_delay', 'css_js_self_host_third_party' ) );
				}
				if ( 'lazyload' === $group ) {
					return $this->any_enabled( $options, array( 'lazy_load', 'youtube_placeholder', 'lazy_render', 'properly_size_images' ) );
				}
				if ( 'images' === $group ) {
					return $this->any_enabled( $options, array( 'image_auto_optimize_uploads', 'properly_size_images' ) ) || ! empty( $options['image_format'] );
				}
				if ( 'cdn' === $group ) {
					return ! empty( $options['cdn'] ) || ! empty( $options['cf_page_caching'] );
				}
				break;

			case 'wp-smush-pro/wp-smush.php':
			case 'wp-smushit/wp-smush.php':
				$options = get_option( 'wp-smush-settings', null );
				if ( 'lazyload' === $group ) {
					return is_array( $options ) && array_key_exists( 'lazy_load', $options ) ? (bool) $options['lazy_load'] : null;
				}
				if ( 'cdn' === $group ) {
					return is_array( $options ) && array_key_exists( 'cdn', $options ) ? (bool) $options['cdn'] : null;
				}
				if ( 'images' === $group ) {
					return true;
				}
				break;

			case 'autoptimize/autoptimize.php':
				if ( 'assets' === $group ) {
					return $this->any_option_state( array( 'autoptimize_js', 'autoptimize_css', 'autoptimize_html' ) );
				}
				if ( 'lazyload' === $group ) {
					return (bool) get_option( 'autoptimize_imgopt_lazyload', false );
				}
				if ( 'images' === $group ) {
					return (bool) get_option( 'autoptimize_imgopt_optimization', false );
				}
				break;

			case 'ewww-image-optimizer/ewww-image-optimizer.php':
				if ( 'lazyload' === $group ) {
					return (bool) get_option( 'ewww_image_optimizer_lazy_load', get_site_option( 'ewww_image_optimizer_lazy_load', false ) );
				}
				if ( 'images' === $group ) {
					return true;
				}
				break;

			case 'sg-cachepress/sg-cachepress.php':
				if ( 'cache' === $group ) {
					return true;
				}
				if ( 'assets' === $group ) {
					return $this->any_option_state( array( 'siteground_optimizer_optimize_html', 'siteground_optimizer_optimize_javascript', 'siteground_optimizer_combine_javascript', 'siteground_optimizer_optimize_css', 'siteground_optimizer_combine_css' ) );
				}
				if ( 'lazyload' === $group ) {
					return (bool) get_option( 'siteground_optimizer_lazyload_images', false );
				}
				return null;
		}

		if ( isset( self::PLUGINS[ $plugin_file ] ) && in_array( $group, self::PLUGINS[ $plugin_file ]['groups'], true ) ) {
			return true;
		}
		return false;
	}

	private function supports_external_feature_disable( $plugin_file, $group ) {
		$supported = array(
			'wp-rocket/wp-rocket.php'             => array( 'assets', 'lazyload' ),
			'perfmatters/perfmatters.php'         => array( 'assets', 'lazyload', 'cdn' ),
			'flying-press/flying-press.php'       => array( 'assets', 'lazyload', 'images', 'cdn' ),
			'wp-smush-pro/wp-smush.php'           => array( 'lazyload', 'cdn' ),
			'wp-smushit/wp-smush.php'             => array( 'lazyload', 'cdn' ),
			'autoptimize/autoptimize.php'         => array( 'assets', 'lazyload', 'images' ),
			'ewww-image-optimizer/ewww-image-optimizer.php' => array( 'lazyload' ),
			'litespeed-cache/litespeed-cache.php' => array( 'assets', 'lazyload', 'images' ),
			'sg-cachepress/sg-cachepress.php'     => array( 'assets', 'lazyload' ),
		);
		return isset( $supported[ $plugin_file ] ) && in_array( $group, $supported[ $plugin_file ], true );
	}

	private function can_disable_all_external_groups( $plugin_file, array $groups ) {
		foreach ( $groups as $group ) {
			if ( ! $this->supports_external_feature_disable( $plugin_file, $group ) ) {
				return false;
			}
		}
		return ! empty( $groups );
	}

	private function apply_external_feature_disable( $plugin_file, $group ) {
		switch ( $plugin_file ) {
			case 'wp-rocket/wp-rocket.php':
				$options = get_option( 'wp_rocket_settings', array() );
				if ( ! is_array( $options ) ) {
					return false;
				}
				$keys = 'assets' === $group
					? array( 'minify_css', 'minify_js', 'minify_concatenate_css', 'minify_concatenate_js', 'delay_js', 'remove_unused_css', 'async_css' )
					: array( 'lazyload', 'lazyload_iframes', 'lazyload_youtube' );
				foreach ( $keys as $key ) {
					$options[ $key ] = 0;
				}
				update_option( 'wp_rocket_settings', $options, false );
				delete_transient( 'wp_rocket_settings' );
				return true;

			case 'perfmatters/perfmatters.php':
				$options = get_option( 'perfmatters_options', array() );
				if ( ! is_array( $options ) ) {
					return false;
				}
				if ( 'assets' === $group ) {
					$options['assets'] = isset( $options['assets'] ) && is_array( $options['assets'] ) ? $options['assets'] : array();
					foreach ( array( 'defer_js', 'delay_js', 'minify_js', 'remove_unused_css', 'minify_css' ) as $key ) {
						$options['assets'][ $key ] = false;
					}
				} elseif ( 'lazyload' === $group ) {
					$options['lazyload'] = isset( $options['lazyload'] ) && is_array( $options['lazyload'] ) ? $options['lazyload'] : array();
					foreach ( array( 'lazy_loading', 'lazy_loading_iframes', 'css_background_images', 'elements' ) as $key ) {
						$options['lazyload'][ $key ] = false;
					}
				} elseif ( 'cdn' === $group ) {
					$options['cdn']               = isset( $options['cdn'] ) && is_array( $options['cdn'] ) ? $options['cdn'] : array();
					$options['cdn']['enable_cdn'] = false;
				}
				update_option( 'perfmatters_options', $options, false );
				return true;

			case 'flying-press/flying-press.php':
				$options = get_option( 'FLYING_PRESS_CONFIG', array() );
				if ( ! is_array( $options ) ) {
					return false;
				}
				$keys = array();
				if ( 'assets' === $group ) {
					$keys = array( 'css_js_minify', 'css_rucss', 'js_delay', 'js_delay_third_party', 'js_delay_selected', 'css_js_self_host_third_party' );
				} elseif ( 'lazyload' === $group ) {
					$keys = array( 'lazy_load', 'youtube_placeholder', 'lazy_render', 'properly_size_images' );
				} elseif ( 'images' === $group ) {
					$keys                    = array( 'image_auto_optimize_uploads', 'properly_size_images' );
					$options['image_format'] = '';
				} elseif ( 'cdn' === $group ) {
					$keys = array( 'cdn', 'cf_page_caching' );
				}
				foreach ( $keys as $key ) {
					$options[ $key ] = false;
				}
				update_option( 'FLYING_PRESS_CONFIG', $options, false );
				return true;

			case 'wp-smush-pro/wp-smush.php':
			case 'wp-smushit/wp-smush.php':
				$options = get_option( 'wp-smush-settings', array() );
				if ( ! is_array( $options ) ) {
					return false;
				}
				if ( 'lazyload' === $group ) {
					$options['lazy_load'] = false;
				} elseif ( 'cdn' === $group ) {
					$options['cdn'] = false;
				}
				update_option( 'wp-smush-settings', $options, false );
				return true;

			case 'autoptimize/autoptimize.php':
				if ( 'assets' === $group ) {
					foreach ( array( 'autoptimize_js', 'autoptimize_css', 'autoptimize_html' ) as $option ) {
						update_option( $option, 'off', false );
					}
					return true;
				}
				if ( 'lazyload' === $group ) {
					update_option( 'autoptimize_imgopt_lazyload', '', false );
					return true;
				}
				if ( 'images' === $group ) {
					update_option( 'autoptimize_imgopt_optimization', '', false );
					return true;
				}
				break;

			case 'ewww-image-optimizer/ewww-image-optimizer.php':
				if ( 'lazyload' === $group ) {
					update_option( 'ewww_image_optimizer_lazy_load', false, false );
					update_site_option( 'ewww_image_optimizer_lazy_load', false );
					return true;
				}
				break;

			case 'litespeed-cache/litespeed-cache.php':
				if ( 'assets' === $group ) {
					foreach ( array( 'litespeed.conf.optm-css_min', 'litespeed.conf.optm-js_min', 'litespeed.conf.optm-css_comb', 'litespeed.conf.optm-js_comb', 'litespeed.conf.optm-js_defer', 'litespeed.conf.optm-js_delay', 'litespeed.conf.optm-css_async' ) as $option ) {
						update_option( $option, 0, false );
					}
					return true;
				}
				if ( 'lazyload' === $group ) {
					foreach ( array( 'litespeed.conf.media-lazy', 'litespeed.conf.media-iframe_lazy', 'litespeed.conf.vqimg' ) as $option ) {
						update_option( $option, 0, false );
					}
					return true;
				}
				if ( 'images' === $group ) {
					foreach ( array( 'litespeed.conf.media-webp', 'litespeed.conf.media-webp_replace', 'litespeed.conf.img_optm-auto' ) as $option ) {
						update_option( $option, 0, false );
					}
					return true;
				}
				break;

			case 'sg-cachepress/sg-cachepress.php':
				if ( 'assets' === $group ) {
					foreach ( array( 'siteground_optimizer_optimize_html', 'siteground_optimizer_optimize_javascript', 'siteground_optimizer_combine_javascript', 'siteground_optimizer_optimize_css', 'siteground_optimizer_combine_css', 'siteground_optimizer_defer_javascript' ) as $option ) {
						update_option( $option, 0, false );
					}
					return true;
				}
				if ( 'lazyload' === $group ) {
					update_option( 'siteground_optimizer_lazyload_images', 0, false );
					return true;
				}
				break;
		}
		return false;
	}

	/** @return array<string,array<string,mixed>> */
	private function external_option_snapshot( $plugin_file ) {
		$map      = array(
			'wp-rocket/wp-rocket.php'             => array( 'option' => array( 'wp_rocket_settings' ) ),
			'perfmatters/perfmatters.php'         => array( 'option' => array( 'perfmatters_options' ) ),
			'flying-press/flying-press.php'       => array( 'option' => array( 'FLYING_PRESS_CONFIG' ) ),
			'wp-smush-pro/wp-smush.php'           => array( 'option' => array( 'wp-smush-settings' ) ),
			'wp-smushit/wp-smush.php'             => array( 'option' => array( 'wp-smush-settings' ) ),
			'autoptimize/autoptimize.php'         => array( 'option' => array( 'autoptimize_js', 'autoptimize_css', 'autoptimize_html', 'autoptimize_imgopt_lazyload', 'autoptimize_imgopt_optimization' ) ),
			'ewww-image-optimizer/ewww-image-optimizer.php' => array(
				'option'      => array( 'ewww_image_optimizer_lazy_load' ),
				'site_option' => array( 'ewww_image_optimizer_lazy_load' ),
			),
			'litespeed-cache/litespeed-cache.php' => array( 'option' => array( 'litespeed.conf.optm-css_min', 'litespeed.conf.optm-js_min', 'litespeed.conf.optm-css_comb', 'litespeed.conf.optm-js_comb', 'litespeed.conf.optm-js_defer', 'litespeed.conf.optm-js_delay', 'litespeed.conf.optm-css_async', 'litespeed.conf.media-lazy', 'litespeed.conf.media-iframe_lazy', 'litespeed.conf.media-webp', 'litespeed.conf.media-webp_replace', 'litespeed.conf.img_optm-auto' ) ),
			'sg-cachepress/sg-cachepress.php'     => array( 'option' => array( 'siteground_optimizer_optimize_html', 'siteground_optimizer_optimize_javascript', 'siteground_optimizer_combine_javascript', 'siteground_optimizer_optimize_css', 'siteground_optimizer_combine_css', 'siteground_optimizer_defer_javascript', 'siteground_optimizer_lazyload_images' ) ),
		);
		$snapshot = array(
			'option'      => array(),
			'site_option' => array(),
		);
		if ( empty( $map[ $plugin_file ] ) ) {
			return $snapshot;
		}
		foreach ( isset( $map[ $plugin_file ]['option'] ) ? $map[ $plugin_file ]['option'] : array() as $option ) {
			$snapshot['option'][ $option ] = get_option( $option, null );
		}
		foreach ( isset( $map[ $plugin_file ]['site_option'] ) ? $map[ $plugin_file ]['site_option'] : array() as $option ) {
			$snapshot['site_option'][ $option ] = get_site_option( $option, null );
		}
		return $snapshot;
	}

	private function restore_external_option_snapshot( array $snapshot ) {
		foreach ( isset( $snapshot['option'] ) ? $snapshot['option'] : array() as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
		foreach ( isset( $snapshot['site_option'] ) ? $snapshot['site_option'] : array() as $option => $value ) {
			if ( null === $value ) {
				delete_site_option( $option );
			} else {
				update_site_option( $option, $value );
			}
		}
	}

	private function any_enabled( array $options, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! empty( $options[ $key ] ) && 'off' !== $options[ $key ] && '0' !== (string) $options[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/** Return true/false, or null when none of the expected option keys exist. */
	private function any_option_state( array $options ) {
		$found  = false;
		$marker = new \stdClass();
		foreach ( $options as $option ) {
			$value = get_option( $option, $marker );
			if ( $marker === $value ) {
				continue;
			}
			$found = true;
			if ( ! empty( $value ) && 'off' !== $value && '0' !== (string) $value ) {
				return true;
			}
		}
		return $found ? false : null;
	}

	private function group_label( $group ) {
		switch ( $group ) {
			case 'cache':
				return __( 'page caching', 'nbh-performance' );
			case 'assets':
				return __( 'CSS and JavaScript optimization', 'nbh-performance' );
			case 'lazyload':
				return __( 'lazy loading', 'nbh-performance' );
			case 'images':
				return __( 'image optimization and next-generation formats', 'nbh-performance' );
			case 'cdn':
				return __( 'CDN URL rewriting', 'nbh-performance' );
		}
		return sanitize_text_field( $group );
	}

	private function human_list( array $values ) {
		$values = array_values( array_filter( array_unique( $values ) ) );
		$count  = count( $values );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $values[0];
		}
		$last = array_pop( $values );
		return implode( ', ', $values ) . ' ' . __( 'and', 'nbh-performance' ) . ' ' . $last;
	}

	/** @return array<int,string> */
	private function sanitize_groups( $groups ) {
		if ( is_string( $groups ) ) {
			$groups = explode( ',', $groups );
		}
		if ( ! is_array( $groups ) ) {
			return array();
		}
		$allowed = array_keys( self::GROUP_LABELS );
		$out     = array();
		foreach ( $groups as $group ) {
			$group = sanitize_key( (string) $group );
			if ( in_array( $group, $allowed, true ) ) {
				$out[] = $group;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function action( $label, $resolution, $plugin, $style, $confirm_title, $confirm_message, array $groups = array() ) {
		$args = array(
			'action'     => 'nbh_performance_resolve_issue',
			'resolution' => $resolution,
		);
		if ( '' !== $plugin ) {
			$args['plugin'] = $plugin;
		}
		if ( $groups ) {
			$args['groups'] = implode( ',', $groups );
		}
		$url = add_query_arg( $args, admin_url( 'admin-post.php' ) );
		$url = wp_nonce_url( $url, $this->nonce_action( $resolution, $plugin, $groups ) );
		return array(
			'label'           => $label,
			'url'             => $url,
			'style'           => $style,
			'confirm_title'   => $confirm_title,
			'confirm_message' => $confirm_message,
		);
	}

	private function nonce_action( $resolution, $plugin, array $groups ) {
		return 'nbh_performance_resolve_' . $resolution . '_' . $plugin . '_' . implode( '-', $groups );
	}
}
