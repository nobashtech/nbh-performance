<?php
/** Validates a completed plugin update and schedules a verified rollback on regression. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Licensing\UpdateRollbackManager;
use NBH\Performance\Modules\Reliability\RuntimeReliabilityManager;
use NBH\Performance\Modules\Validation\StagingValidator;

final class PostUpdateValidationJobHandler implements JobHandlerInterface, Module {
	private $validator;
	private $reliability;
	private $rollback;

	public function __construct( StagingValidator $validator, RuntimeReliabilityManager $reliability, UpdateRollbackManager $rollback ) {
		$this->validator   = $validator;
		$this->reliability = $reliability;
		$this->rollback    = $rollback;
	}

	public function register() {
		add_action( 'nbh_performance_post_update_validation_fallback', array( $this, 'run_scheduled' ), 10, 1 );
	}

	public function supports( $type ) {
		return 'post_update_validation' === $type;
	}

	public function run_scheduled( $payload = array() ) {
		$result = $this->handle( is_array( $payload ) ? $payload : array() );
		if ( is_wp_error( $result ) ) {
			$attempt = absint( get_option( 'nbh_performance_post_update_fallback_attempt', 0 ) ) + 1;
			update_option( 'nbh_performance_post_update_fallback_attempt', $attempt, false );
			if ( $attempt < 4 && ! wp_next_scheduled( 'nbh_performance_post_update_validation_fallback', array( $payload ) ) ) {
				wp_schedule_single_event( time() + min( HOUR_IN_SECONDS, (int) pow( 2, $attempt ) * MINUTE_IN_SECONDS ), 'nbh_performance_post_update_validation_fallback', array( $payload ) );
			}
		} else {
			delete_option( 'nbh_performance_post_update_fallback_attempt' );
		}
		return $result;
	}

	public function handle( array $payload ) {
		$runtime        = $this->reliability->assess( true );
		$frontend       = $this->validator->run(
			array(
				'source' => 'post_update',
				'build'  => isset( $payload['build'] ) ? sanitize_text_field( $payload['build'] ) : '',
			)
		);
		$expected_build = isset( $payload['build'] ) ? sanitize_text_field( $payload['build'] ) : '';
		$current_build  = defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '';
		$build_matches  = '' === $expected_build || hash_equals( $expected_build, $current_build );
		if ( ! $build_matches ) {
			$frontend['passed']               = false;
			$frontend['rollback_recommended'] = true;
			$frontend['results'][]            = array(
				'url'      => '',
				'passed'   => false,
				'severity' => 'critical',
				'code'     => 'build_mismatch',
				'status'   => 0,
				'message'  => 'The loaded plugin build does not match the build installed on disk.',
				'cache'    => '',
			);
			$frontend['critical_failures']    = absint( isset( $frontend['critical_failures'] ) ? $frontend['critical_failures'] : 0 ) + 1;
		}
		$passed         = $build_matches && ! empty( $runtime['reliable'] ) && ! empty( $frontend['passed'] );
		$transport_only = $build_matches && ! empty( $runtime['reliable'] ) && ! empty( $frontend['transport_failures'] ) && empty( $frontend['rollback_recommended'] );
		$report         = array(
			'passed'             => $passed,
			'runtime'            => $runtime,
			'frontend'           => $frontend,
			'build'              => $current_build,
			'expected_build'     => $expected_build,
			'build_matches'      => $build_matches,
			'checked_at'         => time(),
			'rollback_scheduled' => false,
		);

		if ( ! $passed && ! $transport_only && get_option( 'nbh_performance_update_auto_rollback_enabled', 0 ) ) {
			$report['rollback_scheduled'] = (bool) $this->rollback->schedule_latest_restore( 'post_update_validation_failure' );
		}
		update_option( 'nbh_performance_last_update_validation', $report, false );
		if ( $transport_only ) {
			return new \WP_Error( 'nbh_performance_post_update_transport', __( 'Post-update validation could not reach the frontend. The validation will retry without rolling back.', 'nbh-performance' ) );
		}
		return $report;
	}
}
