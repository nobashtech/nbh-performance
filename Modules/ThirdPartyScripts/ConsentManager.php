<?php
/**
 * Consent-aware third-party loading decisions.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ThirdPartyScripts;

final class ConsentManager {
	/**
	 * Determine whether a category may load.
	 *
	 * @param string $category Consent category.
	 * @return bool
	 */
	public function is_granted( $category ) {
		$category = sanitize_key( $category );
		$granted  = ! (bool) get_option( 'nbh_performance_consent_mode_enabled', 0 );

		if ( ! $granted ) {
			$cookie_map  = (array) apply_filters(
				'nbh_performance_consent_cookie_map',
				array(
					'analytics'      => 'nbh_performance_consent_analytics',
					'marketing'      => 'nbh_performance_consent_marketing',
					'functional'     => 'nbh_performance_consent_functional',
					'external_media' => 'nbh_performance_consent_external_media',
				)
			);
			$cookie_name = isset( $cookie_map[ $category ] ) ? sanitize_key( $cookie_map[ $category ] ) : '';
			$granted     = '' !== $cookie_name && isset( $_COOKIE[ $cookie_name ] ) && '1' === sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		}

		return (bool) apply_filters( 'nbh_performance_third_party_consent_granted', $granted, $category );
	}
}
