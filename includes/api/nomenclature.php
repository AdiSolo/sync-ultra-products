<?php

namespace UltraB2BProductSync\API;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Download nomenclature XML from B2B API
 * 
 * @return bool True on success, false on failure
 */
function download_nomenclature()
{
    Utils\log_sync("🔄 Începem descărcarea nomenclaturii...");

    try {
        // Set maximum execution time to 30 minutes if possible
        @set_time_limit(1800);
        // Increase memory limit to 1GB
        @ini_set('memory_limit', '1024M');

        // Request nomenclature data
        Utils\log_sync("🔄 Solicitare date nomenclatură...");

        // Create a SOAP client with large data settings
        $client = get_soap_client(true); // true = for large data
        if (!$client) {
            Utils\log_sync("❌ Nu s-a putut crea clientul SOAP pentru nomenclatură");
            return false;
        }

        Utils\log_sync("✅ Client SOAP creat cu succes pentru nomenclatură (configurație pentru date mari)");

        // Make the request with the client
        $id = null;
        try {
            $response = $client->requestData([
                'Service' => 'NOMENCLATURE',
                'all' => true,
                'additionalParameters' => null,
                'compress' => false
            ]);

            $id = $response->return ?? null;
            if (!$id) {
                // Try alternative service name (some systems use different casing)
                Utils\log_sync("⚠️ Prima încercare eșuată, încercăm cu 'nomenclature' (lowercase)...");

                $response = $client->requestData([
                    'Service' => 'nomenclature',
                    'all' => true,
                    'additionalParameters' => null,
                    'compress' => false
                ]);

                $id = $response->return ?? null;
            }
        } catch (\Exception $e) {
            Utils\log_sync("❌ Eroare la solicitarea datelor: " . $e->getMessage());
            return false;
        }

        if (!$id) {
            Utils\log_sync("❌ Nu s-a putut obține ID-ul cererii pentru nomenclatură");
            return false;
        }

        Utils\log_sync("ℹ️ ID cerere nomenclatură obținut: {$id}");

        // Wait for data to be ready with increased timeout and attempts
        Utils\log_sync("🔄 Așteptăm datele nomenclaturii să fie gata...");
        Utils\log_sync("ℹ️ Setăm timeout pentru nomenclatură: 60 încercări, 5 secunde între încercări");
        $xml_data = wait_and_get_data($id, 60, 5); // 60 attempts, 5 seconds each (up to 5 minutes)

        if (!$xml_data) {
            Utils\log_sync("❌ Nu s-au putut obține datele nomenclaturii după ce au fost raportate ca fiind gata");

            // Try to get the data directly one more time with multiple approaches
            Utils\log_sync("🔄 Încercăm să obținem datele direct cu multiple abordări...");

            // Check if the data is ready before trying to get it
            $is_ready = is_data_ready($id);
            Utils\log_sync("ℹ️ Verificare status direct: " . ($is_ready ? "Gata" : "Nu este gata"));

            if ($is_ready) {
                // Try multiple times with different approaches
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    Utils\log_sync("🔄 Încercare directă #{$attempt} de a obține datele...");

                    // Create a fresh SOAP client for each attempt
                    $fresh_client = get_soap_client(true); // Use large data settings
                    if (!$fresh_client) {
                        Utils\log_sync("⚠️ Nu s-a putut crea un client SOAP nou pentru încercarea #{$attempt}");
                        continue;
                    }

                    try {
                        // Try with a fresh client
                        $data = $fresh_client->getDataByID(['ID' => $id]);

                        // Try to extract data from the response
                        if (isset($data->return)) {
                            if (is_string($data->return)) {
                                $xml_data = $data->return;
                                Utils\log_sync("✅ Am obținut datele ca string direct din return");
                                break;
                            } else if (isset($data->return->data)) {
                                $xml_data = $data->return->data;
                                Utils\log_sync("✅ Am obținut datele din proprietatea data");
                                break;
                            }
                        }

                        // Try to get the raw response
                        $raw_response = $fresh_client->__getLastResponse();
                        if (!empty($raw_response)) {
                            Utils\log_sync("ℹ️ Încercăm să extragem datele din răspunsul SOAP brut...");

                            // Try to extract XML from the SOAP envelope
                            if (preg_match('/<return[^>]*>(.*)<\/return>/s', $raw_response, $matches)) {
                                $xml_content = $matches[1];
                                $xml_data = html_entity_decode($xml_content);
                                Utils\log_sync("✅ Am extras datele din envelope SOAP");
                                break;
                            }
                        }

                        Utils\log_sync("⚠️ Încercarea #{$attempt} nu a găsit date valide");
                    } catch (\Exception $e) {
                        Utils\log_sync("⚠️ Eroare în încercarea #{$attempt}: " . $e->getMessage());
                    }

                    // Wait a bit before the next attempt
                    sleep(3);
                }

                if (!$xml_data) {
                    // Last resort: try the standard function
                    $xml_data = get_data_by_id($id);
                }

                if (!$xml_data) {
                    Utils\log_sync("❌ Toate încercările de a obține datele au eșuat");
                    return false;
                }

                Utils\log_sync("✅ Am reușit să obținem datele după încercări multiple");
            } else {
                Utils\log_sync("❌ Datele încă nu sunt gata după timeout");
                return false;
            }
        }

        // Check if we got valid data
        if (!is_string($xml_data)) {
            $type = gettype($xml_data);
            Utils\log_sync("❌ Datele primite nu sunt de tip string, ci de tip: {$type}");

            if (is_object($xml_data)) {
                $class = get_class($xml_data);
                Utils\log_sync("ℹ️ Obiectul este de clasa: {$class}");

                // Try to convert object to string if possible
                if (method_exists($xml_data, '__toString')) {
                    Utils\log_sync("🔄 Încercăm să convertim obiectul la string...");
                    $xml_data = (string)$xml_data;
                } else {
                    Utils\log_sync("❌ Obiectul nu poate fi convertit la string");
                    return false;
                }
            } else {
                return false;
            }
        }

        $len = strlen($xml_data);
        Utils\log_sync("ℹ️ XML primit: {$len} bytes");

        if ($len === 0) {
            Utils\log_sync('❌ XML gol primit.');
            return false;
        }

        // Save first 100 characters to log for debugging
        $preview = substr($xml_data, 0, 100);
        Utils\log_sync("ℹ️ Primele 100 caractere: " . $preview);

        // Save XML file
        Utils\log_sync("🔄 Salvăm fișierul XML nomenclatură...");
        $uploads_dir = WP_CONTENT_DIR . '/uploads';
        if (!file_exists($uploads_dir)) {
            Utils\log_sync("🔄 Creăm directorul uploads...");
            $result = wp_mkdir_p($uploads_dir);
            if (!$result) {
                Utils\log_sync("❌ Nu s-a putut crea directorul uploads");
                return false;
            }
        }

        $file = "{$uploads_dir}/sync-ultra.xml";
        Utils\log_sync("🔄 Scriem în fișierul: {$file}");

        // Get file size before writing
        $original_size = file_exists($file) ? filesize($file) : 0;
        Utils\log_sync("ℹ️ Dimensiune fișier înainte de scriere: {$original_size} bytes");

        // Get data length
        $data_length = strlen($xml_data);
        Utils\log_sync("ℹ️ Lungime date de scris: {$data_length} bytes");

        // Try to write the file with multiple attempts
        $max_attempts = 3;
        $bytes = false;

        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            if ($attempt > 0) {
                Utils\log_sync("🔄 Încercare #{$attempt} de a scrie fișierul...");
                sleep(2); // Wait a bit between attempts
            }

            // Try to write the file
            $bytes = file_put_contents($file, $xml_data);

            if ($bytes !== false) {
                if ($attempt > 0) {
                    Utils\log_sync("✅ Încercarea #{$attempt} de a scrie fișierul a reușit");
                }
                break;
            }

            Utils\log_sync("⚠️ Încercarea #{$attempt} de a scrie fișierul a eșuat");

            // Check for file permissions issues
            if (file_exists($file)) {
                $perms = substr(sprintf('%o', fileperms($file)), -4);
                Utils\log_sync("ℹ️ Permisiuni fișier: {$perms}");

                // Try to make the file writable
                @chmod($file, 0666);
                Utils\log_sync("🔄 Încercare de a face fișierul writable");
            }

            // Check directory permissions
            $dir_perms = substr(sprintf('%o', fileperms($uploads_dir)), -4);
            Utils\log_sync("ℹ️ Permisiuni director: {$dir_perms}");
        }

        if ($bytes === false) {
            Utils\log_sync("❌ Eroare la scrierea XML în {$file} după {$max_attempts} încercări");

            // Try to write to a temporary file to see if it's a permissions issue
            $temp_file = "{$uploads_dir}/test-write-" . time() . ".txt";
            $temp_result = file_put_contents($temp_file, "Test write");
            if ($temp_result === false) {
                Utils\log_sync("❌ Nu se poate scrie nici în fișierul temporar. Probabil o problemă de permisiuni.");
            } else {
                Utils\log_sync("✅ S-a putut scrie în fișierul temporar. Problema este specifică fișierului XML.");
                @unlink($temp_file);
            }

            return false;
        }

        // Verify the file was written correctly
        if (file_exists($file)) {
            $new_size = filesize($file);
            Utils\log_sync("ℹ️ Dimensiune fișier după scriere: {$new_size} bytes");

            if ($new_size != $bytes) {
                Utils\log_sync("⚠️ Dimensiunea fișierului ({$new_size}) nu corespunde cu bytes scriși ({$bytes})");
            }

            if ($new_size < 100) {
                Utils\log_sync("⚠️ Fișierul scris este prea mic ({$new_size} bytes)");
            }
        } else {
            Utils\log_sync("⚠️ Fișierul nu există după scriere!");
        }

        // Update timestamp of last download
        update_option('ultra_b2b_last_download', time());

        Utils\log_sync("✅ XML salvat local: {$file} ({$bytes} bytes)");

        // Return success after saving the file - parsing will happen separately
        return true;
    } catch (\Exception $e) {
        Utils\log_sync("❌ Excepție în download_nomenclature: " . $e->getMessage());
        return false;
    }
}

