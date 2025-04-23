<?php

/**
 * Plugin Name: Ultra B2B Product Sync (Manual)
 * Description: Sincronizează produse din Ultra B2B în WooCommerce. Adaugă produse noi cu preț, stoc și imagini.
 * Version: 0.2
 * Author: ChatGPT x Adrian
 */

namespace UltraB2BProductSync;

if (!defined('ABSPATH')) exit;

// Define constants
define('ULTRA_B2B_VERSION', '0.2');
define('ULTRA_B2B_PATH', plugin_dir_path(__FILE__));
define('ULTRA_B2B_URL', plugin_dir_url(__FILE__));

// Load utils
require_once ULTRA_B2B_PATH . 'includes/utils/logger.php';
require_once ULTRA_B2B_PATH . 'includes/utils/helpers.php';

// // Load API
require_once ULTRA_B2B_PATH . 'includes/api/soap-client.php';
require_once ULTRA_B2B_PATH . 'includes/api/nomenclature.php';
require_once ULTRA_B2B_PATH . 'includes/api/categories.php';
require_once ULTRA_B2B_PATH . 'includes/api/prices.php';
require_once ULTRA_B2B_PATH . 'includes/api/stock.php';
require_once ULTRA_B2B_PATH . 'includes/api/translations.php';


// // Load sync modules
require_once ULTRA_B2B_PATH . 'includes/sync/categories.php';
require_once ULTRA_B2B_PATH . 'includes/sync/products.php';
require_once ULTRA_B2B_PATH . 'includes/sync/images.php';

// Load core and admin UI
require_once ULTRA_B2B_PATH . 'includes/core.php';
require_once ULTRA_B2B_PATH . 'includes/admin-ui.php';

// Initialize the plugin
function init()
{
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>Ultra B2B Product Sync necesită WooCommerce să fie instalat și activat.</p></div>';
        });
        return;
    }

    // Initialize hooks and functionality
    Core\init();
}
add_action('plugins_loaded', __NAMESPACE__ . '\init');

// Register activation hook
register_activation_hook(__FILE__, __NAMESPACE__ . '\activate');

function activate()
{
    // Create default options
    add_option('ultra_b2b_sync_log', '');
    add_option('ultra_sync_offset', 0);

    // Log activation
    Utils\log_sync("✅ Plugin activat: versiunea " . ULTRA_B2B_VERSION);
}

// Register deactivation hook
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivate');

function deactivate()
{
    // Clear scheduled hooks
    $timestamp = wp_next_scheduled('ultra_b2b_background_download');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ultra_b2b_background_download');
    }

    // Log deactivation
    Utils\log_sync("⚠️ Plugin dezactivat");
}
