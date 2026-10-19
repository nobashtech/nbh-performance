<?php
/** Atomic per-URL Critical CSS storage. @package NBHPerformance */
namespace NBH\Performance\Modules\CriticalCss;

final class CriticalCssStorage {
	const MAX_BYTES = 262144;

	/** @return string */
	private function directory() {
		return WP_CONTENT_DIR . '/cache/nbh-performance/critical-css';
	}
	/** @return string */
	private function key( $url ) {
		return hash( 'sha256', strtolower( (string) $url ) );
	}
	/** @return string */
	private function path( $url, $variant = 'desktop' ) {
		return $this->directory() . '/' . $this->key( $url ) . '-' . sanitize_key( $variant ) . '.css';
	}

	/** Read validated CSS. @return string */
	public function get( $url, $variant = 'desktop' ) {
		$file = $this->path( $url, $variant );
		if ( ! is_readable( $file ) || filesize( $file ) > self::MAX_BYTES ) {
			return '';
		}
		$index = (array) get_option( 'nbh_performance_critical_css_index', array() );
		$key   = $this->key( $url ) . '-' . sanitize_key( $variant );
		$stamp = isset( $index[ $key ]['updated_at'] ) ? absint( $index[ $key ]['updated_at'] ) : 0;
		$ttl   = max( DAY_IN_SECONDS, min( 90 * DAY_IN_SECONDS, absint( get_option( 'nbh_performance_browser_css_ttl', 30 * DAY_IN_SECONDS ) ) ) );
		if ( 0 === $stamp || ( time() - $stamp ) > $ttl ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = \NBH\Performance\Modules\Core\Filesystem::get_contents( $file );
		return is_string( $css ) && $this->is_valid( $css ) ? $css : '';
	}

	/** Atomically persist generated CSS. @return bool */
	public function put( $url, $css, $context = 'generic', $variant = 'desktop', array $metadata = array() ) {
		$css = trim( (string) $css );
		if ( ! $this->is_valid( $css ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $this->directory() ) ) {
			return false;
		}
		$path = $this->path( $url, $variant );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$temp = @tempnam( dirname( $path ), '.nbh-css-' );
		if ( ! $temp ) {
			return false;
		}
		if ( ! \NBH\Performance\Modules\Core\Filesystem::put_contents( $temp, $css, 0644 ) ) {
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
		$index = (array) get_option( 'nbh_performance_critical_css_index', array() );
		$index[ $this->key( $url ) . '-' . sanitize_key( $variant ) ] = array(
			'url'         => esc_url_raw( $url ),
			'context'     => sanitize_key( $context ),
			'variant'     => sanitize_key( $variant ),
			'updated_at'  => time(),
			'bytes'       => strlen( $css ),
			'engine'      => isset( $metadata['engine'] ) ? sanitize_key( $metadata['engine'] ) : 'browser',
			'assets'      => isset( $metadata['stylesheets'] ) ? (array) $metadata['stylesheets'] : array(),
			'source_hash' => isset( $metadata['source_hash'] ) ? (string) $metadata['source_hash'] : '',
		);
		if ( count( $index ) > 2000 ) {
			uasort(
				$index,
				static function ( $a, $b ) {
					return (int) $a['updated_at'] <=> (int) $b['updated_at'];
				}
			);
			$index = array_slice( $index, -2000, null, true );
		}
		update_option( 'nbh_performance_critical_css_index', $index, false );
		return true;
	}

	/** Read generation metadata without treating static output as browser evidence. */
	public function metadata( $url, $variant = 'desktop' ) {
		$index = (array) get_option( 'nbh_performance_critical_css_index', array() );
		$key   = $this->key( $url ) . '-' . sanitize_key( $variant );
		return isset( $index[ $key ] ) && is_array( $index[ $key ] ) ? $index[ $key ] : array();
	}

	/** Delete CSS for URL. */
	public function delete( $url ) {
		foreach ( array( 'desktop', 'mobile' ) as $variant ) {
			$path = $this->path( $url, $variant );
			if ( is_file( $path ) ) {
				\NBH\Performance\Modules\Core\Filesystem::unlink( $path );
			}
		}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** Hub status for critical CSS store. @return array<string,mixed> */
	public function status() {
		$index = (array) get_option( 'nbh_performance_critical_css_index', array() );
		$bytes = 0;
		foreach ( $index as $row ) {
			$bytes += isset( $row['bytes'] ) ? absint( $row['bytes'] ) : 0;
		}
		$dir_count = 0;
		$dir       = $this->directory();
		if ( is_dir( $dir ) ) {
			$files     = glob( $dir . '/*.css' );
			$dir_count = is_array( $files ) ? count( $files ) : 0;
		}
		return array(
			'entries' => count( $index ),
			'files'   => $dir_count,
			'bytes'   => $bytes,
			'enabled' => (bool) get_option( 'nbh_performance_critical_css_enabled', 0 ),
		);
	}

	/** Clear all critical CSS files and index. @return array<string,mixed> */
	public function flush_all() {
		$dir     = $this->directory();
		$deleted = 0;
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/*.css' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( is_file( $file ) && \NBH\Performance\Modules\Core\Filesystem::unlink( $file ) ) {
						++$deleted;
					}
				}
			}
		}
		update_option( 'nbh_performance_critical_css_index', array(), false );
		return array(
			'success' => true,
			'deleted' => $deleted,
			'status'  => $this->status(),
		);
	}

	/** Conservative generated CSS validation. @return bool */
	private function is_valid( $css ) {
		$length = strlen( (string) $css );
		if ( 8 > $length || self::MAX_BYTES < $length ) {
			return false;
		}
		if ( false !== stripos( $css, '</style' ) || false !== stripos( $css, '<script' ) || false !== stripos( $css, 'javascript:' ) ) {
			return false;
		}
		return false !== strpos( $css, '{' ) && false !== strpos( $css, '}' );
	}
}
