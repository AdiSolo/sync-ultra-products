<?php

namespace UltraB2BProductSync\API;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Get price for a product by UUID
 * 
 * @param string $uuid Product UUID
 * @return float|null Price or null on error
 */
function get_product_price($uuid)
{
    Utils\log_sync("🔄 Obținem prețul pentru produsul cu UUID: {$uuid}");

    // Request price data
    $id = request_data('PRICELIST', false, $uuid);
    if (!$id) {
        return null;
    }

    // Wait for data to be ready
    $xml_data = wait_and_get_data($id);

    if (!$xml_data) {
        return null;
    }

    // Parse price XML
    $price_xml = Utils\parse_html_encoded_xml($xml_data);
    if (!$price_xml) {
        Utils\log_sync("❌ Eroare la parsarea XML-ului de prețuri");
        return null;
    }

    // Extract price value
    if (isset($price_xml->price)) {
        foreach ($price_xml->price as $price_item) {
            $price = (float) $price_item->Price;
            Utils\log_sync("✅ Preț obținut: {$price}");
            return $price;
        }
    }

    Utils\log_sync("⚠️ Nu s-a găsit preț pentru produsul cu UUID: {$uuid}");
    return null;
}

/**
 * Set price for a WooCommerce product
 * 
 * @param int $product_id WooCommerce product ID
 * @param float $price Product price
 * @return bool True on success
 */
function set_product_price($product_id, $price)
{
    if (!is_numeric($price)) {
        $price = 0;
    }

    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_regular_price', $price);

    // Also set sale price to same as regular if it's on sale
    $sale_price = get_post_meta($product_id, '_sale_price', true);
    if (!empty($sale_price)) {
        update_post_meta($product_id, '_sale_price', $price);
    }

    return true;
}

/**
 * Get and set price for a WooCommerce product
 * 
 * @param int $product_id WooCommerce product ID
 * @param string $uuid Product UUID
 * @return float|null Price or null on error
 */
function update_product_price($product_id, $uuid)
{
    $price = get_product_price($uuid);

    if ($price !== null) {
        set_product_price($product_id, $price);
        Utils\log_sync("✅ Preț actualizat pentru produsul ID: {$product_id}, preț: {$price}");
        return $price;
    }

    // Set default price of 0
    set_product_price($product_id, 0);
    Utils\log_sync("⚠️ Preț implicit setat: 0 pentru produsul ID: {$product_id}");
    return 0;
}

/**
 * Bulk update prices for multiple products
 * 
 * @param array $product_uuids Array of product UUIDs
 * @return array Array of [uuid => price]
 */
function bulk_update_prices($product_uuids)
{
    if (empty($product_uuids)) {
        return [];
    }

    Utils\log_sync("🔄 Obținem prețuri pentru " . count($product_uuids) . " produse");

    // Get WooCommerce product IDs for the UUIDs
    $prices = [];

    // Process in batches of 20
    $batches = array_chunk($product_uuids, 20);

    foreach ($batches as $batch) {
        foreach ($batch as $uuid) {
            $price = get_product_price($uuid);
            if ($price !== null) {
                $prices[$uuid] = $price;
            }
        }
    }

    Utils\log_sync("✅ Obținute prețuri pentru " . count($prices) . " produse");
    return $prices;
}
