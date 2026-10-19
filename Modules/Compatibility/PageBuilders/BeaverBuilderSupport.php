<?php
/** Beaver Builder compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\PageBuilders;

use NBH\Performance\Modules\Core\Module;
final class BeaverBuilderSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_asset_exclusion_patterns', array( $this, 'assets' ) );
		add_action( 'fl_builder_cache_cleared', array( $this, 'purge' ) );
	}
	public function assets( $patterns ) {
		$patterns[] = '/bb-plugin/';
		$patterns[] = '/bb-theme/';
		return array_unique( $patterns );
	}
	public function purge() {
		do_action( 'nbh_performance_request_full_purge', 'beaver-builder-cache-cleared' );
	}
}
