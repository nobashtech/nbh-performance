<?php
/** CDN edge provider contract. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

interface EdgeProviderInterface {
	public function id();
	public function is_configured();
	public function purge_all();
	public function purge_urls( array $urls );
}
