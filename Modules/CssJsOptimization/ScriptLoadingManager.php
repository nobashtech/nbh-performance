<?php
/** Dependency-aware JavaScript loading strategy manager 2.0. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Core\PatternMatcher;

final class ScriptLoadingManager implements Module {
	private $compatibility;

	public function __construct( ?ScriptCompatibilityRegistry $compatibility = null ) {
		$this->compatibility = $compatibility ? $compatibility : new ScriptCompatibilityRegistry();
	}

	public function register() {
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 90, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_delay_loader' ), 999 );
	}

	public function filter_script_tag( $tag, $handle, $src ) {
		if ( ! $this->enabled() || empty( $src ) || false !== strpos( $tag, 'data-nbh-performance-delay=' ) ) {
			return $tag;
		}
		$strategy = $this->get_strategy( $handle, $src );
		if ( 'default' === $strategy || $this->is_protected_script( $handle, $src ) ) {
			return $tag;
		}
		if ( 'delay' === $strategy && $this->has_inline_dependencies( $handle ) ) {
			// Inline before/data/after code must remain ordered with its source. Defer is safe; interaction delay is not.
			$strategy = get_option( 'nbh_performance_defer_js_enabled', 0 ) ? 'defer' : 'default';
			do_action( 'nbh_performance_delay_rule_downgraded', $handle, $src, 'inline_dependencies' );
		}
		if ( 'delay' === $strategy ) {
			return $this->delay_tag( $tag, $handle );
		}
		if ( 'async' === $strategy && false === strpos( $tag, ' async' ) ) {
			return str_replace( '<script', '<script async data-nbh-performance-strategy="async"', $tag );
		}
		if ( 'defer' === $strategy && false === strpos( $tag, ' defer' ) && false === strpos( $tag, ' async' ) ) {
			return str_replace( '<script', '<script defer data-nbh-performance-strategy="defer"', $tag );
		}
		return $tag;
	}

	public function enqueue_delay_loader() {
		if ( ! $this->enabled() || ! get_option( 'nbh_performance_delay_js_enabled', 0 ) ) {
			return;
		}
		wp_enqueue_script( 'nbh-performance-delay-loader', NBH_PERFORMANCE_URL . 'assets/js/delay-loader.js', array(), NBH_PERFORMANCE_VERSION, true );
		wp_script_add_data( 'nbh-performance-delay-loader', 'strategy', 'defer' );
		wp_localize_script(
			'nbh-performance-delay-loader',
			'nbhPerformanceDelay',
			array(
				'timeout' => max( 1000, min( 30000, absint( get_option( 'nbh_performance_delay_timeout', 8000 ) ) ) ),
				'events'  => array_values( array_unique( (array) apply_filters( 'nbh_performance_delay_trigger_events', array( 'pointerdown', 'touchstart', 'keydown', 'mousemove', 'scroll' ) ) ) ),
			)
		);
	}

	private function delay_tag( $tag, $handle ) {
		$original_type = '';
		if ( preg_match( '/\s+type=("|\')(.*?)\1/i', $tag, $type_match ) ) {
			$original_type = $type_match[2];
		}
		$was_async    = 1 === preg_match( '/\sasync(?:\s|>|=)/i', $tag );
		$was_defer    = 1 === preg_match( '/\sdefer(?:\s|>|=)/i', $tag );
		$was_nomodule = 1 === preg_match( '/\snomodule(?:\s|>|=)/i', $tag );
		$tag          = preg_replace( '/\s+src=("|\')(.*?)\1/i', ' data-nbh-performance-src=$1$2$1', $tag, 1 );
		$tag          = preg_replace( '/\s+type=("|\')(.*?)\1/i', '', $tag, 1 );
		$tag          = preg_replace( '/\s+(?:async|defer|nomodule)(?:=("|\').*?\1)?/i', '', $tag );
		$metadata     = ' type="text/plain" data-nbh-performance-delay="1" data-nbh-performance-handle="' . esc_attr( $handle ) . '"';
		if ( '' !== $original_type ) {
			$metadata .= ' data-nbh-performance-original-type="' . esc_attr( $original_type ) . '"';
		}
		if ( $was_async ) {
			$metadata .= ' data-nbh-performance-original-async="1"';
		}
		if ( $was_defer ) {
			$metadata .= ' data-nbh-performance-original-defer="1"';
		}
		if ( $was_nomodule ) {
			$metadata .= ' data-nbh-performance-original-nomodule="1"';
		}
		return str_replace( '<script', '<script' . $metadata, $tag );
	}

	private function get_strategy( $handle, $src ) {
		$delay_handles = array_map( 'sanitize_key', (array) get_option( 'nbh_performance_delay_js_handles', array() ) );
		$async_handles = array_map( 'sanitize_key', (array) get_option( 'nbh_performance_async_js_handles', array() ) );
		$strategy      = 'default';
		if ( get_option( 'nbh_performance_delay_js_enabled', 0 ) && ( in_array( $handle, $delay_handles, true ) || $this->matches_delay_pattern( $src ) ) ) {
			$strategy = 'delay';
		} elseif ( in_array( $handle, $async_handles, true ) ) {
			$strategy = 'async';
		} elseif ( get_option( 'nbh_performance_defer_js_enabled', 0 ) ) {
			$strategy = 'defer';
		}
		$strategy = (string) apply_filters( 'nbh_performance_script_loading_strategy', $strategy, $handle, $src );
		return in_array( $strategy, array( 'default', 'delay', 'defer', 'async' ), true ) ? $strategy : 'default';
	}

	private function has_inline_dependencies( $handle ) {
		$scripts = wp_scripts();
		if ( ! $scripts || ! isset( $scripts->registered[ $handle ] ) ) {
			return false;
		}
		return ! empty( $scripts->get_data( $handle, 'before' ) ) || ! empty( $scripts->get_data( $handle, 'after' ) ) || ! empty( $scripts->get_data( $handle, 'data' ) );
	}

	private function is_protected_script( $handle, $src ) {
		$rules = array_merge( $this->compatibility->protected_rules(), (array) apply_filters( 'nbh_performance_protected_script_handles', array(), $handle, $src ) );
		foreach ( $rules as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' !== $rule && ( $rule === $handle || PatternMatcher::matches( $src, $rule ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function matches_delay_pattern( $src ) {
		foreach ( $this->compatibility->delay_patterns() as $pattern ) {
			if ( '' !== $pattern && PatternMatcher::matches( $src, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	private function enabled() {
		return false !== apply_filters( 'nbh_performance_should_optimize_feature', true, 'script_loading' ) && ! is_admin() && ! wp_doing_ajax() && ! get_option( 'nbh_performance_safe_mode', 1 ) && ! get_option( 'nbh_performance_asset_emergency_rollback', 0 );
	}
}
