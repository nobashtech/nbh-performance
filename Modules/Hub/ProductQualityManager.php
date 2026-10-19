<?php
/**
 * Product-quality status, guided recovery and optimization ownership.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Hub;

use NBH\Performance\Modules\CacheEngine\PageCacheManager;
use NBH\Performance\Modules\Compatibility\OwnershipRegistry;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Reliability\RuntimeReliabilityManager;

final class ProductQualityManager {
	const RECOVERY_OPTION = 'nbh_performance_recovery_center_state';

	/** @var PageCacheManager */
	private $page_cache;

	/** @var SafeModeManager */
	private $safe_mode;

	/** @var OwnershipRegistry */
	private $ownership;

	public function __construct( PageCacheManager $page_cache, SafeModeManager $safe_mode, OwnershipRegistry $ownership ) {
		$this->page_cache = $page_cache;
		$this->safe_mode  = $safe_mode;
		$this->ownership  = $ownership;
	}

	/** Return product-quality state used by the dashboard and support tools. */
	public function status() {
		$validation = (array) get_option( 'nbh_performance_last_staging_validation', array() );
		$recovery   = (array) get_option( self::RECOVERY_OPTION, array() );
		$safe_mode  = $this->safe_mode->status();
		$jobs       = ( new JobStore() )->stats();
		$owners     = array();

		foreach ( $this->ownership->owners() as $feature => $owner ) {
			$owners[] = array(
				'feature'      => sanitize_key( $feature ),
				'label'        => $this->feature_label( $feature ),
				'owner'        => sanitize_key( $owner ),
				'owner_label'  => $this->owner_label( $owner ),
				'managed_here' => 'nbh-performance' === $owner,
			);
		}

		return array(
			'accessibility' => array(
				'target'           => 'WCAG 2.2 AA',
				'keyboard_dialogs' => true,
				'reduced_motion'   => true,
				'focus_visible'    => true,
			),
			'localization'  => $this->localization_status(),
			'ownership'     => $owners,
			'recovery'      => array(
				'active'             => ! empty( $recovery['active'] ),
				'safe_mode'          => ! empty( $safe_mode['enabled'] ),
				'snapshot_available' => ! empty( $safe_mode['snapshot_available'] ) || (bool) get_option( 'nbh_performance_configuration_snapshot', array() ),
				'queue_paused'       => ! empty( $jobs['paused'] ),
				'runtime_degraded'   => RuntimeReliabilityManager::is_degraded(),
				'validation_passed'  => $this->validation_is_fresh( $validation, $recovery ),
				'stabilized_at'      => isset( $recovery['stabilized_at'] ) ? absint( $recovery['stabilized_at'] ) : 0,
				'resumed_at'         => isset( $recovery['resumed_at'] ) ? absint( $recovery['resumed_at'] ) : 0,
				'last_action'        => isset( $recovery['last_action'] ) ? sanitize_key( $recovery['last_action'] ) : '',
			),
			'next_action'   => $this->next_action( $safe_mode, $validation, $recovery ),
		);
	}

	/** Return bundled translation and current-locale diagnostics. */
	private function localization_status() {
		$status = array(
			'text_domain'         => 'nbh-performance',
			'locale'              => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
			'rtl'                 => is_rtl(),
			'bundled_locales'     => array(),
			'source_messages'     => 0,
			'translated_messages' => 0,
			'coverage_percent'    => 0,
			'javascript_catalogs' => 0,
		);

		$path = defined( 'NBH_PERFORMANCE_PATH' ) ? NBH_PERFORMANCE_PATH . 'languages/translation-status.json' : '';
		if ( ! $path || ! is_readable( $path ) ) {
			return $status;
		}

		$decoded = json_decode( (string) \NBH\Performance\Modules\Core\Filesystem::get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			return $status;
		}

		$status['source_messages'] = isset( $decoded['source_messages'] ) ? absint( $decoded['source_messages'] ) : 0;

		if ( isset( $decoded['locales'] ) && is_array( $decoded['locales'] ) ) {
			$current_locale = (string) $status['locale'];
			$current_base   = strtolower( strtok( str_replace( '-', '_', $current_locale ), '_' ) );
			$active         = array();

			foreach ( $decoded['locales'] as $locale_data ) {
				if ( ! is_array( $locale_data ) || empty( $locale_data['locale'] ) ) {
					continue;
				}

				$bundled_locale              = sanitize_text_field( (string) $locale_data['locale'] );
				$status['bundled_locales'][] = $bundled_locale;
				$bundled_base                = strtolower( strtok( str_replace( '-', '_', $bundled_locale ), '_' ) );

				if ( $current_locale === $bundled_locale || ( empty( $active ) && $current_base === $bundled_base ) ) {
					$active = $locale_data;
				}
			}

			$status['bundled_locales']      = array_values( array_unique( $status['bundled_locales'] ) );
			$status['bundled_locale_count'] = count( $status['bundled_locales'] );
			$status['translated_messages']  = isset( $active['translated_messages'] ) ? absint( $active['translated_messages'] ) : 0;
			$status['coverage_percent']     = isset( $active['coverage_percent'] ) ? (float) $active['coverage_percent'] : 0;
			$status['translation_tier']     = isset( $active['tier'] ) ? sanitize_key( (string) $active['tier'] ) : '';
			$status['javascript_catalogs']  = isset( $active['javascript_catalogs'] ) && is_array( $active['javascript_catalogs'] ) ? count( $active['javascript_catalogs'] ) : 0;
			return $status;
		}

		// Backward compatibility for the original single-locale metadata schema.
		$locale = isset( $decoded['locale'] ) ? sanitize_text_field( (string) $decoded['locale'] ) : '';
		if ( '' !== $locale ) {
			$status['bundled_locales'][] = $locale;
		}
		$status['bundled_locale_count'] = count( $status['bundled_locales'] );
		$status['translated_messages']  = isset( $decoded['translated_messages'] ) ? absint( $decoded['translated_messages'] ) : 0;
		$status['coverage_percent']     = isset( $decoded['coverage_percent'] ) ? (float) $decoded['coverage_percent'] : 0;
		$status['javascript_catalogs']  = isset( $decoded['javascript_catalogs'] ) && is_array( $decoded['javascript_catalogs'] ) ? count( $decoded['javascript_catalogs'] ) : 0;
		return $status;
	}

	/** Put the optimization runtime into a reversible stable state. */
	public function stabilize() {
		$existing = (array) get_option( self::RECOVERY_OPTION, array() );
		if ( empty( $existing['active'] ) ) {
			$existing['queue_was_paused']      = (bool) get_option( 'nbh_performance_job_queue_paused', 0 );
			$existing['safe_mode_was_enabled'] = (bool) get_option( 'nbh_performance_safe_mode', 1 );
		}

		$this->safe_mode->enable();
		update_option( 'nbh_performance_job_queue_paused', 1, false );
		$purged = $this->page_cache->purge_all();

		$existing['active']        = true;
		$existing['last_action']   = 'stabilize';
		$existing['stabilized_at'] = time();
		$existing['purged']        = (bool) $purged;
		update_option( self::RECOVERY_OPTION, $existing, false );
		do_action( 'nbh_performance_recovery_center_stabilized', $existing );

		return array(
			'success' => true,
			'message' => __( 'Safe Mode is active, background jobs are paused, and generated page cache has been cleared.', 'nbh-performance' ),
			'status'  => $this->status(),
		);
	}

	/** Resume the previous configuration only after a successful frontend validation. */
	public function resume() {
		$state      = (array) get_option( self::RECOVERY_OPTION, array() );
		$validation = (array) get_option( 'nbh_performance_last_staging_validation', array() );

		if ( empty( $state['active'] ) ) {
			return new \WP_Error(
				'nbh_performance_recovery_not_active',
				__( 'Recovery Mode is not active for this site.', 'nbh-performance' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->validation_is_fresh( $validation, $state ) ) {
			return new \WP_Error(
				'nbh_performance_recovery_validation_required',
				__( 'Run and pass a new frontend validation after the site was stabilized.', 'nbh-performance' ),
				array( 'status' => 409 )
			);
		}
		$restore_optimized_mode = empty( $state['safe_mode_was_enabled'] );
		if ( $restore_optimized_mode ) {
			$this->safe_mode->disable( true );
		} else {
			$this->safe_mode->enable();
		}

		if ( empty( $state['queue_was_paused'] ) ) {
			update_option( 'nbh_performance_job_queue_paused', 0, false );
		}

		$warmed = false;
		if ( $restore_optimized_mode && get_option( 'nbh_performance_cache_enabled', 0 ) && ! is_multisite() ) {
			$warmed = $this->page_cache->warm_home();
		}

		$state['active']      = false;
		$state['last_action'] = 'resume';
		$state['resumed_at']  = time();
		$state['warmed']      = (bool) $warmed;
		update_option( self::RECOVERY_OPTION, $state, false );
		do_action( 'nbh_performance_recovery_center_resumed', $state );

		$message = $restore_optimized_mode
			? __( 'The saved performance configuration has been restored and background processing is available again.', 'nbh-performance' )
			: __( 'Recovery is complete. Safe Mode remains active because it was enabled before this recovery session.', 'nbh-performance' );

		return array(
			'success' => true,
			'message' => $message,
			'status'  => $this->status(),
		);
	}

	private function next_action( array $safe_mode, array $validation, array $recovery ) {
		if ( RuntimeReliabilityManager::is_degraded() ) {
			return array(
				'id'     => 'stabilize',
				'label'  => __( 'Open Recovery Center', 'nbh-performance' ),
				'reason' => __( 'The runtime watchdog detected a critical environment problem.', 'nbh-performance' ),
			);
		}
		if ( ! empty( $recovery['active'] ) ) {
			return ! $this->validation_is_fresh( $validation, $recovery )
				? array(
					'id'     => 'validate',
					'label'  => __( 'Validate the frontend', 'nbh-performance' ),
					'reason' => __( 'Validation is required before restoring the saved configuration.', 'nbh-performance' ),
				)
				: array(
					'id'     => 'resume',
					'label'  => __( 'Resume optimized mode', 'nbh-performance' ),
					'reason' => __( 'Frontend validation passed and the saved configuration can be restored.', 'nbh-performance' ),
				);
		}
		if ( ! empty( $safe_mode['enabled'] ) ) {
			return array(
				'id'     => 'stabilize',
				'label'  => __( 'Start guided recovery', 'nbh-performance' ),
				'reason' => __( 'Safe Mode is active. Start a guided recovery session before testing or restoring features.', 'nbh-performance' ),
			);
		}
		return array(
			'id'     => 'review',
			'label'  => __( 'Review performance evidence', 'nbh-performance' ),
			'reason' => __( 'The optimization runtime is active and no recovery action is required.', 'nbh-performance' ),
		);
	}

	/** Determine whether validation evidence was produced after stabilization. */
	private function validation_is_fresh( array $validation, array $recovery ) {
		if ( empty( $validation['passed'] ) ) {
			return false;
		}

		$stabilized_at = isset( $recovery['stabilized_at'] ) ? absint( $recovery['stabilized_at'] ) : 0;
		$checked_at    = isset( $validation['checked_at'] ) ? absint( $validation['checked_at'] ) : 0;

		return $checked_at > 0 && ( 0 === $stabilized_at || $checked_at >= $stabilized_at );
	}

	private function feature_label( $feature ) {
		$labels = array(
			'page_cache'       => __( 'Page cache', 'nbh-performance' ),
			'css_minify'       => __( 'CSS minification', 'nbh-performance' ),
			'js_minify'        => __( 'JavaScript minification', 'nbh-performance' ),
			'lazy_load'        => __( 'Lazy loading', 'nbh-performance' ),
			'critical_css'     => __( 'Critical CSS', 'nbh-performance' ),
			'image_conversion' => __( 'Image conversion', 'nbh-performance' ),
		);
		return isset( $labels[ $feature ] ) ? $labels[ $feature ] : ucwords( str_replace( '_', ' ', sanitize_key( $feature ) ) );
	}

	private function owner_label( $owner ) {
		$labels = array(
			'nbh-performance' => 'NBH Performance',
			'swiftpress'      => 'SwiftPress',
			'litespeed-cache' => 'LiteSpeed Cache',
		);
		return isset( $labels[ $owner ] ) ? $labels[ $owner ] : ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $owner ) ) );
	}
}
