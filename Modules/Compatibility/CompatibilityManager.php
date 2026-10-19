<?php
/** Enterprise compatibility composition root. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

use NBH\Performance\Modules\Compatibility\Ecosystem\SwiftBlocksSupport;
use NBH\Performance\Modules\Compatibility\Ecosystem\SwiftCartSupport;
use NBH\Performance\Modules\Compatibility\Ecosystem\SwiftPressSupport;
use NBH\Performance\Modules\Compatibility\Forms\ContactForm7Support;
use NBH\Performance\Modules\Compatibility\Forms\FluentFormsSupport;
use NBH\Performance\Modules\Compatibility\Forms\GravityFormsSupport;
use NBH\Performance\Modules\Compatibility\Forms\NonceFormExclusion;
use NBH\Performance\Modules\Compatibility\Forms\WPFormsSupport;
use NBH\Performance\Modules\Compatibility\PageBuilders\BeaverBuilderSupport;
use NBH\Performance\Modules\Compatibility\PageBuilders\ElementorSupport;
use NBH\Performance\Modules\Compatibility\PageBuilders\GutenbergSupport;
use NBH\Performance\Modules\Compatibility\SecurityPlugins\WordfenceSupport;
use NBH\Performance\Modules\Compatibility\Seo\RankMathSupport;
use NBH\Performance\Modules\Compatibility\Seo\YoastSupport;
use NBH\Performance\Modules\Compatibility\WooCommerce\WooCommerceCacheExclusion;
use NBH\Performance\Modules\Core\Module;

final class CompatibilityManager implements Module {
	private $ownership;
	public function __construct( OwnershipRegistry $ownership ) {
		$this->ownership = $ownership;
	}
	public function register() {
		$modules = array(
			new WooCommerceCacheExclusion(),
			new NonceFormExclusion(),
			new SwiftPressSupport(),
			new SwiftCartSupport(),
			new SwiftBlocksSupport(),
			new ElementorSupport(),
			new BeaverBuilderSupport(),
			new GutenbergSupport(),
			new RankMathSupport(),
			new YoastSupport(),
			new WPFormsSupport(),
			new GravityFormsSupport(),
			new ContactForm7Support(),
			new FluentFormsSupport(),
			new WordfenceSupport(),
		);
		foreach ( $modules as $module ) {
			$module->register();
		}
		add_filter( 'nbh_performance_should_optimize_feature', array( $this, 'owns_feature' ), 10, 2 );
		do_action( 'nbh_performance_compatibility_registered', $this->ownership->owners() );
	}
	public function owner( $feature ) {
		return $this->ownership->owner( $feature );
	}
	public function nbh_performance_owns( $feature ) {
		return $this->ownership->owns( $feature );
	}
	public function owns_feature( $allowed, $feature ) {
		return $allowed && $this->nbh_performance_owns( $feature );
	}
}
