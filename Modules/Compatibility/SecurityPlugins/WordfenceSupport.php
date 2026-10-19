<?php
/** Wordfence compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\SecurityPlugins;

use NBH\Performance\Modules\Core\Module;
final class WordfenceSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_exclude_url', array( $this, 'exclude' ), 20, 2 );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'scripts' ) );
	}
	public function exclude( $excluded, $url ) {
		return $excluded || false !== stripos( (string) $url, 'wordfence' ) || false !== stripos( (string) $url, 'wfls' );
	}
	public function scripts( $handles ) {
		$handles[] = 'wordfence-ls';
		return array_unique( $handles );
	}
}
