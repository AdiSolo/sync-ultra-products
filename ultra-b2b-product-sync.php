<?php
/**
 * Plugin Name: Ultra B2B Product Sync (Manual)
 * Description: Sincronizează produse din Ultra B2B în WooCommerce. Doar adăugare produse noi (fără preț/stoc/imagine).
 * Version: 0.1
 * Author: ChatGPT x Adrian
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/admin-ui.php';
require_once plugin_dir_path(__FILE__) . 'includes/core.php';
