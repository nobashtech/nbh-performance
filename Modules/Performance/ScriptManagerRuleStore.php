<?php
/**
 * Versioned persistence for Script Manager rules.
 *
 * @package NBHPerformance
 */
namespace NBH\Performance\Modules\Performance;

final class ScriptManagerRuleStore {
	const OPTION         = 'nbh_performance_script_manager_rules';
	const HISTORY_OPTION = 'nbh_performance_script_manager_history';
	const MAX_RULES      = 1000;
	const MAX_HISTORY    = 20;

	/** Return normalized rule records as stored. */
	public function all() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	/** Replace all rules and preserve a bounded rollback snapshot. */
	public function replace( array $rules, $reason = 'manual_save' ) {
		$current = $this->all();
		if ( $current !== $rules ) {
			$this->snapshot( $current, $reason );
		}
		update_option( self::OPTION, array_slice( array_values( $rules ), 0, self::MAX_RULES ), false );
		return $this->all();
	}

	/** Return rule history newest-first. */
	public function history() {
		$history = get_option( self::HISTORY_OPTION, array() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/** Restore a prior snapshot by identifier. */
	public function restore( $snapshot_id ) {
		$snapshot_id = sanitize_key( $snapshot_id );
		foreach ( $this->history() as $snapshot ) {
			if ( isset( $snapshot['id'], $snapshot['rules'] ) && $snapshot_id === $snapshot['id'] && is_array( $snapshot['rules'] ) ) {
				$this->snapshot( $this->all(), 'before_restore' );
				update_option( self::OPTION, array_slice( array_values( $snapshot['rules'] ), 0, self::MAX_RULES ), false );
				return $this->all();
			}
		}
		return null;
	}

	/** Build a portable export payload. */
	public function export_payload() {
		return array(
			'product'      => 'NBH Performance',
			'schema'       => 3,
			'generated_at' => gmdate( 'c' ),
			'rules'        => $this->all(),
		);
	}

	private function snapshot( array $rules, $reason ) {
		$history   = $this->history();
		$history[] = array(
			'id'         => substr( hash( 'sha256', wp_json_encode( $rules ) . microtime( true ) . wp_rand() ), 0, 24 ),
			'created_at' => time(),
			'reason'     => sanitize_key( $reason ),
			'rule_count' => count( $rules ),
			'rules'      => $rules,
		);
		$history   = array_slice( $history, -self::MAX_HISTORY );
		update_option( self::HISTORY_OPTION, $history, false );
	}
}
