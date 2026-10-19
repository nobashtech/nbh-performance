<?php
/** Dynamic-content edge cache exclusions. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

final class EdgeExclusionRules {
	public function is_excluded( $url = '' ) {
		$path     = strtolower( (string) wp_parse_url( $url ? $url : home_url( add_query_arg( array() ) ), PHP_URL_PATH ) );
		$patterns = array( '/wp-admin/', '/wp-login.php', '/cart/', '/checkout/', '/my-account/', '/wc-api/', '/wp-json/', '/feed/' );
		foreach ( (array) apply_filters( 'nbh_performance_edge_excluded_paths', $patterns, $url ) as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $path, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return true;
		}
		foreach ( array_keys( $_COOKIE ) as $cookie ) {
			if ( 0 === strpos( $cookie, 'wordpress_logged_in_' ) || 0 === strpos( $cookie, 'woocommerce_' ) || 'wp_woocommerce_session_' === substr( $cookie, 0, 23 ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'nbh_performance_edge_exclude_url', false, $url );
	}
}