/**
 * Schedule background download of nomenclature XML
 * 
 * @return bool True if scheduled successfully
 */
function schedule_nomenclature_download()
{
    Utils\log_sync("🔄 Programez descărcarea XML în fundal...");

    // Clear any existing scheduled downloads
    $timestamp = wp_next_scheduled('ultra_b2b_background_download');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ultra_b2b_background_download');
    }

    // Schedule a new download immediately
    wp_schedule_single_event(time(), 'ultra_b2b_background_download');

    return true;
}

/**
 * Background download handler
 */
function background_download_handler()
{
    // Set maximum execution time to 30 minutes if possible
    @set_time_limit(1800);
    // Increase memory limit to 1GB
    @ini_set('memory_limit', '1024M');
    // Disable output buffering
    @ob_end_clean();
    // Disable user abort
    @ignore_user_abort(true);

    Utils\log_sync("🔄 Rulăm descărcare XML în fundal...");
    Utils\log_sync("ℹ️ Setări extinse: timeout 30 minute, memorie 1GB");

    // Try to close the session to allow other requests to proceed
    if (function_exists('session_write_close')) {
        @session_write_close();
        Utils\log_sync("ℹ️ Sesiune închisă pentru a permite alte cereri");
    }

    // Try to run the download with error handling
    try {
        $result = download_nomenclature();
        Utils\log_sync($result ? "✅ Descărcare nomenclatură finalizată cu succes" : "❌ Descărcare nomenclatură eșuată");
    } catch (\Exception $e) {
        Utils\log_sync("❌ Excepție în background_download_handler: " . $e->getMessage());
    }
}
add_action('ultra_b2b_background_download', __NAMESPACE__ . '\background_download_handler');

