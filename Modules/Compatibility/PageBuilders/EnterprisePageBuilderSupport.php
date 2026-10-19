<?php
/** Deep page-builder editor, cache and asset compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\PageBuilders;

use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Core\Module;

final class EnterprisePageBuilderSupport implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'init', array( $this, 'detect' ), 40 );
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'cacheable' ), 3, 2 );
		add_filter( 'nbh_performance_asset_exclusion_patterns', array( $this, 'asset_patterns' ) );
		add_filter( 'nbh_performance_selector_safelist', array( $this, 'selector_safelist' ) );
		foreach ( array( 'elementor/core/files/clear_cache', 'et_core_page_resource_auto_clear', 'fl_builder_cache_cleared', 'bricks/generate_css_file', 'breakdance_cache_clear', 'oxygen_vsb_cache_cleared' ) as $hook ) {
			add_action( $hook, array( $this, 'purge' ), 20 );
		}
	}
	public function detect() {
		$plugins = array(
			array(
				'id'             => 'elementor',
				'name'           => 'Elementor',
				'active'         => defined( 'ELEMENTOR_VERSION' ),
				'version'        => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'min_version'    => '3.15',
				'tested_through' => '3.x',
				'confidence'     => 95,
				'features'       => array( 'editor_bypass', 'generated_css_purge', 'popup_safelist', 'asset_rules' ),
			),
			array(
				'id'             => 'divi',
				'name'           => 'Divi',
				'active'         => defined( 'ET_CORE_VERSION' ) || function_exists( 'et_setup_theme' ),
				'version'        => defined( 'ET_CORE_VERSION' ) ? ET_CORE_VERSION : '',
				'min_version'    => '4.20',
				'tested_through' => '5.x',
				'confidence'     => 90,
				'features'       => array( 'visual_builder_bypass', 'static_css_purge', 'asset_rules' ),
			),
			array(
				'id'             => 'bricks',
				'name'           => 'Bricks',
				'active'         => defined( 'BRICKS_VERSION' ),
				'version'        => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
				'min_version'    => '1.9',
				'tested_through' => '2.x',
				'confidence'     => 90,
				'features'       => array( 'builder_bypass', 'generated_css_purge', 'asset_rules' ),
			),
			array(
				'id'             => 'beaver-builder',
				'name'           => 'Beaver Builder',
				'active'         => defined( 'FL_BUILDER_VERSION' ),
				'version'        => defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : '',
				'min_version'    => '2.7',
				'tested_through' => '2.x',
				'confidence'     => 92,
				'features'       => array( 'editor_bypass', 'cache_purge', 'asset_rules' ),
			),
			array(
				'id'             => 'breakdance',
				'name'           => 'Breakdance',
				'active'         => defined( 'BREAKDANCE_VERSION' ),
				'version'        => defined( 'BREAKDANCE_VERSION' ) ? BREAKDANCE_VERSION : '',
				'min_version'    => '1.7',
				'tested_through' => '2.x',
				'confidence'     => 82,
				'features'       => array( 'builder_bypass', 'cache_purge', 'asset_rules' ),
			),
			array(
				'id'             => 'oxygen',
				'name'           => 'Oxygen',
				'active'         => defined( 'CT_VERSION' ),
				'version'        => defined( 'CT_VERSION' ) ? CT_VERSION : '',
				'min_version'    => '4.6',
				'tested_through' => '4.x',
				'confidence'     => 82,
				'features'       => array( 'builder_bypass', 'cache_purge', 'asset_rules' ),
			),
			array(
				'id'             => 'wpbakery',
				'name'           => 'WPBakery',
				'active'         => defined( 'WPB_VC_VERSION' ),
				'version'        => defined( 'WPB_VC_VERSION' ) ? WPB_VC_VERSION : '',
				'min_version'    => '7.0',
				'tested_through' => '8.x',
				'confidence'     => 84,
				'features'       => array( 'editor_bypass', 'asset_rules' ),
			),
			array(
				'id'             => 'gutenberg-fse',
				'name'           => 'Gutenberg / Site Editor',
				'active'         => true,
				'version'        => get_bloginfo( 'version' ),
				'min_version'    => '6.4',
				'tested_through' => '6.x',
				'confidence'     => 96,
				'features'       => array( 'site_editor_bypass', 'global_styles_purge', 'block_safelist' ),
			),
		);
		foreach ( $plugins as $plugin ) {
			$plugin['category']      = 'page_builder';
			$plugin['status']        = $plugin['active'] ? 'supported' : 'inactive';
			$plugin['last_verified'] = '2026-07-16';
			$this->registry->add( $plugin );
		}
	}
	public function cacheable( $allowed, $uri ) {
		if ( ! $allowed ) {
			return false;
		}
		$uri = strtolower( (string) $uri );
		foreach ( array( 'elementor-preview=', 'elementor_library=', 'et_fb=', 'et_bfb=', 'fl_builder', 'ct_builder=', 'breakdance=builder', 'bricks=run', 'vc_editable=true', 'context=edit' ) as $marker ) {
			if ( false !== strpos( $uri, $marker ) ) {
				return false;
			}
		}
		return true;
	}
	public function asset_patterns( $patterns ) {
		return array_values( array_unique( array_merge( (array) $patterns, array( '/elementor/assets/', '/elementor-pro/assets/', '/Divi/', '/et-core/', '/bricks/', '/bb-plugin/', '/breakdance/', '/oxygen/', '/js_composer/' ) ) ) );
	}
	public function selector_safelist( $selectors ) {
		return array_values( array_unique( array_merge( (array) $selectors, array( '.elementor-', '.elementor-popup-modal', '.et_pb_', '.brxe-', '.fl-', '.breakdance', '.oxy-', '.vc_' ) ) ) );
	}
	public function purge() {
		do_action( 'nbh_performance_request_full_purge', 'page-builder-generated-assets-changed' );
	}
}
