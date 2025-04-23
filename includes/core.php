<?php

namespace UltraB2BProductSync\Core;

use UltraB2BProductSync\API;
use UltraB2BProductSync\Sync;
use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Initialize the plugin
 */
function init()
{
    // Register AJAX handlers
    add_action('wp_ajax_ultra_b2b_start_download', 'UltraB2BProductSync\Core\ajax_start_download');
    add_action('wp_ajax_ultra_b2b_check_status', 'UltraB2BProductSync\Core\ajax_check_status');
    add_action('wp_ajax_ultra_b2b_get_file', 'UltraB2BProductSync\Core\ajax_get_file');
    add_action('wp_ajax_ultra_b2b_get_log', 'UltraB2BProductSync\Core\ajax_get_log');

    // Register AJAX handlers for batch processing
    add_action('wp_ajax_ultra_b2b_start_batch', 'UltraB2BProductSync\Core\ajax_start_batch');
    add_action('wp_ajax_ultra_b2b_process_batch_chunk', 'UltraB2BProductSync\Core\ajax_process_batch_chunk');
    add_action('wp_ajax_ultra_b2b_get_batch_progress', 'UltraB2BProductSync\Core\ajax_get_batch_progress');

    // Register AJAX handlers for translations
    add_action('wp_ajax_ultra_b2b_start_translations', 'UltraB2BProductSync\Core\ajax_start_translations');
    add_action('wp_ajax_ultra_b2b_check_translations', 'UltraB2BProductSync\Core\ajax_check_translations');

    // Register the background download hook
    add_action('ultra_b2b_background_download', 'UltraB2BProductSync\API\background_download_handler');
}

/**
 * Register compatibility functions for backward compatibility
 */
function register_compatibility_functions()
{
    if (!function_exists('UltraB2BProductSync\do_process_batch')) {
        function_exists('UltraB2BProductSync\Sync\process_product_batch') &&
            add_action('init', function () {
                function_alias('UltraB2BProductSync\Sync\process_product_batch', 'UltraB2BProductSync\do_process_batch');
            });
    }

    if (!function_exists('UltraB2BProductSync\sync_categories')) {
        function_exists('UltraB2BProductSync\Sync\sync_categories') &&
            add_action('init', function () {
                function_alias('UltraB2BProductSync\Sync\sync_categories', 'UltraB2BProductSync\sync_categories');
            });
    }

    if (!function_exists('UltraB2BProductSync\clear_logs')) {
        function_exists('UltraB2BProductSync\Utils\clear_log') &&
            add_action('init', function () {
                function_alias('UltraB2BProductSync\Utils\clear_log', 'UltraB2BProductSync\clear_logs');
            });
    }
}

/**
 * Create function alias for backward compatibility
 * 
 * @param string $original Original function name
 * @param string $alias Alias function name
 */
function function_alias($original, $alias)
{
    if (!function_exists($alias)) {
        $namespace = substr($alias, 0, strrpos($alias, '\\'));
        $function_name = substr($alias, strrpos($alias, '\\') + 1);

        $code = "namespace $namespace; function $function_name() { 
            return call_user_func_array('$original', func_get_args()); 
        }";

        eval($code);
    }
}

/**
 * Clear logs (direct implementation for compatibility)
 */
function clear_logs()
{
    delete_option('ultra_b2b_sync_log');
    update_option('ultra_sync_offset', 0);
    Utils\log_sync('ℹ️ Log-urile au fost șterse și offset resetat.');

    if (is_admin() && isset($_SERVER['HTTP_REFERER'])) {
        wp_redirect(add_query_arg('action', 'logs_cleared', $_SERVER['HTTP_REFERER']));
        exit;
    }
}

/**
 * AJAX handler to start download and return ID
 */
function ajax_start_download()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    Utils\log_sync("🔄 Început descărcare XML via AJAX...");

    // Request nomenclature data
    $id = API\request_data('NOMENCLATURE', true);
    if (!$id) {
        wp_send_json_error('Nu s-a putut obține ID-ul cererii');
    }

    // Store the ID and timestamp
    update_option('ultra_b2b_current_download_id', $id);
    update_option('ultra_b2b_download_started', time());

    wp_send_json_success([
        'id' => $id,
        'message' => 'Cerere trimisă cu succes'
    ]);
}

/**
 * AJAX handler to check status of download
 */
function ajax_check_status()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : get_option('ultra_b2b_current_download_id', '');

    if (empty($id)) {
        wp_send_json_error('Nu există o descărcare în curs');
    }

    // Check if data is ready
    $isReady = API\is_data_ready($id);

    $start_time = get_option('ultra_b2b_download_started', 0);
    $elapsed = time() - $start_time;

    Utils\log_sync("🔄 Verificare status (după {$elapsed}s): " . ($isReady ? 'gata!' : 'încă se procesează...'));

    wp_send_json_success([
        'status' => $isReady ? 'ready' : 'waiting',
        'elapsed' => $elapsed,
        'message' => $isReady ? 'Datele sunt pregătite pentru descărcare' : 'Încă se procesează...'
    ]);
}

