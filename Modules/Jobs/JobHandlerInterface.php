<?php
/** Background job handler contract. @package NBHPerformance */
namespace NBH\Performance\Modules\Jobs;

interface JobHandlerInterface {
	/** Return whether this handler accepts the job type. */
	public function supports( $type );

	/** Execute the job payload. Return true, an array, or WP_Error. */
	public function handle( array $payload );
}
