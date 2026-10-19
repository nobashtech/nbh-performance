<?php
/** Context matcher for Script Manager rules. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

use NBH\Performance\Modules\Core\PatternMatcher;

final class AssetRuleMatcher {
	/** Return whether a normalized rule matches the current request. */
	public function matches( array $rule ) {
		if ( empty( $rule['enabled'] ) || ! $this->device_matches( $rule ) || ! $this->auth_matches( $rule ) || ! $this->role_matches( $rule ) || ! $this->language_matches( $rule ) || ! $this->query_matches( $rule ) || ! $this->cookie_matches( $rule ) ) {
			return false;
		}
		$scope = isset( $rule['scope'] ) ? (string) $rule['scope'] : 'global';
		$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';
		$uri   = $this->request_uri();
		switch ( $scope ) {
			case 'url_exact':
				return $this->normalize_path( $uri ) === $this->normalize_path( $value );
			case 'url_contains':
				return '' !== $value && false !== stripos( $uri, $value );
			case 'url_regex':
				$is_regex = 0 === strpos( $value, 'regex:' );
				$pattern  = $is_regex ? $value : 'regex:' . $value;
				return '' !== $value && PatternMatcher::matches( $uri, $pattern );
			case 'url_except':
				return '' !== $value && ! PatternMatcher::matches( $uri, $value );
			case 'post_type':
				return '' !== $value && is_singular( sanitize_key( $value ) );
			case 'singular_id':
				return is_singular() && absint( $value ) === (int) get_queried_object_id();
			case 'taxonomy':
				return $this->taxonomy_matches( $value );
			case 'author_archive':
				return is_author() && ( '' === $value || absint( $value ) === (int) get_queried_object_id() );
			case 'front_page':
				return is_front_page();
			case 'home_page':
				return is_home();
			case 'archive':
				return is_archive();
			case 'search':
				return is_search();
			case '404':
				return is_404();
			case 'template':
				return '' !== $value && is_page_template( $value );
			case 'global':
			default:
				return true;
		}
	}

	/** Explain the current request result for previews and diagnostics. */
	public function explain( array $rule ) {
		return array(
			'matched'   => $this->matches( $rule ),
			'url'       => esc_url_raw( $this->current_url() ),
			'device'    => function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 'mobile' : 'desktop',
			'logged_in' => is_user_logged_in(),
			'object_id' => (int) get_queried_object_id(),
		);
	}

	private function taxonomy_matches( $value ) {
		$parts    = array_map( 'sanitize_key', explode( ':', (string) $value, 2 ) );
		$taxonomy = isset( $parts[0] ) ? $parts[0] : '';
		$term     = isset( $parts[1] ) ? $parts[1] : '';
		if ( '' === $taxonomy ) {
			return false;
		}
		return '' !== $term ? is_tax( $taxonomy, $term ) : is_tax( $taxonomy );
	}

	private function device_matches( array $rule ) {
		$device = isset( $rule['device'] ) ? (string) $rule['device'] : 'all';
		if ( 'all' === $device ) {
			return true;
		}
		$is_mobile = function_exists( 'wp_is_mobile' ) && wp_is_mobile();
		return 'mobile' === $device ? $is_mobile : ! $is_mobile;
	}

	private function auth_matches( array $rule ) {
		$auth = isset( $rule['auth'] ) ? (string) $rule['auth'] : 'all';
		if ( 'all' === $auth ) {
			return true;
		}
		return 'logged_in' === $auth ? is_user_logged_in() : ! is_user_logged_in();
	}

	private function role_matches( array $rule ) {
		$roles = isset( $rule['roles'] ) && is_array( $rule['roles'] ) ? array_filter( array_map( 'sanitize_key', $rule['roles'] ) ) : array();
		if ( empty( $roles ) ) {
			return true;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	private function language_matches( array $rule ) {
		$expected = isset( $rule['language'] ) ? sanitize_key( $rule['language'] ) : '';
		if ( '' === $expected ) {
			return true;
		}
		$current = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$current = strtolower( str_replace( '_', '-', (string) $current ) );
		return 0 === strpos( $current, strtolower( str_replace( '_', '-', $expected ) ) );
	}

	private function query_matches( array $rule ) {
		$key = isset( $rule['query_key'] ) ? sanitize_key( $rule['query_key'] ) : '';
		if ( '' === $key ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A sanitized public query parameter selects a read-only asset rule; it does not authorize any mutation.
		$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : null;
		if ( null === $value ) {
			return false;
		}
		$expected = isset( $rule['query_value'] ) ? (string) $rule['query_value'] : '';
		return '' === $expected || hash_equals( $expected, $value );
	}

	private function cookie_matches( array $rule ) {
		$key = isset( $rule['cookie_key'] ) ? sanitize_key( $rule['cookie_key'] ) : '';
		if ( '' === $key ) {
			return true;
		}
		$value = isset( $_COOKIE[ $key ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) ) : null;
		if ( null === $value ) {
			return false;
		}
		$expected = isset( $rule['cookie_value'] ) ? (string) $rule['cookie_value'] : '';
		return '' === $expected || hash_equals( $expected, $value );
	}

	private function request_uri() {
		return isset( $_SERVER['REQUEST_URI'] ) ? \NBH\Performance\Modules\Security\RequestInput::uri() : '/';
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		return $scheme . '://' . $host . $this->request_uri();
	}

	private function normalize_path( $value ) {
		$path = (string) wp_parse_url( (string) $value, PHP_URL_PATH );
		return '/' . ltrim( untrailingslashit( $path ? $path : '/' ), '/' );
	}
}
