<?php
/** Scoped third-party dependency autoloader. @package NBHPerformance */

$nbh_performance_vendor_root = __DIR__ . '/nbh-performance/';

require_once __DIR__ . '/nbh-performance-safe-functions.php';

spl_autoload_register(
	static function ( $class ) use ( $nbh_performance_vendor_root ) {
		$prefixes = array(
			'NBH\Performance\\Vendor\\MatthiasMullie\\Minify\\'       => $nbh_performance_vendor_root . 'matthiasmullie/minify/src/',
			'NBH\Performance\\Vendor\\MatthiasMullie\\PathConverter\\' => $nbh_performance_vendor_root . 'matthiasmullie/path-converter/src/',
			'NBH\Performance\\Vendor\\Sabberworm\\CSS\\'              => $nbh_performance_vendor_root . 'sabberworm/php-css-parser/src/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$length = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $length ) ) {
				continue;
			}

			$file = $base_dir . str_replace( '\\', '/', substr( $class, $length ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
);

// Sabberworm maintains two legacy aliases through eagerly loaded compatibility files.
require_once $nbh_performance_vendor_root . 'sabberworm/php-css-parser/src/Rule/Rule.php';
require_once $nbh_performance_vendor_root . 'sabberworm/php-css-parser/src/RuleSet/RuleContainer.php';
