<?php

namespace UltraB2BProductSync\API;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Download translations XML from B2B API
 * 
 * @return bool True on success, false on failure
 */
function download_translations()
{
    Utils\log_sync("🔄 Începem descărcarea traducerilor...");

    try {
        // Set maximum execution time to 30 minutes if possible
        @set_time_limit(1800);
        // Increase memory limit to 1GB
        @ini_set('memory_limit', '1024M');

        // Request translations data
        Utils\log_sync("🔄 Solicitare date traduceri...");

        // First, check if the SOAP client can be created with large data settings
        $client = get_soap_client(true); // true = for large data
        if (!$client) {
            Utils\log_sync("❌ Nu s-a putut crea clientul SOAP pentru traduceri");
            return false;
        }

        // Log the SOAP client configuration
        Utils\log_sync("ℹ️ Client SOAP creat cu succes pentru traduceri");

        // Make the request with increased timeout
        Utils\log_sync("🔄 Solicitare serviciu Translations...");
        $id = request_data('Translations', true);
        if (!$id) {
            // Try alternative service name (some systems use different casing)
            Utils\log_sync("⚠️ Prima încercare eșuată, încercăm cu 'translations' (lowercase)...");
            $id = request_data('translations', true);

            if (!$id) {
                Utils\log_sync("❌ Nu s-a putut obține ID-ul cererii pentru traduceri");
                return false;
            }
        }

        Utils\log_sync("ℹ️ ID cerere traduceri obținut: {$id}");

        // Wait for data to be ready with increased timeout and attempts
        Utils\log_sync("🔄 Așteptăm datele traducerilor să fie gata...");
        Utils\log_sync("ℹ️ Setăm timeout mai mare pentru traduceri: 120 încercări, 15 secunde între încercări");
        $xml_data = wait_and_get_data($id, 120, 15); // 120 attempts, 15 seconds each (up to ~30 minutes)

        if (!$xml_data) {
            Utils\log_sync("❌ Nu s-au putut obține datele traducerilor după ce au fost raportate ca fiind gata");

            // Try to get the data directly one more time with explicit logging
            Utils\log_sync("🔄 Încercăm să obținem datele direct încă o dată...");

            // Check if the data is ready before trying to get it
            $is_ready = is_data_ready($id);
            Utils\log_sync("ℹ️ Verificare status direct: " . ($is_ready ? "Gata" : "Nu este gata"));

            if ($is_ready) {
                // Try multiple times with different approaches
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    Utils\log_sync("🔄 Încercare directă #{$attempt} de a obține datele...");

                    // Create a fresh SOAP client for each attempt
                    $fresh_client = get_soap_client();
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
                    sleep(5);
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
        Utils\log_sync("ℹ️ XML traduceri primit: {$len} bytes");

        if ($len === 0) {
            Utils\log_sync('❌ XML gol primit pentru traduceri.');
            return false;
        }

        // Save first 100 characters to log for debugging
        $preview = substr($xml_data, 0, 100);
        Utils\log_sync("ℹ️ Primele 100 caractere: " . $preview);

        // Save XML file
        Utils\log_sync("🔄 Salvăm fișierul XML traduceri...");
        $uploads_dir = WP_CONTENT_DIR . '/uploads';
        if (!file_exists($uploads_dir)) {
            Utils\log_sync("🔄 Creăm directorul uploads...");
            $result = wp_mkdir_p($uploads_dir);
            if (!$result) {
                Utils\log_sync("❌ Nu s-a putut crea directorul uploads");
                return false;
            }
        }

        $file = "{$uploads_dir}/translations-ultra.xml";
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
            Utils\log_sync("❌ Eroare la scrierea XML traduceri în {$file} după {$max_attempts} încercări");

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

        Utils\log_sync("✅ XML traduceri salvat local: {$file} ({$bytes} bytes)");

        // Return success after saving the file - parsing will happen separately
        return true;
    } catch (\Exception $e) {
        Utils\log_sync("❌ Excepție în download_translations: " . $e->getMessage());
        return false;
    }
}

/**
 * Schedule background download of translations XML
 * 
 * @return bool True if scheduled successfully
 */
function schedule_translations_download()
{
    Utils\log_sync("🔄 Programez descărcarea traducerilor în fundal...");

    // Clear any existing scheduled downloads
    $timestamp = wp_next_scheduled('ultra_b2b_translations_download');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ultra_b2b_translations_download');
    }

    // Schedule a new download immediately
    wp_schedule_single_event(time(), 'ultra_b2b_translations_download');

    return true;
}

/**
 * Background download handler for translations
 */
function background_translations_download()
{
    // Set maximum execution time to 30 minutes if possible
    @set_time_limit(1800);
    // Increase memory limit to 1GB
    @ini_set('memory_limit', '1024M');
    // Disable output buffering
    @ob_end_clean();
    // Disable user abort
    @ignore_user_abort(true);

    Utils\log_sync("🔄 Rulăm descărcare traduceri în fundal...");
    Utils\log_sync("ℹ️ Setări extinse: timeout 30 minute, memorie 1GB");

    // Try to close the session to allow other requests to proceed
    if (function_exists('session_write_close')) {
        @session_write_close();
        Utils\log_sync("ℹ️ Sesiune închisă pentru a permite alte cereri");
    }

    // Try to run the download with error handling
    try {
        $result = download_translations();
        Utils\log_sync($result ? "✅ Descărcare traduceri finalizată cu succes" : "❌ Descărcare traduceri eșuată");
    } catch (\Exception $e) {
        Utils\log_sync("❌ Excepție în background_translations_download: " . $e->getMessage());
    }
}
add_action('ultra_b2b_translations_download', __NAMESPACE__ . '\background_translations_download');

/**
 * Process translations XML and build mapping
 * 
 * @return bool True on success, false on failure
 */
function process_translations()
{
    Utils\log_sync("🔄 Procesare traduceri...");

    $file = WP_CONTENT_DIR . '/uploads/translations-ultra.xml';
    if (!file_exists($file)) {
        Utils\log_sync('❌ XML-ul traducerilor nu a fost găsit.');
        return false;
    }

    try {
        // Load and parse the XML
        $xml = simplexml_load_file($file);
        if ($xml === false) {
            Utils\log_sync('❌ Eroare la parsarea fișierului XML traduceri.');
            return false;
        }

        // Create mappings for different types of translations
        $nomenclature_translations = []; // For product names, descriptions, etc.
        $property_translations = []; // For property values

        // Process nomenclature requisites value list (product details)
        if (isset($xml->nomenclatureRequisitesValueList)) {
            foreach ($xml->nomenclatureRequisitesValueList as $item) {
                $nomenclature_uuid = (string)$item->nomenclature;
                $requisite = (string)$item->requisite;
                $is_ref = ((string)$item->ref === 'true');
                $json_value = (string)$item->valueJSON;

                // Parse JSON value
                $translations = json_decode($json_value, true);
                if (!$translations) continue;

                // We're interested in the 'md' translations
                if (!isset($translations['md'])) continue;

                $md_translation = $translations['md'];

                // Initialize array for this nomenclature if it doesn't exist
                if (!isset($nomenclature_translations[$nomenclature_uuid])) {
                    $nomenclature_translations[$nomenclature_uuid] = [];
                }

                // Store translation based on requisite
                $nomenclature_translations[$nomenclature_uuid][$requisite] = $md_translation;
            }
        }

        // Process object property value list (category names, etc.)
        if (isset($xml->objectPropertyValueList)) {
            foreach ($xml->objectPropertyValueList as $item) {
                $object_uuid = (string)$item->object;
                $object_type = (int)$item->objectType;
                $json_value = (string)$item->valueJSON;

                // Parse JSON value
                $translations = json_decode($json_value, true);
                if (!$translations) continue;

                // We're interested in the 'md' translations
                if (!isset($translations['md'])) continue;

                $md_translation = $translations['md'];

                // Store translation based on object type
                $property_translations[$object_uuid] = [
                    'type' => $object_type,
                    'translation' => $md_translation
                ];
            }
        }

        // Save mappings to options
        update_option('ultra_b2b_nomenclature_translations', $nomenclature_translations);
        update_option('ultra_b2b_property_translations', $property_translations);

        $nom_count = count($nomenclature_translations);
        $prop_count = count($property_translations);
        Utils\log_sync("✅ Traduceri procesate: {$nom_count} traduceri nomenclature, {$prop_count} traduceri proprietăți.");

        return true;
    } catch (\Exception $e) {
        Utils\log_sync('❌ Eroare la procesarea traducerilor: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get translation for a product
 * 
 * @param string $uuid Product UUID
 * @param string $field Field to get translation for (name, description, etc.)
 * @return string|null Translation or null if not found
 */
function get_product_translation($uuid, $field)
{
    $translations = get_option('ultra_b2b_nomenclature_translations', []);

    if (isset($translations[$uuid]) && isset($translations[$uuid][$field])) {
        return $translations[$uuid][$field];
    }

    return null;
}

/**
 * Get translation for a property
 * 
 * @param string $uuid Property UUID
 * @return string|null Translation or null if not found
 */
function get_property_translation($uuid)
{
    $translations = get_option('ultra_b2b_property_translations', []);

    if (isset($translations[$uuid])) {
        return $translations[$uuid]['translation'];
    }

    return null;
}

/**
 * Check if translations are available
 * 
 * @return bool True if translations are available
 */
function has_translations()
{
    $translations = get_option('ultra_b2b_nomenclature_translations', []);
    return !empty($translations);
}