/**
 * AJAX handler to get the file when ready
 */
function ajax_get_file()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : get_option('ultra_b2b_current_download_id', '');

    if (empty($id)) {
        wp_send_json_error('Nu există o descărcare în curs');
    }

    // First check if ready
    if (!API\is_data_ready($id)) {
        wp_send_json_error('Datele nu sunt încă pregătite');
    }

    Utils\log_sync("🔄 Datele sunt gata, se descarcă acum...");

    // Get data
    $xml_data = API\get_data_by_id($id);
    if (!$xml_data) {
        wp_send_json_error('Nu s-au putut obține datele');
    }

    $len = strlen($xml_data);
    Utils\log_sync("ℹ️ XML primit: {$len} bytes");

    if ($len === 0) {
        wp_send_json_error('XML gol primit');
    }

    // Save file
    $uploads_dir = WP_CONTENT_DIR . '/uploads';
    if (!file_exists($uploads_dir)) {
        wp_mkdir_p($uploads_dir);
    }

    $file = "{$uploads_dir}/sync-ultra.xml";
    $bytes = file_put_contents($file, $xml_data);

    if ($bytes === false) {
        wp_send_json_error('Eroare la salvarea fișierului');
    }

    // Reset offset when downloading new data
    update_option('ultra_sync_offset', 0);
    update_option('ultra_b2b_last_download', time());

    // Clear download ID
    delete_option('ultra_b2b_current_download_id');
    delete_option('ultra_b2b_download_started');

    Utils\log_sync("✅ Descărcare completă: {$file} ({$bytes} bytes)");

    wp_send_json_success([
        'file_url' => content_url('/uploads/sync-ultra.xml'),
        'file_size' => size_format($bytes),
        'message' => 'Fișier descărcat cu succes'
    ]);
}

/**
 * AJAX handler to get the latest log
 */
function ajax_get_log()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    $log = Utils\get_log();

    wp_send_json_success([
        'log' => $log
    ]);
}

/**
 * AJAX handler to start batch processing
 */
function ajax_start_batch()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    // Get batch size from request
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
    $batch_size = max(1, min(50, $batch_size)); // Validate range (1-50)

    // Save batch size for next time
    update_option('ultra_b2b_batch_size', $batch_size);

    // Get total number of products
    $total_products = API\count_products();

    if ($total_products === 0) {
        wp_send_json_error('Nu există produse în XML');
    }

    // Get current offset
    $offset = intval(get_option('ultra_sync_offset', 0));

    // Check if we're at the end
    if ($offset >= $total_products) {
        Utils\log_sync("✅ Toate produsele au fost procesate. Începem de la început.");
        update_option('ultra_sync_offset', 0);
        $offset = 0;
    }

    // Set up batch processing state
    $remaining = $total_products - $offset;
    $chunk_size = min(5, $batch_size); // Process in smaller chunks for real-time feedback

    // Set up batch processing session
    $batch_data = [
        'total_products' => $total_products,
        'batch_size' => $batch_size,
        'chunk_size' => $chunk_size,
        'start_offset' => $offset,
        'current_offset' => $offset,
        'processed' => 0,
        'skipped' => 0,
        'total_to_process' => min($batch_size, $remaining),
        'in_progress' => true,
        'time_started' => time()
    ];

    // Store batch state
    update_option('ultra_b2b_batch_state', $batch_data);

    Utils\log_sync("🔄 Începem procesarea batch-ului în mod AJAX, offset inițial: {$offset}, total de procesat: {$batch_data['total_to_process']}");

    wp_send_json_success([
        'batch_data' => $batch_data,
        'message' => 'Procesare batch inițiată'
    ]);
}


/**
 * AJAX handler to process batch chunks
 */
