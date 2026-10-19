<?php
/**
 * Conservative Speculation Rules API integration.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\Module;

final class SpeculationRulesManager implements Module {
	/** Register hooks. */
	public function register() {
		add_action( 'wp_head', array( $this, 'print_rules' ), 2 );
	}

	/** Print same-origin speculation rules for eligible guests. */
	public function print_rules() {
		if ( ! get_option( 'nbh_performance_speculation_rules_enabled', 0 ) || get_option( 'nbh_performance_safe_mode', 1 ) ) {
			return;
		}

		if ( is_admin() || is_user_logged_in() || is_feed() || is_preview() || wp_doing_ajax() ) {
			return;
		}

		$mode = get_option( 'nbh_performance_speculation_mode', 'prefetch' );
		if ( ! in_array( $mode, array( 'prefetch', 'prerender' ), true ) ) {
			$mode = 'prefetch';
		}

		$exclusions = $this->exclusions();
		$rule       = array(
			'source'    => 'document',
			'where'     => array(
				'and' => array(
					array( 'href_matches' => '/*' ),
					array( 'not' => array( 'href_matches' => $exclusions ) ),
				),
			),
			'eagerness' => 'moderate',
		);

		$payload = array( $mode => array( $rule ) );
		$payload = apply_filters( 'nbh_performance_speculation_rules', $payload, $mode );

		if ( empty( $payload ) ) {
			return;
		}

		printf(
			"<script type=\"speculationrules\">%s</script>\n",
			wp_json_encode( $payload, JSON_UNESCAPED_SLASHES )
		);
	}

	/** @return array<int, string> */
	private function exclusions() {
		$defaults = array(
			'/wp-admin/*',
			'/wp-login.php*',
			'/*?*add-to-cart=*',
			'/*?*wc-ajax=*',
			'/cart/*',
			'/checkout/*',
			'/my-account/*',
		);
		$custom   = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_speculation_exclusions', '' ) );
		$custom   = array_values( array_filter( array_map( 'sanitize_text_field', (array) $custom ) ) );
		return array_values( array_unique( array_merge( $defaults, $custom ) ) );
	}
}
