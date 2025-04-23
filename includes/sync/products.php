<?php

namespace UltraB2BProductSync\Sync;

use UltraB2BProductSync\API;
use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Process a batch of products
 * 
 * @param int $batch_size Number of products to process
 * @return bool True on success, false on failure
 */
function process_product_batch($batch_size = 5)
{
    $offset = intval(get_option('ultra_sync_offset', 0));

    Utils\log_sync("🔄 Procesăm batch-ul începând de la offset-ul {$offset} (mărime batch: {$batch_size})");

    // Get products from nomenclature
    $products = API\get_product_batch($offset, $batch_size);
    if (empty($products)) {
        // If no products, we might be at the end
        if ($offset > 0 && $offset >= API\count_products()) {
            Utils\log_sync("✅ Toate produsele au fost procesate. Începem de la început.");
            update_option('ultra_sync_offset', 0);
        } else {
            Utils\log_sync("❌ Nu s-au găsit produse pentru procesare.");
        }
        return false;
    }

    $count = 0;
    $skipped = 0;

    // Process each product
    foreach ($products as $product) {
        $result = process_single_product($product);
        if ($result === true) {
            $count++;
        } elseif ($result === 'skipped') {
            $skipped++;
        }
    }

    // Update offset for next batch
    update_option('ultra_sync_offset', $offset + $count + $skipped);

    Utils\log_sync("✅ Batch finalizat: {$count} produse noi adăugate, {$skipped} produse sărite. Următorul offset: " . ($offset + $count + $skipped));
    return true;
}

/**
 * Process a single product
 * 
 * @param \SimpleXMLElement $product Product XML node
 * @return bool|string True on success, 'skipped' if skipped, false on failure
 */
function process_single_product($product)
{
    // Extract product data
    $product_code = (string) $product->code;
    $sku = Utils\format_sku($product_code);
    $name = (string) $product->name;
    $description = (string) $product->description;
    $article = (string) $product->article;
    $barcode = (string) $product->barcode;
    $active = ((string) $product->active === 'true');
    $uuid = (string) $product->UUID;
    $category_uuid = (string) $product->parent;

    Utils\log_sync("ℹ️ Verificăm produsul: {$name} (Cod: {$product_code}, SKU: {$sku})");

    // Check if product is active
    if (!$active) {
        Utils\log_sync("ℹ️ Sărit produsul {$name} (SKU: {$sku}) - inactiv");
        return 'skipped';
    }

    // Check if product already exists
    if (Utils\check_sku_exists($product_code)) {
        Utils\log_sync("ℹ️ Sărit produsul {$name} (SKU: {$sku}) - există deja");
        return 'skipped';
    }

    // FIRST: Check stock availability before proceeding
    $stock = API\get_product_stock($uuid);
    if ($stock <= 0) {
        Utils\log_sync("ℹ️ Sărit produsul {$name} (SKU: {$sku}) - stoc zero sau negativ: {$stock}");
        return 'skipped';
    }

    // SECOND: Try to get price
    $price = API\get_product_price($uuid);
    if ($price === null || $price <= 0) {
        Utils\log_sync("ℹ️ Sărit produsul {$name} (SKU: {$sku}) - preț invalid sau zero: {$price}");
        return 'skipped';
    }

    // Check for translations if available
    if (API\has_translations()) {
        // Try to get translated name
        $translated_name = API\get_product_translation($uuid, 'name');
        if ($translated_name) {
            $name = $translated_name;
            Utils\log_sync("📝 Folosim numele tradus: {$name}");
        }

        // Try to get translated description
        $translated_desc = API\get_product_translation($uuid, 'description');
        if ($translated_desc) {
            $description = $translated_desc;
            Utils\log_sync("📝 Folosim descrierea tradusă");
        }
    }

    // Now proceed with creating the product since it has valid stock and price
    Utils\log_sync("✅ Produsul {$name} are stoc ({$stock}) și preț valid ({$price}) - se adaugă în WooCommerce");

    // Create the product in WooCommerce
    $post_id = wp_insert_post([
        'post_title' => $name,
        'post_content' => $description,
        'post_status' => 'publish',
        'post_type' => 'product'
    ]);

    if (!$post_id || is_wp_error($post_id)) {
        if (is_wp_error($post_id)) {
            Utils\log_sync("❌ Eroare la crearea produsului {$name}: " . $post_id->get_error_message());
        } else {
            Utils\log_sync("❌ Eroare la crearea produsului {$name}");
        }
        return false;
    }

    // Set basic product data
    update_post_meta($post_id, '_sku', $sku);
    update_post_meta($post_id, '_uuid', $uuid);

    // Additional metadata
    if (!empty($barcode)) {
        update_post_meta($post_id, '_barcode', $barcode);
    }
    if (!empty($article)) {
        update_post_meta($post_id, '_article', $article);
    }

    // Set product as simple type
    wp_set_object_terms($post_id, 'simple', 'product_type');

    // Set price (we already got it earlier)
    update_post_meta($post_id, '_price', $price);
    update_post_meta($post_id, '_regular_price', $price);

    // Set stock (we already got it earlier)
    update_post_meta($post_id, '_manage_stock', 'yes');
    update_post_meta($post_id, '_stock', $stock);
    update_post_meta($post_id, '_stock_status', 'instock');

    // Assign product to category
    assign_product_to_category($post_id, $category_uuid);

    // Process images
    if (isset($product->imageList) && isset($product->imageList->image)) {
        Images\process_product_images($post_id, $product);
    }

    Utils\log_sync("✅ Adăugat produsul: {$name} (SKU: {$sku}, Stoc: {$stock}, Preț: {$price})");
    return true;
}

