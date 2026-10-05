<?php

/*
Plugin Name:  WooCommerce Store Balance
Plugin URI:   https://github.com/generoi/wp-woocommerce-store-balance
Description:  Gift cards and store credit for WooCommerce: one balance engine, spent after tax like a payment, locked to its currency.
Version:      0.1.7
Requires at least: 6.6
Requires PHP: 8.0
Requires Plugins: woocommerce
Author:       Genero
Author URI:   https://genero.fi/
License:      MIT
Text Domain:  wp-woocommerce-store-balance
Domain Path:  /languages
*/

use GeneroWP\StoreBalance\Install;
use GeneroWP\StoreBalance\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('WC_STORE_BALANCE_VERSION', '0.1.7');
define('WC_STORE_BALANCE_FILE', __FILE__);
define('WC_STORE_BALANCE_PATH', __DIR__);

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

// Installed without its own vendor/ and outside a site-wide composer install
// (a source zip, or a plain checkout into plugins/): load the classes from
// src/ directly rather than fatal.
if (! class_exists(Plugin::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'GeneroWP\\StoreBalance\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

require_once __DIR__.'/src/functions.php';

register_activation_hook(__FILE__, [Install::class, 'activate']);

Plugin::getInstance();
