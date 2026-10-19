<?php
/**
 * Multisite portfolio visibility, network-safe profiles, and branded reports.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\Enterprise;

use NBH\Performance\Modules\Core\Module;
use NBH\Performance\Modules\Jobs\JobStore;
use NBH\Performance\Modules\Licensing\LicenseManager;
use NBH\Performance\Modules\Security\AuditLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class AgencyPortfolioManager implements Module {
	/** @var AuditLogger */
	private $audit;

	/** @var LicenseManager */
	private $licenses;

	public function __construct( AuditLogger $audit, LicenseManager $licenses ) {
		$this->audit    = $audit;
		$this->licenses = $licenses;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'network_admin_menu', array( $this, 'network_menu' ) );
	}

	public function routes() {
		$permission = array( $this, 'permission' );
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/portfolio',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_portfolio' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/portfolio/profile',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_apply_profile' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/portfolio/profile/restore',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_restore_profile' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_report' ),
				'permission_callback' => $permission,
			)
		);
		nbh_performance_register_rest_route(
			'nbh-performance/v1',
			'/enterprise/brand',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rest_brand' ),
				'permission_callback' => $permission,
			)
		);
	}

	/** @return bool */
	public function permission() {
		$capable = is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
		return $capable;
	}

	public function network_menu() {
		if ( is_multisite() ) {
			add_menu_page(
				__( 'NBH Performance Portfolio', 'nbh-performance' ),
				__( 'NBH Performance', 'nbh-performance' ),
				'manage_network_options',
				'nbh-performance-portfolio',
				array( $this, 'render_network_page' ),
				'dashicons-networking',
				81
			);
		}
	}

	public function render_network_page() {
		if ( ! $this->permission() ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'NBH Performance Portfolio', 'nbh-performance' ) . '</h1><p>' . esc_html__( 'Use the NBH Performance dashboard or REST portfolio endpoints to review site health, deploy guarded profiles, and export reports.', 'nbh-performance' ) . '</p></div>';
	}

	/** @return \WP_REST_Response */
	public function rest_portfolio() {
		return rest_ensure_response( $this->portfolio() );
	}

	/** @return array<string,mixed> */
	public function portfolio() {
		$sites = array();
		if ( is_multisite() ) {
			$site_objects = get_sites(
				array(
					'number'   => 100,
					'deleted'  => 0,
					'archived' => 0,
					'spam'     => 0,
				)
			);
			foreach ( $site_objects as $site ) {
				$sites[] = $this->site_status( (int) $site->blog_id );
			}
		} else {
			$sites[] = $this->site_status( get_current_blog_id() );
		}
		$healthy = count(
			array_filter(
				$sites,
				static function ( $site ) {
					return ! empty( $site['healthy'] );
				}
			)
		);
		return array(
			'network'      => is_multisite(),
			'sites'        => $sites,
			'summary'      => array(
				'total'     => count( $sites ),
				'healthy'   => $healthy,
				'attention' => count( $sites ) - $healthy,
			),
			'generated_at' => time(),
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_apply_profile( WP_REST_Request $request ) {
		$profile  = sanitize_key( (string) $request->get_param( 'profile' ) );
		$profiles = $this->profiles();
		if ( ! isset( $profiles[ $profile ] ) ) {
			return new WP_Error( 'nbh_performance_portfolio_profile_invalid', __( 'Select a valid guarded profile.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'site_ids' ) ) ) ) );
		if ( empty( $ids ) ) {
			$ids[] = get_current_blog_id();
		}
		if ( count( $ids ) > 50 ) {
			return new WP_Error( 'nbh_performance_portfolio_site_limit', __( 'A maximum of 50 sites can be updated per request.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$results = array();
		foreach ( $ids as $blog_id ) {
			if ( is_multisite() && ! get_site( $blog_id ) ) {
				continue;
			}
			if ( is_multisite() ) {
				switch_to_blog( $blog_id );
			}
			$snapshot = array();
			foreach ( $profiles[ $profile ] as $option => $value ) {
				$snapshot[ $option ] = get_option( $option, null );
				update_option( $option, $value, false );
			}
			update_option(
				'nbh_performance_network_profile_snapshot',
				array(
					'profile'    => $profile,
					'created_at' => time(),
					'values'     => $snapshot,
				),
				false
			);
			update_option( 'nbh_performance_network_profile', $profile, false );
			$results[] = array(
				'site_id' => $blog_id,
				'success' => true,
			);
			if ( is_multisite() ) {
				restore_current_blog();
			}
		}
		$this->audit->record(
			'portfolio_profile_applied',
			array(
				'profile'    => $profile,
				'site_count' => count( $results ),
			),
			'warning'
		);
		return rest_ensure_response(
			array(
				'success' => true,
				'profile' => $profile,
				'results' => $results,
			)
		);
	}


	/** Restore the latest network-profile snapshot on selected sites.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function rest_restore_profile( WP_REST_Request $request ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'site_ids' ) ) ) ) );
		if ( empty( $ids ) ) {
			$ids[] = get_current_blog_id();
		}
		if ( count( $ids ) > 50 ) {
			return new WP_Error( 'nbh_performance_portfolio_site_limit', __( 'A maximum of 50 sites can be restored per request.', 'nbh-performance' ), array( 'status' => 400 ) );
		}
		$results = array();
		foreach ( $ids as $blog_id ) {
			if ( is_multisite() && ! get_site( $blog_id ) ) {
				$results[] = array(
					'site_id' => $blog_id,
					'success' => false,
					'message' => __( 'Site not found.', 'nbh-performance' ),
				);
				continue;
			}
			if ( is_multisite() ) {
				switch_to_blog( $blog_id );
			}
			$snapshot = get_option( 'nbh_performance_network_profile_snapshot', array() );
			$values   = is_array( $snapshot ) && isset( $snapshot['values'] ) && is_array( $snapshot['values'] ) ? $snapshot['values'] : array();
			if ( empty( $values ) ) {
				$results[] = array(
					'site_id' => $blog_id,
					'success' => false,
					'message' => __( 'No profile snapshot is available.', 'nbh-performance' ),
				);
			} else {
				foreach ( $values as $option => $value ) {
					$option = sanitize_key( (string) $option );
					if ( 0 !== strpos( $option, 'nbh_performance_' ) ) {
						continue;
					}
					if ( null === $value ) {
						delete_option( $option );
					} else {
						update_option( $option, $value, false );
					}
				}
				delete_option( 'nbh_performance_network_profile' );
				$results[] = array(
					'site_id' => $blog_id,
					'success' => true,
				);
			}
			if ( is_multisite() ) {
				restore_current_blog();
			}
		}
		$successes = count(
			array_filter(
				$results,
				static function ( $result ) {
					return ! empty( $result['success'] );
				}
			)
		);
		$this->audit->record( 'portfolio_profile_restored', array( 'site_count' => $successes ), 'warning' );
		return rest_ensure_response(
			array(
				'success' => $successes > 0,
				'results' => $results,
			)
		);
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response|WP_Error */
	public function rest_report( WP_REST_Request $request ) {
		if ( ! get_option( 'nbh_performance_agency_reports_enabled', 1 ) ) {
			return new WP_Error( 'nbh_performance_agency_reports_disabled', __( 'Agency portfolio reports are disabled.', 'nbh-performance' ), array( 'status' => 403 ) );
		}
		$portfolio = $this->portfolio();
		$brand     = $this->brand();
		$report    = array(
			'title'        => $brand['report_title'],
			'company'      => $brand['company'],
			'logo_url'     => $brand['logo_url'],
			'generated_at' => time(),
			'portfolio'    => $portfolio,
			'footer'       => $brand['footer'],
		);
		if ( 'html' === sanitize_key( (string) $request->get_param( 'format' ) ) ) {
			$report['html'] = $this->report_html( $report );
		}
		return rest_ensure_response( $report );
	}

	/** @param WP_REST_Request $request Request. @return \WP_REST_Response */
	public function rest_brand( WP_REST_Request $request ) {
		$brand   = array(
			'company'      => sanitize_text_field( (string) $request->get_param( 'company' ) ),
			'report_title' => sanitize_text_field( (string) $request->get_param( 'report_title' ) ),
			'footer'       => sanitize_text_field( (string) $request->get_param( 'footer' ) ),
			'logo_url'     => esc_url_raw( (string) $request->get_param( 'logo_url' ) ),
		);
		$current = array_merge(
			$this->brand(),
			array_filter(
				$brand,
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);
		update_option( 'nbh_performance_agency_brand', $current, false );
		update_option( 'nbh_performance_agency_company', $current['company'], false );
		update_option( 'nbh_performance_agency_report_title', $current['report_title'], false );
		update_option( 'nbh_performance_agency_footer', $current['footer'], false );
		update_option( 'nbh_performance_agency_logo_url', $current['logo_url'], false );
		return rest_ensure_response(
			array(
				'success' => true,
				'brand'   => $this->brand(),
			)
		);
	}

	/** @param int $blog_id Blog ID. @return array<string,mixed> */
	private function site_status( $blog_id ) {
		$switched = is_multisite() && get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		$jobs              = ( new JobStore() )->stats();
		$regressions       = (array) get_option( 'nbh_performance_performance_regression_alerts', array() );
		$conflicts         = (array) get_option( 'nbh_performance_compatibility_matrix', array() );
		$failed_jobs       = absint( isset( $jobs['failed'] ) ? $jobs['failed'] : 0 );
		$status            = array(
			'site_id'             => $blog_id,
			'name'                => get_bloginfo( 'name' ),
			'url'                 => home_url( '/' ),
			'version'             => (string) get_option( 'nbh_performance_version', '' ),
			'page_cache_enabled'  => (bool) get_option( 'nbh_performance_cache_enabled', 0 ),
			'safe_mode'           => (bool) get_option( 'nbh_performance_safe_mode', 1 ),
			'cache_files'         => absint( ( (array) get_option( 'nbh_performance_cache_stats', array() ) )['files'] ?? 0 ),
			'failed_jobs'         => $failed_jobs,
			'regression_alerts'   => count( $regressions ),
			'compatibility_items' => count( $conflicts ),
			'profile'             => sanitize_key( (string) get_option( 'nbh_performance_network_profile', '' ) ),
		);
		$status['healthy'] = ! $status['safe_mode'] && 0 === $failed_jobs && 0 === $status['regression_alerts'];
		if ( $switched ) {
			restore_current_blog();
		}
		return $status;
	}

	/** @return array<string,array<string,mixed>> */
	private function profiles() {
		return array(
			'safe'     => array(
				'nbh_performance_safe_mode'          => 0,
				'nbh_performance_cache_enabled'      => 1,
				'nbh_performance_css_minify_enabled' => 1,
				'nbh_performance_js_minify_enabled'  => 1,
				'nbh_performance_defer_js_enabled'   => 0,
				'nbh_performance_delay_js_enabled'   => 0,
				'nbh_performance_unused_css_enabled' => 0,
			),
			'balanced' => array(
				'nbh_performance_safe_mode'               => 0,
				'nbh_performance_cache_enabled'           => 1,
				'nbh_performance_css_minify_enabled'      => 1,
				'nbh_performance_js_minify_enabled'       => 1,
				'nbh_performance_defer_js_enabled'        => 1,
				'nbh_performance_delay_js_enabled'        => 0,
				'nbh_performance_lazy_load_enabled'       => 1,
				'nbh_performance_sitemap_preload_enabled' => 1,
			),
			'advanced' => array(
				'nbh_performance_safe_mode'            => 0,
				'nbh_performance_cache_enabled'        => 1,
				'nbh_performance_css_minify_enabled'   => 1,
				'nbh_performance_js_minify_enabled'    => 1,
				'nbh_performance_defer_js_enabled'     => 1,
				'nbh_performance_delay_js_enabled'     => 1,
				'nbh_performance_critical_css_enabled' => 1,
				'nbh_performance_unused_css_enabled'   => 1,
				'nbh_performance_visual_regression_guard_enabled' => 1,
			),
		);
	}

	/** @return array<string,string> */
	private function brand() {
		$brand   = get_option( 'nbh_performance_agency_brand', array() );
		$brand   = is_array( $brand ) ? $brand : array();
		$company = trim( (string) get_option( 'nbh_performance_agency_company', '' ) );
		$title   = trim( (string) get_option( 'nbh_performance_agency_report_title', '' ) );
		$footer  = trim( (string) get_option( 'nbh_performance_agency_footer', '' ) );
		$logo    = trim( (string) get_option( 'nbh_performance_agency_logo_url', '' ) );
		$company = '' !== $company ? $company : ( isset( $brand['company'] ) && '' !== trim( (string) $brand['company'] ) ? (string) $brand['company'] : get_bloginfo( 'name' ) );
		$title   = '' !== $title ? $title : ( isset( $brand['report_title'] ) && '' !== trim( (string) $brand['report_title'] ) ? (string) $brand['report_title'] : __( 'WordPress Performance Report', 'nbh-performance' ) );
		$footer  = '' !== $footer ? $footer : ( isset( $brand['footer'] ) && '' !== trim( (string) $brand['footer'] ) ? (string) $brand['footer'] : __( 'Generated by NBH Performance', 'nbh-performance' ) );
		$logo    = '' !== $logo ? $logo : ( isset( $brand['logo_url'] ) ? (string) $brand['logo_url'] : '' );
		return array(
			'company'      => sanitize_text_field( $company ),
			'report_title' => sanitize_text_field( $title ),
			'footer'       => sanitize_text_field( $footer ),
			'logo_url'     => esc_url_raw( $logo ),
		);
	}

	/** @param array<string,mixed> $report Report. @return string */
	private function report_html( array $report ) {
		$rows = '';
		foreach ( $report['portfolio']['sites'] as $site ) {
			$rows .= '<tr><td>' . esc_html( $site['name'] ) . '</td><td>' . esc_html( $site['url'] ) . '</td><td>' . esc_html( $site['healthy'] ? 'Healthy' : 'Needs attention' ) . '</td><td>' . absint( $site['cache_files'] ) . '</td><td>' . absint( $site['failed_jobs'] ) . '</td></tr>';
		}
		$logo = ! empty( $report['logo_url'] ) ? '<img src="' . esc_url( $report['logo_url'] ) . '" alt="" style="max-width:180px;max-height:60px;margin-bottom:18px">' : '';
		return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $report['title'] ) . '</title><style>body{font-family:Arial,sans-serif;color:#172033;margin:40px}h1{margin-bottom:4px}p{color:#6b7b91}table{width:100%;border-collapse:collapse;margin-top:24px}th,td{border:1px solid #dfe7f1;padding:10px;text-align:left}th{background:#f5f8fc}</style></head><body>' . $logo . '<h1>' . esc_html( $report['title'] ) . '</h1><p>' . esc_html( $report['company'] ) . '</p><table><thead><tr><th>Site</th><th>URL</th><th>Status</th><th>Cached pages</th><th>Failed jobs</th></tr></thead><tbody>' . $rows . '</tbody></table><p>' . esc_html( $report['footer'] ) . '</p></body></html>';
	}
}
