<?php
/** Local-only browser generator adapter. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class HeadlessGeneratorClient {
	private $limiter;
	private $safelist;
	private $jobs;

	public function __construct( GeneratorRateLimiter $limiter, SelectorSafelist $safelist, BrowserJobStore $jobs ) {
		$this->limiter  = $limiter;
		$this->safelist = $safelist;
		$this->jobs     = $jobs;
	}

	/** @return array|\WP_Error */
	public function generate( $url, $context, $variant, array $features = array() ) {
		if ( ! $this->limiter->allow( 'outbound' ) ) {
			return new \WP_Error( 'nbh_performance_rate_limited', __( 'Browser generator rate limit reached.', 'nbh-performance' ) );
		}
		$filtered = apply_filters( 'nbh_performance_local_headless_generate', null, $url, $context, $variant, $features );
		if ( is_array( $filtered ) ) {
			return $filtered;
		}

		return ( new PhpCssGenerator() )->generate( $url, $variant, $features );
	}

	public function complete( $request_id, array $result = array() ) {
		if ( ! $request_id ) {
			return false;
		}
		$hash = hash( 'sha256', (string) ( $result['critical_css'] ?? '' ) . "\n" . (string) ( $result['used_css'] ?? '' ) );
		return $this->jobs->complete( $request_id, $hash, isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array() );
	}

	public function fail( $request_id, $code, array $metrics = array() ) {
		return $request_id ? $this->jobs->fail( $request_id, $code, $metrics ) : false;
	}
}