/**
 * Parse the nomenclature XML file
 * 
 * @return \SimpleXMLElement|false SimpleXML object or false on failure
 */
function get_nomenclature_data()
{
    $file = WP_CONTENT_DIR . '/uploads/sync-ultra.xml';

    if (!file_exists($file)) {
        Utils\log_sync('❌ XML-ul nu a fost găsit.');
        return false;
    }

    try {
        $xml = simplexml_load_file($file);
        if ($xml === false) {
            Utils\log_sync('❌ Eroare la parsarea fișierului XML.');
            return false;
        }

        return $xml;
    } catch (\Exception $e) {
        Utils\log_sync('❌ Eroare la citirea XML: ' . $e->getMessage());
        return false;
    }
}

/**
 * Count products in nomenclature
 * 
 * @return int Number of products or 0 on failure
 */
function count_products()
{
    $xml = get_nomenclature_data();
    if (!$xml || !isset($xml->nomenclature)) {
        return 0;
    }

    return count($xml->nomenclature);
}

/**
 * Get a batch of products from nomenclature
 * 
 * @param int $offset Starting offset
 * @param int $batch_size Number of products to get
 * @return array Array of product data or empty array on failure
 */
function get_product_batch($offset = 0, $batch_size = 5)
{
    $xml = get_nomenclature_data();
    if (!$xml || !isset($xml->nomenclature)) {
        return [];
    }

    $items = $xml->nomenclature;
    $total = count($items);

    if ($offset >= $total) {
        return [];
    }

    $products = [];
    $end = min($offset + $batch_size, $total);

    for ($i = $offset; $i < $end; $i++) {
        $products[] = $items[$i];
    }

    return $products;
}

/**
 * Get progress information for the current batch
 * 
 * @return array Progress information [current, total, percentage]
 */
function get_batch_progress()
{
    $offset = intval(get_option('ultra_sync_offset', 0));
    $total = count_products();

    if ($total === 0) {
        return [
            'current' => 0,
            'total' => 0,
            'percentage' => 0
        ];
    }

    $percentage = min(100, round(($offset / $total) * 100, 1));

    return [
        'current' => $offset,
        'total' => $total,
        'percentage' => $percentage
    ];
}
