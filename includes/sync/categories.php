<?php

namespace UltraB2BProductSync\Sync;

use UltraB2BProductSync\API;
use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Synchronize categories from B2B to WooCommerce
 * 
 * @return bool True on success, false on failure
 */
function sync_categories()
{
    Utils\log_sync("🔄 Începem sincronizarea categoriilor...");

    // Fetch categories from API
    $xml_data = API\fetch_categories();
    if (!$xml_data) {
        return false;
    }

    // Parse categories
    $categories_xml = Utils\parse_html_encoded_xml($xml_data);
    if (!$categories_xml) {
        return false;
    }

    // Store mapping from UUID to WooCommerce term ID
    $uuid_to_term_id = [];

    // Process category tree
    $processed = process_category_tree($categories_xml->parent, 0, $uuid_to_term_id);

    // Save mapping for future reference
    update_option('ultra_b2b_category_mapping', $uuid_to_term_id);

    Utils\log_sync("✅ Sincronizare categorii completă: {$processed} categorii procesate");
    return true;
}

/**
 * Process category tree recursively
 * 
 * @param \SimpleXMLElement $category_node Category XML node
 * @param int $parent_term_id Parent term ID
 * @param array &$uuid_to_term_id Reference to UUID to term ID mapping
 * @return int Number of categories processed
 */
function process_category_tree($category_node, $parent_term_id = 0, &$uuid_to_term_id = [])
{
    if (!$category_node) {
        return 0;
    }

    $processed = 0;

    // Process current node
    $name = (string) $category_node->name;
    $code = (string) $category_node->code;
    $uuid = (string) $category_node->UUID;

    Utils\log_sync("🔄 Procesăm categoria: {$name} (UUID: {$uuid})");

    // Find or create the category
    $term_id = API\find_or_create_category($uuid, $name, $code, $parent_term_id);

    if ($term_id > 0) {
        $uuid_to_term_id[$uuid] = $term_id;
        $processed++;

        // Process child categories
        if (isset($category_node->parent)) {
            // If only one child, SimpleXML won't return an array
            if (is_object($category_node->parent) && !isset($category_node->parent[0])) {
                $processed += process_category_tree($category_node->parent, $term_id, $uuid_to_term_id);
            } else {
                // Process all children
                foreach ($category_node->parent as $child_category) {
                    $processed += process_category_tree($child_category, $term_id, $uuid_to_term_id);
                }
            }
        }
    }

    return $processed;
}

/**
 * Assign a product to its category
 * 
 * @param int $product_id WooCommerce product ID
 * @param string $category_uuid Category UUID
 * @return bool True on success, false if category not found
 */
function assign_product_to_category($product_id, $category_uuid)
{
    if (empty($category_uuid)) {
        return false;
    }

    $category_id = API\get_category_id_by_uuid($category_uuid);
    if ($category_id > 0) {
        wp_set_object_terms($product_id, [$category_id], 'product_cat');
        Utils\log_sync("✅ Produs adăugat la categoria ID: {$category_id}");
        return true;
    }

    Utils\log_sync("⚠️ Nu s-a găsit categoria pentru UUID: {$category_uuid}");
    return false;
}
