<?php
/**
 * Bounded, same-site PHP CSS generation without a browser or external service.
 *
 * Static analysis cannot measure the viewport or prove visual equivalence.
 * Unknown syntax and uninspectable scripts preserve rules rather than guessing.
 * One source sheet is replaced in place, preserving the surrounding cascade.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\CriticalCss;

use NBH\Performance\Modules\Core\Filesystem;
use NBH\Performance\Modules\CssJsOptimization\AssetUrlResolver;
use NBH\Performance\Modules\CssJsOptimization\CssUrlRewriter;
use NBH\Performance\Vendor\Sabberworm\CSS\Parser;
use NBH\Performance\Vendor\Sabberworm\CSS\Settings;
use NBH\Performance\Vendor\Sabberworm\CSS\OutputFormat;
use NBH\Performance\Vendor\Sabberworm\CSS\CSSList\CSSList;
use NBH\Performance\Vendor\Sabberworm\CSS\CSSList\KeyFrame;
use NBH\Performance\Vendor\Sabberworm\CSS\RuleSet\DeclarationBlock;

final class PhpCssGenerator implements \NBH\Performance\Modules\Core\Module {
	const MODE         = 'php-conservative';
	const SOURCE_QUERY = 'nbh_css_source';
	const MAX_HTML     = 2097152;
	const MAX_CSS      = 1048576;

	/** Whether the built-in parser and HTML reader are available. */
	public static function available() {
		return class_exists( 'DOMDocument' ) && class_exists( Parser::class );
	}

	/** Authorize our anonymous source fetch; never trust a query flag alone. */
	public static function is_source_request() {
		if ( ! isset( $_SERVER['HTTP_X_NBH_CSS_SOURCE'] ) || ! is_string( $_SERVER['HTTP_X_NBH_CSS_SOURCE'] ) ) {
			return false;
		}
		$provided = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_NBH_CSS_SOURCE'] ) );
		return hash_equals( self::source_token(), $provided );
	}

	private static function source_token() {
		return hash_hmac( 'sha256', 'nbh-php-css-source', wp_salt( 'nonce' ) );
	}

	/** Mark source snapshots uncacheable and disable only our CSS transformations. */
	public function register() {
		add_action( 'init', array( $this, 'protect_source' ), 0 );
		add_filter( 'nbh_performance_should_optimize_feature', array( $this, 'source_feature' ), 0, 2 );
	}

	public function protect_source() {
		if ( self::is_source_request() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress cache interoperability flag, not a new plugin constant.
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public function source_feature( $allowed, $feature ) {
		return self::is_source_request() && in_array( $feature, array( 'critical_css', 'unused_css' ), true ) ? false : $allowed;
	}

	/** Fetch only the configured site origin; redirects, credentials and remote assets are absent. */
	public function generate( $url, $variant, array $features ) {
		if ( ! self::available() || ! $this->same_origin( $url ) ) {
			return new \WP_Error( 'nbh_performance_php_css_unavailable', __( 'PHP CSS generation requires DOM, the bundled CSS parser and a same-site URL.', 'nbh-performance' ) );
		}
		$response = wp_remote_get(
			add_query_arg( self::SOURCE_QUERY, '1', $url ),
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_HTML + 1,
				'cookies'             => array(),
				'headers'             => array(
					'X-NBH-CSS-Source' => self::source_token(),
					'Cache-Control'    => 'no-cache',
					'User-Agent'       => 'mobile' === $variant ? 'NBH Performance PHP CSS Mobile' : 'NBH Performance PHP CSS Desktop',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$html = wp_remote_retrieve_body( $response );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || strlen( $html ) > self::MAX_HTML || false === stripos( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'text/html' ) ) {
			return new \WP_Error( 'nbh_performance_php_css_source_failed', __( 'The anonymous HTML source could not be read safely. Original styles remain active.', 'nbh-performance' ) );
		}
		return $this->analyze( $url, $html, $features );
	}

	/** Build artifacts from bounded HTML and readable local CSS, never remote downloads. */
	public function analyze( $url, $html, array $features ) {
		if ( ! self::available() || ! $this->same_origin( $url ) || strlen( $html ) > self::MAX_HTML || false !== stripos( $html, '<!ENTITY' ) ) {
			return new \WP_Error( 'nbh_performance_php_css_invalid_source', __( 'The CSS source is unavailable or exceeds the PHP analysis limits.', 'nbh-performance' ) );
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$dom    = new \DOMDocument();
			$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded || $dom->getElementsByTagName( '*' )->length > 12000 || $dom->getElementsByTagName( 'base' )->length ) {
			return new \WP_Error( 'nbh_performance_php_css_complex_source', __( 'This page needs an unsupported base URL or exceeds the PHP analysis limits.', 'nbh-performance' ) );
		}
		$tokens          = $this->tokens( $dom );
		$resolver        = new AssetUrlResolver();
		$rewriter        = new CssUrlRewriter( $resolver );
		$unknown_scripts = false;
		$script_bytes    = 0;
		$scripts         = $dom->getElementsByTagName( 'script' );
		foreach ( $scripts as $script ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes this native PHP property name.
			$text = $script->textContent;
			if ( $script->hasAttribute( 'src' ) ) {
				$source = $rewriter->absolute_url( $script->getAttribute( 'src' ), $url );
				$asset  = $source ? $resolver->resolve( $source ) : false;
				if ( ! $asset || 'js' !== strtolower( pathinfo( $asset['file'], PATHINFO_EXTENSION ) ) || filesize( $asset['file'] ) > 262144 || $script_bytes > 524288 ) {
					$unknown_scripts = true;
					continue;
				}
				$text = Filesystem::get_contents( $asset['file'] );
				if ( ! is_string( $text ) ) {
					$unknown_scripts = true;
					continue;
				}
			}
			$script_bytes += strlen( $text );
			$this->add_script_tokens( $tokens, $text );
		}
		$candidates = array();
		$links      = $dom->getElementsByTagName( 'link' );
		$checked    = 0;
		foreach ( $links as $link ) {
			if ( ++$checked > 80 || count( $candidates ) >= 24 ) {
				break;
			}
			if ( 'stylesheet' !== strtolower( trim( $link->getAttribute( 'rel' ) ) ) || $link->hasAttribute( 'disabled' ) || $link->hasAttribute( 'title' ) ) {
				continue;
			}
			$media = strtolower( trim( $link->getAttribute( 'media' ) ) );
			if ( ! in_array( $media, array( '', 'all', 'screen' ), true ) ) {
				continue;
			}
			$source = $rewriter->absolute_url( $link->getAttribute( 'href' ), $url );
			$asset  = $source && $this->same_origin( $source ) ? $resolver->resolve( $source ) : false;
			if ( ! $asset || 'css' !== strtolower( pathinfo( $asset['file'], PATHINFO_EXTENSION ) ) || filesize( $asset['file'] ) > self::MAX_CSS || preg_match( '/(?:admin-bar|dashicons|elementor|wp-block-library|woocommerce-(?:layout|smallscreen))/', $source . ' ' . $link->getAttribute( 'id' ) ) ) {
				continue;
			}
			$candidates[] = array(
				'url'   => $source,
				'file'  => $asset['file'],
				'bytes' => filesize( $asset['file'] ),
				'media' => $media,
			);
		}
		if ( empty( $candidates ) ) {
			return new \WP_Error( 'nbh_performance_php_css_no_sources', __( 'No supported local stylesheet was found. Original styles remain active.', 'nbh-performance' ) );
		}
		// Replacing one sheet at its original position avoids crossing inline/protected styles.
		usort(
			$candidates,
			static function ( $left, $right ) {
				return $right['bytes'] <=> $left['bytes'];
			}
		);
		$chosen = $candidates[0];
		$css    = Filesystem::get_contents( $chosen['file'] );
		if ( ! is_string( $css ) || strlen( $css ) > self::MAX_CSS ) {
			return new \WP_Error( 'nbh_performance_php_css_read_failed', __( 'The source stylesheet could not be read.', 'nbh-performance' ) );
		}
		if ( false !== stripos( $css, '@import' ) ) {
			return new \WP_Error( 'nbh_performance_php_css_import', __( 'Imported stylesheets remain unchanged in PHP mode.', 'nbh-performance' ) ); }
		$source_hash = hash( 'sha256', $css );
		$css         = $rewriter->rewrite( $css, $chosen['url'] );
		// Imports, modern nesting and unsupported at-rules must never be silently rewritten.
		if ( false === $css || preg_match( '/@(?:import|charset|layer|container|scope|property|starting-style)|[&]/i', $css ) ) {
			return new \WP_Error( 'nbh_performance_php_css_unsupported', __( 'This stylesheet uses syntax that the conservative PHP engine leaves unchanged.', 'nbh-performance' ) );
		}
		try {
			$document = ( new Parser( $css, Settings::create()->beStrict() ) )->parse();
			$this->reduce( $document, $tokens, $unknown_scripts );
			$used = $document->render( OutputFormat::createCompact() );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'nbh_performance_php_css_parse_failed', __( 'CSS parsing failed. Original styles remain active.', 'nbh-performance' ) );
		}
		if ( strlen( $used ) < 8 || strlen( $used ) > UsedCssStorage::MAX_BYTES || preg_match( '/<|javascript\s*:|expression\s*\(/i', $used ) ) {
			return new \WP_Error( 'nbh_performance_php_css_unsafe', __( 'The generated CSS did not pass the conservative safety checks.', 'nbh-performance' ) );
		}
		// Conservative Critical CSS is the retained source sheet, not a measured viewport subset.
		// Other source sheets remain render-blocking; no unverified async CSS is published.
		$critical = 'screen' === $chosen['media'] ? '@media screen{' . $used . '}' : $used;
		if ( ! empty( $features['critical_css'] ) && strlen( $critical ) > CriticalCssStorage::MAX_BYTES ) {
			return new \WP_Error( 'nbh_performance_php_css_inline_limit', __( 'Conservative Critical CSS exceeds the inline limit. Original styles remain active.', 'nbh-performance' ) );
		}
		$result         = array(
			'engine'       => self::MODE,
			'critical_css' => ! empty( $features['critical_css'] ) ? $critical : '',
			'used_css'     => $used,
			'full_css'     => $css,
			'stylesheets'  => array( $chosen['url'] ),
			'source_hash'  => $source_hash,
			'metrics'      => array(
				'engine_version'      => 'php-conservative-1',
				'original_bytes'      => strlen( $css ),
				'used_bytes'          => strlen( $used ),
				'uninspected_scripts' => $unknown_scripts,
			),
		);
		$result['seal'] = self::seal( $result );
		return $result;
	}

	/** Static artifacts have a distinct provenance; browser visual metrics are never fabricated. */
	public static function validates_result( array $result ) {
		return isset( $result['engine'], $result['seal'] ) && self::MODE === $result['engine'] && is_string( $result['seal'] ) && hash_equals( self::seal( $result ), $result['seal'] );
	}

	private static function seal( array $result ) {
		unset( $result['seal'] );
		return hash_hmac( 'sha256', wp_json_encode( $result ), wp_salt( 'nonce' ) );
	}

	/** Collect exact, case-sensitive DOM tokens; no browser state is inferred. */
	private function tokens( \DOMDocument $dom ) {
		$out = array(
			'classes' => array(),
			'ids'     => array(),
			'tags'    => array(),
			'script'  => array(),
		);
		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement exposes this native PHP property name.
			$out['tags'][ strtolower( $node->tagName ) ] = true;
			foreach ( preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) ) as $class_name ) {
				$out['classes'][ $class_name ] = true;
			}
			$out['ids'][ $node->getAttribute( 'id' ) ] = true;
		}
		return $out;
	}

	private function add_script_tokens( array &$tokens, $text ) {
		preg_match_all( '/[a-zA-Z_][a-zA-Z0-9_-]*/', $text, $matches );
		foreach ( $matches[0] as $token ) {
			$tokens['script'][ $token ] = true;
		}
	}

	private function reduce( CSSList $items, array $tokens, $preserve_all ) {
		$safelist = new SelectorSafelist();
		foreach ( $items->getContents() as $item ) {
			if ( $item instanceof DeclarationBlock ) {
				$keep = array();
				foreach ( $item->getSelectors() as $selector ) {
					$value = (string) $selector->getSelector();
					if ( $preserve_all || $safelist->protects( $value ) || $this->possibly_used( $value, $tokens ) ) {
						$keep[] = $selector;
					}
				}
				if ( empty( $keep ) ) {
					$items->remove( $item );
				} else {
					$item->setSelectors( $keep, $items );
				}
			} elseif ( $item instanceof CSSList && ! $item instanceof KeyFrame ) {
				$this->reduce( $item, $tokens, $preserve_all );
				if ( empty( $item->getContents() ) ) {
					$items->remove( $item );
				}
			}
		}
	}

	private function possibly_used( $selector, array $tokens ) {
		// Unknown, escaped, pseudo or attribute selectors are retained in full.
		if ( ! preg_match( '/^[a-zA-Z0-9_.*#\s>+~-]+$/D', $selector ) ) {
			return true;
		}
		preg_match_all( '/([.#])([a-zA-Z_][a-zA-Z0-9_-]*)/', $selector, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$kind = '.' === $match[1] ? 'classes' : 'ids';
			if ( ! isset( $tokens[ $kind ][ $match[2] ] ) && ! isset( $tokens['script'][ $match[2] ] ) ) {
				// A class constructed from a string prefix cannot be proven unused.
				$dynamic = false;
				foreach ( $tokens['script'] as $token => $unused ) {
					if ( '-' === substr( $token, -1 ) && 0 === strpos( $match[2], $token ) ) {
						$dynamic = true;
						break;
					}
				}
				if ( ! $dynamic ) {
					return false;
				}
			}
		}
		// Element-only selectors are inexpensive and remain available for dynamic DOM.
		return true;
	}

	private function same_origin( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		return is_array( $home ) && is_array( $test ) && isset( $test['scheme'], $test['host'] )
			&& in_array( strtolower( $test['scheme'] ), array( 'http', 'https' ), true )
			&& strtolower( $home['scheme'] ) === strtolower( $test['scheme'] )
			&& strtolower( $home['host'] ) === strtolower( $test['host'] )
			&& (int) ( $home['port'] ?? 0 ) === (int) ( $test['port'] ?? 0 )
			&& empty( $test['user'] ) && empty( $test['pass'] );
	}
}
