<?php
/**
 * NBH Performance global bootstrap helpers.
 *
 * Global functions are intentionally kept to a minimum. The REST wrapper
 * provides one consistent registration point without carrying legacy routes.
 *
 * @package NBHPerformance
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nbh_performance_register_rest_route' ) ) {
	/**
	 * Register an NBH Performance REST route.
	 *
	 * @param string $namespace REST namespace.
	 * @param string $route     Route path.
	 * @param array  $args      Route arguments.
	 * @param bool   $override  Whether to override an existing route.
	 * @return bool
	 */
	function nbh_performance_register_rest_route( $rest_namespace, $route, $args = array(), $override = false ) {
		return register_rest_route( $rest_namespace, $route, $args, $override );
	}
}
