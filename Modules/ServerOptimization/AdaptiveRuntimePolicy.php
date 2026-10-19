<?php
/** Adaptive runtime limits for heterogeneous hosting. @package NBHPerformance */
namespace NBH\Performance\Modules\ServerOptimization;

use NBH\Performance\Modules\Core\Module;

final class AdaptiveRuntimePolicy implements Module {
	private $detector;
	private $profile;
	public function __construct( HostingEnvironmentDetector $detector ) {
		$this->detector = $detector;
	}
	public function register() {
		add_filter( 'nbh_performance_preload_batch_size', array( $this, 'preload_batch' ), 5 );
		add_filter( 'nbh_performance_critical_css_concurrency', array( $this, 'critical_css_workers' ), 5 );
		add_filter( 'nbh_performance_max_asset_bytes', array( $this, 'asset_size' ), 5 );
		add_filter( 'nbh_performance_image_max_source_bytes', array( $this, 'image_size' ), 5 );
		add_filter( 'nbh_performance_rum_rate_limit', array( $this, 'rum_rate' ), 5 );
		add_filter( 'nbh_performance_should_combine_assets', array( $this, 'asset_combining' ), 5, 2 );
		add_filter( 'nbh_performance_runtime_config', array( $this, 'runtime_config' ), 5 );
		add_filter( 'nbh_performance_server_environment', array( $this, 'environment' ) );
	}
	/** @return array<string,mixed> */
	public function environment() {
		return $this->profile();
	}
	private function profile() {
		if ( null === $this->profile ) {
			$this->profile = $this->detector->detect();
		}
		return $this->profile;
	}
	private function mode() {
		$p = $this->profile();
		return isset( $p['recommend_mode'] ) ? $p['recommend_mode'] : 'balanced';
	}
	public function preload_batch( $value ) {
		$caps = array(
			'conservative'   => 1,
			'provider_aware' => 2,
			'balanced'       => 3,
			'high_capacity'  => 8,
		);
		return max( 1, min( (int) $value, $caps[ $this->mode() ] ) );
	}
	public function critical_css_workers( $value ) {
		$caps = array(
			'conservative'   => 1,
			'provider_aware' => 1,
			'balanced'       => 2,
			'high_capacity'  => 3,
		);
		return max( 1, min( (int) $value, $caps[ $this->mode() ] ) );
	}
	public function asset_size( $value ) {
		$caps = array(
			'conservative'   => 1048576,
			'provider_aware' => 1572864,
			'balanced'       => 2097152,
			'high_capacity'  => 4194304,
		);
		return min( (int) $value, $caps[ $this->mode() ] );
	}
	public function image_size( $value ) {
		$caps = array(
			'conservative'   => 8388608,
			'provider_aware' => 16777216,
			'balanced'       => 26214400,
			'high_capacity'  => 52428800,
		);
		return min( (int) $value, $caps[ $this->mode() ] );
	}
	public function rum_rate( $value ) {
		$caps = array(
			'conservative'   => 15,
			'provider_aware' => 20,
			'balanced'       => 30,
			'high_capacity'  => 60,
		);
		return max( 5, min( (int) $value, $caps[ $this->mode() ] ) );
	}
	public function asset_combining( $allowed, $protocol ) {
		$p = $this->profile();
		if ( ! empty( $p['proxy']['cloudflare'] ) || ! empty( $p['proxy']['fastly'] ) || 'provider_aware' === $this->mode() ) {
			return false;
		}
		return $allowed;
	}
	public function runtime_config( $config ) {
		$p                             = $this->profile();
		$config['hosting_mode']        = $this->mode();
		$config['server_family']       = $p['server_family'];
		$config['managed_provider']    = $p['provider'];
		$config['external_cache']      = ! empty( $p['object_cache']['external'] );
		$config['proxy_cache_present'] = ! empty( $p['proxy']['varnish'] ) || ! empty( $p['proxy']['cloudflare'] ) || ! empty( $p['proxy']['fastly'] );
		return $config;
	}
}
