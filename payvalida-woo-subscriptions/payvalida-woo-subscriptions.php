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

define('PAYVALIDA_SANDBOX_URL', 'https://api-test.payvalida.com');
define('PAYVALIDA_PROD_URL',    'https://api.payvalida.com');

define('PAYVALIDA_MERCHANT',    'merchantid'); //add merchant id here
define('PAYVALIDA_FIXED_HASH',  'fixedhash'); // Replace with real hash

require_once plugin_dir_path(__FILE__) . 'includes/class-payvalida-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-payvalida-admin.php';

function payvalida_plugin_activate() {

}
register_activation_hook(__FILE__, 'payvalida_plugin_activate');

function payvalida_plugin_deactivate() {
    
}
register_deactivation_hook(__FILE__, 'payvalida_plugin_deactivate');

// Instantiate the admin class (which sets up the menu/page)
new Payvalida_Admin();