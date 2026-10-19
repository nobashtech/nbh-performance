<?php
/** Atomic used-CSS bundle storage with source stylesheet manifest. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class UsedCssStorage {
	const MAX_BYTES = 2097152;

	private function directory() {
		return WP_CONTENT_DIR . '/cache/nbh-performance/used-css';
	}
	private function key( $url ) {
		return hash( 'sha256', strtolower( (string) $url ) );
	}
	private function base_name( $url, $variant ) {
		return $this->key( $url ) . '-' . sanitize_key( $variant );
	}
	private function css_path( $url, $variant ) {
		return $this->directory() . '/' . $this->base_name( $url, $variant ) . '.css';
	}
	private function manifest_path( $url, $variant ) {
		return $this->directory() . '/' . $this->base_name( $url, $variant ) . '.json';
	}

	/** @return bool */
	public function put( $url, $css, array $assets, $context = 'generic', $variant = 'desktop', array $metadata = array() ) {
		$css = trim( (string) $css );
		if ( ! $this->is_valid_css( $css ) ) {
			return false;
		}
		$assets = $this->sanitize_assets( $assets );
		if ( empty( $assets ) ) {
			return false;
		}

		wp_mkdir_p( $this->directory() );
		$css_path      = $this->css_path( $url, $variant );
		$manifest_path = $this->manifest_path( $url, $variant );
		if ( ! $this->atomic_write( $css_path, $css ) ) {
			return false;
		}

		$manifest = array(
			'url'         => esc_url_raw( $url ),
			'context'     => sanitize_key( $context ),
			'variant'     => sanitize_key( $variant ),
			'updated_at'  => time(),
			'bytes'       => strlen( $css ),
			'sha256'      => hash( 'sha256', $css ),
			'assets'      => $assets,
			'engine'      => isset( $metadata['engine'] ) ? sanitize_key( $metadata['engine'] ) : 'browser',
			'source_hash' => isset( $metadata['source_hash'] ) ? (string) $metadata['source_hash'] : '',
		);
		if ( ! $this->atomic_write( $manifest_path, wp_json_encode( $manifest ) ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			\NBH\Performance\Modules\Core\Filesystem::unlink( $css_path );
			return false;
		}
		return true;
	}

	/** @return array */
	public function get_bundle( $url, $variant = 'desktop' ) {
		$css_path      = $this->css_path( $url, $variant );
		$manifest_path = $this->manifest_path( $url, $variant );
		if ( ! is_readable( $css_path ) || ! is_readable( $manifest_path ) || filesize( $css_path ) > self::MAX_BYTES ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = \NBH\Performance\Modules\Core\Filesystem::get_contents( $css_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = \NBH\Performance\Modules\Core\Filesystem::get_contents( $manifest_path );
		$data = json_decode( (string) $raw, true );
		if ( ! is_string( $css ) || ! $this->is_valid_css( $css ) || ! is_array( $data ) || empty( $data['sha256'] ) || ! is_string( $data['sha256'] ) || ! hash_equals( (string) $data['sha256'], hash( 'sha256', $css ) ) ) {
			return array();
		}
		$updated_at = isset( $data['updated_at'] ) ? absint( $data['updated_at'] ) : 0;
		if ( ! $this->is_fresh( $updated_at ) ) {
			return array();
		}
		$filename = basename( $css_path );
		return array(
			'url'         => add_query_arg( 'ver', substr( $data['sha256'], 0, 16 ), content_url( '/cache/nbh-performance/used-css/' . rawurlencode( $filename ) ) ),
			'assets'      => isset( $data['assets'] ) ? $this->sanitize_assets( (array) $data['assets'] ) : array(),
			'bytes'       => strlen( $css ),
			'updated_at'  => $updated_at,
			'version'     => substr( $data['sha256'], 0, 16 ),
			'engine'      => isset( $data['engine'] ) ? (string) $data['engine'] : 'browser',
			'source_hash' => isset( $data['source_hash'] ) ? (string) $data['source_hash'] : '',
		);
	}

	public function delete( $url ) {
		foreach ( array( 'desktop', 'mobile' ) as $variant ) {
			foreach ( array( $this->css_path( $url, $variant ), $this->manifest_path( $url, $variant ) ) as $path ) {
				if ( is_file( $path ) ) {
					\NBH\Performance\Modules\Core\Filesystem::unlink( $path );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
	}

	/** Hub status for used CSS store. @return array<string,mixed> */
	public function status() {
		$dir   = $this->directory();
		$files = 0;
		$bytes = 0;
		if ( is_dir( $dir ) ) {
			$matches = glob( $dir . '/*.css' );
			if ( is_array( $matches ) ) {
				foreach ( $matches as $file ) {
					if ( ! is_file( $file ) ) {
						continue;
					}
					++$files;
					$bytes += (int) filesize( $file );
				}
			}
		}
		return array(
			'files'   => $files,
			'bytes'   => $bytes,
			'enabled' => (bool) get_option( 'nbh_performance_unused_css_enabled', 0 ),
		);
	}

	/** Clear all used CSS bundles and manifests. @return array<string,mixed> */
	public function flush_all() {
		$dir     = $this->directory();
		$deleted = 0;
		if ( is_dir( $dir ) ) {
			$matches = array_merge(
				(array) glob( $dir . '/*.css' ),
				(array) glob( $dir . '/*.json' )
			);
			foreach ( $matches as $file ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_file( $file ) && \NBH\Performance\Modules\Core\Filesystem::unlink( $file ) ) {
					++$deleted;
				}
			}
		}
		return array(
			'success' => true,
			'deleted' => $deleted,
			'status'  => $this->status(),
		);
	}

	private function sanitize_assets( array $assets ) {
		$out = array();
		foreach ( array_slice( $assets, 0, 200 ) as $asset ) {
			if ( ! is_scalar( $asset ) ) {
				continue;
			}
			$asset = esc_url_raw( html_entity_decode( (string) $asset, ENT_QUOTES, 'UTF-8' ) );
			if ( $asset ) {
				$out[] = $asset;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function is_valid_css( $css ) {
		$length = strlen( (string) $css );
		if ( 8 > $length || self::MAX_BYTES < $length ) {
			return false;
		}
		if ( false !== stripos( $css, '</style' ) || false !== stripos( $css, '<script' ) || false !== stripos( $css, 'javascript:' ) ) {
			return false;
		}
		return false !== strpos( $css, '{' ) && false !== strpos( $css, '}' );
	}

	private function is_fresh( $updated_at ) {
		$ttl = max( DAY_IN_SECONDS, min( 90 * DAY_IN_SECONDS, absint( get_option( 'nbh_performance_browser_css_ttl', 30 * DAY_IN_SECONDS ) ) ) );
		return 0 < $updated_at && ( time() - $updated_at ) <= $ttl;
	}

	private function atomic_write( $path, $contents ) {
		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$temp = @tempnam( dirname( $path ), '.nbh-css-' );
		if ( ! $temp ) {
			return false;
		}
		if ( ! \NBH\Performance\Modules\Core\Filesystem::put_contents( $temp, (string) $contents, 0644 ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, 0644 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. Atomic same-filesystem rename is required for publication; WP_Filesystem::move() can unlink/copy and is not an atomic commit. Failure is checked before accepting the new artifact.
		if ( ! @rename( $temp, $path ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}
}
