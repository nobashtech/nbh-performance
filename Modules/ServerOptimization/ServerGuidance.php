<?php
/** Server-specific optimization guidance. @package NBHPerformance */
namespace NBH\Performance\Modules\ServerOptimization;

final class ServerGuidance {
	/**
	 * @param array<string, mixed> $report Capability report.
	 * @return array<int, array<string, string>>
	 */
	public function recommendations( $report ) {
		$items = array();
		if ( empty( $report['compression']['gzip']['enabled'] ) && empty( $report['compression']['brotli']['enabled'] ) ) {
			$items[] = array(
				'severity' => 'high',
				'code'     => 'compression',
				'message'  => __( 'Enable Brotli or GZIP compression at the web-server or CDN layer.', 'nbh-performance' ),
			);
		}
		if ( empty( $report['protocol']['http2'] ) && empty( $report['protocol']['http3'] ) ) {
			$items[] = array(
				'severity' => 'medium',
				'code'     => 'protocol',
				'message'  => __( 'Enable HTTP/2 or HTTP/3 before using asset combining aggressively.', 'nbh-performance' ),
			);
		}
		if ( 'constrained' === $report['hosting']['tier'] ) {
			$items[] = array(
				'severity' => 'medium',
				'code'     => 'hosting',
				'message'  => __( 'Constrained hosting detected. NBH Performance will reduce preload and background-worker concurrency.', 'nbh-performance' ),
			);
		}

		if ( ! empty( $report['environment'] ) ) {
			$environment = $report['environment'];
			if ( empty( $environment['filesystem']['content_writable'] ) ) {
				$items[] = array(
					'severity' => 'high',
					'code'     => 'filesystem',
					'message'  => __( 'WP Content is not writable. NBH Performance will avoid unsafe drop-in and file operations until permissions are corrected.', 'nbh-performance' ),
				);
			}
			if ( ! empty( $environment['managed'] ) ) {
				$items[] = array(
					'severity' => 'low',
					'code'     => 'managed_host',
					'message'  => __( 'Managed WordPress hosting detected. NBH Performance will avoid duplicating provider-level cache and server features.', 'nbh-performance' ),
				);
			}
			if ( ! empty( $environment['cron_disabled'] ) ) {
				$items[] = array(
					'severity' => 'low',
					'code'     => 'cron',
					'message'  => __( 'WP-Cron is disabled. Ensure a real server cron runs wp-cron.php for preload and maintenance jobs.', 'nbh-performance' ),
				);
			}
			if ( 'litespeed' === $environment['server_family'] || 'openlitespeed' === $environment['server_family'] ) {
				$items[] = array(
					'severity' => 'low',
					'code'     => 'litespeed',
					'message'  => __( 'LiteSpeed detected. NBH Performance will use provider-aware settings and avoid aggressive duplicate asset combining.', 'nbh-performance' ),
				);
			}
		}
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$items[] = array(
				'severity' => 'low',
				'code'     => 'php',
				'message'  => __( 'Consider a supported PHP 8.1+ release after confirming theme and plugin compatibility.', 'nbh-performance' ),
			);
		}
		return (array) apply_filters( 'nbh_performance_server_guidance', $items, $report );
	}
}
