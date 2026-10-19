<?php
/** Transactional settings snapshots and rollback. @package NBHPerformance */
namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobQueue;
use NBH\Performance\Modules\Security\CapabilityGuard;
use NBH\Performance\Modules\Security\NonceGuard;
use WP_REST_Request;
use WP_REST_Server;

final class ConfigurationTransactionManager implements Module {
	const OPTION = 'nbh_performance_configuration_transactions';
	private $queue;
	private $toggles;
	private $settings;
	private $active = array();

	public function __construct( JobQueue $queue, FeatureToggleManager $toggles, AdvancedSettingsManager $settings ) {
		$this->queue    = $queue;
		$this->toggles  = $toggles;
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'nbh_performance_before_feature_toggles_updated', array( $this, 'before_features' ), 10, 1 );
		add_action( 'nbh_performance_feature_toggles_updated', array( $this, 'after_features' ), 100, 1 );
		add_action( 'nbh_performance_before_advanced_settings_updated', array( $this, 'before_settings' ), 10, 1 );
		add_action( 'nbh_performance_advanced_settings_updated', array( $this, 'after_settings' ), 100, 1 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/transactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_list' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/transactions/rollback',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_rollback' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( WP_REST_Request $request ) {
		return CapabilityGuard::can_manage() && NonceGuard::verify_rest( $request );
	}
	public function before_features( $values ) {
		$this->begin( 'feature_toggles', array_keys( (array) $values ) );
	}
	public function after_features( $values ) {
		$this->finish( 'feature_toggles', (array) $values );
	}
	public function before_settings( $values ) {
		$this->begin( 'advanced_settings', array_keys( (array) $values ) );
	}
	public function after_settings( $values ) {
		$this->finish( 'advanced_settings', (array) $values );
	}

	/** Begin a transaction before a managed settings change. */
	public function begin( $context, array $requested_options = array() ) {
		$context = sanitize_key( $context );
		if ( isset( $this->active[ $context ] ) ) {
			return $this->active[ $context ];
		}
		$id     = wp_generate_uuid4();
		$record = array(
			'id'                => $id,
			'context'           => $context,
			'status'            => 'pending',
			'created_at'        => time(),
			'updated_at'        => time(),
			'user_id'           => get_current_user_id(),
			'requested_options' => array_values( array_unique( array_map( 'sanitize_key', $requested_options ) ) ),
			'before'            => $this->managed_snapshot(),
			'after'             => array(),
			'validation'        => array(),
		);
		$this->store_record( $record );
		$this->active[ $context ] = $id;
		return $id;
	}

