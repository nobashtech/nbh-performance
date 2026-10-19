<?php
/**
 * NBH_PERFORMANCE_DROPIN
 *
 * Fail-safe early page-cache runtime with origin isolation, response metadata,
 * conditional requests, precompressed delivery, stale-while-revalidate and
 * stale-if-error protection.
 *
 * @package NBHPerformance
 */

defined( 'ABSPATH' ) || exit;

// Isolate early-runtime variables from the WordPress global scope.
( static function () {

	if (
	! defined( 'WP_CACHE' ) ||
	! WP_CACHE ||
	defined( 'NBH_PERFORMANCE_BYPASS' ) ||
	( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE )
	) {
		return;
	}

	$config_file = WP_CONTENT_DIR . '/cache/nbh-performance/runtime.json';
	if ( ! is_readable( $config_file ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
	$config_raw = @file_get_contents( $config_file );
	$config     = json_decode( (string) $config_raw, true );
	if (
	! is_array( $config ) ||
	empty( $config['enabled'] ) ||
	empty( $config['cache_root'] ) ||
	empty( $config['site_host'] ) ||
	empty( $config['site_scheme'] ) ||
	! isset( $config['config_version'] ) ||
	8 !== (int) $config['config_version']
	) {
		return;
	}

	$expected_cache_root   = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR . '/cache/nbh-performance/pages' ), '/' );
	$configured_cache_root = is_string( $config['cache_root'] ) ? rtrim( str_replace( '\\', '/', $config['cache_root'] ), '/' ) : '';
	if ( ! is_string( $config['cache_root'] ) || ! is_string( $config['site_host'] ) || ! is_string( $config['site_scheme'] ) || $configured_cache_root !== $expected_cache_root ) {
		return;
	}

	$nbh_lines = static function ( $value ) {
		return array_values( array_filter( array_map( 'strval', is_array( $value ) ? array_filter( $value, 'is_scalar' ) : array() ) ) );
	};

	$nbh_pattern_matches = static function ( $subject, $pattern ) {
		$subject = (string) $subject;
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		if ( 0 === stripos( $pattern, 'regex:' ) ) {
			$expression = trim( substr( $pattern, 6 ) );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return '' !== $expression && strlen( $expression ) <= 500 && 1 === @preg_match( $expression, $subject );
		}
		if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) {
			$quoted = preg_quote( $pattern, '#' );
			$quoted = str_replace( array( '\\*', '\\?' ), array( '.*', '.' ), $quoted );
			return 1 === preg_match( '#^' . $quoted . '$#i', $subject );
		}
		return false !== stripos( $subject, $pattern );
	};

	$nbh_cookie_value = static function ( array $names ) {
		foreach ( $names as $name ) {
			$name = (string) $name;
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
			if ( '' !== $name && isset( $_COOKIE[ $name ] ) && '' !== (string) $_COOKIE[ $name ] ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
				return (string) $_COOKIE[ $name ];
			}
		}
		return '';
	};

	$nbh_bounded_variant = static function ( $value, $length ) {
		$value = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $value ) );
		$value = trim( (string) $value, '-_' );
		return substr( $value, 0, (int) $length );
	};

	$nbh_normalize_path = static function ( $value ) {
		$path = rawurldecode( (string) $value );
		$path = str_replace( array( "\0", '\\' ), array( '', '/' ), $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = preg_replace( '#(?:^|/)\.\.(?:/|$)#', '/', $path );
		return '/' . ltrim( (string) $path, '/' );
	};

	$nbh_accepts_encoding = static function ( $encoding ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
		$header = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT_ENCODING'] ) : '';
		if ( '' === $header ) {
			return false;
		}
		foreach ( explode( ',', $header ) as $part ) {
			$tokens = array_map( 'trim', explode( ';', $part ) );
			if ( strtolower( $tokens[0] ) !== strtolower( (string) $encoding ) ) {
				continue;
			}
			foreach ( array_slice( $tokens, 1 ) as $parameter ) {
				if ( 0 === strpos( $parameter, 'q=' ) && (float) substr( $parameter, 2 ) <= 0 ) {
					return false;
				}
			}
			return true;
		}
		return false;
	};

	$nbh_request_has_no_cache = static function () {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
		$cache_control = isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ? strtolower( (string) $_SERVER['HTTP_CACHE_CONTROL'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
		$pragma = isset( $_SERVER['HTTP_PRAGMA'] ) ? strtolower( (string) $_SERVER['HTTP_PRAGMA'] ) : '';
		return 1 === preg_match( '/(?:^|,)\s*(?:no-cache|no-store|max-age=0)\s*(?:,|$)/', $cache_control ) || false !== strpos( $pragma, 'no-cache' );
	};

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return;
	}

	if (
	isset( $_SERVER['PHP_AUTH_USER'] ) ||
	isset( $_SERVER['PHP_AUTH_PW'] ) ||
	! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ||
	! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ||
	! empty( $_SERVER['HTTP_RANGE'] ) ||
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
	( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( (string) $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ||
	$nbh_request_has_no_cache()
	) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This drop-in runs before wp_parse_url() is loaded; PHP parsing is followed by strict origin/path validation.
	$early_path = (string) parse_url( $uri, PHP_URL_PATH );
	if ( preg_match( '#/(?:wp-admin(?:/|$)|wp-login\.php(?:/|$)|wp-cron\.php(?:/|$)|xmlrpc\.php(?:/|$)|wp-comments-post\.php(?:/|$)|wp-json(?:/|$))#i', $early_path ) ) {
		return;
	}

	$mandatory_bypass_cookies  = array(
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'phpsessid',
	);
	$configured_bypass_cookies = $nbh_lines( isset( $config['bypass_cookies'] ) ? $config['bypass_cookies'] : array() );
	$bypass_cookies            = array_values( array_unique( array_merge( $mandatory_bypass_cookies, $configured_bypass_cookies ) ) );
	foreach ( $_COOKIE as $cookie_value ) {
		if ( ! is_scalar( $cookie_value ) ) {
			return;
		}
	}
	foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
		foreach ( $bypass_cookies as $prefix ) {
			if ( '' !== $prefix && 0 === stripos( (string) $cookie_name, (string) $prefix ) ) {
				return;
			}
		}
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
	$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? trim( (string) $_SERVER['HTTP_HOST'] ) : '';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This drop-in runs before wp_parse_url() is loaded; PHP parsing is followed by strict origin/path validation.
	$host_url = parse_url( 'http://' . $raw_host );
	$host     = is_array( $host_url ) && isset( $host_url['host'] ) ? strtolower( (string) $host_url['host'] ) : strtolower( preg_replace( '/:\d+$/', '', $raw_host ) );
	$port     = is_array( $host_url ) && isset( $host_url['port'] ) ? (int) $host_url['port'] : 0;
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
	$scheme = ( isset( $_SERVER['HTTPS'] ) && '' !== (string) $_SERVER['HTTPS'] && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] ) ? 'https' : 'http';
	// HTTP_HOST is the public request authority. SERVER_PORT may be a proxy's internal listener.

	if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
		$port = 0;
	}
	if (
	strtolower( (string) $config['site_host'] ) !== $host ||
	strtolower( (string) $config['site_scheme'] ) !== $scheme ||
	( isset( $config['site_port'] ) ? (int) $config['site_port'] : 0 ) !== $port
	) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This drop-in runs before wp_parse_url() is loaded; PHP parsing is followed by strict origin/path validation.
	$parts = parse_url( $uri );
	$path  = $nbh_normalize_path( isset( $parts['path'] ) ? $parts['path'] : '/' );

	foreach ( $nbh_lines( isset( $config['bypass_paths'] ) ? $config['bypass_paths'] : array() ) as $bypass_path ) {
		$bypass_path = '/' . ltrim( (string) $bypass_path, '/' );
		if ( '/' !== $bypass_path && ( rtrim( $bypass_path, '/' ) === $path || 0 === strpos( $path, rtrim( $bypass_path, '/' ) . '/' ) ) ) {
			return;
		}
	}
	foreach ( $nbh_lines( isset( $config['excluded_paths'] ) ? $config['excluded_paths'] : array() ) as $excluded_path ) {
		if ( $nbh_pattern_matches( $path, $excluded_path ) || $nbh_pattern_matches( $uri, $excluded_path ) ) {
			return;
		}
	}

	$query_parameters = array();
	if ( isset( $parts['query'] ) ) {
		parse_str( (string) $parts['query'], $query_parameters );
	}
	$mandatory_bypass_queries = array(
		'preview',
		'preview_id',
		'preview_nonce',
		'customize_changeset_uuid',
		'customize_messenger_channel',
		'wc-ajax',
		'add-to-cart',
		'remove_item',
		'apply_coupon',
		'coupon_code',
		'order_again',
		'pay_for_order',
		'_wpnonce',
		'_wp_http_referer',
		'rest_route',
		'nbh_css_source',
	);
	foreach ( array_keys( $query_parameters ) as $query_key ) {
		if ( in_array( strtolower( (string) $query_key ), $mandatory_bypass_queries, true ) ) {
			return;
		}
	}
	foreach ( $nbh_lines( isset( $config['ignored_queries'] ) ? $config['ignored_queries'] : array() ) as $ignored_query ) {
		unset( $query_parameters[ $ignored_query ] );
	}
	$allowed_queries = $nbh_lines( isset( $config['allowed_queries'] ) ? $config['allowed_queries'] : array() );
	foreach ( array_keys( $query_parameters ) as $query_key ) {
		if ( ! in_array( (string) $query_key, $allowed_queries, true ) ) {
			return;
		}
	}
	if ( ! empty( $query_parameters ) ) {
		ksort( $query_parameters );
	}
	$canonical_query = http_build_query( $query_parameters, '', '&', PHP_QUERY_RFC3986 );

	$host_segment = strtolower( preg_replace( '/[^a-z0-9._-]+/', '-', $host ) );
	$host_segment = trim( (string) $host_segment, '.-_' );
	$host_segment = '' !== $host_segment ? $host_segment : 'default';
	$variants     = array();

	$mobile_variant_disabled = false;
	foreach ( $nbh_lines( isset( $config['mobile_variant_disabled_paths'] ) ? $config['mobile_variant_disabled_paths'] : array() ) as $disabled_path ) {
		if ( rtrim( $path, '/' ) === rtrim( '/' . ltrim( (string) $disabled_path, '/' ), '/' ) ) {
			$mobile_variant_disabled = true;
			break;
		}
	}
	if ( ! empty( $config['mobile_cache'] ) && ! $mobile_variant_disabled ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
		$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		$is_mobile          = (bool) preg_match( '/android|avantgo|blackberry|bb10|iphone|ipod|opera mini|iemobile|mobile|webos/i', $user_agent );
		$variants['device'] = $is_mobile ? 'mobile' : 'desktop';
	}
	if ( ! empty( $config['language_variant'] ) ) {
		$language = $nbh_cookie_value( $nbh_lines( isset( $config['language_cookies'] ) ? $config['language_cookies'] : array() ) );
		if ( '' !== $language ) {
			$variants['language'] = $nbh_bounded_variant( $language, 20 );
		}
	}
	if ( ! empty( $config['currency_variant'] ) ) {
		$currency = $nbh_cookie_value( $nbh_lines( isset( $config['currency_cookies'] ) ? $config['currency_cookies'] : array() ) );
		if ( '' !== $currency ) {
			$variants['currency'] = $nbh_bounded_variant( $currency, 12 );
		}
	}
	foreach ( array_slice( $nbh_lines( isset( $config['vary_cookies'] ) ? $config['vary_cookies'] : array() ), 0, 10 ) as $vary_cookie ) {
		if ( isset( $_COOKIE[ $vary_cookie ] ) ) {
			$key = 'cookie_' . substr( preg_replace( '/[^a-z0-9_-]+/i', '', strtolower( (string) $vary_cookie ) ), 0, 40 );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
			$variants[ $key ] = substr( hash( 'sha256', (string) $_COOKIE[ $vary_cookie ] ), 0, 16 );
		}
	}
	ksort( $variants );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Self-contained pre-WordPress cache runtime; wp_json_encode() is not available during early bootstrap.
	$variant_json  = json_encode( $variants );
	$origin        = $scheme . '://' . $host_segment . ( $port ? ':' . $port : '' );
	$source        = $origin . '|' . $path . '|' . $canonical_query . '|' . ( false === $variant_json ? '[]' : $variant_json );
	$hash          = hash( 'sha256', $source );
	$cache_root    = rtrim( str_replace( '\\', '/', (string) $config['cache_root'] ), '/' );
	$directory     = $cache_root . '/' . $host_segment . '/' . substr( $hash, 0, 2 ) . '/' . $hash;
	$file          = $directory . '/index.html';
	$metadata_file = $directory . '/metadata.json';
	$error_file    = $directory . '/error.json';
	$refresh_file  = $directory . '/refresh.lock';

	if ( ! is_readable( $file ) || ! is_readable( $metadata_file ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
	$metadata_raw = @file_get_contents( $metadata_file );
	$metadata     = json_decode( (string) $metadata_raw, true );
	if ( ! is_array( $metadata ) || ! isset( $metadata['schema_version'], $metadata['content_length'] ) || 2 !== (int) $metadata['schema_version'] || empty( $metadata['generated_at'] ) || empty( $metadata['expires_at'] ) ) {
		return;
	}
	foreach ( array( 'etag', 'last_modified', 'content_sha256', 'url' ) as $text_field ) {
		if ( isset( $metadata[ $text_field ] ) && ! is_string( $metadata[ $text_field ] ) ) {
			return;
		}
	}
	foreach ( array( 'generated_at', 'expires_at', 'content_length' ) as $number_field ) {
		if ( ! is_numeric( $metadata[ $number_field ] ) ) {
			return;
		}
	}
	$status = isset( $metadata['status'] ) ? (int) $metadata['status'] : 0;
	if (
	200 !== $status &&
	! ( 404 === $status && ! empty( $config['cache_404'] ) ) &&
	! ( in_array( $status, array( 301, 308 ), true ) && ! empty( $config['cache_redirects'] ) )
	) {
		return;
	}

	$metadata_variants = isset( $metadata['variants'] ) && is_array( $metadata['variants'] ) ? $metadata['variants'] : array();
	ksort( $metadata_variants );
	if ( $metadata_variants !== $variants ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This drop-in runs before wp_parse_url() is loaded; PHP parsing is followed by strict origin/path validation.
	$metadata_url = isset( $metadata['url'] ) && is_string( $metadata['url'] ) ? parse_url( $metadata['url'] ) : false;
	if ( is_array( $metadata_url ) ) {
		$metadata_scheme = isset( $metadata_url['scheme'] ) ? strtolower( (string) $metadata_url['scheme'] ) : '';
		$metadata_host   = isset( $metadata_url['host'] ) ? strtolower( (string) $metadata_url['host'] ) : '';
		$metadata_port   = isset( $metadata_url['port'] ) ? (int) $metadata_url['port'] : 0;
		if ( ( 'https' === $metadata_scheme && 443 === $metadata_port ) || ( 'http' === $metadata_scheme && 80 === $metadata_port ) ) {
			$metadata_port = 0;
		}
		$metadata_path  = $nbh_normalize_path( isset( $metadata_url['path'] ) ? $metadata_url['path'] : '/' );
		$metadata_query = array();
		if ( isset( $metadata_url['query'] ) ) {
			parse_str( (string) $metadata_url['query'], $metadata_query );
			foreach ( $nbh_lines( isset( $config['ignored_queries'] ) ? $config['ignored_queries'] : array() ) as $ignored_query ) {
				unset( $metadata_query[ $ignored_query ] );
			}
			ksort( $metadata_query );
		}
		$metadata_query = http_build_query( $metadata_query, '', '&', PHP_QUERY_RFC3986 );
		if ( $metadata_scheme !== $scheme || $metadata_host !== $host || $metadata_port !== $port || $metadata_path !== $path || $metadata_query !== $canonical_query ) {
			return;
		}
	}

	$headers = isset( $metadata['headers'] ) && is_array( $metadata['headers'] ) ? $metadata['headers'] : array();
	foreach ( $headers as $header_value ) {
		if ( ! is_scalar( $header_value ) ) {
			return;
		}
	}
	$location = isset( $headers['location'] ) ? (string) $headers['location'] : '';
	if ( in_array( $status, array( 301, 308 ), true ) ) {
		if ( '' === $location || preg_match( '/[\r\n]/', $location ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This drop-in runs before wp_parse_url() is loaded; PHP parsing is followed by strict origin/path validation.
		$location_parts = parse_url( $location );
		if ( is_array( $location_parts ) && ! empty( $location_parts['host'] ) ) {
			$location_scheme = isset( $location_parts['scheme'] ) ? strtolower( (string) $location_parts['scheme'] ) : $scheme;
			$location_host   = strtolower( (string) $location_parts['host'] );
			$location_port   = isset( $location_parts['port'] ) ? (int) $location_parts['port'] : 0;
			if ( ( 'https' === $location_scheme && 443 === $location_port ) || ( 'http' === $location_scheme && 80 === $location_port ) ) {
				$location_port = 0;
			}
			if ( $location_scheme !== $scheme || $location_host !== $host || $location_port !== $port ) {
				return;
			}
		} elseif ( 0 !== strpos( $location, '/' ) ) {
			return;
		}
	}

	$file_size = filesize( $file );
	if (
	false === $file_size ||
	$file_size > 50 * 1024 * 1024 ||
	( ! in_array( $status, array( 301, 308 ), true ) && $file_size < 1 ) ||
	(int) $file_size !== (int) $metadata['content_length']
	) {
		return;
	}
	if ( ! empty( $config['verify_integrity'] ) && ! empty( $metadata['content_sha256'] ) && ! hash_equals( (string) $metadata['content_sha256'], hash_file( 'sha256', $file ) ) ) {
		return;
	}

	$now       = time();
	$generated = (int) $metadata['generated_at'];
	$expires   = (int) $metadata['expires_at'];
	$swr       = isset( $metadata['stale_while_revalidate'] ) ? max( 0, (int) $metadata['stale_while_revalidate'] ) : max( 0, (int) $config['stale_while_revalidate'] );
	$sie       = isset( $metadata['stale_if_error'] ) ? max( 0, (int) $metadata['stale_if_error'] ) : max( 0, (int) $config['stale_if_error'] );
	$state     = 'HIT';

	if ( $now > $expires ) {
		if ( $now <= $expires + $swr ) {
			$lock_timeout = isset( $config['lock_timeout'] ) ? max( 10, (int) $config['lock_timeout'] ) : 45;
			if ( is_file( $refresh_file ) ) {
				$lock_mtime = filemtime( $refresh_file );
				if ( false !== $lock_mtime && ( $now - $lock_mtime ) > $lock_timeout ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $refresh_file );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$refresh_handle = @fopen( $refresh_file, 'x' );
			if ( false !== $refresh_handle ) {
				// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The destination is an owned WP_CONTENT_DIR/cache/nbh-performance runtime/probe/lock file, not a plugin source directory.
				fwrite( $refresh_handle, (string) $now );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush
				fflush( $refresh_handle );
				$GLOBALS['nbh_performance_revalidation_lock']       = $refresh_handle;
				$GLOBALS['nbh_performance_revalidation_file']       = $refresh_file;
				$GLOBALS['nbh_performance_revalidation_cache_file'] = $file;
				if ( ! defined( 'NBH_PERFORMANCE_REVALIDATING' ) ) {
					define( 'NBH_PERFORMANCE_REVALIDATING', true );
				}
				return;
			}
			$state = 'STALE';
		} else {
			$error = array();
			if ( is_readable( $error_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$error = json_decode( (string) file_get_contents( $error_file ), true );
			}
			$failed_at = is_array( $error ) && isset( $error['failed_at'] ) ? (int) $error['failed_at'] : 0;
			if ( 0 === $failed_at || $now > $expires + $sie || ( $now - $failed_at ) > $sie ) {
				return;
			}
			$state = 'STALE-ERROR';
		}
	}

	$etag          = isset( $metadata['etag'] ) ? (string) $metadata['etag'] : '';
	$last_modified = isset( $metadata['last_modified'] ) ? (string) $metadata['last_modified'] : '';
	$not_modified  = false;
	if ( 200 === $status && '' !== $etag && isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
		$client_etags = array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) );
		$not_modified = in_array( $etag, $client_etags, true ) || in_array( '*', $client_etags, true );
	} elseif ( 200 === $status && '' !== $last_modified && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WordPress runtime: magic quotes and WP sanitizers do not exist yet. Raw input is restricted by method/session/query allowlists and strict configured-origin checks before selecting a hashed cache path.
		$client_time  = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		$server_time  = strtotime( $last_modified );
		$not_modified = false !== $client_time && false !== $server_time && $client_time >= $server_time;
	}

	http_response_code( $not_modified ? 304 : $status );
	header( 'X-NBH-Performance: ' . $state, true );
	header( 'Age: ' . max( 0, $now - $generated ), true );
	header( 'Cache-Control: public, max-age=0, must-revalidate, stale-while-revalidate=' . $swr . ', stale-if-error=' . $sie, true );
	if ( 'HIT' !== $state ) {
		header( 'Warning: 110 - "Response is stale"', true );
	}
	if ( '' !== $etag ) {
		header( 'ETag: ' . $etag, true );
	}
	if ( '' !== $last_modified ) {
		header( 'Last-Modified: ' . $last_modified, true );
	}

	$allowed_headers = array( 'content-type', 'content-language', 'link', 'x-robots-tag', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'location' );
	foreach ( $headers as $name => $value ) {
		$name  = strtolower( (string) $name );
		$value = (string) $value;
		if ( in_array( $name, $allowed_headers, true ) && '' !== $value && ! preg_match( '/[\r\n]/', $value ) ) {
			if ( 'location' === $name && ! in_array( $status, array( 301, 308 ), true ) ) {
				continue;
			}
			header( $name . ': ' . $value, true );
		}
	}

	if ( $not_modified ) {
		exit;
	}
	if ( in_array( $status, array( 301, 308 ), true ) ) {
		header( 'Content-Length: 0', true );
		exit;
	}

	$serve_file = $file;
	$encoding   = '';
	// Never serve leftover compressed bytes from an earlier or incomplete write.
	$valid_compression = static function ( $name, $path ) use ( $metadata ) {
		$entry = isset( $metadata['compression'][ $name ] ) ? $metadata['compression'][ $name ] : null;
		return is_array( $entry ) && isset( $entry['file'], $entry['bytes'] ) &&
		is_string( $entry['file'] ) && basename( $path ) === $entry['file'] &&
		is_numeric( $entry['bytes'] ) && is_readable( $path ) && (int) filesize( $path ) === (int) $entry['bytes'];
	};
	if ( ! empty( $config['brotli_cache'] ) && $nbh_accepts_encoding( 'br' ) && $valid_compression( 'br', $file . '.br' ) ) {
		$serve_file = $file . '.br';
		$encoding   = 'br';
	} elseif ( ! empty( $config['gzip_cache'] ) && $nbh_accepts_encoding( 'gzip' ) && $valid_compression( 'gzip', $file . '.gz' ) ) {
		$serve_file = $file . '.gz';
		$encoding   = 'gzip';
	}
	if ( is_readable( $file . '.br' ) || is_readable( $file . '.gz' ) ) {
		header( 'Vary: Accept-Encoding', true );
	}
	if ( '' !== $encoding ) {
		header( 'Content-Encoding: ' . $encoding, true );
	}
	$serve_size = filesize( $serve_file );
	if ( false !== $serve_size ) {
		header( 'Content-Length: ' . (int) $serve_size, true );
	}
	if ( 'HEAD' !== $method ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $serve_file );
	}
	exit;
} )();
