<?php
/**
 * Plugin Name:       NBH Performance
 * Plugin URI:        https://nbhperformance.com
 * Description:       Improve your WordPress site's speed and performance with reliable page caching, image optimization, and practical optimization tools. Built with simplicity, stability, and control in mind.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Nobash
 * Author URI:        https://nobash.com
 * Text Domain:       nbh-performance
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 Nobash.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the GNU General Public License, version 2 or any later version.
 * This program is distributed without any warranty. See LICENSE.
 *
 * @package NBHPerformance
 */

defined( 'ABSPATH' ) || exit;

define( 'NBH_PERFORMANCE_VERSION', '1.0.0' );
define( 'NBH_PERFORMANCE_BUILD', '1.0.0-wporg-review-20261018.2' );
define( 'NBH_PERFORMANCE_FILE', __FILE__ );
define( 'NBH_PERFORMANCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NBH_PERFORMANCE_URL', plugin_dir_url( __FILE__ ) );
define( 'NBH_PERFORMANCE_BASENAME', plugin_basename( __FILE__ ) );
define( 'NBH_PERFORMANCE_PRODUCT_URL', 'https://nbhperformance.com' );
define( 'NBH_PERFORMANCE_RELEASE_INTEGRITY_ENFORCED', false );

define( 'NBH_PERFORMANCE_SETTINGS_SCHEMA_VERSION', 2026071702 );
define( 'NBH_PERFORMANCE_EDITION', 'free' );

require_once NBH_PERFORMANCE_PATH . 'includes/functions.php';
require_once NBH_PERFORMANCE_PATH . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'NBH\Performance\Modules\Core\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NBH\Performance\Modules\Core\Lifecycle', 'deactivate' ) );
NBH\Performance\Modules\Core\Lifecycle::register_runtime_hooks();

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new NBH\Performance\Modules\Core\Plugin();
		$plugin->boot();
	},
	0
);
