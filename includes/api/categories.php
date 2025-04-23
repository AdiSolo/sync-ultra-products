<?php

namespace UltraB2BProductSync\API;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Fetch categories data from B2B API
 * 
 * @return string|false XML string with categories or false on failure
 */
function fetch_categories()
{
    Utils\log_sync("🔄 Obținem lista de categorii de la API...");

    // Request category data
    $id = request_data('PARENTLIST', true, 'NOMENCLATURE');
    if (!$id) {
        return false;
    }

    // Wait for data to be ready
    $xml_data = wait_and_get_data($id);

    if (!$xml_data) {
        return false;
    }

    return $xml_data;
}

/**
 * Parse categories data
 * 
 * @param string $xml_data XML string with categories
 * @return \SimpleXMLElement|false SimpleXML object or false on failure
 */
function parse_categories($xml_data)
{
    // Parse XML
    $xml = Utils\parse_html_encoded_xml($xml_data);

    if (!$xml) {
        Utils\log_sync("❌ Eroare la parsarea XML-ului de categorii");
        return false;
    }

    return $xml;
}

/**
 * Get WooCommerce category ID from B2B UUID
 * 
 * @param string $uuid B2B category UUID
 * @return int WooCommerce category ID or 0 if not found
 */
function get_category_id_by_uuid($uuid)
{
    // First check mapping
    $mapping = get_option('ultra_b2b_category_mapping', []);
    if (isset($mapping[$uuid])) {
        return $mapping[$uuid];
    }

    // If not in mapping, try direct query
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'meta_query' => [
            [
                'key' => 'ultra_b2b_uuid',
                'value' => $uuid
            ]
        ]
    ]);

    if (!empty($terms)) {
        // Store in mapping for future use
        $mapping[$uuid] = $terms[0]->term_id;
        update_option('ultra_b2b_category_mapping', $mapping);

        return $terms[0]->term_id;
    }

    return 0;
}

/**
 * Remove special characters from category name
 * 
 * @param string $name Category name
 * @return string Clean category name
 */
function clean_category_name($name)
{
    // Remove leading dots, underscores, etc.
    return preg_replace('/^[\.\_]/', '', $name);
}

/**
 * Find WooCommerce category by UUID or create it if it doesn't exist
 * 
 * @param string $uuid B2B category UUID
 * @param string $name Category name
 * @param string $code Category code
 * @param int $parent_id Parent category ID
 * @return int Category ID or 0 on failure
 */
function find_or_create_category($uuid, $name, $code, $parent_id = 0)
{
    // Check if category exists
    $term_id = get_category_id_by_uuid($uuid);

    if ($term_id > 0) {
        // Update existing category
        wp_update_term($term_id, 'product_cat', [
            'name' => clean_category_name($name),
            'parent' => $parent_id
        ]);

        // Update meta
        update_term_meta($term_id, 'ultra_b2b_uuid', $uuid);
        update_term_meta($term_id, 'ultra_b2b_code', $code);

        return $term_id;
    }

    // Create new category
    $result = wp_insert_term(clean_category_name($name), 'product_cat', [
        'slug' => sanitize_title($code),
        'parent' => $parent_id
    ]);

    if (is_wp_error($result)) {
        Utils\log_sync("❌ Eroare la crearea categoriei {$name}: " . $result->get_error_message());
        return 0;
    }

    $term_id = $result['term_id'];

    // Add metadata
    update_term_meta($term_id, 'ultra_b2b_uuid', $uuid);
    update_term_meta($term_id, 'ultra_b2b_code', $code);

    // Update mapping
    $mapping = get_option('ultra_b2b_category_mapping', []);
    $mapping[$uuid] = $term_id;
    update_option('ultra_b2b_category_mapping', $mapping);

    return $term_id;
}
