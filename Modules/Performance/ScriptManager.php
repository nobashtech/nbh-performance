<?php
/**
 * Enterprise Script Manager.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;
use WP_REST_Request;
use WP_REST_Response;

final class ScriptManager implements Module {
	const MAX_WARNINGS = 100;

	private $matcher;
	private $graph;
	private $store;
	private $runtime_warnings = array();
	private $matched_rules    = array();

	public function __construct( ?AssetRuleMatcher $matcher = null, ?AssetDependencyGraph $graph = null, ?ScriptManagerRuleStore $store = null ) {
		$this->matcher = $matcher ? $matcher : new AssetRuleMatcher();
		$this->graph   = $graph ? $graph : new AssetDependencyGraph();
		$this->store   = $store ? $store : new ScriptManagerRuleStore();
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'apply_rules' ), PHP_INT_MAX );
		add_filter( 'nbh_performance_script_loading_strategy', array( $this, 'loading_strategy' ), 30, 3 );
		add_filter( 'script_loader_src', array( $this, 'filter_asset_version' ), 999, 2 );
		add_filter( 'style_loader_src', array( $this, 'filter_asset_version' ), 999, 2 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 999, 3 );
		add_filter( 'style_loader_tag', array( $this, 'filter_style_tag' ), 999, 4 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'print_preloads' ), 2 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'shutdown', array( $this, 'persist_warnings' ), 1 );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_rules' ),
					'permission_callback' => array( $this, 'allowed' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_rules' ),
					'permission_callback' => array( $this, 'allowed' ),
				),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/diagnostics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze_rule' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_rules' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_rules' ),
				'permission_callback' => array( $this, 'allowed' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/script-manager/history',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'history' ),
					'permission_callback' => array( $this, 'allowed' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_history' ),
					'permission_callback' => array( $this, 'allowed' ),
				),
			)
		);
	}

	public function status() {
		$enabled   = (bool) get_option( 'nbh_performance_script_manager_enabled', 0 );
		$testing   = (bool) get_option( 'nbh_performance_script_manager_testing_mode', 1 );
		$safe_mode = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		$rules     = $this->store->all();
		$warnings  = (array) get_option( 'nbh_performance_script_manager_warnings', array() );
		$active    = 0;
		foreach ( $rules as $rule ) {
			if ( ! isset( $rule['enabled'] ) || $rule['enabled'] ) {
				++$active;
			}
		}
		$snapshot = get_option( 'nbh_performance_asset_inventory_snapshot', array() );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$scripts  = isset( $snapshot['scripts'] ) && is_array( $snapshot['scripts'] ) ? $snapshot['scripts'] : array();
		$styles   = isset( $snapshot['styles'] ) && is_array( $snapshot['styles'] ) ? $snapshot['styles'] : array();
		$assets   = array_values( array_merge( $scripts, $styles ) );

		return rest_ensure_response(
			array(
				'enabled'       => $enabled,
				'testing_mode'  => $testing,
				'safe_mode'     => $safe_mode,
				'runtime_ready' => $enabled && ! $safe_mode,
				'rule_count'    => count( $rules ),
				'active_rules'  => $active,
				'warning_count' => count( $warnings ),
				'warnings'      => array_values( $warnings ),
				'rules'         => $rules,
				'protected'     => $this->protected_handles(),
				'history'       => $this->history_summary(),
				'schema'        => 3,
				'inventory'     => array(
					'enabled'      => (bool) get_option( 'nbh_performance_asset_inventory_enabled', 0 ) || $enabled,
					'captured_at'  => isset( $snapshot['captured_at'] ) ? (int) $snapshot['captured_at'] : 0,
					'url'          => isset( $snapshot['url'] ) ? (string) $snapshot['url'] : '',
					'script_count' => count( $scripts ),
					'style_count'  => count( $styles ),
					'asset_count'  => count( $assets ),
				),
				'assets'        => $assets,
			)
		);
	}

	public function allowed( $request ) {
		return current_user_can( 'manage_options' ) && \NBH\Performance\Modules\Security\NonceGuard::verify_rest( $request );
	}

	public function read_rules() {
		return rest_ensure_response(
			array(
				'rules'        => $this->store->all(),
				'protected'    => $this->protected_handles(),
				'testing_mode' => (bool) get_option( 'nbh_performance_script_manager_testing_mode', 1 ),
				'warnings'     => get_option( 'nbh_performance_script_manager_warnings', array() ),
				'history'      => $this->history_summary(),
				'schema'       => 3,
			)
		);
	}

	public function diagnostics() {
		return rest_ensure_response(
			array(
				'scripts'       => $this->registry_diagnostics( wp_scripts(), 'script' ),
				'styles'        => $this->registry_diagnostics( wp_styles(), 'style' ),
				'warnings'      => get_option( 'nbh_performance_script_manager_warnings', array() ),
				'matched_rules' => array_values( $this->matched_rules ),
				'context'       => $this->request_context(),
			)
		);
	}

	public function save_rules( $request ) {
		$rules = $request->get_param( 'rules' );
		$clean = $this->normalize_rules( $rules );
		$this->store->replace( $clean, 'manual_save' );
		if ( null !== $request->get_param( 'testing_mode' ) ) {
			update_option( 'nbh_performance_script_manager_testing_mode', rest_sanitize_boolean( $request->get_param( 'testing_mode' ) ) ? 1 : 0, false );
		}
		do_action( 'nbh_performance_script_manager_rules_updated', $clean );
		return $this->read_rules();
	}

	public function analyze_rule( WP_REST_Request $request ) {
		$rule = $this->normalize_rule( $request->get_param( 'rule' ) );
		if ( ! $rule ) {
			return new \WP_Error( 'nbh_invalid_rule', __( 'The proposed rule is invalid.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$registry = 'script' === $rule['type'] ? wp_scripts() : wp_styles();
		$item     = $registry && isset( $registry->registered[ $rule['handle'] ] ) ? $registry->registered[ $rule['handle'] ] : null;
		$meta     = $registry ? $this->graph->metadata( $registry, $rule['handle'] ) : array(
			'dependencies' => array(),
			'dependents'   => array(),
		);
		$risk     = $this->risk_level( $rule, $meta );
		return rest_ensure_response(
			array(
				'rule'         => $rule,
				'registered'   => (bool) $item,
				'protected'    => in_array( $rule['handle'], $this->protected_handles(), true ),
				'dependencies' => $meta['dependencies'],
				'dependents'   => $meta['dependents'],
				'risk'         => $risk,
				'explanation'  => $this->matcher->explain( $rule ),
			)
		);
	}

	public function export_rules() {
		return new WP_REST_Response( $this->store->export_payload(), 200 );
	}

	public function import_rules( WP_REST_Request $request ) {
		$payload = $request->get_param( 'payload' );
		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true );
		}
		if ( ! is_array( $payload ) || empty( $payload['rules'] ) || ! is_array( $payload['rules'] ) ) {
			return new \WP_Error( 'nbh_invalid_import', __( 'The imported Script Manager file is invalid.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$clean = $this->normalize_rules( $payload['rules'] );
		$this->store->replace( $clean, 'import' );
		return $this->read_rules();
	}

	public function history() {
		return rest_ensure_response( array( 'history' => $this->history_summary() ) );
	}

	public function restore_history( WP_REST_Request $request ) {
		$rules = $this->store->restore( $request->get_param( 'snapshot_id' ) );
		if ( null === $rules ) {
			return new \WP_Error( 'nbh_history_not_found', __( 'The requested rules snapshot was not found.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		return $this->read_rules();
	}

	public function apply_rules() {
		if ( ! $this->runtime_enabled() ) {
			return;
		}
		foreach ( $this->matching_rules() as $rule ) {
			$this->matched_rules[ $rule['id'] ] = array(
				'id'     => $rule['id'],
				'handle' => $rule['handle'],
				'action' => $rule['action'],
			);
			$registry                           = 'script' === $rule['type'] ? wp_scripts() : wp_styles();
			if ( ! $registry || ! isset( $registry->registered[ $rule['handle'] ] ) ) {
				continue;
			}
			if ( 'unload' === $rule['action'] ) {
				$this->apply_unload( $registry, $rule );
			} elseif ( 'move_footer' === $rule['action'] && 'script' === $rule['type'] ) {
				$registry->add_data( $rule['handle'], 'group', 1 );
				if ( isset( $registry->registered[ $rule['handle'] ]->extra ) ) {
					$registry->registered[ $rule['handle'] ]->extra['group'] = 1;
				}
			}
		}
	}

	public function loading_strategy( $strategy, $handle, $src ) {
		unset( $src );
		if ( ! $this->runtime_enabled() ) {
			return $strategy;
		}
		foreach ( $this->matching_rules() as $rule ) {
			if ( 'script' === $rule['type'] && $handle === $rule['handle'] && in_array( $rule['action'], array( 'delay', 'defer', 'async' ), true ) ) {
				return $rule['action'];
			}
		}
		return $strategy;
	}

	public function filter_asset_version( $src, $handle ) {
		if ( ! $this->runtime_enabled() || ! $this->has_matching_action( $handle, 'remove_version' ) ) {
			return $src;
		}
		return remove_query_arg( 'ver', $src );
	}

	public function filter_script_tag( $tag, $handle, $src ) {
		unset( $src );
		if ( ! $this->runtime_enabled() ) {
			return $tag;
		}
		if ( $this->has_matching_action( $handle, 'move_footer' ) ) {
			$tag = str_replace( '<script ', '<script data-nbh-moved-footer="1" ', $tag );
		}
		return $tag;
	}

	public function filter_style_tag( $html, $handle, $href, $media ) {
		unset( $href, $media );
		if ( $this->runtime_enabled() && $this->has_matching_action( $handle, 'preload' ) ) {
			$html = str_replace( '<link ', '<link data-nbh-preloaded-style="1" ', $html );
		}
		return $html;
	}

	public function resource_hints( $urls, $relation_type ) {
		if ( ! $this->runtime_enabled() || ! in_array( $relation_type, array( 'preconnect', 'dns-prefetch' ), true ) ) {
			return $urls;
		}
		foreach ( $this->matching_rules() as $rule ) {
			if ( $relation_type !== $rule['action'] ) {
				continue;
			}
			$src  = $this->registered_source( $rule['type'], $rule['handle'] );
			$host = $src ? wp_parse_url( $src, PHP_URL_HOST ) : '';
			if ( $host ) {
				$urls[] = '//' . $host;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	public function print_preloads() {
		if ( ! $this->runtime_enabled() ) {
			return;
		}
		$printed = array();
		foreach ( $this->matching_rules() as $rule ) {
			if ( 'preload' !== $rule['action'] ) {
				continue;
			}
			$src = $this->registered_source( $rule['type'], $rule['handle'] );
			if ( ! $src || isset( $printed[ $src ] ) ) {
				continue;
			}
			$printed[ $src ] = true;
			printf( "\n<link rel=\"preload\" href=\"%s\" as=\"%s\"%s>\n", esc_url( $src ), 'style' === $rule['type'] ? 'style' : 'script', 'script' === $rule['type'] ? ' crossorigin="anonymous"' : '' );
		}
	}

	public function persist_warnings() {
		if ( empty( $this->runtime_warnings ) ) {
			return;
		}
		$warnings = array_merge( (array) get_option( 'nbh_performance_script_manager_warnings', array() ), $this->runtime_warnings );
		update_option( 'nbh_performance_script_manager_warnings', array_slice( $warnings, -self::MAX_WARNINGS ), false );
	}

	public function admin_bar( $admin_bar ) {
		if ( is_admin() || ! current_user_can( 'manage_options' ) || ! $admin_bar ) {
			return;
		}
		$admin_bar->add_node(
			array(
				'id'    => 'nbh-performance-script-manager',
				'title' => __( 'NBH Script Manager', 'nbh-performance' ),
				'href'  => add_query_arg( 'inspect_url', $this->current_url(), admin_url( 'options-general.php?page=nbh-performance' ) ) . '#script_manager',
				'meta'  => array( 'title' => __( 'Inspect this page in NBH Performance Script Manager', 'nbh-performance' ) ),
			)
		);
	}

	private function apply_unload( $registry, array $rule ) {
		$dependents        = $this->graph->dependents( $registry, $rule['handle'] );
		$queued_dependents = array_values( array_intersect( $dependents, (array) $registry->queue ) );
		if ( $queued_dependents && empty( $rule['cascade'] ) ) {
			$this->warn( $rule, 'dependency_blocked', $queued_dependents );
			return;
		}
		if ( ! empty( $rule['cascade'] ) ) {
			foreach ( $queued_dependents as $dependent ) {
				$this->dequeue( $rule['type'], $dependent );
			}
		}
		$this->dequeue( $rule['type'], $rule['handle'] );
	}

	private function matching_rules() {
		$out = array();
		foreach ( $this->store->all() as $rule ) {
			if ( $this->matcher->matches( $rule ) ) {
				$out[] = $rule;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['priority'] <=> (int) $a['priority'];
			}
		);
		return $out;
	}

	private function has_matching_action( $handle, $action ) {
		foreach ( $this->matching_rules() as $rule ) {
			if ( $handle === $rule['handle'] && $action === $rule['action'] ) {
				return true;
			}
		}
		return false;
	}

	private function runtime_enabled() {
		if ( is_admin() || wp_doing_ajax() || get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_script_manager_enabled', 0 ) ) {
			return false;
		}
		if ( get_option( 'nbh_performance_script_manager_testing_mode', 1 ) ) {
			return is_user_logged_in() && current_user_can( 'manage_options' );
		}
		return true;
	}

	private function normalize_rules( $rules ) {
		$clean = array();
		foreach ( array_slice( is_array( $rules ) ? $rules : array(), 0, ScriptManagerRuleStore::MAX_RULES ) as $rule ) {
			$normalized = $this->normalize_rule( $rule );
			if ( $normalized ) {
				$clean[] = $normalized;
			}
		}
		return $clean;
	}

	private function normalize_rule( $rule ) {
		if ( ! is_array( $rule ) ) {
			return null;
		}
		$handle  = isset( $rule['handle'] ) ? sanitize_key( $rule['handle'] ) : '';
		$type    = isset( $rule['type'] ) ? sanitize_key( $rule['type'] ) : '';
		$action  = isset( $rule['action'] ) ? sanitize_key( $rule['action'] ) : 'unload';
		$scope   = isset( $rule['scope'] ) ? sanitize_key( $rule['scope'] ) : 'global';
		$actions = array( 'unload', 'delay', 'defer', 'async', 'move_footer', 'preload', 'preconnect', 'dns-prefetch', 'remove_version' );
		if ( '' === $handle || ! in_array( $type, array( 'script', 'style' ), true ) || ! in_array( $action, $actions, true ) ) {
			return null;
		}
		if ( in_array( $handle, $this->protected_handles(), true ) && 'unload' === $action ) {
			return null;
		}
		$scopes = array( 'global', 'url_exact', 'url_contains', 'url_regex', 'url_except', 'post_type', 'singular_id', 'taxonomy', 'front_page', 'home_page', 'archive', 'author_archive', 'search', '404', 'template' );
		if ( ! in_array( $scope, $scopes, true ) ) {
			$scope = 'global';
		}
		$device = isset( $rule['device'] ) ? sanitize_key( $rule['device'] ) : 'all';
		$auth   = isset( $rule['auth'] ) ? sanitize_key( $rule['auth'] ) : 'all';
		return array(
			'id'           => ! empty( $rule['id'] ) ? substr( sanitize_key( $rule['id'] ), 0, 64 ) : wp_generate_uuid4(),
			'handle'       => $handle,
			'type'         => $type,
			'action'       => $action,
			'scope'        => $scope,
			'value'        => isset( $rule['value'] ) ? substr( sanitize_text_field( $rule['value'] ), 0, 500 ) : '',
			'device'       => in_array( $device, array( 'all', 'desktop', 'mobile' ), true ) ? $device : 'all',
			'auth'         => in_array( $auth, array( 'all', 'guest', 'logged_in' ), true ) ? $auth : 'all',
			'roles'        => array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', isset( $rule['roles'] ) ? (array) $rule['roles'] : array() ) ) ) ), 0, 20 ),
			'language'     => isset( $rule['language'] ) ? substr( sanitize_key( $rule['language'] ), 0, 20 ) : '',
			'query_key'    => isset( $rule['query_key'] ) ? substr( sanitize_key( $rule['query_key'] ), 0, 64 ) : '',
			'query_value'  => isset( $rule['query_value'] ) ? substr( sanitize_text_field( $rule['query_value'] ), 0, 200 ) : '',
			'cookie_key'   => isset( $rule['cookie_key'] ) ? substr( sanitize_key( $rule['cookie_key'] ), 0, 64 ) : '',
			'cookie_value' => isset( $rule['cookie_value'] ) ? substr( sanitize_text_field( $rule['cookie_value'] ), 0, 200 ) : '',
			'cascade'      => ! empty( $rule['cascade'] ),
			'enabled'      => ! isset( $rule['enabled'] ) || rest_sanitize_boolean( $rule['enabled'] ),
			'priority'     => max( 0, min( 100, isset( $rule['priority'] ) ? absint( $rule['priority'] ) : 50 ) ),
			'note'         => isset( $rule['note'] ) ? substr( sanitize_text_field( $rule['note'] ), 0, 250 ) : '',
		);
	}

	private function registry_diagnostics( $registry, $type ) {
		$out = array();
		foreach ( (array) $registry->queue as $handle ) {
			if ( ! isset( $registry->registered[ $handle ] ) ) {
				continue;
			}
			$item  = $registry->registered[ $handle ];
			$meta  = $this->graph->metadata( $registry, $handle );
			$out[] = array(
				'handle'       => sanitize_key( $handle ),
				'type'         => $type,
				'src'          => esc_url_raw( (string) $item->src ),
				'dependencies' => $meta['dependencies'],
				'dependents'   => $meta['dependents'],
				'protected'    => in_array( sanitize_key( $handle ), $this->protected_handles(), true ),
			);
		}
		return $out;
	}

	private function registered_source( $type, $handle ) {
		$registry = 'script' === $type ? wp_scripts() : wp_styles();
		if ( ! $registry || ! isset( $registry->registered[ $handle ] ) ) {
			return '';
		}
		$src = (string) $registry->registered[ $handle ]->src;
		if ( 0 === strpos( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			return home_url( $src );
		}
		return $src;
	}

	private function risk_level( array $rule, array $meta ) {
		if ( in_array( $rule['handle'], $this->protected_handles(), true ) ) {
			return 'critical';
		}
		if ( 'unload' === $rule['action'] && ! empty( $meta['dependents'] ) ) {
			return 'high';
		}
		if ( in_array( $rule['action'], array( 'delay', 'async', 'move_footer' ), true ) && ! empty( $meta['dependents'] ) ) {
			return 'medium';
		}
		return 'low';
	}

	private function dequeue( $type, $handle ) {
		if ( 'script' === $type ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		} else {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
	}

	private function warn( array $rule, $reason, array $handles ) {
		$this->runtime_warnings[] = array(
			'time'       => time(),
			'rule_id'    => $rule['id'],
			'handle'     => $rule['handle'],
			'reason'     => sanitize_key( $reason ),
			'blocked_by' => array_values( array_map( 'sanitize_key', $handles ) ),
		);
	}

	private function protected_handles() {
		$handles = array( 'jquery', 'jquery-core', 'wp-hooks', 'wp-i18n', 'wp-element', 'wp-api-fetch', 'wp-polyfill', 'wp-dom-ready', 'heartbeat', 'wc-cart-fragments', 'wc-checkout', 'woocommerce', 'elementor-frontend', 'wpforms', 'contact-form-7', 'nbh-performance-delay-loader' );
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters( 'nbh_performance_script_manager_protected_handles', $handles ) ) ) );
	}

	private function history_summary() {
		return array_map(
			static function ( $snapshot ) {
				return array(
					'id'         => isset( $snapshot['id'] ) ? $snapshot['id'] : '',
					'created_at' => isset( $snapshot['created_at'] ) ? (int) $snapshot['created_at'] : 0,
					'reason'     => isset( $snapshot['reason'] ) ? $snapshot['reason'] : '',
					'rule_count' => isset( $snapshot['rule_count'] ) ? (int) $snapshot['rule_count'] : 0,
				);
			},
			array_reverse( $this->store->history() )
		);
	}

	private function request_context() {
		return array(
			'url'       => esc_url_raw( $this->current_url() ),
			'object_id' => (int) get_queried_object_id(),
			'mobile'    => function_exists( 'wp_is_mobile' ) && wp_is_mobile(),
			'locale'    => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
		);
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/';
		return $scheme . '://' . $host . $uri;
	}
}
