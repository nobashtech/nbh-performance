<?php
namespace NBH\Performance\Modules\Compatibility\Forms;

use NBH\Performance\Modules\Core\Module;
final class ContactForm7Support extends FormPluginSupport implements Module {
	protected function markers() {
		return array( 'contact-form-7', 'wpcf7' );
	}
}
