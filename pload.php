<?php
/**
 * Plugin Name:       Pload – Smart Image Optimizer & WebP
 * Description:       Slow, steady and lightweight image optimization that runs safely on shared hosting.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pload contributors
 * License:           GPL-2.0-or-later
 * Text Domain:       pload
 */

defined( 'ABSPATH' ) || exit;

define( 'PLOAD_VERSION', '1.1.0' );
define( 'PLOAD_FILE', __FILE__ );
define( 'PLOAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLOAD_URL', plugin_dir_url( __FILE__ ) );

require_once PLOAD_DIR . 'includes/class-pload-logger.php';
require_once PLOAD_DIR . 'includes/class-pload-settings.php';
require_once PLOAD_DIR . 'includes/class-pload-conflicts.php';
require_once PLOAD_DIR . 'includes/class-pload-environment.php';
require_once PLOAD_DIR . 'includes/class-pload-drivers.php';
require_once PLOAD_DIR . 'includes/class-pload-stats.php';
require_once PLOAD_DIR . 'includes/class-pload-converter.php';
require_once PLOAD_DIR . 'includes/class-pload-queue.php';
require_once PLOAD_DIR . 'includes/class-pload-delivery.php';
require_once PLOAD_DIR . 'includes/class-pload-cleanup.php';
require_once PLOAD_DIR . 'includes/class-pload-space.php';
require_once PLOAD_DIR . 'includes/class-pload-runner.php';
require_once PLOAD_DIR . 'includes/class-pload-ui.php';
require_once PLOAD_DIR . 'includes/class-pload-admin-page.php';
require_once PLOAD_DIR . 'includes/class-pload-media.php';
require_once PLOAD_DIR . 'includes/class-pload-admin.php';
require_once PLOAD_DIR . 'includes/class-pload-plugin.php';

register_activation_hook( __FILE__, array( 'Pload_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Pload_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Pload_Plugin', 'instance' ) );
