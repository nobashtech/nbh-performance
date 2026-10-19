<?php
/** CSS/JS optimization coordinator. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use NBH\Performance\Modules\Core\Module;
final class AssetOptimizer implements Module {
	private $resolver;
	private $css;
	private $js;
	private $cache;
	private $exclusions;
	private $combiner;
	public function __construct( AssetUrlResolver $resolver, CssMinifier $css, JsMinifier $js, AssetCache $cache, OptimizationExclusions $exclusions, FileCombiner $combiner ) {
		$this->resolver   = $resolver;
		$this->css        = $css;
		$this->js         = $js;
		$this->cache      = $cache;
		$this->exclusions = $exclusions;
		$this->combiner   = $combiner;
	}
	public function register() {
		add_filter( 'style_loader_src', array( $this, 'optimize_css_url' ), 99, 2 );
		add_filter( 'script_loader_src', array( $this, 'optimize_js_url' ), 99, 2 );
		add_action( 'nbh_performance_cleanup_tick', array( $this, 'cleanup_old_assets' ) );
	}
	/** @param string $url URL. @param string $handle Handle. @return string */
	public function optimize_css_url( $url, $handle ) {
		return $this->optimize( $url, 'css', $handle );
	}
	/** @param string $url URL. @param string $handle Handle. @return string */
	public function optimize_js_url( $url, $handle ) {
		return $this->optimize( $url, 'js', $handle );
	}
	private function optimize( $url, $type, $handle ) {
		if ( false === apply_filters( 'nbh_performance_should_optimize_feature', true, $type . '_minify' ) || is_admin() || wp_doing_ajax() || get_option( 'nbh_performance_asset_emergency_rollback', 0 ) || ! get_option( 'nbh_performance_' . $type . '_minify_enabled', 0 ) || get_option( 'nbh_performance_safe_mode', 1 ) ) {
			return $url;
		}
		if ( $this->exclusions->is_excluded( $url, $type ) || false === apply_filters( 'nbh_performance_should_optimize_asset', true, $url, $type, $handle ) ) {
			return $url;
		}
		$resolved = $this->resolver->resolve( $url );
		if ( false === $resolved ) {
			return $url;
		}
		$destination = $this->cache->destination( $resolved['file'], $type );
		if ( is_file( $destination['file'] ) && filesize( $destination['file'] ) > 0 ) {
			return $destination['url'];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = \NBH\Performance\Modules\Core\Filesystem::get_contents( $resolved['file'] );
		if ( false === $source || strlen( $source ) > (int) apply_filters( 'nbh_performance_max_asset_bytes', 2097152, $type ) ) {
			return $url;
		}
		$result = 'css' === $type ? $this->css->minify( $source ) : $this->js->minify( $source );
		if ( false === $result || ! $this->cache->write( $destination['file'], $result ) ) {
			$this->record_failure( $url, $type );
			return $url;
		}
		do_action( 'nbh_performance_asset_optimized', $url, $destination['url'], $type, $handle );
		return $destination['url'];
	}
	private function record_failure( $url, $type ) {
		$failures         = (array) get_option( 'nbh_performance_asset_failures', array() );
		$key              = hash( 'sha256', $type . '|' . $url );
		$failures[ $key ] = array(
			'type' => $type,
			'url'  => esc_url_raw( $url ),
			'time' => time(),
		);
		if ( count( $failures ) > 100 ) {
			$failures = array_slice( $failures, -100, null, true );
		}
		update_option( 'nbh_performance_asset_failures', $failures, false );
	}
	public function cleanup_old_assets() {
		$root = WP_CONTENT_DIR . '/cache/nbh-performance/assets';
		if ( ! is_dir( $root ) ) {
			return;
		}
		$cutoff = time() - (int) apply_filters( 'nbh_performance_asset_cache_lifespan', 30 * DAY_IN_SECONDS );
		foreach ( array( 'css', 'js' ) as $type ) {
			$files = glob( $root . '/' . $type . '/*.' . $type );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/** Hub status for minified asset cache. @return array<string,mixed> */
	public function status() {
		$root   = WP_CONTENT_DIR . '/cache/nbh-performance/assets';
		$css    = $this->count_type( $root . '/css', 'css' );
		$js     = $this->count_type( $root . '/js', 'js' );
		$fails  = (array) get_option( 'nbh_performance_asset_failures', array() );
		$recent = 0;
		$cutoff = time() - HOUR_IN_SECONDS;
		foreach ( $fails as $failure ) {
			if ( isset( $failure['time'] ) && (int) $failure['time'] >= $cutoff ) {
				++$recent;
			}
		}
		return array(
			'css_files'          => $css['files'],
			'css_bytes'          => $css['bytes'],
			'js_files'           => $js['files'],
			'js_bytes'           => $js['bytes'],
			'failure_count'      => count( $fails ),
			'recent_failures'    => $recent,
			'emergency_rollback' => (bool) get_option( 'nbh_performance_asset_emergency_rollback', 0 ),
			'combine_mode'       => sanitize_key( (string) get_option( 'nbh_performance_combine_mode', 'off' ) ),
			'combining_ready'    => $this->combining_is_recommended(),
		);
	}

	/** Delete all cached minified CSS/JS files. @return array<string,mixed> */
	public function flush_cache() {
		$root    = WP_CONTENT_DIR . '/cache/nbh-performance/assets';
		$deleted = 0;
		foreach ( array( 'css', 'js' ) as $type ) {
			$files = glob( $root . '/' . $type . '/*.' . $type );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
					clearstatcache( true, $file );
					if ( ! is_file( $file ) ) {
						++$deleted;
					}
				}
			}
		}
		return array(
			'success' => true,
			'deleted' => $deleted,
			'status'  => $this->status(),
		);
	}

	/** @return array{files:int,bytes:int} */
	private function count_type( $directory, $extension ) {
		$files = 0;
		$bytes = 0;
		if ( ! is_dir( $directory ) ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$matches = glob( $directory . '/*.' . $extension );
		if ( ! is_array( $matches ) ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		foreach ( $matches as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			++$files;
			$bytes += (int) filesize( $file );
		}
		return array(
			'files' => $files,
			'bytes' => $bytes,
		);
	}

	/** @return bool */
	public function combining_is_recommended() {
		return $this->combiner->should_combine();
	}
}
