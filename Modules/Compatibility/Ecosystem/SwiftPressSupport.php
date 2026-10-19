<?php
/** SwiftPress ecosystem compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Ecosystem;

use NBH\Performance\Modules\Core\Module;
final class SwiftPressSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_optimization_owners', array( $this, 'owners' ), 20 );
	}
	public function owners( $owners ) {
		if ( defined( 'SWIFTPRESS_VERSION' ) ) {
			$owners['lazy_load'] = 'swiftpress';
		}
		return $owners;
	}
}
