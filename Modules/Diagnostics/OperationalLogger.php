<?php
/** Structured, privacy-aware operational event log. @package NBHPerformance */
namespace NBH\Performance\Modules\Diagnostics;

final class OperationalLogger {
	const OPTION = 'nbh_performance_operational_log';
	const LIMIT  = 500;

	public function log( $channel, $event, array $context = array(), $severity = 'info' ) {
		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$severity  = in_array( $severity, array( 'debug', 'info', 'warning', 'error', 'critical' ), true ) ? $severity : 'info';
		$entries[] = array(
			'id'        => wp_generate_uuid4(),
			'timestamp' => time(),
			'channel'   => sanitize_key( $channel ),
			'event'     => sanitize_key( $event ),
			'severity'  => $severity,
			'user_id'   => get_current_user_id(),
			'context'   => $this->redact( $context ),
		);
		if ( count( $entries ) > self::LIMIT ) {
			$entries = array_slice( $entries, -1 * self::LIMIT );
		}
		update_option( self::OPTION, $entries, false );
		return end( $entries );
	}

	public function recent( $limit = 100, $severity = '' ) {
		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? $entries : array();
		if ( '' !== $severity ) {
			$severity = sanitize_key( $severity );
			$entries  = array_values(
				array_filter(
					$entries,
					static function ( $entry ) use ( $severity ) {
						return is_array( $entry ) && isset( $entry['severity'] ) && $severity === $entry['severity'];
					}
				)
			);
		}
		return array_values( array_reverse( array_slice( $entries, -1 * max( 1, min( 250, absint( $limit ) ) ) ) ) );
	}

	public function clear() {
		delete_option( self::OPTION );
		return true;
	}

	private function redact( array $context ) {
		$blocked = array( 'password', 'pass', 'license', 'license_key', 'token', 'secret', 'authorization', 'cookie', 'api_key', 'private_key', 'signature' );
		$clean   = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $blocked, true ) || preg_match( '/(?:token|secret|password|license|authorization|cookie|key)$/i', $key ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = substr( sanitize_textarea_field( $value ), 0, 1000 );
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = $this->redact( array_slice( $value, 0, 50, true ) );
			} else {
				$clean[ $key ] = '[complex]';
			}
		}
		return $clean;
	}
}
