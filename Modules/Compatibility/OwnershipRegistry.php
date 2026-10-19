<?php
/** Optimization ownership precedence registry. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

final class OwnershipRegistry {
	private $detector;
	public function __construct( PluginDetector $detector ) {
		$this->detector = $detector;
	}
	/** @return array<string,string> */
	public function owners() {
		$owners = array(
			'page_cache'       => 'nbh-performance',
			'css_minify'       => 'nbh-performance',
			'js_minify'        => 'nbh-performance',
			'lazy_load'        => 'nbh-performance',
			'critical_css'     => 'nbh-performance',
			'image_conversion' => 'nbh-performance',
		);
		if ( $this->detector->active( 'swiftpress/swiftpress.php' ) ) {
			$owners['lazy_load'] = 'swiftpress';
		}
		if ( $this->detector->active( 'swiftblocks/swiftblocks.php' ) ) {
			$owners['critical_css'] = 'nbh-performance';
		}
		if ( $this->detector->active( 'litespeed-cache/litespeed-cache.php' ) ) {
			$owners['page_cache'] = 'litespeed-cache';
		}
		return (array) apply_filters( 'nbh_performance_optimization_owners', $owners );
	}
	public function owner( $feature ) {
		$owners = $this->owners();
		return isset( $owners[ $feature ] ) ? $owners[ $feature ] : 'nbh-performance';
	}
	public function owns( $feature ) {
		return 'nbh-performance' === $this->owner( $feature );
	}
}
