<?php
/** One-time confirmation tokens bound to a verified backup. @package NBHPerformance */
namespace NBH\Performance\Modules\DatabaseOptimization;

final class ConfirmationToken {
	const TTL = 600;
	public function issue( $user_id, array $operations, $backup_id ) {
		$plain = wp_generate_password( 48, false, false );
		$key   = 'nbh_performance_db_confirm_' . hash( 'sha256', $plain );
		set_transient(
			$key,
			array(
				'user_id'    => (int) $user_id,
				'operations' => array_values( $operations ),
				'backup_id'  => sanitize_key( $backup_id ),
			),
			self::TTL
		);
		return $plain;
	}
	public function consume( $token, $user_id, array $operations, $backup_id ) {
		$key  = 'nbh_performance_db_confirm_' . hash( 'sha256', (string) $token );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) || (int) $data['user_id'] !== (int) $user_id || ! hash_equals( (string) ( $data['backup_id'] ?? '' ), sanitize_key( $backup_id ) ) ) {
			return false;
		}
		$expected = array_values( array_unique( array_map( 'sanitize_key', $data['operations'] ) ) );
		$actual   = array_values( array_unique( array_map( 'sanitize_key', $operations ) ) );
		sort( $expected );
		sort( $actual );
		return $expected === $actual;
	}
}
