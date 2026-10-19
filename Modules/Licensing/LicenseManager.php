<?php
/** Local open-source feature access. No account or license key is required. @package NBHPerformance */
namespace NBH\Performance\Modules\Licensing;

final class LicenseManager {
	public function refresh() {
		return $this->status();
	}

	/** Retains the dashboard data contract without any remote licensing calls. */
	public function status() {
		return array(
			'status'       => 'active',
			'tier'         => 'free',
			'plan'         => 'free',
			'license'      => 'GPL-2.0-or-later',
			'entitlements' => array( 'all', 'agency' ),
			'is_agency'    => true,
			'provider'     => 'local',
			'source'       => 'open-source',
			'configured'   => true,
			'expires_at'   => 0,
			'manage_url'   => '',
			'account_url'  => '',
		);
	}

	public function has_agency_access() {
		return true;
	}
}
