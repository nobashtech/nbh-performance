<?php
/** CDN, WAF and security plugin coordination. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Cdn;

use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Core\Module;
final class CdnSecurityCompatibility implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'init', array( $this, 'detect' ), 40 );
		add_filter( 'nbh_performance_runtime_config', array( $this, 'runtime' ) );
	}
	public function detect() {
		$items = array(
			array(
				'id'         => 'cloudflare',
				'name'       => 'Cloudflare',
				'active'     => defined( 'CLOUDFLARE_PLUGIN_DIR' ) || isset( $_SERVER['HTTP_CF_RAY'] ),
				'category'   => 'cdn',
				'confidence' => 94,
			),
			array(
				'id'         => 'bunnycdn',
				'name'       => 'Bunny CDN',
				'active'     => defined( 'BUNNYCDN_VERSION' ) || class_exists( 'BunnyCDN' ),
				'category'   => 'cdn',
				'confidence' => 90,
			),
			array(
				'id'         => 'sucuri',
				'name'       => 'Sucuri',
				'active'     => defined( 'SUCURI_PLUGIN_VERSION' ) || class_exists( 'SucuriScan' ),
				'category'   => 'security',
				'confidence' => 90,
			),
			array(
				'id'         => 'wordfence',
				'name'       => 'Wordfence',
				'active'     => defined( 'WORDFENCE_VERSION' ) || class_exists( 'wordfence' ),
				'category'   => 'security',
				'confidence' => 92,
			),
		);
		foreach ( $items as $item ) {
			$item = array_merge(
				$item,
				array(
					'version'        => '',
					'status'         => $item['active'] ? 'supported' : 'inactive',
					'features'       => array( 'purge_coordination', 'waf_safe_requests', 'real_ip_headers' ),
					'tested_through' => 'current-major',
					'last_verified'  => '2026-07-16',
				)
			);
			$this->registry->add( $item );
		}
	}
	public function runtime( $config ) {
		$config['trusted_proxy_headers'] = array_values( array_unique( array_merge( isset( $config['trusted_proxy_headers'] ) ? (array) $config['trusted_proxy_headers'] : array(), array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_FORWARDED_FOR' ) ) ) );
		return $config;
	}
}
