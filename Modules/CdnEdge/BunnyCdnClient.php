<?php
/** BunnyCDN API integration. @package NBHPerformance */
namespace NBH\Performance\Modules\CdnEdge;

use WP_Error;

final class BunnyCdnClient extends AbstractApiClient implements EdgeProviderInterface {
	public function id() {
		return 'bunnycdn';
	}

	public function is_configured() {
		return $this->pull_zone_id() > 0 && '' !== $this->api_key();
	}

	public function purge_all() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'nbh_performance_bunny_not_configured', __( 'BunnyCDN is not configured.', 'nbh-performance' ) );
		}
		$result = $this->request(
			'https://api.bunny.net/pullzone/' . $this->pull_zone_id() . '/purgeCache',
			array(
				'method'  => 'POST',
				'headers' => array(
					'AccessKey' => $this->api_key(),
					'Accept'    => 'application/json',
				),
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	public function purge_urls( array $urls ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'nbh_performance_bunny_not_configured', __( 'BunnyCDN is not configured.', 'nbh-performance' ) );
		}
		$urls = $this->clean_urls( $urls );
		if ( empty( $urls ) ) {
			return new WP_Error( 'nbh_performance_no_edge_urls', __( 'No valid URLs were supplied.', 'nbh-performance' ) );
		}
		$errors = array();
		foreach ( $urls as $url ) {
			$result = $this->request(
				'https://api.bunny.net/purge?url=' . rawurlencode( $url ) . '&async=true',
				array(
					'method'  => 'POST',
					'headers' => array(
						'AccessKey' => $this->api_key(),
						'Accept'    => 'application/json',
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}
		return empty( $errors ) ? true : new WP_Error( 'nbh_performance_bunny_partial_failure', implode( '; ', array_unique( $errors ) ) );
	}

	/**
	 * @param string $api_key Optional key override.
	 * @return true|WP_Error
	 */
	public function test_connection( $api_key = '' ) {
		$api_key = '' !== trim( (string) $api_key ) ? trim( (string) $api_key ) : $this->api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'nbh_performance_bunny_key_missing', __( 'Enter a Bunny CDN AccessKey first.', 'nbh-performance' ) );
		}
		if ( $this->pull_zone_id() > 0 ) {
			$result = $this->request(
				'https://api.bunny.net/pullzone/' . $this->pull_zone_id(),
				array(
					'method'  => 'GET',
					'headers' => array(
						'AccessKey' => $api_key,
						'Accept'    => 'application/json',
					),
				)
			);
			return is_wp_error( $result ) ? $result : true;
		}
		$zones = $this->discover_pull_zones( $api_key );
		return is_wp_error( $zones ) ? $zones : true;
	}

	/**
	 * @param string $api_key Access key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function discover_pull_zones( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( '' === $api_key ) {
			return new WP_Error( 'nbh_performance_bunny_key_missing', __( 'Enter a Bunny CDN AccessKey first.', 'nbh-performance' ) );
		}
		$result = $this->request(
			'https://api.bunny.net/pullzone?page=1&perPage=100&includeCertificate=false',
			array(
				'method'  => 'GET',
				'headers' => array(
					'AccessKey' => $api_key,
					'Accept'    => 'application/json',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items           = array();
		$raw             = isset( $result['Items'] ) && is_array( $result['Items'] ) ? $result['Items'] : ( is_array( $result ) && isset( $result[0] ) ? $result : array() );
		$host_candidates = $this->site_host_candidates();
		$cdn_host        = strtolower( (string) wp_parse_url( (string) get_option( 'nbh_performance_cdn_base_url', '' ), PHP_URL_HOST ) );
		foreach ( $raw as $zone ) {
			if ( ! is_array( $zone ) || empty( $zone['Id'] ) ) {
				continue;
			}
			$id        = absint( $zone['Id'] );
			$name      = sanitize_text_field( (string) ( isset( $zone['Name'] ) ? $zone['Name'] : '' ) );
			$hostnames = array();
			if ( ! empty( $zone['Hostnames'] ) && is_array( $zone['Hostnames'] ) ) {
				foreach ( $zone['Hostnames'] as $row ) {
					if ( is_array( $row ) && ! empty( $row['Value'] ) ) {
						$hostnames[] = strtolower( sanitize_text_field( (string) $row['Value'] ) );
					}
				}
			}
			$cdn_url = '';
			if ( ! empty( $hostnames[0] ) ) {
				$cdn_url = 'https://' . $hostnames[0];
			} elseif ( $name ) {
				$cdn_url = 'https://' . $name . '.b-cdn.net';
			}
			$score = 0;
			foreach ( $hostnames as $hostname ) {
				if ( $cdn_host && false !== strpos( $hostname, $cdn_host ) ) {
					$score += 10;
				}
				foreach ( $host_candidates as $candidate ) {
					if ( false !== strpos( $hostname, $candidate ) || false !== strpos( $name, $candidate ) ) {
						$score += 5;
					}
				}
			}
			$items[] = array(
				'id'        => $id,
				'name'      => $name,
				'hostnames' => $hostnames,
				'cdn_url'   => $cdn_url,
				'score'     => $score,
			);
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'nbh_performance_bunny_zone_not_found', __( 'No Bunny pull zones were found for this AccessKey.', 'nbh-performance' ) );
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['score'] - (int) $a['score'];
			}
		);
		return $items;
	}

	private function pull_zone_id() {
		return absint( get_option( 'nbh_performance_bunny_pull_zone_id', 0 ) );
	}

	private function api_key() {
		return trim( (string) get_option( 'nbh_performance_bunny_api_key', '' ) );
	}
}
