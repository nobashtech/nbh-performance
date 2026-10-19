<?php
/**
 * Parser-backed selector reducer used as a local unused-CSS fallback.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\CriticalCss;

use NBH\Performance\Vendor\Sabberworm\CSS\CSSList\CSSList;
use NBH\Performance\Vendor\Sabberworm\CSS\CSSList\KeyFrame;
use NBH\Performance\Vendor\Sabberworm\CSS\OutputFormat;
use NBH\Performance\Vendor\Sabberworm\CSS\Parser;
use NBH\Performance\Vendor\Sabberworm\CSS\RuleSet\DeclarationBlock;
use NBH\Performance\Vendor\Sabberworm\CSS\Settings;
use Throwable;

final class ParserBackedCssReducer {
	/** @var SelectorSafelist */
	private $safelist;

	public function __construct( SelectorSafelist $safelist ) {
		$this->safelist = $safelist;
	}

	/**
	 * Remove declaration selectors not represented in the browser coverage set.
	 *
	 * @param string   $css            Full CSS.
	 * @param string[] $used_selectors Browser-reported selectors/tokens.
	 * @return string|false
	 */
	public function reduce( $css, array $used_selectors ) {
		if ( ! $this->available() || '' === trim( (string) $css ) || empty( $used_selectors ) ) {
			return false;
		}

		try {
			$settings = Settings::create()->withLenientParsing( true );
			$document = ( new Parser( (string) $css, $settings ) )->parse();
			$used     = $this->normalize_used_selectors( $used_selectors );
			$this->filter_list( $document, $used );
			$result = $document->render( OutputFormat::createCompact() );
			return '' !== trim( $result ) ? $result : false;
		} catch ( Throwable $error ) {
			do_action( 'nbh_performance_css_parser_failure', 'unused-css-reduction', $error );
			return false;
		}
	}

	/** Validate that generated CSS parses successfully. */
	public function validates( $css ) {
		if ( ! $this->available() || '' === trim( (string) $css ) ) {
			return false;
		}

		try {
			( new Parser( (string) $css, Settings::create()->beStrict() ) )->parse();
			return true;
		} catch ( Throwable $error ) {
			do_action( 'nbh_performance_css_parser_failure', 'generated-css-validation', $error );
			return false;
		}
	}

	private function available() {
		return class_exists( '\\NBH\Performance\\Vendor\\Sabberworm\\CSS\\Parser' );
	}

	private function filter_list( CSSList $items, array $used ) {
		foreach ( $items->getContents() as $item ) {
			if ( $item instanceof DeclarationBlock ) {
				$keep = array();
				foreach ( $item->getSelectors() as $selector ) {
					$value = (string) $selector->getSelector();
					if ( $this->selector_is_used( $value, $used ) ) {
						$keep[] = $selector;
					}
				}
				if ( empty( $keep ) ) {
					$items->remove( $item );
				} else {
					$item->setSelectors( $keep, $items );
				}
				continue;
			}

			if ( $item instanceof KeyFrame ) {
				continue;
			}

			if ( $item instanceof CSSList ) {
				$this->filter_list( $item, $used );
				if ( empty( $item->getContents() ) ) {
					$items->remove( $item );
				}
			}
		}
	}

	private function selector_is_used( $selector, array $used ) {
		if ( $this->safelist->protects( $selector ) ) {
			return true;
		}

		$normalized = $this->normalize_selector( $selector );
		if ( '' === $normalized ) {
			return true;
		}

		foreach ( $used as $token ) {
			if ( $normalized === $token || false !== strpos( $normalized, $token ) || false !== strpos( $token, $normalized ) ) {
				return true;
			}
		}
		return false;
	}

	private function normalize_used_selectors( array $selectors ) {
		$normalized = array();
		foreach ( $selectors as $selector ) {
			$value = $this->normalize_selector( (string) $selector );
			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	private function normalize_selector( $selector ) {
		$selector = strtolower( trim( (string) $selector ) );
		$selector = preg_replace( '/:{1,2}[a-z-]+(?:\\([^)]*\\))?/i', '', $selector );
		$selector = preg_replace( '/\\s+/', ' ', (string) $selector );
		return trim( (string) $selector );
	}
}
