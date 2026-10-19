<?php
/** Detects remote/offloaded attachments and prevents destructive local source operations. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class MediaOffloadGuard {
	public function local_source_available( $attachment_id ) {
		$file = get_attached_file( absint( $attachment_id ) );
		if ( $file && is_file( $file ) ) {
			return true;
		}
		return (bool) apply_filters( 'nbh_performance_remote_media_source_available', false, absint( $attachment_id ), $file );
	}
	public function detected_provider() {
		if ( defined( 'AS3CF_VERSION' ) || class_exists( '\DeliciousBrains\WP_Offload_Media\Plugin' ) ) {
			return 'wp-offload-media';
		}
		if ( defined( 'ILAB_MEDIA_CLOUD_VERSION' ) ) {
			return 'media-cloud';
		}
		return '';
	}
}
