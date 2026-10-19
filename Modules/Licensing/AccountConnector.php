<?php
/** Local status adapter; no external account is used. @package NBHPerformance */
namespace NBH\Performance\Modules\Licensing;

final class AccountConnector {
	public function status() {
		return array(
			'connected' => false,
			'required'  => false,
			'provider'  => 'local',
		);
	}
}
