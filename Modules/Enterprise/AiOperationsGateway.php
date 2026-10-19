<?php
/**
 * Secure JSON-RPC operations gateway with MCP-style tool discovery and approvals.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Enterprise;

use NBH\Performance\Modules\CacheEngine\PageCacheManager;
use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Hub\SafeModeManager;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\PerformanceIntelligence\UnifiedMetricsService;
use NBH\Performance\Modules\Security\AuditLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class AiOperationsGateway implements Module {
	const TOKEN_HASH_OPTION  = 'nbh_performance_ai_gateway_token_hash';
	const TOKEN_SCOPE_OPTION = 'nbh_performance_ai_gateway_scope';
	const APPROVALS_OPTION   = 'nbh_performance_ai_gateway_approvals';

	/** @var PageCacheManager */
	private $page_cache;
	/** @var SafeModeManager */
	private $safe_mode;
	/** @var JobStore */
	private $jobs;
	/** @var UnifiedMetricsService */
	private $metrics;
	/** @var AuditLogger */
	private $audit;

	public function __construct( PageCacheManager $page_cache, SafeModeManager $safe_mode, JobStore $jobs, UnifiedMetricsService $metrics, AuditLogger $audit ) {
		$this->page_cache = $page_cache;
		$this->safe_mode  = $safe_mode;
		$this->jobs       = $jobs;
		$this->metrics    = $metrics;
		$this->audit      = $audit;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/ai/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => $admin,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/ai/token',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_rotate_token' ),
				'permission_callback' => $admin,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/ai/approval',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_approval' ),
				'permission_callback' => $admin,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/ai/rpc',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_rpc' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** @return \WP_REST_Response */
	public function rest_status() {
		return rest_ensure_response( $this->status() );
	}

	/** @return array<string,mixed> */
	public function status() {
		$approvals = $this->approvals();
		$pending   = array_values(
			array_filter(
				$approvals,
				static function ( $item ) {
					return is_array( $item ) && 'pending' === ( isset( $item['status'] ) ? $item['status'] : '' );
				}
			)
		);
		return array(
			'configured'        => (bool) get_option( self::TOKEN_HASH_OPTION, '' ),
			'scope'             => sanitize_key( (string) get_option( self::TOKEN_SCOPE_OPTION, 'read' ) ),
			'write_enabled'     => (bool) get_option( 'nbh_performance_ai_gateway_write_enabled', 0 ),
			'pending_approvals' => array_map( array( $this, 'public_approval' ), array_slice( $pending, 0, 20 ) ),
			'endpoint'          => rest_url( 'nbh-performance/v1/enterprise/ai/rpc' ),
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response */
	public function rest_rotate_token( WP_REST_Request $request ) {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$scope = in_array( $scope, array( 'read', 'read_write' ), true ) ? $scope : 'read';
		$token = 'scai_' . wp_generate_password( 56, false, false );
		update_option( self::TOKEN_HASH_OPTION, wp_hash_password( $token ), false );
		update_option( self::TOKEN_SCOPE_OPTION, $scope, false );
		update_option( self::APPROVALS_OPTION, array(), false );
		$this->audit->record( 'ai_gateway_token_rotated', array( 'scope' => $scope ), 'warning' );
		return rest_ensure_response(
			array(
				'success' => true,
				'token'   => $token,
				'scope'   => $scope,
				'notice'  => __( 'Copy this token now. NBH Performance stores only a one-way hash.', 'nbh-performance' ),
			)
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_approval( WP_REST_Request $request ) {
		$id       = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$decision = sanitize_key( (string) $request->get_param( 'decision' ) );
		if ( ! in_array( $decision, array( 'approved', 'denied' ), true ) ) {
			return new WP_Error( 'nbh_performance_ai_approval_decision_invalid', __( 'Choose approved or denied.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$approvals = $this->approvals();
		if ( ! isset( $approvals[ $id ] ) || ! is_array( $approvals[ $id ] ) || 'pending' !== $approvals[ $id ]['status'] ) {
			return new WP_Error( 'nbh_performance_ai_approval_missing', __( 'The approval request is missing or no longer pending.', 'nbh-performance' ), array( 'status' => 404 ) );
		}
		$approvals[ $id ]['status']     = $decision;
		$approvals[ $id ]['decided_at'] = time();
		$approvals[ $id ]['decided_by'] = get_current_user_id();
		update_option( self::APPROVALS_OPTION, $this->limit_approvals( $approvals ), false );
		$this->audit->record(
			'ai_gateway_approval_decided',
			array(
				'approval_id' => $id,
				'decision'    => $decision,
			),
			'warning'
		);
		return rest_ensure_response(
			array(
				'success'  => true,
				'approval' => $this->public_approval( $approvals[ $id ] ),
			)
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response */
	public function rest_rpc( WP_REST_Request $request ) {
		$id         = $request->get_param( 'id' );
		$authorized = $this->authorize_external( $request );
		if ( is_wp_error( $authorized ) ) {
			return rest_ensure_response( $this->rpc_error( $id, -32001, $authorized->get_error_message() ) );
		}
		$method = sanitize_text_field( (string) $request->get_param( 'method' ) );
		$params = $request->get_param( 'params' );
		$params = is_array( $params ) ? $params : array();

		if ( 'initialize' === $method ) {
			return rest_ensure_response(
				$this->rpc_result(
					$id,
					array(
						'protocolVersion' => '2025-06-18',
						'serverInfo'      => array(
							'name'    => 'NBH Performance Enterprise Gateway',
							'version' => NBH_PERFORMANCE_VERSION,
						),
						'capabilities'    => array( 'tools' => new \stdClass() ),
					)
				)
			);
		}
		if ( 'tools/list' === $method ) {
			return rest_ensure_response( $this->rpc_result( $id, array( 'tools' => array_values( $this->public_tools() ) ) ) );
		}
		if ( 'tools/call' !== $method ) {
			return rest_ensure_response( $this->rpc_error( $id, -32601, 'Method not found.' ) );
		}

		$name      = sanitize_key( (string) ( isset( $params['name'] ) ? $params['name'] : '' ) );
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$tools     = $this->tools();
		if ( ! isset( $tools[ $name ] ) ) {
			return rest_ensure_response( $this->rpc_error( $id, -32602, 'Unknown tool.' ) );
		}
		if ( ! empty( $tools[ $name ]['write'] ) ) {
			$approval = $this->authorize_write_call( $name, $arguments );
			if ( is_wp_error( $approval ) ) {
				$data = $approval->get_error_data();
				return rest_ensure_response(
					$this->rpc_result(
						$id,
						array(
							'isError'  => true,
							'content'  => array(
								array(
									'type' => 'text',
									'text' => $approval->get_error_message(),
								),
							),
							'approval' => is_array( $data ) ? $data : array(),
						)
					)
				);
			}
		}
		$result = $this->execute_tool( $name, $arguments );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				$this->rpc_result(
					$id,
					array(
						'isError' => true,
						'content' => array(
							array(
								'type' => 'text',
								'text' => $result->get_error_message(),
							),
						),
					)
				)
			);
		}
		$this->audit->record(
			'ai_gateway_tool_called',
			array(
				'tool'  => $name,
				'write' => ! empty( $tools[ $name ]['write'] ) ? 'yes' : 'no',
			),
			! empty( $tools[ $name ]['write'] ) ? 'warning' : 'info'
		);
		return rest_ensure_response(
			$this->rpc_result(
				$id,
				array(
					'content'           => array(
						array(
							'type' => 'text',
							'text' => wp_json_encode( $result ),
						),
					),
					'structuredContent' => $result,
				)
			)
		);
	}


	/** Return MCP-compatible tool definitions without internal authorization metadata.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function public_tools() {
		$tools = $this->tools();
		foreach ( $tools as $name => $tool ) {
			unset( $tool['write'] );
			$tools[ $name ] = $tool;
		}
		return $tools;
	}

	/** @return array<string,array<string,mixed>> */
	private function tools() {
		return array(
			'nbh_performance_get_status'      => array(
				'name'        => 'nbh_performance_get_status',
				'description' => 'Read current NBH Performance cache, Safe Mode, queue, and edge status.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'write'       => false,
			),
			'nbh_performance_get_performance' => array(
				'name'        => 'nbh_performance_get_performance',
				'description' => 'Read unified laboratory and real-user performance metrics.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'write'       => false,
			),
			'nbh_performance_get_jobs'        => array(
				'name'        => 'nbh_performance_get_jobs',
				'description' => 'Read background job queue statistics.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'write'       => false,
			),
			'nbh_performance_purge_cache'     => array(
				'name'        => 'nbh_performance_purge_cache',
				'description' => 'Purge the complete page cache or one same-origin URL after explicit approval.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'         => array( 'type' => 'string' ),
						'approval_id' => array( 'type' => 'string' ),
					),
				),
				'write'       => true,
			),
			'nbh_performance_set_safe_mode'   => array(
				'name'        => 'nbh_performance_set_safe_mode',
				'description' => 'Activate or turn off Safe Mode after explicit approval.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'enabled' ),
					'properties' => array(
						'enabled'     => array( 'type' => 'boolean' ),
						'approval_id' => array( 'type' => 'string' ),
					),
				),
				'write'       => true,
			),
		);
	}

	/** @param string $name Tool. @param array<string,mixed> $arguments Args. @return array<string,mixed>|WP_Error */
	private function execute_tool( $name, array $arguments ) {
		if ( 'nbh_performance_get_status' === $name ) {
			return array(
				'version'            => NBH_PERFORMANCE_VERSION,
				'safe_mode'          => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
				'page_cache_enabled' => (bool) get_option( 'nbh_performance_cache_enabled', 0 ),
				'cache'              => get_option( 'nbh_performance_cache_stats', array() ),
				'jobs'               => $this->jobs->stats(),
				'edge'               => array(
					'enabled'    => (bool) get_option( 'nbh_performance_edge_html_cache_enabled', 0 ),
					'last_purge' => get_option( 'nbh_performance_edge_last_purge', array() ),
				),
			);
		}
		if ( 'nbh_performance_get_performance' === $name ) {
			return $this->metrics->summary();
		}
		if ( 'nbh_performance_get_jobs' === $name ) {
			return $this->jobs->stats();
		}
		if ( 'nbh_performance_purge_cache' === $name ) {
			$url = isset( $arguments['url'] ) ? esc_url_raw( (string) $arguments['url'] ) : '';
			if ( '' !== $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
				return new WP_Error( 'nbh_performance_ai_url_forbidden', __( 'Only same-origin URLs may be purged.', 'nbh-performance' ) );
			}
			$success = '' === $url ? $this->page_cache->purge_all() : $this->page_cache->purge_url( $url );
			return array(
				'success' => $success,
				'url'     => $url,
			);
		}
		if ( 'nbh_performance_set_safe_mode' === $name ) {
			$enabled = ! empty( $arguments['enabled'] );
			$enabled ? $this->safe_mode->enable() : $this->safe_mode->disable( false );
			return array(
				'success'   => true,
				'safe_mode' => $enabled,
			);
		}
		return new WP_Error( 'nbh_performance_ai_tool_unknown', __( 'Unknown operation.', 'nbh-performance' ) );
	}

	/** @param WP_REST_Request $request Request. @return true|WP_Error */
	private function authorize_external( WP_REST_Request $request ) {
		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		$token         = 0 === stripos( $authorization, 'Bearer ' ) ? trim( substr( $authorization, 7 ) ) : '';
		$hash          = (string) get_option( self::TOKEN_HASH_OPTION, '' );
		if ( '' === $token || '' === $hash || ! wp_check_password( $token, $hash ) ) {
			return new WP_Error( 'nbh_performance_ai_unauthorized', __( 'Invalid AI operations token.', 'nbh-performance' ), array( 'status' => 401 ) );
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'nbh_performance_ai_rate_' . md5( $ip . '|' . substr( $hash, -16 ) );
		$count = absint( get_transient( $key ) );
		if ( $count >= 60 ) {
			return new WP_Error( 'nbh_performance_ai_rate_limited', __( 'AI operations rate limit exceeded.', 'nbh-performance' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/** @param string $tool Tool. @param array<string,mixed> $arguments Args. @return true|WP_Error */
	private function authorize_write_call( $tool, array $arguments ) {
		if ( 'read_write' !== get_option( self::TOKEN_SCOPE_OPTION, 'read' ) || ! get_option( 'nbh_performance_ai_gateway_write_enabled', 0 ) ) {
			return new WP_Error( 'nbh_performance_ai_write_disabled', __( 'Write operations are disabled for this gateway.', 'nbh-performance' ) );
		}
		$approval_id = isset( $arguments['approval_id'] ) ? sanitize_text_field( (string) $arguments['approval_id'] ) : '';
		$args_copy   = $arguments;
		unset( $args_copy['approval_id'] );
		$args_hash = hash( 'sha256', wp_json_encode( $args_copy ) );
		$approvals = $this->approvals();
		if ( '' !== $approval_id && isset( $approvals[ $approval_id ] ) && is_array( $approvals[ $approval_id ] ) ) {
			$item = $approvals[ $approval_id ];
			if ( 'approved' === $item['status'] && $tool === $item['tool'] && $args_hash === $item['args_hash'] && empty( $item['consumed_at'] ) && absint( $item['expires_at'] ) >= time() ) {
				$approvals[ $approval_id ]['status']      = 'consumed';
				$approvals[ $approval_id ]['consumed_at'] = time();
				update_option( self::APPROVALS_OPTION, $this->limit_approvals( $approvals ), false );
				return true;
			}
		}
		$id               = wp_generate_uuid4();
		$approvals[ $id ] = array(
			'id'         => $id,
			'tool'       => $tool,
			'args_hash'  => $args_hash,
			'arguments'  => $this->redact_arguments( $args_copy ),
			'status'     => 'pending',
			'created_at' => time(),
			'expires_at' => time() + HOUR_IN_SECONDS,
		);
		update_option( self::APPROVALS_OPTION, $this->limit_approvals( $approvals ), false );
		$this->audit->record(
			'ai_gateway_approval_requested',
			array(
				'approval_id' => $id,
				'tool'        => $tool,
			),
			'warning'
		);
		return new WP_Error(
			'nbh_performance_ai_approval_required',
			__( 'Administrator approval is required before this write operation can run.', 'nbh-performance' ),
			array(
				'approval_required' => true,
				'approval_id'       => $id,
				'expires_at'        => $approvals[ $id ]['expires_at'],
			)
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function approvals() {
		$items = get_option( self::APPROVALS_OPTION, array() );
		return is_array( $items ) ? $items : array();
	}

	/** @param array<string,array<string,mixed>> $items Items. @return array<string,array<string,mixed>> */
	private function limit_approvals( array $items ) {
		uasort(
			$items,
			static function ( $a, $b ) {
				return absint( isset( $b['created_at'] ) ? $b['created_at'] : 0 ) <=> absint( isset( $a['created_at'] ) ? $a['created_at'] : 0 );
			}
		);
		return array_slice( $items, 0, 100, true );
	}

	/** @param array<string,mixed> $item Item. @return array<string,mixed> */
	private function public_approval( $item ) {
		return array(
			'id'         => isset( $item['id'] ) ? $item['id'] : '',
			'tool'       => isset( $item['tool'] ) ? $item['tool'] : '',
			'arguments'  => isset( $item['arguments'] ) ? $item['arguments'] : array(),
			'status'     => isset( $item['status'] ) ? $item['status'] : '',
			'created_at' => absint( isset( $item['created_at'] ) ? $item['created_at'] : 0 ),
			'expires_at' => absint( isset( $item['expires_at'] ) ? $item['expires_at'] : 0 ),
		);
	}

	/** @param array<string,mixed> $arguments Args. @return array<string,mixed> */
	private function redact_arguments( array $arguments ) {
		foreach ( $arguments as $key => $value ) {
			if ( preg_match( '/(?:token|secret|password|license|cookie)/i', (string) $key ) ) {
				$arguments[ $key ] = '[redacted]';
			} elseif ( ! is_scalar( $value ) ) {
				$arguments[ $key ] = '[complex]';
			} else {
				$arguments[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $arguments;
	}

	/** @param mixed $id ID. @param mixed $result Result. @return array<string,mixed> */
	private function rpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}
	/** @param mixed $id ID. @param int $code Code. @param string $message Message. @return array<string,mixed> */
	private function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => (int) $code,
				'message' => (string) $message,
			),
		);
	}
}
