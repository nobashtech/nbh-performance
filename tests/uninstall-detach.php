<?php
/** Emulates a default, retain-data uninstall using a disposable filesystem. */
error_reporting( E_ALL );
$test_root = sys_get_temp_dir() . '/nbh-uninstall-' . bin2hex( random_bytes( 8 ) );
$test_content = $test_root . '/wp-content';
mkdir( $test_content, 0700, true );
define( 'WP_UNINSTALL_PLUGIN', 'nbh-performance/nbh-performance.php' );
define( 'ABSPATH', $test_root . '/' );
define( 'WP_CONTENT_DIR', $test_content );
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['cleared'][] = $hook; return true; }
function wp_delete_file( $file ) { return unlink( $file ); }
function get_option( $name, $default = false ) { return $default; }
file_put_contents( $test_content . '/advanced-cache.php', '<?php // NBH_PERFORMANCE_DROPIN' );
file_put_contents( $test_content . '/object-cache.php', '<?php // NBH_PERFORMANCE_OBJECT_CACHE_DROPIN' );
file_put_contents( $test_content . '/nbh-performance-object-cache-config.php', '<?php return array();' );
file_put_contents( $test_content . '/retained-data.json', '{"data":"must-retain"}' );
require dirname( __DIR__ ) . '/uninstall.php';
function verify( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
    echo 'PASS: ' . $message . "\n";
}
verify( ! file_exists( $test_content . '/advanced-cache.php' ), 'Owned early cache is detached' );
verify( ! file_exists( $test_content . '/object-cache.php' ), 'Owned object cache is detached' );
verify( ! file_exists( $test_content . '/nbh-performance-object-cache-config.php' ), 'Owned credentials config removed' );
verify( is_file( $test_content . '/retained-data.json' ), 'Retain-data policy does not purge data' );
verify( ! empty( $GLOBALS['cleared'] ), 'Uninstall clears registered cron hooks' );
unlink( $test_content . '/retained-data.json' );
rmdir( $test_content );
rmdir( $test_root );
