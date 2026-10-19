<?php
/**
 * Detects hosting, server, proxy, filesystem and execution constraints.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\ServerOptimization;

final class HostingEnvironmentDetector {
	/** @return array<string, mixed> */
	public function detect() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		$headers  = $this->headers();
		$managed  = $this->managed_provider( $software, $headers );
		$server   = $this->server_family( $software, $headers );
		$proxy    = $this->proxy_layer( $headers );
		$fs       = $this->filesystem();
		$limits   = $this->limits();

		return array(
			'provider'       => $managed['provider'],
			'managed'        => $managed['managed'],
			'server_family'  => $server,
			'proxy'          => $proxy,
			'filesystem'     => $fs,
			'limits'         => $limits,
			'object_cache'   => $this->object_cache(),
			'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'external_cron'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'multisite'      => is_multisite(),
			'containerized'  => file_exists( '/.dockerenv' ) || getenv( 'KUBERNETES_SERVICE_HOST' ),
			'recommend_mode' => $this->recommended_mode( $managed, $server, $fs, $limits ),
		);
	}

	/** @return array<string, string> */
	private function headers() {
		$keys = array( 'HTTP_CF_RAY', 'HTTP_X_KINSTA_CACHE', 'HTTP_X_WP_ENGINE', 'HTTP_X_FLYWHEEL_CACHE', 'HTTP_X_LITESPEED_CACHE', 'HTTP_X_VARNISH', 'HTTP_X_CACHE', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FASTLY_CLIENT_IP', 'HTTP_X_SUCURI_ID' );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		return $out;
	}

	/** @return array{provider:string,managed:bool} */
	private function managed_provider( $software, $headers ) {
		$signals = array(
			'wp_engine'    => defined( 'WPE_APIKEY' ) || isset( $headers['HTTP_X_WP_ENGINE'] ),
			'kinsta'       => defined( 'KINSTA_CACHE_ZONE' ) || isset( $headers['HTTP_X_KINSTA_CACHE'] ),
			'flywheel'     => defined( 'FLYWHEEL_CONFIG_DIR' ) || isset( $headers['HTTP_X_FLYWHEEL_CACHE'] ),
			'pressable'    => defined( 'PRESSABLE_SITE_ID' ),
			'pagely'       => defined( 'PAGELYBIN' ),
			'cloudways'    => false !== strpos( $software, 'cloudways' ) || isset( $_SERVER['cw_allowed_ip'] ),
			'godaddy'      => defined( 'GD_SYSTEM_PLUGIN_DIR' ),
			'wordpresscom' => defined( 'IS_WPCOM' ) || defined( 'WP_COMSH_VERSION' ),
		);
		foreach ( $signals as $provider => $matched ) {
			if ( $matched ) {
				return array(
					'provider' => $provider,
					'managed'  => true,
				);
			}
		}
		return array(
			'provider' => 'self_managed_or_shared',
			'managed'  => false,
		);
	}

	/** @return string */
	private function server_family( $software, $headers ) {
		if ( false !== strpos( $software, 'openlitespeed' ) ) {
			return 'openlitespeed';
		}
		if ( false !== strpos( $software, 'litespeed' ) || isset( $headers['HTTP_X_LITESPEED_CACHE'] ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $software, 'iis' ) ) {
			return 'iis';
		}
		return 'unknown';
	}

	/** @return array<string, bool|string> */
	private function proxy_layer( $headers ) {
		return array(
			'cloudflare' => isset( $headers['HTTP_CF_RAY'] ),
			'varnish'    => isset( $headers['HTTP_X_VARNISH'] ),
			'fastly'     => isset( $headers['HTTP_FASTLY_CLIENT_IP'] ),
			'sucuri'     => isset( $headers['HTTP_X_SUCURI_ID'] ),
			'forwarded'  => isset( $headers['HTTP_X_FORWARDED_PROTO'] ),
		);
	}

	/** @return array<string, mixed> */
	private function filesystem() {
		$uploads = wp_get_upload_dir();
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
		return array(
			'method'           => function_exists( 'get_filesystem_method' ) ? get_filesystem_method( array(), $content, false ) : 'unknown',
			'content_writable' => $content && wp_is_writable( $content ),
			'uploads_writable' => empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] ),
			'dropins_writable' => $content && wp_is_writable( $content ),
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
			'disk_free_bytes'  => function_exists( 'disk_free_space' ) && $content ? (int) @disk_free_space( $content ) : 0,
		);
	}

	/** @return array<string, int|bool|string> */
	private function limits() {
		return array(
			'memory_bytes'        => (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ),
			'max_execution_time'  => max( 0, (int) ini_get( 'max_execution_time' ) ),
			'max_input_vars'      => max( 0, (int) ini_get( 'max_input_vars' ) ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'proc_open'           => function_exists( 'proc_open' ),
			'exec'                => function_exists( 'exec' ),
			'shell_exec'          => function_exists( 'shell_exec' ),
		);
	}

	/** @return array<string, bool|string> */
	private function object_cache() {
		return array(
			'external'  => wp_using_ext_object_cache(),
			'redis'     => defined( 'WP_REDIS_DISABLED' ) ? ! WP_REDIS_DISABLED : class_exists( 'Redis' ),
			'memcached' => class_exists( 'Memcached' ),
		);
	}

	/** @return string */
	private function recommended_mode( $managed, $server, $fs, $limits ) {
		if ( ! $fs['content_writable'] || ( $limits['memory_bytes'] > 0 && $limits['memory_bytes'] < 134217728 ) ) {
			return 'conservative';
		}
		if ( $managed['managed'] || in_array( $server, array( 'litespeed', 'openlitespeed' ), true ) ) {
			return 'provider_aware';
		}
		if ( $limits['memory_bytes'] >= 536870912 ) {
			return 'high_capacity';
		}
		return 'balanced';
	}
}
