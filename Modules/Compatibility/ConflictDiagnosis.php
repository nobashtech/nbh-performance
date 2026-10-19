<?php
/** Automated compatibility diagnosis. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

final class ConflictDiagnosis {
	private $detector;
	private $ownership;

	public function __construct( PluginDetector $detector, OwnershipRegistry $ownership ) {
		$this->detector  = $detector;
		$this->ownership = $ownership;
	}

	/** @return array<int,array<string,mixed>> */
	public function diagnose() {
		$issues = array();
		$groups = array(
			'cache'    => array(
				'wp-rocket/wp-rocket.php'             => 'WP Rocket',
				'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
				'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
				'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
				'flying-press/flying-press.php'       => 'FlyingPress',
				'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
				'breeze/breeze.php'                   => 'Breeze',
				'wp-hummingbird/wp-hummingbird.php'   => 'Hummingbird',
				'nitropack/main.php'                  => 'NitroPack',
				'nitropack/nitropack.php'             => 'NitroPack',
				'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
				'sg-cachepress/sg-cachepress.php'     => 'SiteGround Speed Optimizer',
			),
			'assets'   => array(
				'autoptimize/autoptimize.php'         => 'Autoptimize',
				'perfmatters/perfmatters.php'         => 'Perfmatters',
				'flying-press/flying-press.php'       => 'FlyingPress',
				'wp-rocket/wp-rocket.php'             => 'WP Rocket',
				'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
				'sg-cachepress/sg-cachepress.php'     => 'SiteGround Speed Optimizer',
				'jetpack-boost/jetpack-boost.php'     => 'Jetpack Boost',
				'wp-asset-clean-up/wpacu.php'         => 'Asset CleanUp',
				'breeze/breeze.php'                   => 'Breeze',
			),
			'lazyload' => array(
				'autoptimize/autoptimize.php'         => 'Autoptimize',
				'perfmatters/perfmatters.php'         => 'Perfmatters',
				'flying-press/flying-press.php'       => 'FlyingPress',
				'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
				'sg-cachepress/sg-cachepress.php'     => 'SiteGround Speed Optimizer',
				'wp-smushit/wp-smush.php'             => 'Smush',
				'wp-smush-pro/wp-smush.php'           => 'Smush Pro',
				'ewww-image-optimizer/ewww-image-optimizer.php' => 'EWWW Image Optimizer',
			),
		);

		$seen = array();
		foreach ( $groups as $group => $plugins ) {
			foreach ( $plugins as $plugin => $name ) {
				$key = $group . '|' . $plugin;
				if ( isset( $seen[ $key ] ) || ! $this->detector->active( $plugin ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$issues[]     = array(
					'id'          => sanitize_key( $group . '-' . $name ),
					'severity'    => 'warning',
					'group'       => $group,
					'plugin'      => $name,
					'recoverable' => true,
					'message'     => sprintf(
						/* translators: 1: plugin name, 2: optimization group */
						__( '%1$s overlaps with NBH Performance %2$s optimization. Resolve ownership before enabling both.', 'nbh-performance' ),
						$name,
						$group
					),
				);
			}
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$issues[] = array(
				'id'          => 'elementor-active',
				'severity'    => 'info',
				'group'       => 'page_builder',
				'plugin'      => 'Elementor',
				'recoverable' => false,
				'message'     => __( 'Elementor is active. Editor previews stay uncached and Elementor assets are excluded from aggressive optimization.', 'nbh-performance' ),
			);
		}

		if ( get_option( 'nbh_performance_dropin_conflict' ) ) {
			$issues[] = array(
				'id'          => 'advanced-cache-dropin',
				'severity'    => 'critical',
				'group'       => 'cache',
				'plugin'      => '',
				'recoverable' => true,
				'message'     => __( 'A foreign advanced-cache.php drop-in prevents safe page caching.', 'nbh-performance' ),
			);
		}
		if ( get_option( 'nbh_performance_asset_emergency_rollback' ) ) {
			$issues[] = array(
				'id'          => 'asset-health-rollback',
				'severity'    => 'critical',
				'group'       => 'assets',
				'plugin'      => '',
				'recoverable' => true,
				'message'     => __( 'Asset health monitoring triggered an emergency rollback.', 'nbh-performance' ),
			);
		}
		foreach ( $this->ownership->owners() as $feature => $owner ) {
			if ( 'nbh-performance' !== $owner ) {
				$issues[] = array(
					'id'          => 'owner-' . sanitize_key( $feature ),
					'severity'    => 'info',
					'group'       => 'ownership',
					'plugin'      => $owner,
					'recoverable' => false,
					/* translators: 1: optimization feature label, 2: owning plugin name. */
					'message'     => sprintf( __( '%1$s optimization is owned by %2$s.', 'nbh-performance' ), $feature, $owner ),
				);
			}
		}
		return (array) apply_filters( 'nbh_performance_compatibility_diagnosis', $issues );
	}
}
