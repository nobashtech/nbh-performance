<?php
/**
 * Safe, reversible server-rule installation and portable configuration generation.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\ServerOptimization;

use NBH\Performance\Modules\Core\Module;

final class ServerRuleManager implements Module {
	const BEGIN_MARKER = '# BEGIN NBH Performance';
	const END_MARKER   = '# END NBH Performance';
	const STATUS_KEY   = 'nbh_performance_server_rules_status';

	/** @var HostingEnvironmentDetector */
	private $environment;

	public function __construct( HostingEnvironmentDetector $environment ) {
		$this->environment = $environment;
	}

	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_reconcile' ), 30 );
		add_action( 'nbh_performance_settings_schema_upgraded', array( $this, 'schedule_reconcile' ) );
		add_action( 'nbh_performance_upgraded', array( $this, 'schedule_reconcile' ) );
		foreach ( array( 'nbh_performance_cache_enabled', 'nbh_performance_nextgen_image_serving_enabled', 'nbh_performance_edge_cache_headers_enabled', 'nbh_performance_edge_cache_ttl', 'nbh_performance_server_rules_enabled', 'nbh_performance_browser_cache_rules_enabled', 'nbh_performance_compression_rules_enabled', 'nbh_performance_static_cache_max_age' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'schedule_reconcile' ), 20, 2 );
		}
		add_action( 'nbh_performance_server_rules_reconcile', array( $this, 'reconcile' ) );
		add_filter( 'debug_information', array( $this, 'site_health_info' ) );
	}

	/** @param mixed $old_value Previous value. @param mixed $new_value New value. */
	public function schedule_reconcile( $old_value = null, $new_value = null ) {
		if ( func_num_args() >= 2 && $old_value === $new_value ) {
			return;
		}
		delete_transient( 'nbh_performance_server_rules_checked' );
		if ( ! wp_next_scheduled( 'nbh_performance_server_rules_reconcile' ) ) {
			wp_schedule_single_event( time() + 5, 'nbh_performance_server_rules_reconcile' );
		}
	}

	public function maybe_reconcile() {
		if ( ! current_user_can( 'manage_options' ) || get_transient( 'nbh_performance_server_rules_checked' ) ) {
			return;
		}
		set_transient( 'nbh_performance_server_rules_checked', 1, 6 * HOUR_IN_SECONDS );
		$this->reconcile();
	}

	/** @return array<string,mixed> */
	public function reconcile() {
		$status = array(
			'checked_at' => time(),
			'server'     => $this->server_family(),
			'installed'  => false,
			'managed'    => false,
			'mode'       => 'snippet_only',
			'message'    => '',
			'backup'     => '',
			'files'      => array(),
		);

		$this->generate_portable_configs( $status );
		$this->protect_runtime_directories( $status );

		$enabled           = (bool) get_option( 'nbh_performance_server_rules_enabled', 0 );
		$status['enabled'] = $enabled;
		if ( in_array( $status['server'], array( 'apache', 'litespeed', 'openlitespeed' ), true ) ) {
			$status = $enabled ? $this->install_htaccess_rules( $status ) : $this->remove_htaccess_rules( $status );
		} elseif ( $enabled ) {
			$status['message'] = __( 'Reviewed server snippets were generated. NBH Performance did not edit the active Nginx or IIS configuration automatically.', 'nbh-performance' );
		} else {
			$status['message'] = __( 'Automatic server rules are disabled. Generated snippets remain available for review.', 'nbh-performance' );
		}

		update_option( self::STATUS_KEY, $status, false );
		return $status;
	}

	/** @param array<string,mixed> $status Status. @return array<string,mixed> */
	private function install_htaccess_rules( $status ) {
		$path                        = trailingslashit( ABSPATH ) . '.htaccess';
		$status['files']['htaccess'] = $path;

		if ( file_exists( $path ) && ( ! is_readable( $path ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $path ) ) ) {
			$status['mode']    = 'read_only';
			$status['message'] = __( 'The root .htaccess file is not safely writable. Existing rules were left untouched and a manual snippet was generated.', 'nbh-performance' );
			return $status;
		}
		if ( ! file_exists( $path ) && ! \NBH\Performance\Modules\Core\Filesystem::is_writable( dirname( $path ) ) ) {
			$status['mode']    = 'read_only';
			$status['message'] = __( 'The WordPress root is not writable. Existing server configuration was left untouched.', 'nbh-performance' );
			return $status;
		}

		$before = file_exists( $path ) ? \NBH\Performance\Modules\Core\Filesystem::get_contents( $path ) : '';
		if ( false === $before ) {
			$status['mode']    = 'read_failed';
			$status['message'] = __( 'NBH Performance could not read .htaccess safely and made no changes.', 'nbh-performance' );
			return $status;
		}
		if ( ! $this->markers_are_valid( $before ) ) {
			$status['mode']    = 'marker_conflict';
			$status['message'] = __( 'An incomplete NBH Performance marker block was detected. No server file was changed.', 'nbh-performance' );
			return $status;
		}

		$eol     = false !== strpos( $before, "\r\n" ) ? "\r\n" : "\n";
		$block   = self::BEGIN_MARKER . $eol . implode( $eol, $this->apache_rules() ) . $eol . self::END_MARKER;
		$updated = $this->replace_managed_block( $before, $block, $eol );
		if ( $updated === $before ) {
			$status['installed'] = true;
			$status['managed']   = true;
			$status['mode']      = 'current';
			$status['message']   = __( 'NBH Performance server rules are already current.', 'nbh-performance' );
			return $status;
		}

		$backup = '';
		if ( '' !== $before ) {
			$backup = $this->backup_file( $before );
			if ( '' === $backup ) {
				$status['mode']    = 'backup_failed';
				$status['message'] = __( 'A verified backup could not be created, so .htaccess was not changed.', 'nbh-performance' );
				return $status;
			}
		}

		if ( ! $this->atomic_write( $path, $updated, file_exists( $path ) ? ( fileperms( $path ) & 0777 ) : 0644 ) ) {
			$status['mode']    = 'write_failed';
			$status['backup']  = $backup;
			$status['message'] = __( 'Atomic .htaccess replacement failed. The previous configuration remains active.', 'nbh-performance' );
			return $status;
		}

		$after = \NBH\Performance\Modules\Core\Filesystem::get_contents( $path );
		if ( false === $after || false === strpos( $after, $block ) || ! $this->third_party_content_preserved( $before, $after ) ) {
			$this->atomic_write( $path, $before, 0644 );
			$status['mode']    = 'verification_failed';
			$status['backup']  = $backup;
			$status['message'] = __( 'Rule verification failed, so NBH Performance restored the previous .htaccess automatically.', 'nbh-performance' );
			return $status;
		}

		$status['installed'] = true;
		$status['managed']   = true;
		$status['mode']      = 'managed';
		$status['backup']    = $backup;
		$status['message']   = __( 'Safe NBH Performance rules were installed atomically inside a dedicated block. WordPress, hosting, and third-party rules were preserved.', 'nbh-performance' );
		return $status;
	}


	/** Remove only NBH Performance's bounded marker block. */
	private function remove_htaccess_rules( $status ) {
		$path                        = trailingslashit( ABSPATH ) . '.htaccess';
		$status['files']['htaccess'] = $path;
		if ( ! file_exists( $path ) ) {
			$status['mode']    = 'disabled';
			$status['message'] = __( 'Server-rule management is disabled and no NBH Performance block is present.', 'nbh-performance' );
			return $status;
		}
		if ( ! is_readable( $path ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $path ) ) {
			$status['mode']    = 'read_only';
			$status['message'] = __( 'Server-rule management is disabled, but .htaccess is not writable. No file was changed.', 'nbh-performance' );
			return $status;
		}
		$before = \NBH\Performance\Modules\Core\Filesystem::get_contents( $path );
		if ( false === $before || false === strpos( $before, self::BEGIN_MARKER ) ) {
			$status['mode']    = 'disabled';
			$status['message'] = __( 'Server-rule management is disabled and no managed block is present.', 'nbh-performance' );
			return $status;
		}
		$pattern = '/^[ \t]*' . preg_quote( self::BEGIN_MARKER, '/' ) . '.*?' . preg_quote( self::END_MARKER, '/' ) . '[ \t]*(?:\r?\n)?/ms';
		$after   = preg_replace( $pattern, '', $before, 1 );
		if ( null === $after || ! $this->third_party_content_preserved( $before, $after ) ) {
			$status['mode']    = 'verification_failed';
			$status['message'] = __( 'NBH Performance could not isolate its marker block safely, so .htaccess was left unchanged.', 'nbh-performance' );
			return $status;
		}
		$backup = $this->backup_file( $before );
		if ( '' === $backup || ! $this->atomic_write( $path, $after, fileperms( $path ) & 0777 ) ) {
			$status['mode']    = 'write_failed';
			$status['message'] = __( 'NBH Performance could not safely remove its server block. The existing file remains active.', 'nbh-performance' );
			return $status;
		}
		$status['mode']    = 'disabled';
		$status['backup']  = $backup;
		$status['message'] = __( 'Only the NBH Performance marker block was removed. Every other .htaccess rule was preserved.', 'nbh-performance' );
		return $status;
	}

	/** @return string[] */
	private function apache_rules() {
		$browser_ttl = max( DAY_IN_SECONDS, min( YEAR_IN_SECONDS, absint( get_option( 'nbh_performance_static_cache_max_age', YEAR_IN_SECONDS ) ) ) );
		$edge_ttl    = max( 60, min( DAY_IN_SECONDS, absint( get_option( 'nbh_performance_edge_cache_ttl', HOUR_IN_SECONDS ) ) ) );
		$rules       = array(
			'# This bounded block contains only module-guarded, reversible directives.',
			'<IfModule mod_mime.c>',
			'  AddType image/avif .avif',
			'  AddType image/webp .webp',
			'  AddType application/wasm .wasm',
			'</IfModule>',
		);

		if ( get_option( 'nbh_performance_compression_rules_enabled', 1 ) ) {
			$rules = array_merge(
				$rules,
				array(
					'',
					'<IfModule mod_deflate.c>',
					'  AddOutputFilterByType DEFLATE text/plain text/html text/xml text/css',
					'  AddOutputFilterByType DEFLATE application/javascript application/json application/xml application/rss+xml application/atom+xml image/svg+xml application/wasm',
					'</IfModule>',
					'',
					'<IfModule mod_brotli.c>',
					'  AddOutputFilterByType BROTLI_COMPRESS text/plain text/html text/xml text/css application/javascript application/json application/xml application/rss+xml image/svg+xml application/wasm',
					'</IfModule>',
				)
			);
		}

		if ( get_option( 'nbh_performance_browser_cache_rules_enabled', 1 ) ) {
			$rules = array_merge(
				$rules,
				array(
					'',
					'<IfModule mod_expires.c>',
					'  ExpiresActive On',
					'  ExpiresByType text/html "access plus 0 seconds"',
					'  ExpiresByType application/json "access plus 0 seconds"',
					'  ExpiresByType text/css "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType application/javascript "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/avif "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/webp "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/jpeg "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/png "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/gif "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/svg+xml "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType image/x-icon "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType font/woff2 "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType font/woff "access plus ' . $browser_ttl . ' seconds"',
					'  ExpiresByType application/wasm "access plus ' . $browser_ttl . ' seconds"',
					'</IfModule>',
					'',
					'<IfModule mod_headers.c>',
					'  <FilesMatch "\\.(?:css|js|mjs|jpg|jpeg|png|gif|svg|webp|avif|ico|woff|woff2|ttf|otf|wasm)$">',
					'    Header set Cache-Control "public, max-age=' . $browser_ttl . ', immutable"',
					'  </FilesMatch>',
					'  <FilesMatch "\\.(?:html?|json|xml)$">',
					'    Header unset Pragma',
					'    Header set Cache-Control "no-cache, must-revalidate"',
					'  </FilesMatch>',
					'  Header append Vary Accept-Encoding env=!dont-vary',
					'</IfModule>',
				)
			);
		}

		if ( get_option( 'nbh_performance_edge_cache_headers_enabled', 0 ) ) {
			$rules[] = '';
			$rules[] = '<IfModule mod_headers.c>';
			$rules[] = '  <FilesMatch "\\.(?:html?)$">';
			$rules[] = '    Header set CDN-Cache-Control "public, max-age=' . $edge_ttl . '"';
			$rules[] = '  </FilesMatch>';
			$rules[] = '</IfModule>';
		}
		return $rules;
	}

	/** @param array<string,mixed> $status Status. */
	private function generate_portable_configs( &$status ) {
		$directory = WP_CONTENT_DIR . '/cache/nbh-performance/server-rules';
		if ( ! wp_mkdir_p( $directory ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $directory ) ) {
			return;
		}
		$files = array(
			'nginx.conf'         => $this->nginx_rules(),
			'web.config.example' => $this->iis_rules(),
			'htaccess.example'   => implode( "\n", $this->apache_rules() ) . "\n",
			'README.txt'         => $this->instructions(),
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;
			if ( $this->atomic_write( $path, $contents, 0644 ) ) {
				$status['files'][ sanitize_key( $name ) ] = $path;
			}
		}
	}

	/** @param array<string,mixed> $status Status. */
	private function protect_runtime_directories( &$status ) {
		$directories = array( WP_CONTENT_DIR . '/cache/nbh-performance/server-rules', WP_CONTENT_DIR . '/cache/nbh-performance/backups' );
		$protection  = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
		foreach ( $directories as $directory ) {
			if ( wp_mkdir_p( $directory ) && \NBH\Performance\Modules\Core\Filesystem::is_writable( $directory ) ) {
				$this->atomic_write( trailingslashit( $directory ) . '.htaccess', $protection, 0644 );
				$this->atomic_write( trailingslashit( $directory ) . 'index.html', '', 0644 );
			}
		}
		$status['runtime_protected'] = true;
	}

	private function nginx_rules() {
		$ttl         = max( DAY_IN_SECONDS, min( YEAR_IN_SECONDS, absint( get_option( 'nbh_performance_static_cache_max_age', YEAR_IN_SECONDS ) ) ) );
		$compression = get_option( 'nbh_performance_compression_rules_enabled', 1 )
			? "# Compression belongs in the http/server context. Keep existing host settings when already configured.\ngzip on;\ngzip_vary on;\ngzip_min_length 1024;\ngzip_types text/plain text/css application/json application/javascript application/xml application/rss+xml image/svg+xml application/wasm;\n\n"
			: "# NBH Performance compression rules are disabled.\n\n";
		$browser     = get_option( 'nbh_performance_browser_cache_rules_enabled', 1 )
			? "location ~* \\.(?:css|js|mjs|jpg|jpeg|png|gif|svg|webp|avif|ico|woff|woff2|ttf|otf|wasm)$ {\n    expires " . $ttl . "s;\n    add_header Cache-Control \"public, max-age=" . $ttl . ", immutable\" always;\n    access_log off;\n}\n\nlocation ~* \\.(?:html|htm|json|xml)$ {\n    add_header Cache-Control \"no-cache, must-revalidate\" always;\n}\n\n"
			: "# NBH Performance browser-cache rules are disabled.\n\n";
		return "# NBH Performance portable Nginx snippet. Include only after review.\n"
			. "# Validate before reload: nginx -t\n"
			. $compression . $browser
			. "# Add MIME types only when your global mime.types does not already include them:\n"
			. "# types { image/avif avif; image/webp webp; application/wasm wasm; }\n";
	}

	private function iis_rules() {
		$days         = max( 1, min( 365, (int) ceil( absint( get_option( 'nbh_performance_static_cache_max_age', YEAR_IN_SECONDS ) ) / DAY_IN_SECONDS ) ) );
		$client_cache = get_option( 'nbh_performance_browser_cache_rules_enabled', 1 )
			? '<clientCache cacheControlMode="UseMaxAge" cacheControlMaxAge="' . sprintf( '%d.00:00:00', $days ) . '" />'
			: '';
		$compression  = get_option( 'nbh_performance_compression_rules_enabled', 1 )
			? '<urlCompression doStaticCompression="true" doDynamicCompression="true" />'
			: '';
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<!-- Example only. Merge through IIS Manager; never replace an existing web.config blindly. -->\n"
			. '<configuration><system.webServer><staticContent>'
			. '<mimeMap fileExtension=".webp" mimeType="image/webp" />'
			. '<mimeMap fileExtension=".avif" mimeType="image/avif" />'
			. '<mimeMap fileExtension=".wasm" mimeType="application/wasm" />'
			. $client_cache . '</staticContent>' . $compression
			. "</system.webServer></configuration>\n";
	}

	private function instructions() {
		return "NBH Performance server rules\n\nApache/LiteSpeed: NBH Performance manages only the bounded # BEGIN NBH Performance / # END NBH Performance block when .htaccess is writable.\nAll existing WordPress, host, security, and optimization-plugin blocks remain untouched.\nNginx: review nginx.conf, include it inside the active server block, run nginx -t, then reload.\nIIS: merge web.config.example through IIS Manager. Never replace an existing web.config blindly.\nOpenLiteSpeed managed environments may ignore .htaccess; use the generated snippet or hosting panel when required.\nBackups are stored under wp-content/cache/nbh-performance/backups/server-rules and the newest three are retained.\n";
	}

	private function server_family() {
		$report = $this->environment->detect();
		return is_array( $report ) && ! empty( $report['server_family'] ) ? sanitize_key( $report['server_family'] ) : 'unknown';
	}

	private function markers_are_valid( $contents ) {
		return substr_count( $contents, self::BEGIN_MARKER ) === substr_count( $contents, self::END_MARKER ) && substr_count( $contents, self::BEGIN_MARKER ) <= 1;
	}

	private function replace_managed_block( $contents, $block, $eol ) {
		$pattern = '/^[ \t]*' . preg_quote( self::BEGIN_MARKER, '/' ) . '.*?' . preg_quote( self::END_MARKER, '/' ) . '[ \t]*(?:\r?\n)?/ms';
		if ( preg_match( $pattern, $contents ) ) {
			return preg_replace( $pattern, $block . $eol, $contents, 1 );
		}
		return ( '' === trim( $contents ) ? '' : rtrim( $contents ) . $eol . $eol ) . $block . $eol;
	}

	private function third_party_content_preserved( $before, $after ) {
		$strip = function ( $value ) {
			return trim( preg_replace( '/^[ \t]*' . preg_quote( self::BEGIN_MARKER, '/' ) . '.*?' . preg_quote( self::END_MARKER, '/' ) . '[ \t]*(?:\r?\n)?/ms', '', $value ) );
		};
		return hash_equals( hash( 'sha256', $strip( $before ) ), hash( 'sha256', $strip( $after ) ) );
	}

	private function backup_file( $contents ) {
		$directory = WP_CONTENT_DIR . '/cache/nbh-performance/backups/server-rules';
		if ( ! wp_mkdir_p( $directory ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $directory ) ) {
			return '';
		}
		$path = trailingslashit( $directory ) . 'htaccess-' . gmdate( 'Ymd-His' ) . '-' . substr( hash( 'sha256', $contents ), 0, 10 ) . '.bak';
		if ( ! $this->atomic_write( $path, $contents, 0600 ) ) {
			return '';
		}
		$this->prune_backups( $directory );
		return $path;
	}

	private function prune_backups( $directory ) {
		$files = glob( trailingslashit( $directory ) . 'htaccess-*.bak' );
		if ( ! is_array( $files ) || count( $files ) <= 3 ) {
			return;
		}
		sort( $files, SORT_STRING );
		foreach ( array_slice( $files, 0, -3 ) as $file ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $file );
		}
	}

	private function atomic_write( $path, $contents, $permissions = 0644 ) {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $directory ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$temp = @tempnam( $directory, '.nbh-rule-' );
		if ( ! $temp ) {
			return false;
		}
		if ( ! \NBH\Performance\Modules\Core\Filesystem::put_contents( $temp, (string) $contents, $permissions ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		}
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, $permissions );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. Atomic same-filesystem rename is required for publication; WP_Filesystem::move() can unlink/copy and is not an atomic commit. Failure is checked before accepting the new artifact.
		if ( ! @rename( $temp, $path ) ) {
			\NBH\Performance\Modules\Core\Filesystem::unlink( $temp );
			return false;
		}
		return true;
	}

	/** @param array<string,mixed> $info Site Health info. @return array<string,mixed> */
	public function site_health_info( $info ) {
		$status                               = get_option( self::STATUS_KEY, array() );
		$info['nbh_performance_server_rules'] = array(
			'label'  => __( 'NBH Performance server rules', 'nbh-performance' ),
			'fields' => array(
				'server' => array(
					'label' => __( 'Detected server', 'nbh-performance' ),
					'value' => isset( $status['server'] ) ? $status['server'] : 'unknown',
				),
				'mode'   => array(
					'label' => __( 'Management mode', 'nbh-performance' ),
					'value' => isset( $status['mode'] ) ? $status['mode'] : 'not_checked',
				),
				'result' => array(
					'label' => __( 'Last result', 'nbh-performance' ),
					'value' => isset( $status['message'] ) ? $status['message'] : __( 'Not checked yet.', 'nbh-performance' ),
				),
			),
		);
		return $info;
	}
}
