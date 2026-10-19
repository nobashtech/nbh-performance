<?php
/**
 * Capability verification guard.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Security;

final class CapabilityGuard {
	public static function can_manage() {
		return current_user_can( apply_filters( 'nbh_performance_manage_capability', 'manage_options' ) );
	}
}
