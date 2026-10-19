<?php
/** Shared nonce-form compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Forms;

abstract class FormPluginSupport {
	abstract protected function markers();
	public function register() {
		add_filter( 'nbh_performance_exclude_url', array( $this, 'exclude' ), 20, 2 );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'scripts' ) );
	}
	public function exclude( $excluded, $url ) {
		foreach ( $this->markers() as $marker ) {
			if ( false !== stripos( (string) $url, $marker ) ) {
				return true;
			}
		}
		return $excluded;
	}
	public function scripts( $handles ) {
		return array_unique( array_merge( $handles, $this->markers() ) );
	}
}