	/** Commit and enqueue post-change validation. */
	public function finish( $context, array $updated = array() ) {
		$context = sanitize_key( $context );
		if ( empty( $this->active[ $context ] ) ) {
			return '';
		}
		$id = $this->active[ $context ];
		unset( $this->active[ $context ] );
		$transactions = $this->all();
		foreach ( $transactions as &$record ) {
			if ( $id !== $record['id'] ) {
				continue;
			}
			$record['status']     = 'committed';
			$record['updated_at'] = time();
			$record['updated']    = $updated;
			$record['after']      = $this->managed_snapshot();
			break;
		}
		if ( empty( $updated ) ) {
			foreach ( $transactions as &$record ) {
				if ( $id === $record['id'] ) {
					$record['status'] = 'no_changes';
					break;
				}
			}
			$this->save_all( $transactions );
			return $id;
		}
		$this->save_all( $transactions );
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'all'       => true,
				'warm_urls' => array( home_url( '/' ) ),
				'reason'    => 'configuration_transaction',
			),
			array(
				'priority'   => 90,
				'unique_key' => 'transaction-purge|' . $id,
			)
		);
		if ( get_option( 'nbh_performance_staging_auto_validation', 1 ) ) {
			$this->queue->enqueue(
				'staging_validation',
				array(
					'transaction_id' => $id,
					'context'        => $context,
				),
				array(
					'delay'        => 10,
					'priority'     => 70,
					'max_attempts' => 2,
					'unique_key'   => 'validation|' . $id,
				)
			);
		}
		do_action( 'nbh_performance_configuration_transaction_committed', $id, $context, $updated );
		return $id;
	}

	/** Roll back a committed transaction. */
	public function rollback( $id, $reason = 'manual' ) {
		$id           = sanitize_text_field( (string) $id );
		$transactions = $this->all();
		$target       = null;
		foreach ( $transactions as $index => $record ) {
			if ( $id === $record['id'] ) {
				$target = $index;
				break;
			}
		}
		if ( null === $target || empty( $transactions[ $target ]['before'] ) || ! is_array( $transactions[ $target ]['before'] ) ) {
			return false;
		}
		$before = $transactions[ $target ]['before'];
		$after  = isset( $transactions[ $target ]['after'] ) && is_array( $transactions[ $target ]['after'] ) ? $transactions[ $target ]['after'] : array();
		foreach ( $after as $option => $value ) {
			unset( $value );
			if ( ! array_key_exists( $option, $before ) ) {
				delete_option( $option );
			}
		}
		foreach ( $before as $option => $value ) {
			update_option( $option, $value, false );
		}
		$transactions[ $target ]['status']          = 'rolled_back';
		$transactions[ $target ]['rollback_reason'] = sanitize_text_field( (string) $reason );
		$transactions[ $target ]['rolled_back_at']  = time();
		$transactions[ $target ]['updated_at']      = time();
		$this->save_all( $transactions );
		$this->queue->enqueue(
			'cache_invalidate',
			array(
				'all'       => true,
				'warm_urls' => array( home_url( '/' ) ),
				'reason'    => 'configuration_rollback',
			),
			array(
				'priority'   => 100,
				'unique_key' => 'rollback-purge|' . $id,
			)
		);
		do_action( 'nbh_performance_configuration_transaction_rolled_back', $id, $reason );
		return true;
	}

	/** Attach validation output to a transaction. */
	public function record_validation( $id, array $validation ) {
		$transactions = $this->all();
		foreach ( $transactions as &$record ) {
			if ( $id !== $record['id'] ) {
				continue;
			}
			$record['validation'] = $validation;
			$record['updated_at'] = time();
			$record['status']     = ! empty( $validation['passed'] ) ? 'validated' : 'validation_failed';
			break;
		}
		$this->save_all( $transactions );
	}

	public function rest_list() {
		return rest_ensure_response( array( 'transactions' => $this->summaries() ) );
	}
	public function rest_rollback( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => $this->rollback( (string) $request->get_param( 'id' ), 'manual_rest' ) ) );
	}
	public function latest() {
		$all = $this->all();
		return $all ? $all[0] : array();
	}
	public function latest_summary() {
		$summaries = $this->summaries();
		return $summaries ? $summaries[0] : array();
	}
	public function all() {
		$records = get_option( self::OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	/** Return transaction metadata without option values or secrets. */
	public function summaries() {
		return array_map(
			static function ( $record ) {
				return array(
					'id'                => isset( $record['id'] ) ? sanitize_text_field( $record['id'] ) : '',
					'context'           => isset( $record['context'] ) ? sanitize_key( $record['context'] ) : '',
					'status'            => isset( $record['status'] ) ? sanitize_key( $record['status'] ) : '',
					'created_at'        => isset( $record['created_at'] ) ? absint( $record['created_at'] ) : 0,
					'updated_at'        => isset( $record['updated_at'] ) ? absint( $record['updated_at'] ) : 0,
					'rolled_back_at'    => isset( $record['rolled_back_at'] ) ? absint( $record['rolled_back_at'] ) : 0,
					'rollback_reason'   => isset( $record['rollback_reason'] ) ? sanitize_text_field( $record['rollback_reason'] ) : '',
					'requested_options' => isset( $record['requested_options'] ) ? array_values( array_map( 'sanitize_key', (array) $record['requested_options'] ) ) : array(),
					'updated_count'     => isset( $record['updated'] ) && is_array( $record['updated'] ) ? count( $record['updated'] ) : 0,
					'validation'        => isset( $record['validation'] ) && is_array( $record['validation'] ) ? $record['validation'] : array(),
				);
			},
			$this->all()
		);
	}

	private function managed_snapshot() {
		$options = array( 'nbh_performance_safe_mode' );
		foreach ( $this->toggles->groups() as $group ) {
			$options = array_merge( $options, array_keys( $group['features'] ) );
		}
		foreach ( $this->settings->schema() as $section ) {
			$options = array_merge( $options, array_keys( $section['fields'] ) );
		}
		$snapshot = array();
		$sentinel = '__nbh_performance_transaction_missing__';
		foreach ( array_values( array_unique( array_map( 'sanitize_key', $options ) ) ) as $option ) {
			$value = get_option( $option, $sentinel );
			if ( $sentinel !== $value ) {
				$snapshot[ $option ] = $value;
			}
		}
		ksort( $snapshot );
		return $snapshot;
	}

	private function store_record( array $record ) {
		$records = $this->all();
		array_unshift( $records, $record );
		$this->save_all( $records );
	}
	private function save_all( array $records ) {
		update_option( self::OPTION, array_slice( array_values( $records ), 0, 20 ), false );
	}
}
