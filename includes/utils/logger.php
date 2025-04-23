<?php

namespace UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Log a message to the sync log
 * 
 * @param string $message The message to log
 * @return void
 */
function log_sync($message)
{
    $log = get_option('ultra_b2b_sync_log', '');
    $timestamp = date('Y-m-d H:i:s');
    $log = "[{$timestamp}] $message\n" . $log;

    // Keep log at a reasonable size
    if (strlen($log) > 50000) {
        $log = substr($log, 0, 50000);
    }

    update_option('ultra_b2b_sync_log', $log);
}

/**
 * Clear the sync log
 * 
 * @return void
 */
function clear_log()
{
    delete_option('ultra_b2b_sync_log');
    update_option('ultra_sync_offset', 0);
    log_sync('ℹ️ Log-urile au fost șterse și offset resetat.');
}

/**
 * Get the current sync log
 * 
 * @return string The current log
 */
function get_log()
{
    return get_option('ultra_b2b_sync_log', '');
}
