<?php
/** Safe bounded process runner for optional image binaries. @package NBHPerformance */
namespace NBH\Performance\Modules\ImageOptimization;

final class SafeProcessRunner {
	public function available( $binary ) {
		return function_exists( 'proc_open' ) && '' !== $this->resolve_binary( $binary );
	}
	public function run( $binary, array $arguments, $timeout = 60 ) {
		$path = $this->resolve_binary( $binary );
		if ( '' === $path || ! function_exists( 'proc_open' ) ) {
			return array(
				'ok'     => false,
				'code'   => -1,
				'stderr' => 'Processor unavailable.',
			);
		}
		$command = array_merge( array( $path ), array_map( 'strval', $arguments ) );
		$spec    = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.NoSilencedErrors.Discouraged -- Optional fixed-allowlist image processors use argv without a shell, with output/time limits; failure returns a processor error for fallback handling. Optional fixed-allowlist local image binaries run through argv with bypass_shell, bounded diagnostics and a timeout; unavailable processors are rejected.
		$process = @proc_open( $command, $spec, $pipes, null, array(), array( 'bypass_shell' => true ) );
		if ( ! is_resource( $process ) ) {
			return array(
				'ok'     => false,
				'code'   => -1,
				'stderr' => 'Unable to start processor.',
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$start     = microtime( true );
		$stdout    = '';
		$stderr    = '';
		$timed_out = false;
		while ( true ) {
			$status = proc_get_status( $process );
			$stdout = substr( $stdout . stream_get_contents( $pipes[1], 8192 ), 0, 4096 );
			$stderr = substr( $stderr . stream_get_contents( $pipes[2], 8192 ), 0, 4096 );
			if ( ! $status['running'] ) {
				break;
			}
			if ( microtime( true ) - $start > max( 5, (int) $timeout ) ) {
				proc_terminate( $process, 9 );
				$stderr   .= ' Timed out.';
				$timed_out = true;
				break;
			}
			usleep( 50000 );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $pipes[2] );
		$code = proc_close( $process );
		// PHP before 8.3 may return -1 after proc_get_status() reaps the child.
		if ( -1 === $code && isset( $status['exitcode'] ) && $status['exitcode'] >= 0 ) {
			$code = (int) $status['exitcode'];
		}
		return array(
			'ok'     => ! $timed_out && 0 === $code,
			'code'   => $code,
			'stdout' => substr( $stdout, 0, 4096 ),
			'stderr' => substr( $stderr, 0, 4096 ),
		);
	}
	private function resolve_binary( $binary ) {
		$allowed = array( 'vips', 'cwebp', 'avifenc', 'jpegoptim', 'optipng', 'pngquant', 'gifsicle' );
		if ( ! in_array( $binary, $allowed, true ) ) {
			return '';
		}
		$custom = (array) apply_filters( 'nbh_performance_image_binary_paths', array(), $binary );
		if ( isset( $custom[ $binary ] ) && is_string( $custom[ $binary ] ) && is_executable( $custom[ $binary ] ) ) {
			return $custom[ $binary ];
		}
		foreach ( array( '/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/' ) as $base ) {
			$candidate = $base . $binary;
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}
}
