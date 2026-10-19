<?php
/**
 * NBH Performance fallback PSR-4 autoloader.
 *
 * Composer's generated autoloader takes precedence when available.
 *
 * @package NBHPerformance
 */

defined( 'ABSPATH' ) || exit;

$nbh_performance_scoped_vendor = dirname( __DIR__ ) . '/vendor/nbh-performance-autoload.php';
if ( is_readable( $nbh_performance_scoped_vendor ) ) {
	require_once $nbh_performance_scoped_vendor;
}

$nbh_performance_composer = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $nbh_performance_composer ) ) {
	require_once $nbh_performance_composer;
}

spl_autoload_register(
	static function ( $nbh_performance_class ) {
		$nbh_performance_prefix = 'NBH\Performance\\';
		$nbh_performance_length = strlen( $nbh_performance_prefix );

		if ( 0 !== strncmp( $nbh_performance_prefix, $nbh_performance_class, $nbh_performance_length ) ) {
			return;
		}

		$nbh_performance_relative = substr( $nbh_performance_class, $nbh_performance_length );
		$nbh_performance_file     = dirname( __DIR__ ) . '/' . str_replace( '\\', '/', $nbh_performance_relative ) . '.php';

		if ( is_readable( $nbh_performance_file ) ) {
			require_once $nbh_performance_file;
		}
	}
);
