<?php
namespace NBH\Performance\Modules\Compatibility\Forms;

use NBH\Performance\Modules\Core\Module;
final class WPFormsSupport extends FormPluginSupport implements Module {
	protected function markers() {
		return array( 'wpforms', 'wpforms-lite' );
	}
}
