<?php
/**
 * Lightweight dependency container.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Core;

use InvalidArgumentException;
use RuntimeException;

final class Container {
	/** @var array<string, mixed> */
	private $definitions = array();

	/** @var array<string, mixed> */
	private $resolved = array();

	/**
	 * Add a service definition.
	 *
	 * @param string   $id      Service ID.
	 * @param callable $factory Factory receiving this container.
	 */
	public function set( $id, callable $factory ) {
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Service ID cannot be empty.' );
		}

		$this->definitions[ $id ] = $factory;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service ID.
	 * @return mixed
	 */
	public function get( $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->definitions[ $id ] ) ) {
			throw new RuntimeException( sprintf( 'Unknown service: %s', esc_html( $id ) ) );
		}

		$this->resolved[ $id ] = call_user_func( $this->definitions[ $id ], $this );

		return $this->resolved[ $id ];
	}
}
