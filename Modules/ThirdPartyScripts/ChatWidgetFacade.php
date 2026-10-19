<?php
/**
 * Consent-aware chat widget loading guard.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ThirdPartyScripts;

final class ChatWidgetFacade {
	/** @var ConsentManager */
	private $consent;

	/** @param ConsentManager $consent Consent manager. */
	public function __construct( ConsentManager $consent ) {
		$this->consent = $consent;
	}

	/**
	 * Convert known chat scripts into delayed inert scripts.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source URL.
	 * @return string
	 */
	public function filter_script_tag( $tag, $handle, $src ) {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) || ! get_option( 'nbh_performance_chat_facade_enabled', 0 ) || is_admin() || '' === $src || ! $this->is_chat_url( $src ) ) {
			return $tag;
		}
		if ( get_option( 'nbh_performance_consent_mode_enabled', 0 ) && ! $this->consent->is_granted( 'functional' ) ) {
			return $this->make_inert( $tag, 'functional' );
		}
		return $this->make_inert( $tag, 'interaction' );
	}

	/** @param string $src URL. @return bool */
	private function is_chat_url( $src ) {
		$hosts = (array) apply_filters( 'nbh_performance_chat_widget_hosts', array( 'client.crisp.chat', 'widget.intercom.io', 'js.intercomcdn.com', 'embed.tawk.to', 'static.zdassets.com' ) );
		$host  = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		return in_array( $host, $hosts, true );
	}

	/** @param string $tag Tag. @param string $reason Reason. @return string */
	private function make_inert( $tag, $reason ) {
		$tag = preg_replace( '/\s+src=("|\')(.*?)\1/i', ' data-nbh-performance-src=$1$2$1', $tag, 1 );
		$tag = preg_replace( '/\s+type=("|\')(.*?)\1/i', '', $tag, 1 );
		return str_replace( '<script', '<script type="text/plain" data-nbh-performance-delay="1" data-nbh-performance-category="' . esc_attr( $reason ) . '"', $tag );
	}
}
