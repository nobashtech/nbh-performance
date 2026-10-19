<?php
/** Browser CSS generation background-job handler. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

use NBH\Performance\Modules\CriticalCss\CriticalCssQueue;

final class CriticalCssJobHandler implements JobHandlerInterface {
	private $queue;
	public function __construct( CriticalCssQueue $queue ) {
		$this->queue = $queue;
	}
	public function supports( $type ) {
		return 'critical_css_generate' === $type;
	}
	public function handle( array $payload ) {
		$url     = isset( $payload['url'] ) ? esc_url_raw( $payload['url'] ) : '';
		$variant = isset( $payload['variant'] ) ? sanitize_key( $payload['variant'] ) : 'desktop';
		$reason  = isset( $payload['reason'] ) ? sanitize_key( $payload['reason'] ) : 'background';
		return $this->queue->generate_item( $url, $variant, $reason );
	}
}
