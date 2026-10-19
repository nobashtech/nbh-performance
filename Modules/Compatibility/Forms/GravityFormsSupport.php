<?php
namespace NBH\Performance\Modules\Compatibility\Forms;

use NBH\Performance\Modules\Core\Module;
final class GravityFormsSupport extends FormPluginSupport implements Module {
	protected function markers() {
		return array( 'gravityforms', 'gform', 'gravity-form' );
	}
}
