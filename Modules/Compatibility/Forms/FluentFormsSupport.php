<?php
namespace NBH\Performance\Modules\Compatibility\Forms;

use NBH\Performance\Modules\Core\Module;
final class FluentFormsSupport extends FormPluginSupport implements Module {
	protected function markers() {
		return array( 'fluentform', 'fluent-form' );
	}
}
