<?php
/** Consent platform script-order and delay safeguards. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Consent;

use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Core\Module;
final class ConsentCompatibility implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'init', array( $this, 'detect' ), 40 );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'scripts' ) );
		add_filter( 'nbh_performance_delay_compatibility_rules', array( $this, 'rules' ) );
	}
	public function detect() {
		$items = array(
			array(
				'id'         => 'complianz',
				'name'       => 'Complianz',
				'active'     => defined( 'cmplz_plugin' ),
				'confidence' => 94,
			),
			array(
				'id'         => 'cookieyes',
				'name'       => 'CookieYes',
				'active'     => defined( 'CKY_APP_URL' ) || defined( 'COOKIE_LAW_INFO_VERSION' ),
				'confidence' => 91,
			),
			array(
				'id'         => 'cookiebot',
				'name'       => 'Cookiebot',
				'active'     => defined( 'CYBOT_COOKIEBOT_VERSION' ),
				'confidence' => 91,
			),
			array(
				'id'         => 'one-trust',
				'name'       => 'OneTrust',
				'active'     => defined( 'ONETRUST_PLUGIN_VERSION' ),
				'confidence' => 86,
			),
		);
		foreach ( $items as $item ) {
			$item = array_merge(
				$item,
				array(
					'category'       => 'consent',
					'version'        => '',
					'status'         => $item['active'] ? 'supported' : 'inactive',
					'features'       => array( 'consent_order_protection', 'category_blocking_protection', 'scanner_compatibility' ),
					'tested_through' => 'current-major',
					'last_verified'  => '2026-07-16',
				)
			);
			$this->registry->add( $item );
		}
	}
	public function scripts( $handles ) {
		return array_values( array_unique( array_merge( (array) $handles, array( 'cmplz-cookiebanner', 'cmplz-cookieblocker', 'cookie-law-info', 'ckyBanner', 'cookiebot', 'one-trust-cookie' ) ) ) );
	}
	public function rules( $rules ) {
		return array_values( array_unique( array_merge( (array) $rules, array( 'complianz', 'cmplz', 'cookieyes', 'cookie-law-info', 'cookiebot', 'consent', 'onetrust' ) ) ) );
	}
}
