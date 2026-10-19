<?php
/** Cloudflare API integration. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

use WP_Error;

final class CloudflareApiClient extends AbstractApiClient implements EdgeProviderInterface {
	public function id() {
		return 'cloudflare';
	}

	public function is_configured() {
		return '' !== $this->zone_id() && '' !== $this->token();
	}

	public function purge_all() {
		return $this->send( array( 'purge_everything' => true ) );
	}

	public function purge_urls( array $urls ) {
		$urls = $this->clean_urls( $urls );
		return empty( $urls )
			? new WP_Error( 'nbh_performance_no_edge_urls', __( 'No valid URLs were supplied.', 'nbh-performance' ) )
			: $this->send( array( 'files' => $urls ) );
	}

	/**
	 * @param string $token Optional token override.
	 * @return true|WP_Error
	 */
	public function test_connection( $token = '' ) {
		$token = '' !== trim( (string) $token ) ? trim( (string) $token ) : $this->token();
		if ( '' === $token ) {
			return new WP_Error( 'nbh_performance_cloudflare_token_missing', __( 'Enter a Cloudflare API token first.', 'nbh-performance' ) );
		}
		if ( $this->zone_id() ) {
			$result = $this->request(
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $this->zone_id() ),
				array(
					'method'  => 'GET',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return ! empty( $result['success'] ) ? true : new WP_Error( 'nbh_performance_cloudflare_rejected', __( 'Cloudflare rejected the zone lookup.', 'nbh-performance' ), $result );
		}
		$zones = $this->discover_zones( $token );
		return is_wp_error( $zones ) ? $zones : true;
	}

	/**
	 * @param string $token API token.
	 * @return array<int,array<string,string>>|WP_Error
	 */
	public function discover_zones( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'nbh_performance_cloudflare_token_missing', __( 'Enter a Cloudflare API token first.', 'nbh-performance' ) );
		}
		$matches = array();
		foreach ( $this->site_host_candidates() as $name ) {
			$result = $this->request(
				'https://api.cloudflare.com/client/v4/zones?name=' . rawurlencode( $name ) . '&status=active&per_page=50',
				array(
					'method'  => 'GET',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['success'] ) ) {
				$message = ! empty( $result['errors'][0]['message'] ) ? (string) $result['errors'][0]['message'] : __( 'Cloudflare zone discovery failed.', 'nbh-performance' );
				return new WP_Error( 'nbh_performance_cloudflare_rejected', $message, $result );
			}
			foreach ( (array) ( isset( $result['result'] ) ? $result['result'] : array() ) as $zone ) {
				if ( ! is_array( $zone ) || empty( $zone['id'] ) ) {
					continue;
				}
				$id = preg_replace( '/[^a-f0-9]/i', '', (string) $zone['id'] );
				if ( '' === $id ) {
					continue;
				}
				$matches[ $id ] = array(
					'id'     => $id,
					'name'   => sanitize_text_field( (string) ( isset( $zone['name'] ) ? $zone['name'] : '' ) ),
					'status' => sanitize_key( (string) ( isset( $zone['status'] ) ? $zone['status'] : '' ) ),
				);
			}
		}
		if ( empty( $matches ) ) {
			return new WP_Error( 'nbh_performance_cloudflare_zone_not_found', __( 'No active Cloudflare zone matched this site hostname. Enter the Zone ID manually.', 'nbh-performance' ) );
		}
		return array_values( $matches );
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 * @return true|WP_Error
	 */
	private function send( array $payload ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'nbh_performance_cloudflare_not_configured', __( 'Cloudflare is not configured.', 'nbh-performance' ) );
		}
		$url    = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $this->zone_id() ) . '/purge_cache';
		$result = $this->request(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['success'] ) ) {
			return true;
		}
		$message = ! empty( $result['errors'][0]['message'] ) ? (string) $result['errors'][0]['message'] : __( 'Cloudflare rejected the purge request.', 'nbh-performance' );
		return new WP_Error( 'nbh_performance_cloudflare_rejected', $message, $result );
	}

	private function zone_id() {
		return preg_replace( '/[^a-f0-9]/i', '', (string) get_option( 'nbh_performance_cloudflare_zone_id', '' ) );
	}

	private function token() {
		return trim( (string) get_option( 'nbh_performance_cloudflare_api_token', '' ) );
	}
}