function ajax_process_batch_chunk()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    // Get batch state
    $batch_data = get_option('ultra_b2b_batch_state', []);

    if (empty($batch_data) || !isset($batch_data['in_progress']) || !$batch_data['in_progress']) {
        wp_send_json_error('Nu există un batch în curs de procesare');
    }

    // Calculate how many products to process in this chunk
    $chunk_size = $batch_data['chunk_size'];
    $remaining_in_batch = $batch_data['total_to_process'] - ($batch_data['processed'] + $batch_data['skipped']);
    $to_process = min($chunk_size, $remaining_in_batch);

    // Stop if we've processed all products in the batch
    if ($to_process <= 0) {
        // Mark batch as complete
        $batch_data['in_progress'] = false;
        update_option('ultra_b2b_batch_state', $batch_data);

        Utils\log_sync("✅ Batch finalizat: {$batch_data['processed']} produse noi adăugate, {$batch_data['skipped']} produse sărite.");

        // Return final status
        wp_send_json_success([
            'status' => 'complete',
            'batch_data' => $batch_data,
            'message' => 'Procesare batch completă'
        ]);
    }

    // Get products for this chunk
    $products = API\get_product_batch($batch_data['current_offset'], $to_process);

    if (empty($products)) {
        // If no products but we expect some, something's wrong
        Utils\log_sync("⚠️ Nu s-au găsit produse la offset {$batch_data['current_offset']}");

        // Mark batch as complete
        $batch_data['in_progress'] = false;
        update_option('ultra_b2b_batch_state', $batch_data);

        wp_send_json_error('Nu s-au găsit produse pentru procesare');
    }

    // OPTIMIZATION: First check stock for all products in batch to avoid processing those with zero stock
    $products_with_stock = API\batch_check_stock($products);

    // If none of the products have stock, skip this entire chunk
    if (empty($products_with_stock)) {
        $skipped_count = count($products);
        $batch_data['current_offset'] += $skipped_count;
        $batch_data['skipped'] += $skipped_count;

        // Update global offset
        update_option('ultra_sync_offset', $batch_data['current_offset']);
        update_option('ultra_b2b_batch_state', $batch_data);

        Utils\log_sync("⏩ Chunk complet ignorat: toate cele {$skipped_count} produse au stoc zero");

        // Calculate progress
        $total_processed = $batch_data['processed'] + $batch_data['skipped'];
        $percentage = ($total_processed / $batch_data['total_to_process']) * 100;

        // Return success but indicate we skipped everything
        wp_send_json_success([
            'status' => 'skipped_chunk',
            'batch_data' => $batch_data,
            'processed_chunk' => 0,
            'skipped_chunk' => $skipped_count,
            'percentage' => round($percentage, 1),
            'message' => 'Toate produsele din chunk ignorat (stoc zero)'
        ]);
    }

    // Process each valid product
    $processed_count = 0;
    $skipped_count = count($products) - count($products_with_stock); // Products already skipped due to zero stock

    foreach ($products_with_stock as $product) {
        $result = Sync\process_single_product($product);
        if ($result === true) {
            $processed_count++;
        } elseif ($result === 'skipped') {
            $skipped_count++;
        }
    }

    // Update batch state
    $batch_data['current_offset'] += count($products); // Move past all products in the original chunk
    $batch_data['processed'] += $processed_count;
    $batch_data['skipped'] += $skipped_count;

    // Update global offset
    update_option('ultra_sync_offset', $batch_data['current_offset']);
    update_option('ultra_b2b_batch_state', $batch_data);

    // Calculate progress
    $total_processed = $batch_data['processed'] + $batch_data['skipped'];
    $percentage = ($total_processed / $batch_data['total_to_process']) * 100;

    Utils\log_sync("🔄 Chunk procesat: {$processed_count} produse noi, {$skipped_count} sărite. Progres: " . round($percentage, 1) . "%");

    // Check if we should continue or we're done with the batch
    $is_complete = ($total_processed >= $batch_data['total_to_process']);

    if ($is_complete) {
        // Mark batch as complete
        $batch_data['in_progress'] = false;
        update_option('ultra_b2b_batch_state', $batch_data);

        Utils\log_sync("✅ Batch finalizat: {$batch_data['processed']} produse noi adăugate, {$batch_data['skipped']} produse sărite.");
    }

    wp_send_json_success([
        'status' => $is_complete ? 'complete' : 'processing',
        'batch_data' => $batch_data,
        'processed_chunk' => $processed_count,
        'skipped_chunk' => $skipped_count,
        'percentage' => round($percentage, 1),
        'message' => $is_complete ? 'Procesare batch completă' : 'Chunk procesat cu succes'
    ]);
}

/**
 * AJAX handler to get batch progress
 */
function ajax_get_batch_progress()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    // Get batch state
    $batch_data = get_option('ultra_b2b_batch_state', []);

    // Get overall progress
    $progress = API\get_batch_progress();

    wp_send_json_success([
        'batch_data' => $batch_data,
        'progress' => $progress
    ]);
}


// Add these functions to your core.php file:

/**
 * AJAX handler to start translations download
 */
function ajax_start_translations()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    Utils\log_sync("🔄 Început descărcare traduceri via AJAX...");

    // Start translations download in the background
    API\schedule_translations_download();

    wp_send_json_success([
        'message' => 'Descărcare traduceri inițiată'
    ]);
}

/**
 * AJAX handler to check translations status
 */
function ajax_check_translations()
{
    // Security check
    check_ajax_referer('ultra_b2b_ajax_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permisiune negată');
    }

    $translations_file = WP_CONTENT_DIR . '/uploads/translations-ultra.xml';
    $translations_exist = file_exists($translations_file);

    if (!$translations_exist) {
        wp_send_json_error('Fișierul de traduceri nu a fost descărcat încă');
    }

    $translations = get_option('ultra_b2b_nomenclature_translations', []);
    $translations_count = count($translations);

    if ($translations_count === 0) {
        wp_send_json_error('Traducerile nu au fost procesate încă');
    }

    wp_send_json_success([
        'count' => $translations_count,
        'message' => 'Traduceri procesate cu succes'
    ]);
}

// AJAX handlers for translations are now registered in the init() function

/**
 * Force download the XML file
 */
function force_download_xml()
{
    $file = WP_CONTENT_DIR . '/uploads/sync-ultra.xml';
    Utils\force_download($file, 'sync-ultra.xml');
}
