<?php

namespace UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Check if a product with the given SKU exists
 * 
 * @param string $product_code Product code from the B2B system
 * @return bool True if the product exists
 */
function check_sku_exists($product_code)
{
    // Add LU prefix if not already there
    $sku = (strpos($product_code, 'LU') === 0) ? $product_code : 'LU' . $product_code;

    // Check if product exists
    $product_id = wc_get_product_id_by_sku($sku);

    return ($product_id > 0);
}

/**
 * Format a SKU with the LU prefix
 * 
 * @param string $product_code The product code
 * @return string Formatted SKU
 */
function format_sku($product_code)
{
    return (strpos($product_code, 'LU') === 0) ? $product_code : 'LU' . $product_code;
}

/**
 * Force download of a file
 * 
 * @param string $file Path to the file
 * @param string $filename Optional filename for the download
 * @return bool True on success, false on failure
 */
function force_download($file, $filename = null)
{
    if (!file_exists($file)) {
        log_sync("❌ Fișierul nu a fost găsit pentru descărcare: {$file}");
        return false;
    }

    // Set filename if not provided
    if (empty($filename)) {
        $filename = basename($file);
    }

    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Force download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));

    // Read the file directly to output
    readfile($file);
    exit;
}

/**
 * Parse HTML encoded XML string
 * 
 * @param string $encoded_xml HTML encoded XML string
 * @return \SimpleXMLElement|false SimpleXML object or false on failure
 */
function parse_html_encoded_xml($encoded_xml)
{
    // Decode HTML entities
    $xml_string = html_entity_decode($encoded_xml);

    // Enable internal error handling
    $old_value = libxml_use_internal_errors(true);

    // Try to parse XML
    $xml = simplexml_load_string($xml_string);

    // Check for errors
    $errors = libxml_get_errors();
    if (!empty($errors)) {
        foreach ($errors as $error) {
            log_sync("❌ XML Error: " . $error->message . " at line " . $error->line);
        }

        // Try alternative parsing with DOMDocument
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml_string);
            $xml = simplexml_import_dom($dom);
        } catch (\Exception $e) {
            log_sync("❌ DOMDocument Error: " . $e->getMessage());
            $xml = false;
        }
    }

    // Restore previous error handling
    libxml_clear_errors();
    libxml_use_internal_errors($old_value);

    return $xml;
}
