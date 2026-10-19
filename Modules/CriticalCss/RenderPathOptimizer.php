<?php
/** Critical CSS injection, used-CSS replacement and render-path safeguards. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

use NBH\Performance\Modules\Core\Module;

final class RenderPathOptimizer implements Module {
	/** @var CriticalCssStorage */ private $storage;
	/** @var UsedCssStorage */ private $used_storage;
	/** @var CriticalCssContext */ private $context;
	/** @var string */ private $active_css              = '';
	/** @var string */ private $active_used_css_url     = '';
	/** @var string */ private $active_used_css_version = '';
	/** @var string[] */ private $active_used_assets    = array();
	private $php_mode                                   = false;
	private $php_critical                               = false;

	public function __construct( CriticalCssStorage $storage, UsedCssStorage $used_storage, CriticalCssContext $context ) {
		$this->storage      = $storage;
		$this->used_storage = $used_storage;
		$this->context      = $context;
	}

	public function register() {
		add_action( 'wp', array( $this, 'prepare' ), 20 );
		add_action( 'wp_head', array( $this, 'inject' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_used_css' ), PHP_INT_MAX );
		add_filter( 'style_loader_tag', array( $this, 'filter_style' ), 90, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_loader' ), 999 );
		add_action( 'nbh_performance_critical_css_generated', array( $this, 'store_generated' ), 10, 4 );
		add_action( 'wp_head', array( $this, 'resource_hints' ), 3 );
	}

	public function prepare() {
		$critical_enabled = (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ) && false !== apply_filters( 'nbh_performance_should_optimize_feature', true, 'critical_css' );
		$used_enabled     = (bool) get_option( 'nbh_performance_unused_css_enabled', 0 ) && false !== apply_filters( 'nbh_performance_should_optimize_feature', true, 'unused_css' );
		if ( is_admin() || wp_doing_ajax() || get_option( 'nbh_performance_safe_mode', 1 ) || ( ! $critical_enabled && ! $used_enabled ) || is_user_logged_in() ) {
			return;
		}
		$url = $this->current_url();
		if ( is_404() || wp_parse_url( $url, PHP_URL_QUERY ) || ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) || ! ( new \NBH\Performance\Modules\CacheEngine\RequestEligibility() )->is_request_cacheable() ) {
			return;
		}

		$variant            = $this->variant();
		$this->active_css   = $critical_enabled ? $this->storage->get( $url, $variant ) : '';
		$critical_metadata  = $this->storage->metadata( $url, $variant );
		$this->php_critical = isset( $critical_metadata['engine'] ) && PhpCssGenerator::MODE === $critical_metadata['engine'];
		if ( $this->php_critical && ! $this->source_is_current( $critical_metadata['assets'] ?? array(), $critical_metadata['source_hash'] ?? '' ) ) {
			$this->active_css = ''; }
		if ( $used_enabled ) {
			$bundle = $this->used_storage->get_bundle( $url, $variant );
			if ( ! empty( $bundle['url'] ) && ! empty( $bundle['assets'] ) ) {
				$this->php_mode = isset( $bundle['engine'] ) && PhpCssGenerator::MODE === $bundle['engine'];
				if ( $this->php_mode && ! $this->source_is_current( $bundle['assets'], $bundle['source_hash'] ?? '' ) ) {
					$bundle = array(); }
			}
			if ( ! empty( $bundle['url'] ) && ! empty( $bundle['assets'] ) ) {
				$this->active_used_css_url     = (string) $bundle['url'];
				$this->active_used_css_version = isset( $bundle['version'] ) ? (string) $bundle['version'] : NBH_PERFORMANCE_VERSION;
				$this->active_used_assets      = array_map( array( $this, 'canonical_asset' ), (array) $bundle['assets'] );
				$this->active_used_assets      = array_values( array_filter( array_unique( $this->active_used_assets ) ) );
			}
		}
		if ( ( $critical_enabled && '' === $this->active_css ) || ( $used_enabled && '' === $this->active_used_css_url ) ) {
			do_action( 'nbh_performance_browser_css_missing', $url, 'missing-or-stale' );
		}
	}

	public function inject() {
		if ( '' !== $this->active_css ) {
			$css = $this->sanitize_inline_css( $this->active_css );
			if ( '' !== $css ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is context-sanitized immediately above.
				echo '<style id="nbh-performance-critical-css" data-context="' . esc_attr( $this->context->resolve() ) . '">' . $css . '</style>' . "\n";
			}
		}
	}

	/** Make stored CSS safe for direct placement inside a style element. */
	private function sanitize_inline_css( $css ) {
		$css = wp_strip_all_tags( (string) $css );
		$css = str_replace( array( "\0", '<![CDATA[', ']]>', '<!--', '-->' ), '', $css );
		$css = preg_replace( '#</style#i', '<\/style', $css );
		$css = preg_replace( '#expression\s*\(#i', 'no-expression(', $css );
		$css = preg_replace( '#javascript\s*:#i', 'blocked:', $css );
		return trim( (string) $css );
	}

	public function enqueue_used_css() {
		if ( '' !== $this->active_used_css_url && ! $this->bundle_matches_current_styles() ) {
			$this->active_used_css_url = '';
			$this->active_used_assets  = array();
			do_action( 'nbh_performance_browser_css_missing', $this->current_url(), 'stylesheet-manifest-changed' );
		}
		if ( '' !== $this->active_used_css_url && ! $this->php_mode ) {
			wp_enqueue_style( 'nbh-performance-used-css', $this->active_used_css_url, array(), $this->active_used_css_version );
		}
	}

	public function filter_style( $html, $handle, $href, $media ) {
		if ( 'nbh-performance-used-css' === $handle ) {
			return str_replace( '<link ', '<link data-nbh-performance-used-css="1" ', $html );
		}
		if ( '' !== $this->active_used_css_url && ! $this->protected_style( $handle, $href ) && in_array( $this->canonical_asset( $href ), $this->active_used_assets, true ) ) {
			if ( $this->php_mode ) {
				// Keep the stylesheet in its exact original position, with media/id attributes intact.
				$replaced = str_replace( esc_url( $href ), esc_url( $this->active_used_css_url ), $html );
				return $replaced === $html ? $html : str_replace( '<link ', '<link data-nbh-performance-used-css="1" ', $replaced );
			}
			return '<noscript data-nbh-performance-original-style="' . esc_attr( $handle ) . '">' . $html . '</noscript>';
		}
		if ( $this->php_critical || '' === $this->active_css || ! get_option( 'nbh_performance_async_css_enabled', 0 ) || $this->protected_style( $handle, $href ) ) {
			return $html;
		}
		$safe = esc_url( $href );
		return '<link rel="preload" as="style" href="' . $safe . '" data-nbh-performance-async-style="1" data-media="' . esc_attr( $media ? $media : 'all' ) . '"><noscript>' . $html . '</noscript>';
	}

	public function enqueue_loader() {
		if ( ! $this->php_critical && '' !== $this->active_css && get_option( 'nbh_performance_async_css_enabled', 0 ) && '' === $this->active_used_css_url ) {
			wp_enqueue_script( 'nbh-performance-css-loader', NBH_PERFORMANCE_URL . 'assets/js/css-loader.js', array(), NBH_PERFORMANCE_VERSION, false );
			wp_script_add_data( 'nbh-performance-css-loader', 'strategy', 'defer' );
		}
	}

	public function store_generated( $url, $css, $context, $variant = 'desktop' ) {
		if ( $this->storage->get( $url, $variant ) !== $css ) {
			$this->storage->put( $url, $css, $context, $variant );
		}
	}

	public function resource_hints() {
		if ( get_option( 'nbh_performance_safe_mode', 1 ) ) {
			return;
		}
		$lcp = esc_url( (string) apply_filters( 'nbh_performance_lcp_preload_url', '', $this->current_url() ) );
		if ( $lcp ) {
			echo '<link rel="preload" href="' . esc_url( $lcp ) . '" as="image" fetchpriority="high">' . "\n";
		}
		$fonts = array_slice( (array) apply_filters( 'nbh_performance_font_preload_urls', array(), $this->current_url() ), 0, 4 );
		foreach ( $fonts as $font ) {
			$font = esc_url( $font );
			if ( $font ) {
				echo '<link rel="preload" href="' . esc_url( $font ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
			}
		}
	}

	private function protected_style( $handle, $href ) {
		$protected = (array) apply_filters( 'nbh_performance_critical_css_protected_styles', array( 'admin-bar', 'dashicons', 'elementor-icons', 'elementor-frontend', 'wp-block-library', 'woocommerce-layout', 'woocommerce-smallscreen' ), $handle, $href );
		return in_array( $handle, $protected, true ) || false !== stripos( $href, '/uploads/elementor/css/' );
	}

	private function canonical_asset( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$path  = rawurldecode( (string) $parts['path'] );
		$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
		return $host . $path . $query;
	}

	/** Refuse a stale bundle when any generated source stylesheet has changed or disappeared. */
	private function bundle_matches_current_styles() {
		global $wp_styles;
		if ( empty( $this->active_used_assets ) || ! is_object( $wp_styles ) || empty( $wp_styles->queue ) || empty( $wp_styles->registered ) ) {
			return false;
		}
		$current = array();
		foreach ( (array) $wp_styles->queue as $handle ) {
			if ( empty( $wp_styles->registered[ $handle ] ) ) {
				continue;
			}
			$dependency = $wp_styles->registered[ $handle ];
			$src        = isset( $dependency->src ) ? (string) $dependency->src : '';
			if ( '' === $src || $this->protected_style( $handle, $src ) ) {
				continue;
			}
			if ( 0 === strpos( $src, '//' ) ) {
				$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
			} elseif ( ! preg_match( '#^https?://#i', $src ) ) {
				$base = isset( $wp_styles->base_url ) ? trailingslashit( (string) $wp_styles->base_url ) : trailingslashit( site_url() );
				$src  = $base . ltrim( $src, '/' );
			}
			$version = isset( $dependency->ver ) ? $dependency->ver : null;
			if ( false === $version && isset( $wp_styles->default_version ) ) {
				$version = $wp_styles->default_version;
			}
			if ( null !== $version && '' !== (string) $version ) {
				$src = add_query_arg( 'ver', (string) $version, $src );
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reapply the existing WordPress core style_loader_src filter to compare the exact registered stylesheet URL; this is not a new unprefixed hook.
			$src   = (string) apply_filters( 'style_loader_src', $src, $handle );
			$asset = $this->canonical_asset( $src );
			if ( $asset ) {
				$current[] = $asset;
			}
		}
		$current = array_values( array_unique( $current ) );
		return empty( array_diff( $this->active_used_assets, $current ) );
	}

	/** Static bundles are invalid as soon as their exact source bytes change. */
	private function source_is_current( array $assets, $hash ) {
		if ( 1 !== count( $assets ) || ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			return false; }
		$resolved = ( new \NBH\Performance\Modules\CssJsOptimization\AssetUrlResolver() )->resolve( reset( $assets ) );
		return $resolved && filesize( $resolved['file'] ) <= PhpCssGenerator::MAX_CSS && hash_equals( $hash, (string) hash_file( 'sha256', $resolved['file'] ) );
	}

	private function variant() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		return preg_match( '/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/', $agent ) ? 'mobile' : 'desktop';
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/';
		return esc_url_raw( $scheme . '://' . $host . $uri );
	}
}
