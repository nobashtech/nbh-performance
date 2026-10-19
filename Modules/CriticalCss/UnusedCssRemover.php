<?php
/** Parser-backed unused CSS removal and generated-CSS validation. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class UnusedCssRemover {
	private $safelist;
	private $validator;
	private $parser;
	private $visual_guard;

	public function __construct( SelectorSafelist $safelist, CoverageValidator $validator, ParserBackedCssReducer $parser, VisualRegressionGuard $visual_guard ) {
		$this->safelist     = $safelist;
		$this->validator    = $validator;
		$this->parser       = $parser;
		$this->visual_guard = $visual_guard;
	}

	public function process( $css, array $used_selectors, array $metrics = array() ) {
		$css = (string) $css;
		if ( '' === trim( $css ) || empty( $used_selectors ) ) {
			return $css;
		}
		$result = $this->parser->reduce( $css, $used_selectors );
		if ( is_string( $result ) && $this->validator->validate( $css, $result, $metrics ) && $this->visual_guard->passes( $metrics ) ) {
			return $result;
		}
		return $css;
	}

	public function validate_generated( $original, $optimized, array $metrics = array() ) {
		$original  = (string) $original;
		$optimized = trim( (string) $optimized );
		if ( '' === $optimized || strlen( $optimized ) > UsedCssStorage::MAX_BYTES ) {
			return false;
		}
		if ( false !== stripos( $optimized, '</style' ) || false !== stripos( $optimized, '<script' ) || false !== stripos( $optimized, 'javascript:' ) ) {
			return false;
		}
		if ( ! $this->parser->validates( $optimized ) ) {
			return false;
		}
		if ( ! $this->visual_guard->passes( $metrics ) ) {
			return false;
		}
		return '' === trim( $original ) || $this->validator->validate( $original, $optimized, $metrics );
	}
}
