<?php
/** SwiftCart compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Ecosystem;

use NBH\Performance\Modules\Core\Module;
final class SwiftCartSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_exclude_url', array( $this, 'exclude' ), 30, 2 );
	}
	public function exclude( $excluded, $url ) {
		return $excluded || false !== stripos( (string) $url, '/cart' ) || false !== stripos( (string) $url, '/checkout' ) || false !== stripos( (string) $url, '/my-account' );
	}
}
