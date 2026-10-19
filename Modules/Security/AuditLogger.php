<?php
/** Privacy-aware append-only security audit log. @package NBHPerformance */
namespace NBH\Performance\Modules\Security;

final class AuditLogger {
	const OPTION = 'nbh_performance_security_audit_log';
	const LIMIT  = 250;
	public function log( $event, array $context = array(), $severity = 'info' ) {
		$this->record( $event, $context, $severity );
	}
	public function record( $event, array $context = array(), $severity = 'info' ) {
		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			'id'        => wp_generate_uuid4(),
			'timestamp' => time(),
			'event'     => sanitize_key( $event ),
			'severity'  => in_array( $severity, array( 'info', 'warning', 'critical' ), true ) ? $severity : 'info',
			'user_id'   => get_current_user_id(),
			'context'   => $this->sanitize_context( $context ),
		);
		if ( count( $entries ) > self::LIMIT ) {
			$entries = array_slice( $entries, -1 * self::LIMIT );
		}
		update_option( self::OPTION, $entries, false );
	}
	private function sanitize_context( array $context ) {
		$blocked = array( 'license', 'license_key', 'password', 'token', 'secret', 'authorization', 'cookie' );
		$clean   = array();
		foreach ( $context as $key => $value ) {
			$key           = sanitize_key( $key );
			$clean[ $key ] = in_array( $key, $blocked, true ) ? '[redacted]' : ( is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '[complex]' );
		}
		return $clean;
	}
}
