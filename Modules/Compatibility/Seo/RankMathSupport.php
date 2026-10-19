<?php
/** Rank Math compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Seo;

use NBH\Performance\Modules\Core\Module;
final class RankMathSupport implements Module {
	public function register() {
		add_action( 'rank_math/sitemap/flush_cache', array( $this, 'purge' ) );
		add_filter( 'nbh_performance_sitemap_candidates', array( $this, 'sitemap' ) );
	}
	public function purge() {
		do_action( 'nbh_performance_request_full_purge', 'rank-math-sitemap' );
	}
	public function sitemap( $urls ) {
		$urls[] = home_url( '/sitemap_index.xml' );
		return array_unique( $urls );
	}
}
