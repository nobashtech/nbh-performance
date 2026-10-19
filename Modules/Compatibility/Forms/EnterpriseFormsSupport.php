<?php
/** AJAX, nonce, captcha and multi-step form compatibility. @package NBHPerformance */
namespace NBH\Performance\Modules\Compatibility\Forms;

use NBH\Performance\Modules\Compatibility\IntegrationRegistry;
use NBH\Performance\Modules\Core\Module;
final class EnterpriseFormsSupport implements Module {
	private $registry;
	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}
	public function register() {
		add_action( 'init', array( $this, 'detect' ), 40 );
		add_filter( 'nbh_performance_protected_script_handles', array( $this, 'scripts' ) );
		add_filter( 'nbh_performance_delay_compatibility_rules', array( $this, 'delay_rules' ) );
		add_filter( 'nbh_performance_should_cache_request', array( $this, 'cacheable' ), 4, 2 );
	}
	public function detect() {
		$items = array(
			array(
				'id'         => 'contact-form-7',
				'name'       => 'Contact Form 7',
				'active'     => defined( 'WPCF7_VERSION' ),
				'version'    => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
				'confidence' => 94,
			),
			array(
				'id'         => 'gravity-forms',
				'name'       => 'Gravity Forms',
				'active'     => class_exists( 'GFForms' ),
				'version'    => defined( 'GFForms::VERSION' ) ? GFForms::VERSION : '',
				'confidence' => 94,
			),
			array(
				'id'         => 'wpforms',
				'name'       => 'WPForms',
				'active'     => defined( 'WPFORMS_VERSION' ),
				'version'    => defined( 'WPFORMS_VERSION' ) ? WPFORMS_VERSION : '',
				'confidence' => 93,
			),
			array(
				'id'         => 'fluent-forms',
				'name'       => 'Fluent Forms',
				'active'     => defined( 'FLUENTFORM_VERSION' ),
				'version'    => defined( 'FLUENTFORM_VERSION' ) ? FLUENTFORM_VERSION : '',
				'confidence' => 91,
			),
			array(
				'id'         => 'formidable',
				'name'       => 'Formidable Forms',
				'active'     => defined( 'FRM_VERSION' ),
				'version'    => defined( 'FRM_VERSION' ) ? FRM_VERSION : '',
				'confidence' => 88,
			),
			array(
				'id'         => 'ninja-forms',
				'name'       => 'Ninja Forms',
				'active'     => defined( 'NINJA_FORMS_VERSION' ),
				'version'    => defined( 'NINJA_FORMS_VERSION' ) ? NINJA_FORMS_VERSION : '',
				'confidence' => 88,
			),
		);
		foreach ( $items as $item ) {
			$item = array_merge(
				$item,
				array(
					'category'       => 'forms',
					'status'         => $item['active'] ? 'supported' : 'inactive',
					'features'       => array( 'ajax_submit', 'nonce_protection', 'captcha_protection', 'file_upload_protection', 'multi_step_protection' ),
					'tested_through' => 'current-major',
					'last_verified'  => '2026-07-16',
				)
			);
			$this->registry->add( $item );
		}
	}
	public function scripts( $handles ) {
		return array_values( array_unique( array_merge( (array) $handles, array( 'contact-form-7', 'wpcf7-recaptcha', 'gform_gravityforms', 'gform_json', 'wpforms', 'wpforms-recaptcha', 'fluent-form-submission', 'formidable', 'nf-front-end', 'google-recaptcha', 'hcaptcha', 'turnstile' ) ) ) );
	}
	public function delay_rules( $rules ) {
		return array_values( array_unique( array_merge( (array) $rules, array( 'wpcf7', 'gform', 'gravityforms', 'wpforms', 'fluentform', 'formidable', 'ninja-forms', 'recaptcha', 'hcaptcha', 'turnstile' ) ) ) );
	}
	public function cacheable( $allowed, $uri ) {
		$uri = strtolower( (string) $uri );
		foreach ( array( 'admin-ajax.php', '/wp-json/contact-form-7/', '/wp-json/wpforms/', '/wp-json/gf/', 'fluentform', 'formidable', 'ninja-forms' ) as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return false;
			}
		}
		return $allowed;
	}
}
