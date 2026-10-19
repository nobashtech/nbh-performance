<?php
/** Dependency-order-preserving CSS/JS bundle compiler. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

use NBH\Performance\Modules\Core\Module;

final class AssetBundleCompiler implements Module {
	private $resolver;
	private $cache;
	private $css;
	private $js;
	private $rewriter;
	private $exclusions;
	private $policy;
	private $manifests;
	private $lock;
	public function __construct( AssetUrlResolver $resolver, AssetCache $cache, CssMinifier $css, JsMinifier $js, CssUrlRewriter $rewriter, OptimizationExclusions $exclusions, FileCombiner $policy, AssetManifestStore $manifests, AssetCompilationLock $lock ) {
		$this->resolver   = $resolver;
		$this->cache      = $cache;
		$this->css        = $css;
		$this->js         = $js;
		$this->rewriter   = $rewriter;
		$this->exclusions = $exclusions;
		$this->policy     = $policy;
		$this->manifests  = $manifests;
		$this->lock       = $lock;
	}
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'compile_frontend_assets' ), 1000 );
		add_action( 'nbh_performance_cleanup_tick', array( $this, 'cleanup_orphans' ) );
		add_action( 'update_option_nbh_performance_combine_assets_enabled', array( $this, 'flush_manifests' ) );
		add_action( 'update_option_nbh_performance_combine_mode', array( $this, 'flush_manifests' ) );
	}
	public function compile_frontend_assets() {
		if ( ! $this->enabled() || ! $this->policy->should_combine() ) {
			return;
		}
		$this->compile_styles();
		$this->compile_scripts();
	}
	private function compile_styles() {
		$styles = wp_styles();
		if ( ! $styles ) {
			return;
		}
		$handles = $this->ordered_handles( $styles );
		$items   = array();
		foreach ( $handles as $handle ) {
			if ( empty( $styles->registered[ $handle ] ) ) {
				continue;
			}
			$obj = $styles->registered[ $handle ];
			$src = $this->normalized_src( $obj->src, $styles->base_url );
			if ( ! $this->eligible_style( $handle, $src, $styles ) ) {
				continue;
			}
			$resolved = $this->resolver->resolve( $src );
			if ( false === $resolved ) {
				continue;
			}
			$items[] = array(
				'handle' => $handle,
				'url'    => $src,
				'file'   => $resolved['file'],
				'media'  => isset( $obj->args ) ? (string) $obj->args : 'all',
			);
		}
		$this->publish_bundle( 'css', $items, $styles );
	}
	private function compile_scripts() {
		$scripts = wp_scripts();
		if ( ! $scripts ) {
			return;
		}
		$handles = $this->ordered_handles( $scripts );
		$items   = array();
		foreach ( $handles as $handle ) {
			if ( empty( $scripts->registered[ $handle ] ) ) {
				continue;
			}
			$obj = $scripts->registered[ $handle ];
			$src = $this->normalized_src( $obj->src, $scripts->base_url );
			if ( ! $this->eligible_script( $handle, $src, $scripts ) ) {
				continue;
			}
			$resolved = $this->resolver->resolve( $src );
			if ( false === $resolved ) {
				continue;
			}
			$items[] = array(
				'handle' => $handle,
				'url'    => $src,
				'file'   => $resolved['file'],
			);
		}
		$this->publish_bundle( 'js', $items, $scripts );
	}
	private function publish_bundle( $type, array $items, $registry ) {
		if ( count( $items ) < 2 ) {
			return;
		}
		$fingerprints = array();
		foreach ( $items as $item ) {
			$fingerprints[] = $item['file'] . '|' . (int) filemtime( $item['file'] ) . '|' . (int) filesize( $item['file'] );
		}
		$key         = hash( 'sha256', $type . '|' . implode( ';', $fingerprints ) . '|' . NBH_PERFORMANCE_VERSION );
		$context     = $type . '-' . substr( $key, 0, 16 );
		$destination = $this->cache->combined_destination( $key, $type );
		$lock        = $this->lock->acquire( $key );
		if ( false === $lock ) {
			return;
		}
		try {
			if ( ! is_file( $destination['file'] ) || 0 === filesize( $destination['file'] ) ) {
				$output = $this->compile_contents( $type, $items );
				if ( false === $output || '' === trim( $output ) || ! $this->cache->write( $destination['file'], $output ) ) {
					$this->record_failure( $type, $items, 'compile_or_write_failed' );
					return;
				}
			}
			$manifest = array(
				'hash'    => $key,
				'type'    => $type,
				'file'    => $destination['file'],
				'url'     => $destination['url'],
				'handles' => array_column( $items, 'handle' ),
				'bytes'   => (int) filesize( $destination['file'] ),
				'created' => time(),
			);
			$this->manifests->publish( $context, $manifest );
			$bundle_handle = 'nbh-performance-bundle-' . substr( $key, 0, 12 );
			foreach ( $items as $item ) {
				if ( 'css' === $type ) {
					wp_dequeue_style( $item['handle'] );
				} else {
					wp_dequeue_script( $item['handle'] );
				}
			}
			if ( 'css' === $type ) {
				wp_enqueue_style( $bundle_handle, $destination['url'], array(), $key, 'all' );
			} else {
				wp_enqueue_script( $bundle_handle, $destination['url'], array(), $key, true );
				wp_script_add_data( $bundle_handle, 'strategy', 'defer' );
			}
				do_action( 'nbh_performance_asset_bundle_published', $manifest );
		} finally {
			$this->lock->release( $lock );
		}
	}
	private function compile_contents( $type, array $items ) {
		$output = array();
		$max    = (int) apply_filters( 'nbh_performance_max_bundle_source_bytes', 8388608, $type );
		$total  = 0;
		foreach ( $items as $item ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$source = \NBH\Performance\Modules\Core\Filesystem::get_contents( $item['file'] );
			if ( false === $source ) {
				return false;
			}
			$total += strlen( $source );
			if ( $total > $max ) {
				return false;
			}
			if ( 'css' === $type ) {
				$source = $this->rewriter->rewrite( $source, $item['url'] );
				if ( false === $source ) {
					return false;
				}
				$source = $this->css->minify( $source );
				if ( false === $source ) {
					return false;
				}
				if ( isset( $item['media'] ) && ! in_array( strtolower( $item['media'] ), array( '', 'all' ), true ) ) {
					$source = '@media ' . $item['media'] . '{' . $source . '}';
				}
			} else {
				$source = $this->js->minify( $source );
				if ( false === $source ) {
					return false;
				}
				$source .= ";\n";
			}
			$output[] = '/* NBH:' . sanitize_key( $item['handle'] ) . ' */' . "\n" . $source;
		}
		return implode( "\n", $output );
	}
	private function ordered_handles( $registry ) {
		$registry->all_deps( $registry->queue );
		return array_values( array_unique( (array) $registry->to_do ) );
	}
	private function normalized_src( $src, $base ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}
		if ( 0 === strpos( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			return home_url( $src );
		}
		if ( ! preg_match( '#^https?://#i', $src ) ) {
			return trailingslashit( $base ) . ltrim( $src, '/' );
		}
		return $src;
	}
	private function eligible_style( $handle, $src, $styles ) {
		if ( '' === $src || $this->exclusions->is_excluded( $src, 'css' ) ) {
			return false;
		}
		if ( $styles->get_data( $handle, 'conditional' ) || $styles->get_data( $handle, 'rtl' ) || $styles->get_data( $handle, 'alt' ) ) {
			return false;
		}
		return (bool) apply_filters( 'nbh_performance_bundle_style_eligible', true, $handle, $src );
	}
	private function eligible_script( $handle, $src, $scripts ) {
		if ( '' === $src || $this->exclusions->is_excluded( $src, 'js' ) ) {
			return false;
		}
		foreach ( array( 'before', 'after', 'data', 'conditional' ) as $key ) {
			if ( $scripts->get_data( $handle, $key ) ) {
				return false;
			}
		}
		$type = (string) $scripts->get_data( $handle, 'type' );
		if ( 'module' === $type || $scripts->get_data( $handle, 'nomodule' ) ) {
			return false;
		}
		return (bool) apply_filters( 'nbh_performance_bundle_script_eligible', true, $handle, $src );
	}
	private function record_failure( $type, array $items, $reason ) {
		$failures         = (array) get_option( 'nbh_performance_asset_failures', array() );
		$key              = hash( 'sha256', $type . '|' . implode( ',', array_column( $items, 'handle' ) ) . '|' . $reason );
		$failures[ $key ] = array(
			'type'    => $type,
			'handles' => array_column( $items, 'handle' ),
			'reason'  => $reason,
			'time'    => time(),
		);
		if ( count( $failures ) > 100 ) {
			$failures = array_slice( $failures, -100, null, true );
		}
		update_option( 'nbh_performance_asset_failures', $failures, false );
	}
	public function cleanup_orphans() {
		$active = array();
		foreach ( $this->manifests->all() as $record ) {
			foreach ( array( 'active', 'previous' ) as $slot ) {
				if ( ! empty( $record[ $slot ]['file'] ) ) {
					$active[ wp_normalize_path( $record[ $slot ]['file'] ) ] = true;
				}
			}
		}
		$root = WP_CONTENT_DIR . '/cache/nbh-performance/assets';
		foreach ( array( 'css', 'js' ) as $type ) {
			$files = glob( $root . '/' . $type . '/combined-*.' . $type );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( ! isset( $active[ wp_normalize_path( $file ) ] ) && filemtime( $file ) < time() - 7 * DAY_IN_SECONDS ) {
					wp_delete_file( $file );
				}
			}
		}
	}
	public function flush_manifests() {
		$this->manifests->clear();
	}
	private function enabled() {
		return ! is_admin() && ! wp_doing_ajax() && ! get_option( 'nbh_performance_safe_mode', 1 ) && ! get_option( 'nbh_performance_asset_emergency_rollback', 0 ) && false !== apply_filters( 'nbh_performance_should_optimize_feature', true, 'asset_bundles' );
	}
}
