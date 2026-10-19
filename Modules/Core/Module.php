<?php
/**
 * Module contract.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

interface Module {
	/** Register hooks and services. */
	public function register();
}
