<?php
/**
 * Module loader.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

final class Loader {
	/** @var Module[] */
	private $modules = array();

	public function add( Module $module ) {
		$this->modules[] = $module;
	}

	public function register() {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}
}
