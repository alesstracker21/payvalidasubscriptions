<?php
/**
 * Plugin Name: Payvalida WooCommerce Subscriptions Integration
 * Description: Integration with Payvalida, including support for variable subscription products (each variation has its own plan versions).
 * Author: Alessandro Morelli
 * Version: 0.2
 */

if ( ! defined('ABSPATH') ) {
    exit; // No direct access.
}

// --- 1. Define environment URLs ---
define('PAYVALIDA_SANDBOX_URL', 'https://api-test.payvalida.com');
define('PAYVALIDA_PROD_URL',    'https://api.payvalida.com');

// --- 2. Hardcode some credentials or pull from settings ---
// (Defaults will be saved via Settings)
if ( ! defined('PAYVALIDA_MERCHANT') ) {
    define('PAYVALIDA_MERCHANT', get_option('payvalida_merchant', 'datosnoblemediapruebas') );
}
if ( ! defined('PAYVALIDA_FIXED_HASH') ) {
    define('PAYVALIDA_FIXED_HASH', get_option('payvalida_fixed_hash', 'hash') );
}

// --- 3. Define the log file (if saving logging is enabled) ---
if ( ! defined('PAYVALIDA_LOG_FILE') ) {
    // This will create (or use) a file called payvalida.log in the plugin’s root folder.
    define('PAYVALIDA_LOG_FILE', plugin_dir_path(__FILE__) . 'payvalida.log');
}

// 4) Require our classes
require_once plugin_dir_path(__FILE__) . 'includes/class-payvalida-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-payvalida-admin.php';

// Plugin activation/deactivation hooks…
function payvalida_plugin_activate() { /* ... */ }
register_activation_hook(__FILE__, 'payvalida_plugin_activate');

function payvalida_plugin_deactivate() { /* ... */ }
register_deactivation_hook(__FILE__, 'payvalida_plugin_deactivate');

// Instantiate the admin class (which now sets up a multi–tab admin page)
new Payvalida_Admin();
