<?php
/** Managed-host cache ownership and purge coordination. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Hosting;

use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Core\Module;
final class HostingCompatibility implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'init', array( $this, 'detect' ), 35 );
		add_action( 'nbh_performance_after_cache_purge', array( $this, 'purge_host_cache' ), 20 );
		add_filter( 'nbh_performance_host_page_cache_detected', array( $this, 'host_cache_detected' ) );
	}
	public function detect() {
		$hosts = array(
			array(
				'id'         => 'kinsta',
				'name'       => 'Kinsta',
				'active'     => defined( 'KINSTAMU_VERSION' ) || isset( $_SERVER['KINSTA_CACHE_ZONE'] ),
				'confidence' => 92,
			),
			array(
				'id'         => 'wp-engine',
				'name'       => 'WP Engine',
				'active'     => defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon' ),
				'confidence' => 92,
			),
			array(
				'id'         => 'siteground',
				'name'       => 'SiteGround',
				'active'     => defined( 'SG_CACHEPRESS_VERSION' ) || defined( 'SITEGROUND_PLUGIN_VERSION' ),
				'confidence' => 90,
			),
			array(
				'id'         => 'cloudways',
				'name'       => 'Cloudways',
				'active'     => defined( 'VARNISH_PURGE_KEY' ) || class_exists( 'Breeze_Admin' ),
				'confidence' => 86,
			),
			array(
				'id'         => 'hostinger',
				'name'       => 'Hostinger',
				'active'     => defined( 'HOSTINGER_PLUGIN_VERSION' ) || defined( 'HOSTINGER_ABSPATH' ),
				'confidence' => 84,
			),
			array(
				'id'         => 'rocket-net',
				'name'       => 'Rocket.net',
				'active'     => defined( 'ROCKETCDN_DOMAIN' ) || class_exists( 'RocketNet\\Plugin' ),
				'confidence' => 84,
			),
			array(
				'id'         => 'pressable',
				'name'       => 'Pressable',
				'active'     => defined( 'PRESSABLE_PRODUCTION' ) || defined( 'PRESSABLE_STAGING' ),
				'confidence' => 84,
			),
			array(
				'id'         => 'pantheon',
				'name'       => 'Pantheon',
				'active'     => isset( $_ENV['PANTHEON_ENVIRONMENT'] ) || defined( 'PANTHEON_ENVIRONMENT' ),
				'confidence' => 90,
			),
			array(
				'id'         => 'wordpress-vip',
				'name'       => 'WordPress VIP',
				'active'     => defined( 'WPCOM_IS_VIP_ENV' ) || defined( 'VIP_GO_APP_ID' ),
				'confidence' => 94,
			),
			array(
				'id'         => 'litespeed-server',
				'name'       => 'LiteSpeed / OpenLiteSpeed',
				'active'     => isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( \NBH\Performance\Modules\Security\RequestInput::server_text( 'SERVER_SOFTWARE' ), 'litespeed' ),
				'confidence' => 90,
			),
		);
		foreach ( $hosts as $host ) {
			$host = array_merge(
				$host,
				array(
					'category'       => 'hosting',
					'version'        => '',
					'status'         => $host['active'] ? 'supported' : 'inactive',
					'features'       => array( 'host_cache_detection', 'coordinated_purge', 'double_cache_warning' ),
					'tested_through' => 'managed-service',
					'last_verified'  => '2026-07-16',
				)
			);
			$this->registry->add( $host );
		}
	}
	public function host_cache_detected( $detected ) {
		foreach ( $this->registry->active() as $item ) {
			if ( 'hosting' === $item['category'] ) {
				return true;
			}
		}
		return $detected;
	}
	public function purge_host_cache() {
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_memcached' ) ) {
			\WpeCommon::purge_memcached();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		do_action( 'nbh_performance_host_cache_purge_requested' );
	}
}
