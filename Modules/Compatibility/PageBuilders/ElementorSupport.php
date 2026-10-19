<?php
/** Elementor compatibility — editor safety, asset exclusions, purge hooks. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\PageBuilders;

use NBH\Performance\Modules\Core\Module;

final class ElementorSupport implements Module {
	public function register() {
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'cacheable' ), 4, 2 );
		add_filter( 'nbh_performance_asset_exclusion_patterns', array( $this, 'assets' ) );
		add_filter( 'nbh_performance_selector_safelist', array( $this, 'selector_safelist' ) );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'protected_scripts' ) );
		add_filter( 'nbh_performance_delay_js_exclusions', array( $this, 'delay_exclusions' ) );
		add_action( 'template_redirect', array( $this, 'editor_nocache' ), 0 );
		add_action( 'elementor/core/files/clear_cache', array( $this, 'purge' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'purge' ) );
	}

	/** @param bool $allowed Whether cacheable. @param string $uri Request URI. @return bool */
	public function cacheable( $allowed, $uri ) {
		if ( ! $allowed ) {
			return false;
		}
		$uri = strtolower( (string) $uri );
		foreach ( array( 'elementor-preview=', 'elementor_library=', 'elementor_css=', 'action=elementor' ) as $marker ) {
			if ( false !== strpos( $uri, $marker ) ) {
				return false;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
			return false;
		}
		return true;
	}

	public function editor_nocache() {
		if ( ! $this->is_editor_request() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the WordPress/cache interoperability flag; a plugin-prefixed constant would not protect these requests.
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/** @param array<int,string> $patterns Exclusion patterns. @return array<int,string> */
	public function assets( $patterns ) {
		return array_values(
			array_unique(
				array_merge(
					(array) $patterns,
					array(
						'/elementor/assets/',
						'/elementor-pro/assets/',
						'/elementor/css/',
						'elementor-frontend',
						'elementor-pro-frontend',
					)
				)
			)
		);
	}

	/** @param array<int,string> $selectors CSS selectors. @return array<int,string> */
	public function selector_safelist( $selectors ) {
		return array_values(
			array_unique(
				array_merge(
					(array) $selectors,
					array(
						'.elementor-',
						'.elementor-popup-modal',
						'.elementor-widget-',
						'.elementor-section',
						'.elementor-element',
						'#elementor-popup-modal-',
					)
				)
			)
		);
	}

	/** @param array<int,string> $handles Script handles. @return array<int,string> */
	public function protected_scripts( $handles ) {
		return array_values(
			array_unique(
				array_merge(
					(array) $handles,
					array(
						'elementor-frontend',
						'elementor-pro-frontend',
						'elementor-waypoints',
						'swiper',
						'jquery-numerator',
					)
				)
			)
		);
	}

	/** @param array<int,string> $patterns Delay exclusions. @return array<int,string> */
	public function delay_exclusions( $patterns ) {
		return array_values(
			array_unique(
				array_merge(
					(array) $patterns,
					array(
						'elementor-frontend',
						'elementor-pro-frontend',
						'/elementor/assets/js/',
						'/elementor-pro/assets/js/',
					)
				)
			)
		);
	}

	public function purge() {
		do_action( 'nbh_performance_request_full_purge', 'elementor-css-regenerated' );
	}

	private function is_editor_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
			return true;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && method_exists( \Elementor\Plugin::$instance->editor, 'is_edit_mode' ) ) {
			return (bool) \Elementor\Plugin::$instance->editor->is_edit_mode();
		}
		return false;
	}
}
