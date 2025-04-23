<?php

namespace UltraB2BProductSync\API;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Get stock for a product by UUID
 * 
 * @param string $uuid Product UUID
 * @return int Stock quantity or 0 on error
 */
function get_product_stock($uuid)
{
    Utils\log_sync("🔄 Obținem stocul pentru produsul cu UUID: {$uuid}");

    // Request stock data
    $id = request_data('BALANCE', false, $uuid);
    if (!$id) {
        Utils\log_sync("❌ Nu s-a putut obține ID cerere pentru stoc (UUID: {$uuid})");
        return 0;
    }

    // Wait for data to be ready
    $xml_data = wait_and_get_data($id);

    if (!$xml_data) {
        Utils\log_sync("❌ Nu s-au putut obține date de stoc (UUID: {$uuid})");
        return 0;
    }

    // Parse stock XML
    $stock_xml = Utils\parse_html_encoded_xml($xml_data);
    if (!$stock_xml) {
        Utils\log_sync("❌ Eroare la parsarea XML-ului de stocuri (UUID: {$uuid})");
        return 0;
    }

    // Extract stock value
    if (isset($stock_xml->balance)) {
        foreach ($stock_xml->balance as $stock_item) {
            $quantity = (int) $stock_item->quantity;
            Utils\log_sync("✅ Stoc obținut pentru {$uuid}: {$quantity}");
            return $quantity;
        }
    }

    Utils\log_sync("⚠️ Nu s-a găsit stoc pentru produsul cu UUID: {$uuid}");
    return 0;
}

/**
 * Set stock for a WooCommerce product
 * 
 * @param int $product_id WooCommerce product ID
 * @param int $quantity Stock quantity
 * @return bool True on success
 */
function set_product_stock($product_id, $quantity)
{
    update_post_meta($product_id, '_manage_stock', 'yes');
    update_post_meta($product_id, '_stock', $quantity);
    update_post_meta($product_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');

    // Ensure product is visible only if in stock
    $product = wc_get_product($product_id);
    if ($product) {
        if ($quantity > 0) {
            $product->set_catalog_visibility('visible');
        } else {
            // Optional: hide out of stock products
            // $product->set_catalog_visibility('hidden');
        }
        $product->save();
    }

    return true;
}

/**
 * Batch check stock for multiple products
 * 
 * @param array $products Array of product SimpleXML nodes
 * @return array Array of products with positive stock
 */
function batch_check_stock($products)
{
    if (empty($products)) {
        return [];
    }

    $valid_products = [];

    foreach ($products as $product) {
        $uuid = (string) $product->UUID;
        $name = (string) $product->name;
        $sku = Utils\format_sku((string) $product->code);

        // Check stock
        $stock = get_product_stock($uuid);

        if ($stock <= 0) {
            Utils\log_sync("⏩ Ignorat {$name} (SKU: {$sku}) - stoc zero sau negativ: {$stock}");
            continue;
        }

        // Add product to valid list
        $valid_products[] = $product;
    }

    Utils\log_sync("📊 Verificare stoc în lot: " . count($valid_products) . " din " . count($products) . " produse au stoc pozitiv.");

    return $valid_products;
}
