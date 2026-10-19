<?php
/** Versioned active/previous asset manifest storage. @package NBHPerformance */
namespace NBH\Performance\Modules\CssJsOptimization;

final class AssetManifestStore {
	const OPTION = 'nbh_performance_asset_manifests';

	/** @return array */
	public function all() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @param string $context Context. @return array */
	public function active( $context ) {
		$all = $this->all();
		return isset( $all[ $context ]['active'] ) && is_array( $all[ $context ]['active'] ) ? $all[ $context ]['active'] : array();
	}

	/** @param string $context Context. @param array $manifest Manifest. @return bool */
	public function publish( $context, array $manifest ) {
		$context = sanitize_key( $context );
		if ( '' === $context || empty( $manifest['url'] ) || empty( $manifest['file'] ) || empty( $manifest['hash'] ) ) {
			return false;
		}
		$all             = $this->all();
		$previous        = isset( $all[ $context ]['active'] ) ? $all[ $context ]['active'] : array();
		$all[ $context ] = array(
			'active'   => $manifest,
			'previous' => $previous,
			'updated'  => time(),
		);
		return update_option( self::OPTION, $all, false );
	}

	/** @param string $context Context. @return bool */
	public function rollback( $context ) {
		$context = sanitize_key( $context );
		$all     = $this->all();
		if ( empty( $all[ $context ]['previous'] ) || ! is_array( $all[ $context ]['previous'] ) ) {
			return false;
		}
		$current                     = isset( $all[ $context ]['active'] ) ? $all[ $context ]['active'] : array();
		$all[ $context ]['active']   = $all[ $context ]['previous'];
		$all[ $context ]['previous'] = $current;
		$all[ $context ]['updated']  = time();
		return update_option( self::OPTION, $all, false );
	}

	/** @param string $context Context. */
	public function clear( $context = '' ) {
		if ( '' === $context ) {
			delete_option( self::OPTION );
			return;
		}
		$all = $this->all();
		unset( $all[ sanitize_key( $context ) ] );
		update_option( self::OPTION, $all, false );
	}
}
