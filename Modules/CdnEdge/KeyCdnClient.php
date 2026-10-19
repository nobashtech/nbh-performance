<?php
/** KeyCDN API integration. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

use WP_Error;

final class KeyCdnClient extends AbstractApiClient implements EdgeProviderInterface {
	public function id() {
		return 'keycdn';
	}

	public function is_configured() {
		return $this->zone_id() > 0 && '' !== $this->api_key();
	}

	public function purge_all() {
		return $this->send( 'purgezone.json', array( 'zone_id' => $this->zone_id() ) );
	}

	public function purge_urls( array $urls ) {
		$urls = $this->clean_urls( $urls );
		return empty( $urls )
			? new WP_Error( 'nbh_performance_no_edge_urls', __( 'No valid URLs were supplied.', 'nbh-performance' ) )
			: $this->send(
				'purgeurl.json',
				array(
					'zone_id' => $this->zone_id(),
					'urls'    => $urls,
				)
			);
	}

	/**
	 * @param string $api_key Optional key override.
	 * @return true|WP_Error
	 */
	public function test_connection( $api_key = '' ) {
		$api_key = '' !== trim( (string) $api_key ) ? trim( (string) $api_key ) : $this->api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'nbh_performance_keycdn_key_missing', __( 'Enter a KeyCDN API key first.', 'nbh-performance' ) );
		}
		$zones = $this->discover_zones( $api_key );
		return is_wp_error( $zones ) ? $zones : true;
	}

	/**
	 * @param string $api_key API key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function discover_zones( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( '' === $api_key ) {
			return new WP_Error( 'nbh_performance_keycdn_key_missing', __( 'Enter a KeyCDN API key first.', 'nbh-performance' ) );
		}
		$result = $this->request(
			'https://api.keycdn.com/zones.json',
			array(
				'method'  => 'GET',
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is required by HTTP Basic authentication for the selected KeyCDN API; no executable content is encoded.
					'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['status'] ) && 'success' !== $result['status'] ) {
			$message = ! empty( $result['description'] ) ? (string) $result['description'] : __( 'KeyCDN zone discovery failed.', 'nbh-performance' );
			return new WP_Error( 'nbh_performance_keycdn_rejected', $message, $result );
		}
		$data            = isset( $result['data']['zones'] ) && is_array( $result['data']['zones'] ) ? $result['data']['zones'] : array();
		$items           = array();
		$host_candidates = $this->site_host_candidates();
		$cdn_host        = strtolower( (string) wp_parse_url( (string) get_option( 'nbh_performance_cdn_base_url', '' ), PHP_URL_HOST ) );
		foreach ( $data as $zone ) {
			if ( ! is_array( $zone ) || empty( $zone['id'] ) ) {
				continue;
			}
			$id        = absint( $zone['id'] );
			$name      = sanitize_text_field( (string) ( isset( $zone['name'] ) ? $zone['name'] : '' ) );
			$zone_url  = esc_url_raw( (string) ( isset( $zone['zoneurl'] ) ? $zone['zoneurl'] : ( isset( $zone['url'] ) ? $zone['url'] : '' ) ) );
			$zone_host = strtolower( (string) wp_parse_url( $zone_url, PHP_URL_HOST ) );
			$score     = 0;
			if ( $cdn_host && $zone_host && false !== strpos( $zone_host, $cdn_host ) ) {
				$score += 10;
			}
			foreach ( $host_candidates as $candidate ) {
				if ( false !== strpos( $name, $candidate ) || ( $zone_host && false !== strpos( $zone_host, $candidate ) ) ) {
					$score += 5;
				}
			}
			$items[] = array(
				'id'      => $id,
				'name'    => $name,
				'cdn_url' => $zone_url ? $zone_url : ( $zone_host ? 'https://' . $zone_host : '' ),
				'score'   => $score,
			);
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'nbh_performance_keycdn_zone_not_found', __( 'No KeyCDN zones were found for this API key.', 'nbh-performance' ) );
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['score'] - (int) $a['score'];
			}
		);
		return $items;
	}

	/**
	 * @param string               $endpoint Endpoint.
	 * @param array<string,mixed>  $payload  Payload.
	 * @return true|WP_Error
	 */
	private function send( $endpoint, array $payload ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'nbh_performance_keycdn_not_configured', __( 'KeyCDN is not configured.', 'nbh-performance' ) );
		}
		$result = $this->request(
			'https://api.keycdn.com/zones/' . ltrim( (string) $endpoint, '/' ),
			array(
				'method'  => 'POST',
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is required by HTTP Basic authentication for the selected KeyCDN API; no executable content is encoded.
					'Authorization' => 'Basic ' . base64_encode( $this->api_key() . ':' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['status'] ) && 'success' !== $result['status'] ) {
			$message = ! empty( $result['description'] ) ? (string) $result['description'] : __( 'KeyCDN rejected the purge request.', 'nbh-performance' );
			return new WP_Error( 'nbh_performance_keycdn_rejected', $message, $result );
		}
		return true;
	}

	private function zone_id() {
		return absint( get_option( 'nbh_performance_keycdn_zone_id', 0 ) );
	}

	private function api_key() {
		return trim( (string) get_option( 'nbh_performance_keycdn_api_key', '' ) );
	}
}
