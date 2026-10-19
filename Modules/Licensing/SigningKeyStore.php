<?php
/**
 * Embedded Ed25519 public-key trust store for NBH Performance services.
 *
 * Private signing keys must never be distributed with the plugin. Key IDs
 * make controlled rotation possible without weakening signature enforcement.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Licensing;

final class SigningKeyStore {
	const UPDATE_CURRENT_KEY_ID = 'update-2026-07';

	/**
	 * Return the current key ID for a signing purpose.
	 *
	 * @param string $purpose Signing purpose (reserved for future release-signing key sets).
	 * @return string
	 */
	public static function current_key_id( $purpose ) {
		unset( $purpose );
		return self::UPDATE_CURRENT_KEY_ID;
	}

	/**
	 * Resolve a base64-encoded Ed25519 public key.
	 *
	 * Constants may add an emergency replacement key, but the embedded release
	 * key remains the default source of trust.
	 *
	 * @param string $purpose Signing purpose: update or license.
	 * @param string $key_id  Key identifier from the signed payload.
	 * @return string Empty string when the key is unknown or malformed.
	 */
	public static function public_key( $purpose, $key_id ) {
		$purpose = sanitize_key( (string) $purpose );
		$key_id  = sanitize_text_field( (string) $key_id );
		$keys    = self::keys( $purpose );

		if ( '' === $key_id ) {
			$key_id = self::current_key_id( $purpose );
		}
		if ( ! isset( $keys[ $key_id ] ) ) {
			return '';
		}

		$encoded = trim( (string) $keys[ $key_id ] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode cryptographic public-key/signature bytes for integrity verification; decoded data is never evaluated as code.
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded || 32 !== strlen( $decoded ) ) {
			return '';
		}

		return $encoded;
	}

	/**
	 * Return known public keys for a purpose.
	 *
	 * @param string $purpose Signing purpose.
	 * @return array<string,string>
	 */
	private static function keys( $purpose ) {
		unset( $purpose );
		$keys = array(
			self::UPDATE_CURRENT_KEY_ID => 'UGPJZBj+Fy6nfUDJoyuyN30ptSFVlhTNEyW4Ruty5E4=',
		);
		if ( defined( 'NBH_PERFORMANCE_UPDATE_PUBLIC_KEY' ) ) {
			$keys['configured-update-key'] = trim( (string) NBH_PERFORMANCE_UPDATE_PUBLIC_KEY );
		}
		return (array) apply_filters( 'nbh_performance_update_public_keys', $keys );
	}
}
