<?php
/** Machine-readable recovery runbook. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

final class RecoveryRunbook {
	public function steps() {
		return array(
			array(
				'id'     => 'safe_mode',
				'title'  => 'Activate Safe Mode',
				'action' => 'Set nbh_performance_safe_mode to 1 and clear page cache.',
				'risk'   => 'low',
			),
			array(
				'id'     => 'asset_rollback',
				'title'  => 'Rollback optimized assets',
				'action' => 'Restore the previous active asset manifest.',
				'risk'   => 'low',
			),
			array(
				'id'     => 'cache_dropin',
				'title'  => 'Disable page-cache drop-in',
				'action' => 'Disable cache through NBH Performance so ownership checks can remove only its own drop-in.',
				'risk'   => 'medium',
			),
			array(
				'id'     => 'object_cache',
				'title'  => 'Disable object cache',
				'action' => 'Disable the NBH-owned object-cache.php drop-in and verify WordPress database connectivity.',
				'risk'   => 'medium',
			),
			array(
				'id'     => 'queue',
				'title'  => 'Pause background jobs',
				'action' => 'Pause the queue before diagnosing repeated worker failures.',
				'risk'   => 'low',
			),
			array(
				'id'     => 'database',
				'title'  => 'Database recovery',
				'action' => 'Use the most recent verified SQL artifact through a controlled database recovery process.',
				'risk'   => 'high',
			),
		);
	}
}
