<?php
/** Lightweight source-level regression checks; no WordPress installation required. */
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
define( 'ABSPATH', __DIR__ . '/../' );
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '/' ) { return 'https://example.org' . $path; }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $n ) { return json_encode( $n ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function untrailingslashit( $p ) { return rtrim( $p, '/\\' ); }
function apply_filters( $name, $value ) { return $value; }
function get_option( $name, $default = false ) { return $default; }
require_once ABSPATH . 'Modules/Core/Module.php';
require_once ABSPATH . 'Modules/Core/PatternMatcher.php';
require_once ABSPATH . 'Modules/Security/Sanitization.php';
require_once ABSPATH . 'Modules/CacheEngine/CacheKeyGenerator.php';
require_once ABSPATH . 'Modules/Licensing/UpdateRollbackManager.php';
require_once ABSPATH . 'Modules/Licensing/LicenseManager.php';
use NBH\Performance\Modules\Core\PatternMatcher;
use NBH\Performance\Modules\CacheEngine\CacheKeyGenerator;
use NBH\Performance\Modules\Licensing\UpdateRollbackManager;
use NBH\Performance\Modules\Licensing\LicenseManager;
function check( $passed, $message ) {
    if ( ! $passed ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
    echo "PASS: {$message}\n";
}
check( PatternMatcher::matches( '/cart/item/', '/cart/*' ), 'Wildcard matches a cart path' );
check( ! PatternMatcher::matches( '/checkout/', '/account/*' ), 'Unrelated paths do not match' );
check( PatternMatcher::matches( '/product/123/', 'regex:#^/product/[0-9]+/#' ), 'Explicit regexp matches' );
check( ! PatternMatcher::matches( '/anything/', 'regex:#broken[' ), 'Invalid regex fails closed' );
$key = new CacheKeyGenerator();
$one = $key->generate( 'https://example.org/shop/?b=2&a=1&utm_source=spam' );
$two = $key->generate( 'https://example.org:443/shop/?a=1&b=2' );
check( $one === $two, 'Query order, tracking params and default HTTPS port normalize' );
check( $one !== $key->generate( 'http://example.org/shop/?a=1&b=2' ), 'HTTP/HTTPS origin isolation' );
check( $one !== $key->generate( 'https://elsewhere.org/shop/?a=1&b=2' ), 'Host isolation' );
check( $one !== $key->generate( 'https://example.org/shop/?a=1&b=2', array( 'mobile' => true ) ), 'Device/variant isolation' );
$path = $key->path( '/tmp/nbh-cache', '../../outside');
check( strpos( $path, '/tmp/nbh-cache/' ) === 0 && strpos( $path, '../' ) === false, 'Cache path traversal is bounded' );
$rollback = new UpdateRollbackManager();
check( true === $rollback->snapshot_before_update_without_blocking( true, array() ), 'Updates proceed with auto rollback disabled' );
$licensing = new LicenseManager();
check( $licensing->has_agency_access() && 'local' === $licensing->status()['provider'], 'All bundled feature access remains local' );
$readme = file_get_contents( ABSPATH . 'readme.txt' );
$main = file_get_contents( ABSPATH . 'nbh-performance.php' );
check( strpos( $readme, 'Stable tag: 1.0.0' ) !== false && strpos( $main, 'Version:           1.0.0' ) !== false, 'Version/readme stable tags match' );
check( strpos( $main, "Text Domain:       nbh-performance" ) !== false, 'Text domain matches slug' );
echo "All lightweight checks passed. WordPress functional tests must still be run in a real installation.\n";
