<?php
/**
 * Verifies the signed release manifest and every packaged plugin file.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Security;

use NBH\Performance\Modules\Licensing\SignedPayload;
use NBH\Performance\Modules\Licensing\SigningKeyStore;

final class IntegrityVerifier {
	const MANIFEST  = 'release-integrity.json';
	const TRANSIENT = 'nbh_performance_integrity_status';

	/** @return array<string,mixed> */
	public function verify( $force = false ) {
		$cached = get_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$result = array(
			'valid'      => true,
			'checked_at' => time(),
			'missing'    => array(),
			'modified'   => array(),
			'unexpected' => array(),
			'errors'     => array(),
			'build'      => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
		);
		$file   = NBH_PERFORMANCE_PATH . self::MANIFEST;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = is_readable( $file ) ? json_decode( (string) \NBH\Performance\Modules\Core\Filesystem::get_contents( $file ), true ) : null;
		if ( ! is_array( $manifest ) ) {
			$result['valid']     = false;
			$result['missing'][] = self::MANIFEST;
			return $this->cache( $result );
		}

		$signature = isset( $manifest['manifest_signature'] ) ? trim( (string) $manifest['manifest_signature'] ) : '';
		$key_id    = isset( $manifest['key_id'] ) ? sanitize_text_field( (string) $manifest['key_id'] ) : '';
		$key       = SigningKeyStore::public_key( 'update', $key_id );
		if ( '' === $signature || '' === $key_id || '' === $key || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$result['valid']    = false;
			$result['errors'][] = 'manifest_signature_unavailable';
			return $this->cache( $result );
		}

		$signed = $manifest;
		unset( $signed['manifest_signature'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode cryptographic public-key/signature bytes for integrity verification; decoded data is never evaluated as code.
		$signature_raw = base64_decode( $signature, true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode cryptographic public-key/signature bytes for integrity verification; decoded data is never evaluated as code.
		$public_raw = base64_decode( $key, true );
		if (
			false === $signature_raw ||
			false === $public_raw ||
			64 !== strlen( $signature_raw ) ||
			32 !== strlen( $public_raw ) ||
			! sodium_crypto_sign_verify_detached( $signature_raw, SignedPayload::canonical_json( $signed ), $public_raw )
		) {
			$result['valid']    = false;
			$result['errors'][] = 'manifest_signature_invalid';
			return $this->cache( $result );
		}

		$build = isset( $manifest['build'] ) ? (string) $manifest['build'] : '';
		if ( '' === $build || ! defined( 'NBH_PERFORMANCE_BUILD' ) || ! hash_equals( (string) NBH_PERFORMANCE_BUILD, $build ) ) {
			$result['valid']    = false;
			$result['errors'][] = 'build_identity_mismatch';
		}
		if ( 'sha256' !== strtolower( isset( $manifest['algorithm'] ) ? (string) $manifest['algorithm'] : '' ) ) {
			$result['valid']    = false;
			$result['errors'][] = 'manifest_algorithm_invalid';
		}

		$files = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();
		if ( count( $files ) < 10 ) {
			$result['valid']    = false;
			$result['errors'][] = 'manifest_file_set_incomplete';
		}
		foreach ( array( 'nbh-performance.php', 'Modules/Core/Plugin.php', 'Modules/Licensing/SigningKeyStore.php', 'build-provenance.json', 'sbom.cyclonedx.json' ) as $required ) {
			if ( ! isset( $files[ $required ] ) ) {
				$result['missing'][] = $required;
			}
		}

		foreach ( $files as $relative => $hash ) {
			$relative = $this->safe_relative_path( $relative );
			if ( '' === $relative || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $hash ) ) {
				$result['modified'][] = (string) $relative;
				continue;
			}
			$target = NBH_PERFORMANCE_PATH . $relative;
			if ( ! is_file( $target ) || ! is_readable( $target ) ) {
				$result['missing'][] = $relative;
				continue;
			}
			$actual = hash_file( 'sha256', $target );
			if ( ! is_string( $actual ) || ! hash_equals( strtolower( (string) $hash ), strtolower( $actual ) ) ) {
				$result['modified'][] = $relative;
			}
		}

		$this->verify_packaged_file_set( $result, $files );

		$this->verify_release_identity( $result, $build );
		$result['missing']    = array_values( array_unique( array_filter( $result['missing'] ) ) );
		$result['modified']   = array_values( array_unique( array_filter( $result['modified'] ) ) );
		$result['unexpected'] = array_values( array_unique( array_filter( $result['unexpected'] ) ) );
		$result['errors']     = array_values( array_unique( array_filter( $result['errors'] ) ) );
		$result['valid']      = $result['valid'] && empty( $result['missing'] ) && empty( $result['modified'] ) && empty( $result['unexpected'] ) && empty( $result['errors'] );
		return $this->cache( $result );
	}


	/**
	 * Detect files and symbolic links that are not part of the signed package.
	 *
	 * @param array<string,mixed>  $result Verification result.
	 * @param array<string,string> $files  Signed file map.
	 */
	private function verify_packaged_file_set( array &$result, array $files ) {
		$expected                   = array_fill_keys( array_keys( $files ), true );
		$expected[ self::MANIFEST ] = true;
		$root                       = wp_normalize_path( untrailingslashit( NBH_PERFORMANCE_PATH ) );
		$iterator                   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $entry ) {
			$path     = wp_normalize_path( $entry->getPathname() );
			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			$relative = $this->safe_relative_path( $relative );
			if ( '' === $relative ) {
				$result['unexpected'][] = '[invalid-path]';
				continue;
			}
			if ( $entry->isLink() || ! $entry->isFile() || ! isset( $expected[ $relative ] ) ) {
				$result['unexpected'][] = $relative;
			}
		}
	}

	/** @param array<string,mixed> $result Result. @param string $build Build. */
	private function verify_release_identity( array &$result, $build ) {
		$provenance_file = NBH_PERFORMANCE_PATH . 'build-provenance.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$provenance = is_readable( $provenance_file ) ? json_decode( (string) \NBH\Performance\Modules\Core\Filesystem::get_contents( $provenance_file ), true ) : null;
		if ( ! is_array( $provenance ) || empty( $provenance['buildId'] ) || ! hash_equals( $build, (string) $provenance['buildId'] ) ) {
			$result['errors'][] = 'provenance_build_mismatch';
		}

		$sbom_file = NBH_PERFORMANCE_PATH . 'sbom.cyclonedx.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sbom       = is_readable( $sbom_file ) ? json_decode( (string) \NBH\Performance\Modules\Core\Filesystem::get_contents( $sbom_file ), true ) : null;
		$sbom_build = '';
		if ( is_array( $sbom ) && ! empty( $sbom['metadata']['properties'] ) && is_array( $sbom['metadata']['properties'] ) ) {
			foreach ( $sbom['metadata']['properties'] as $property ) {
				if ( isset( $property['name'], $property['value'] ) && 'nbh-performance:build-id' === $property['name'] ) {
					$sbom_build = (string) $property['value'];
					break;
				}
			}
		}
		if ( '' === $sbom_build || ! hash_equals( $build, $sbom_build ) ) {
			$result['errors'][] = 'sbom_build_mismatch';
		}
	}

	private function safe_relative_path( $relative ) {
		$relative = str_replace( '\\', '/', trim( (string) $relative ) );
		if ( '' === $relative || 0 === strpos( $relative, '/' ) || false !== strpos( $relative, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) || preg_match( '#^[A-Za-z]:#', $relative ) ) {
			return '';
		}
		return ltrim( $relative, '/' );
	}

	/** @param array<string,mixed> $result Result. @return array<string,mixed> */
	private function cache( array $result ) {
		set_transient( self::TRANSIENT, $result, HOUR_IN_SECONDS );
		return $result;
	}
}
