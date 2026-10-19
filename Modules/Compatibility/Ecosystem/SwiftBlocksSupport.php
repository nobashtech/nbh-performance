<?php
/** SwiftBlocks compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Ecosystem;

use NBH\Performance\Modules\Core\Module;
final class SwiftBlocksSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_dynamic_css_safelist', array( $this, 'selectors' ) );
	}
	public function selectors( $selectors ) {
		$selectors[] = 'swiftblocks-';
		$selectors[] = 'wp-block-swiftblocks-';
		return array_unique( $selectors );
	}
}