/**
 * Update an existing product
 * 
 * @param int $product_id WooCommerce product ID
 * @param \SimpleXMLElement $product_data Product XML data
 * @return bool True on success
 */
function update_existing_product($product_id, $product_data)
{
    $uuid = (string) $product_data->UUID;
    $name = (string) $product_data->name;
    $description = (string) $product_data->description;

    // Check for translations if available
    if (API\has_translations()) {
        // Try to get translated name
        $translated_name = API\get_product_translation($uuid, 'name');
        if ($translated_name) {
            $name = $translated_name;
            Utils\log_sync("📝 Folosim numele tradus pentru actualizare: {$name}");
        }

        // Try to get translated description
        $translated_desc = API\get_product_translation($uuid, 'description');
        if ($translated_desc) {
            $description = $translated_desc;
            Utils\log_sync("📝 Folosim descrierea tradusă pentru actualizare");
        }
    }

    Utils\log_sync("🔄 Actualizare produs existent: {$name} (ID: {$product_id})");

    // Check stock first
    $stock = API\get_product_stock($uuid);
    if ($stock <= 0) {
        Utils\log_sync("⚠️ Produs existent {$name} (ID: {$product_id}) are stoc zero sau negativ: {$stock}");
        // Consider setting product to out of stock instead of skipping completely
        update_post_meta($product_id, '_stock', 0);
        update_post_meta($product_id, '_stock_status', 'outofstock');
    } else {
        update_post_meta($product_id, '_stock', $stock);
        update_post_meta($product_id, '_stock_status', 'instock');
    }

    // Check price
    $price = API\get_product_price($uuid);
    if ($price !== null && $price > 0) {
        update_post_meta($product_id, '_price', $price);
        update_post_meta($product_id, '_regular_price', $price);
        Utils\log_sync("✅ Preț actualizat pentru {$name}: {$price}");
    } else {
        Utils\log_sync("⚠️ Nu s-a putut obține un preț valid pentru {$name}");
    }

    // Update product data
    wp_update_post([
        'ID' => $product_id,
        'post_title' => $name,
        'post_content' => $description,
        'post_status' => 'publish',
    ]);

    // Update product category
    $category_uuid = (string) $product_data->parent;
    assign_product_to_category($product_id, $category_uuid);

    // Update images if needed
    if (isset($product_data->imageList) && isset($product_data->imageList->image)) {
        Images\process_product_images($product_id, $product_data);
    }

    Utils\log_sync("✅ Produs actualizat: {$name} (ID: {$product_id}, Stoc: {$stock})");
    return true;
}
