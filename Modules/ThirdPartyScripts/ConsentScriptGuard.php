<?php
/**
 * Prevent consent-controlled third-party scripts from executing early.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ThirdPartyScripts;

final class ConsentScriptGuard {
	/** @var ConsentManager */
	private $consent;

	/** @param ConsentManager $consent Consent manager. */
	public function __construct( ConsentManager $consent ) {
		$this->consent = $consent;
	}

	/**
	 * Make a recognized script inert until its consent category is granted.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 * @return string
	 */
	public function filter_script_tag( $tag, $handle, $src ) {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_consent_mode_enabled', 0 ) || is_admin() || '' === $src || false !== strpos( $tag, 'data-nbh-performance-delay=' ) ) {
			return $tag;
		}
		$category = $this->category_for_url( $src );
		if ( '' === $category || $this->consent->is_granted( $category ) ) {
			return $tag;
		}
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
		$tag         = preg_replace( '/\s+src=("|\')(.*?)\1/i', ' data-nbh-performance-src=$1$2$1', $tag, 1 );
		$tag         = preg_replace( '/\s+type=("|\')(.*?)\1/i', '', $tag, 1 );
		return str_replace(
			'<script',
			'<script type="text/plain" data-nbh-performance-delay="1" data-nbh-performance-category="' . esc_attr( $category ) . '" data-nbh-performance-consent-cookie="' . esc_attr( $cookie_name ) . '"',
			$tag
		);
	}

	/** @param string $src Script URL. @return string */
	private function category_for_url( $src ) {
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		$map  = (array) apply_filters(
			'nbh_performance_consent_host_categories',
			array(
				'www.googletagmanager.com' => 'analytics',
				'www.google-analytics.com' => 'analytics',
				'ssl.google-analytics.com' => 'analytics',
				'connect.facebook.net'     => 'marketing',
				'client.crisp.chat'        => 'functional',
				'widget.intercom.io'       => 'functional',
				'js.intercomcdn.com'       => 'functional',
				'embed.tawk.to'            => 'functional',
				'static.zdassets.com'      => 'functional',
			)
		);
		return isset( $map[ $host ] ) ? sanitize_key( $map[ $host ] ) : '';
	}
}
