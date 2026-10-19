<?php
/** Yoast SEO compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Seo;

use NBH\Performance\Modules\Core\Module;
final class YoastSupport implements Module {
	public function register() {
		add_action( 'wpseo_hit_sitemap_index', array( $this, 'noop' ) );
		add_filter( 'nbh_performance_sitemap_candidates', array( $this, 'sitemap' ) );
	}
	public function sitemap( $urls ) {
		$urls[] = home_url( '/sitemap_index.xml' );
		return array_unique( $urls );
	}
	public function noop() {
	}
}
