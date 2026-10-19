<?php
/** Version-aware compatibility registry and confidence reporting. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility;

final class IntegrationRegistry {
	private $items = array();

	public function add( array $profile ) {
		$id = isset( $profile['id'] ) ? sanitize_key( $profile['id'] ) : '';
		if ( '' === $id ) {
			return;
		}
		$defaults               = array(
			'id'             => $id,
			'name'           => $id,
			'category'       => 'plugin',
			'active'         => false,
			'version'        => '',
			'min_version'    => '',
			'tested_through' => '',
			'confidence'     => 0,
			'status'         => 'inactive',
			'features'       => array(),
			'limitations'    => array(),
			'last_verified'  => '',
		);
		$profile                = wp_parse_args( $profile, $defaults );
		$profile['id']          = $id;
		$profile['confidence']  = max( 0, min( 100, absint( $profile['confidence'] ) ) );
		$profile['features']    = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $profile['features'] ) ) ) );
		$profile['limitations'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $profile['limitations'] ) ) );
		$this->items[ $id ]     = $profile;
	}

	public function all() {
		return array_values( $this->items );
	}
	public function active() {
		return array_values(
			array_filter(
				$this->items,
				static function ( $item ) {
					return ! empty( $item['active'] );
				}
			)
		);
	}
	public function get( $id ) {
		$id = sanitize_key( $id );
		return isset( $this->items[ $id ] ) ? $this->items[ $id ] : null;
	}
	public function summary() {
		$active      = $this->active();
		$warnings    = 0;
		$unsupported = 0;
		foreach ( $active as $item ) {
			if ( 'warning' === $item['status'] ) {
				++$warnings;
			}
			if ( 'unsupported' === $item['status'] ) {
				++$unsupported;
			}
		}
		return array(
			'registered'  => count( $this->items ),
			'active'      => count( $active ),
			'warnings'    => $warnings,
			'unsupported' => $unsupported,
		);
	}
}
