<?php
/** Language-aware cache variants and translation lifecycle integration. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Multilingual;

use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Core\Module;
final class MultilingualSupport implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'init', array( $this, 'detect' ), 40 );
		add_filter( 'nbh_performance_cache_variant_context', array( $this, 'variant' ) );
		add_filter( 'nbh_performance_runtime_config', array( $this, 'runtime' ) );
		foreach ( array( 'wpml_translation_update', 'pll_save_post', 'trp_save_settings' ) as $hook ) {
			add_action( $hook, array( $this, 'purge' ), 20 );
		}
	}
	public function detect() {
		$items = array(
			array(
				'id'         => 'wpml',
				'name'       => 'WPML',
				'active'     => defined( 'ICL_SITEPRESS_VERSION' ),
				'version'    => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : '',
				'confidence' => 94,
			),
			array(
				'id'         => 'polylang',
				'name'       => 'Polylang',
				'active'     => defined( 'POLYLANG_VERSION' ),
				'version'    => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : '',
				'confidence' => 94,
			),
			array(
				'id'         => 'translatepress',
				'name'       => 'TranslatePress',
				'active'     => defined( 'TRP_PLUGIN_VERSION' ),
				'version'    => defined( 'TRP_PLUGIN_VERSION' ) ? TRP_PLUGIN_VERSION : '',
				'confidence' => 90,
			),
			array(
				'id'         => 'weglot',
				'name'       => 'Weglot',
				'active'     => defined( 'WEGLOT_VERSION' ),
				'version'    => defined( 'WEGLOT_VERSION' ) ? WEGLOT_VERSION : '',
				'confidence' => 84,
			),
		);
		foreach ( $items as $item ) {
			$item = array_merge(
				$item,
				array(
					'category'       => 'multilingual',
					'status'         => $item['active'] ? 'supported' : 'inactive',
					'features'       => array( 'language_cache_variant', 'translated_url_purge', 'language_cookie_bypass' ),
					'tested_through' => 'current-major',
					'last_verified'  => '2026-07-16',
				)
			);
			$this->registry->add( $item );
		}
	}
	public function variant( $context ) {
		$locale              = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$context['language'] = sanitize_key( str_replace( '_', '-', strtolower( $locale ) ) );
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$context['language'] = sanitize_key( ICL_LANGUAGE_CODE );
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );
			if ( $lang ) {
				$context['language'] = sanitize_key( $lang );
			}
		}
		return $context;
	}
	public function runtime( $config ) {
		$config['variant_cookies'] = array_values( array_unique( array_merge( isset( $config['variant_cookies'] ) ? (array) $config['variant_cookies'] : array(), array( 'wp-wpml_current_language', 'pll_language', 'trp_language', 'weglot_switcher' ) ) ) );
		return $config;
	}
	public function purge() {
		do_action( 'nbh_performance_request_full_purge', 'translation-content-changed' );
	}
}
