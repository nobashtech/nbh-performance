<?php
/** Gutenberg compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\PageBuilders;

use NBH\Performance\Modules\Core\Module;
final class GutenbergSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_asset_exclusion_patterns', array( $this, 'assets' ) );
		add_filter( 'nbh_performance_dynamic_css_safelist', array( $this, 'selectors' ) );
	}
	public function assets( $patterns ) {
		$patterns[] = '/wp-includes/js/dist/';
		return array_unique( $patterns );
	}
	public function selectors( $selectors ) {
		$selectors[] = 'wp-block-';
		$selectors[] = 'has-';
		$selectors[] = 'is-layout-';
		return array_unique( $selectors );
	}
}
