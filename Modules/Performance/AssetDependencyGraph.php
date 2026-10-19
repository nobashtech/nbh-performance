<?php
/** Dependency graph for queued WordPress scripts and styles. @package NBHPerformance */
namespace NBH\Performance\Modules\Performance;

final class AssetDependencyGraph {
	/** Return transitive dependents ordered deepest-first for safe unloading. */
	public function dependents( $registry, $handle ) {
		$graph = array();
		foreach ( (array) $registry->registered as $candidate => $item ) {
			foreach ( (array) $item->deps as $dependency ) {
				$graph[ sanitize_key( $dependency ) ][] = sanitize_key( $candidate );
			}
		}
		$out  = array();
		$seen = array();
		$this->walk( sanitize_key( $handle ), $graph, $seen, $out );
		return array_values( array_reverse( array_unique( $out ) ) );
	}

	/** Return direct dependency metadata for diagnostics. */
	public function metadata( $registry, $handle ) {
		$handle = sanitize_key( $handle );
		$item   = isset( $registry->registered[ $handle ] ) ? $registry->registered[ $handle ] : null;
		return array(
			'dependencies' => $item ? array_values( array_map( 'sanitize_key', (array) $item->deps ) ) : array(),
			'dependents'   => $this->dependents( $registry, $handle ),
		);
	}

	private function walk( $handle, array $graph, array &$seen, array &$out ) {
		if ( isset( $seen[ $handle ] ) ) {
			return;
		}
		$seen[ $handle ] = true;
		foreach ( isset( $graph[ $handle ] ) ? (array) $graph[ $handle ] : array() as $dependent ) {
			$this->walk( $dependent, $graph, $seen, $out );
			$out[] = $dependent;
		}
	}
}
