<?php

namespace UltraB2BProductSync\API;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Get SOAP client for B2B API
 * 
 * @param bool $for_large_data Set to true for clients that will handle large data
 * @return \SoapClient|null SoapClient instance or null on error
 */
function get_soap_client($for_large_data = false)
{
    $credentials = get_api_credentials();

    // Set PHP configuration for large data handling
    if ($for_large_data) {
        // Increase memory limit
        @ini_set('memory_limit', '1024M');

        // Increase timeouts
        @ini_set('default_socket_timeout', 600); // 10 minutes
        @ini_set('max_execution_time', 1800);    // 30 minutes

        // Disable time limit for this script
        @set_time_limit(0);
    }

    try {
        // Create stream context with extended timeout
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ],
            'http' => [
                'timeout' => 600, // 10 minutes timeout for HTTP context
                'user_agent' => 'UltraB2BProductSync/1.0'
            ]
        ]);

        // Create SOAP client with extended timeouts
        $client = new \SoapClient($credentials['wsdl'], [
            'login'      => $credentials['user'],
            'password'   => $credentials['pass'],
            'trace'      => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'connection_timeout' => $for_large_data ? 600 : 180,  // 10 minutes for large data
            'timeout'    => $for_large_data ? 1200 : 300,         // 20 minutes for large data
            'stream_context' => $context,
            'keep_alive' => false, // Disable keep-alive to prevent connection issues
            'features'   => SOAP_SINGLE_ELEMENT_ARRAYS, // Handle single element arrays properly
            'encoding'   => 'UTF-8',
            'soap_version' => SOAP_1_1
        ]);

        // Test connection with a simple call if for large data
        if ($for_large_data) {
            Utils\log_sync('ℹ️ Testăm conexiunea SOAP înainte de a procesa date mari...');
            try {
                // Try a simple call to test the connection
                // Use a valid UUID format for the test to avoid parameter errors
                $test_uuid = '00000000-0000-0000-0000-000000000000';
                $client->__soapCall('isReady', [['ID' => $test_uuid]]);
                Utils\log_sync('✅ Test conexiune SOAP reușit');
            } catch (\Exception $e) {
                Utils\log_sync('⚠️ Test conexiune SOAP eșuat, dar continuăm: ' . $e->getMessage());
                // We continue anyway as this is just a test
            }
        }

        return $client;
    } catch (\Exception $e) {
        Utils\log_sync('❌ Eroare la crearea clientului SOAP: ' . $e->getMessage());

        // Try to get more details about the exception
        Utils\log_sync('🔍 Detalii excepție: ' . get_class($e) . ' în fișierul ' . $e->getFile() . ' la linia ' . $e->getLine());

        return null;
    }
}

/**
 * Get API credentials
 * 
 * @return array Array with user, pass, and wsdl URL
 */
function get_api_credentials()
{
    // In a production environment, these should be stored in WordPress options
    return [
        'user' => 'Cobileanschi Grigore',
        'pass' => '11112222',
        'wsdl' => 'https://web1c.it-ultra.com/b2b/ws/b2b.1cws?wsdl'
    ];
}

/**
 * Request data from B2B API
 * 
 * @param string $service Service name (NOMENCLATURE, PRICELIST, BALANCE, PARENTLIST)
 * @param bool $all Get all data or just specific items
 * @param string|null $additionalParameters Additional parameters for the request
 * @return string|false Request ID or false on error
 */
function request_data($service, $all = true, $additionalParameters = null)
{
    $client = get_soap_client();
    if (!$client) {
        return false;
    }

    try {
        $response = $client->requestData([
            'Service' => $service,
            'all' => $all,
            'additionalParameters' => $additionalParameters,
            'compress' => false
        ]);

        $id = $response->return ?? null;
        if (!$id) {
            Utils\log_sync("❌ Request ID invalid pentru {$service}.");
            return false;
        }

        Utils\log_sync("ℹ️ Cerere trimisă pentru {$service}, ID = {$id}");
        return $id;
    } catch (\Exception $e) {
        Utils\log_sync("❌ Eroare SOAP ({$service}): " . $e->getMessage());
        return false;
    }
}

/**
 * Check if data is ready
 * 
 * @param string $id Request ID
 * @return bool True if data is ready
 */
function is_data_ready($id)
{
    $client = get_soap_client();
    if (!$client) {
        return false;
    }

    try {
        $readyResp = $client->isReady(['ID' => $id]);
        return $readyResp->return ?? false;
    } catch (\Exception $e) {
        Utils\log_sync('❌ Eroare la verificarea statusului: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get data by ID
 * 
 * @param string $id Request ID
 * @return mixed Data or false on error
 */
function get_data_by_id($id)
{
    Utils\log_sync("🔄 Începem obținerea datelor pentru ID: {$id}");

    // Detect if this is likely a large data request (translations or nomenclature)
    $is_large_data = (strpos($id, '-') !== false && strlen($id) > 30);

    // Create a new SOAP client for this specific request with large data settings if needed
    $client = get_soap_client($is_large_data);
    if (!$client) {
        Utils\log_sync("❌ Nu s-a putut crea clientul SOAP pentru getDataByID");
        return false;
    }

    Utils\log_sync("✅ Client SOAP creat cu succes pentru getDataByID" . ($is_large_data ? " (configurație pentru date mari)" : ""));

    try {
        // Additional timeout settings for this specific request
        if ($is_large_data) {
            @ini_set('default_socket_timeout', 600); // 10 minutes
            @set_time_limit(1800); // 30 minutes
            Utils\log_sync("ℹ️ Setări extinse pentru cerere de date mari");
        } else {
            @ini_set('default_socket_timeout', 300); // 5 minutes
        }

        // Log the SOAP request for debugging
        Utils\log_sync("🔄 Trimit cerere SOAP getDataByID pentru ID: {$id}");

        // Make the SOAP call with explicit timeout and retry mechanism
        $max_retries = 3;
        $data = null;
        $last_error = null;

        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                // If this is a retry, log it and wait a bit
                if ($retry > 0) {
                    Utils\log_sync("🔄 Reîncercare #{$retry} pentru getDataByID...");
                    sleep(5); // Wait 5 seconds between retries
                }

                // Try to get the data
                $data = $client->getDataByID(['ID' => $id]);

                // If we got here, the call succeeded
                if ($retry > 0) {
                    Utils\log_sync("✅ Reîncercarea #{$retry} a reușit");
                }

                // Break out of the retry loop
                break;
            } catch (\Exception $e) {
                $last_error = $e;
                Utils\log_sync("⚠️ Eroare la getDataByID (încercarea " . ($retry + 1) . "/{$max_retries}): " . $e->getMessage());

                // If this is the last retry, we'll continue to the error handling below
                if ($retry == $max_retries - 1) {
                    Utils\log_sync("❌ Toate încercările de a obține datele au eșuat");
                }
            }
        }

        // If all retries failed, throw the last error to be caught by the outer try/catch
        if ($data === null && $last_error !== null) {
            throw $last_error;
        }

        // Log the raw response for debugging
        Utils\log_sync("ℹ️ Răspuns primit pentru ID {$id}");

        // Check if the response is empty
        if (empty($data)) {
            Utils\log_sync("❌ Răspunsul este gol");
            return false;
        }

        // Get the type of response
        $type = gettype($data);
        Utils\log_sync("ℹ️ Tipul răspunsului: {$type}");

        // Dump the entire response structure for debugging
        ob_start();
        var_dump($data);
        $dump = ob_get_clean();
        Utils\log_sync("🔍 Structura răspunsului: " . substr($dump, 0, 500) . (strlen($dump) > 500 ? '...' : ''));

        // SPECIAL HANDLING FOR TRANSLATIONS SERVICE
        // Sometimes the translations service returns data in a different format
        if (strpos($id, '-') !== false && strlen($id) > 30) {
            Utils\log_sync("ℹ️ Detectat ID de tip UUID, posibil cerere de traduceri");

            // Try direct access to the response
            if (is_object($data) && isset($data->return)) {
                if (is_string($data->return)) {
                    Utils\log_sync("✅ Răspuns de traduceri găsit direct în proprietatea return (string)");
                    return $data->return;
                } else if (is_object($data->return) && method_exists($data->return, '__toString')) {
                    $str_value = (string)$data->return;
                    Utils\log_sync("✅ Răspuns de traduceri convertit din obiect la string");
                    return $str_value;
                }
            }
        }

        // Check if we have a return property
        if (isset($data->return)) {
            // Check if return has a data property
            if (isset($data->return->data)) {
                $xml_data = $data->return->data;
                Utils\log_sync("✅ Am găsit proprietatea data în răspuns");

                // Verify that the data is valid
                if (empty($xml_data)) {
                    Utils\log_sync("⚠️ Proprietatea data este goală");
                } else if (is_string($xml_data)) {
                    Utils\log_sync("✅ Proprietatea data este un string valid (lungime: " . strlen($xml_data) . ")");
                } else {
                    Utils\log_sync("⚠️ Proprietatea data nu este un string, ci de tip: " . gettype($xml_data));
                }

                return $xml_data;
            }
            // Check if return itself is a string (some SOAP implementations might do this)
            else if (is_string($data->return)) {
                $length = strlen($data->return);
                Utils\log_sync("✅ Proprietatea return este un string (lungime: {$length})");

                // For very short strings, log the content for debugging
                if ($length < 100) {
                    Utils\log_sync("⚠️ Conținutul string-ului este prea scurt: " . $data->return);
                }

                return $data->return;
            }
            // If return is an object, try to find a property that might contain our data
            else if (is_object($data->return)) {
                $return_props = get_object_vars($data->return);
                $props_list = implode(', ', array_keys($return_props));
                Utils\log_sync("⚠️ Obiectul return nu conține proprietatea 'data'. Proprietăți disponibile: {$props_list}");

                // Try to find data in a different property
                foreach ($return_props as $key => $value) {
                    // If it's a string and reasonably long, it might be our XML data
                    if (is_string($value)) {
                        $length = strlen($value);
                        Utils\log_sync("✅ Proprietatea '{$key}' conține date de tip string (lungime: {$length})");

                        // For very short strings, log the content for debugging
                        if ($length < 100) {
                            Utils\log_sync("⚠️ Conținutul string-ului este prea scurt: " . $value);
                            continue; // Skip short strings
                        }

                        return $value;
                    }
                    // If it's an object, check if it has a 'data' property or can be converted to string
                    else if (is_object($value)) {
                        if (isset($value->data) && is_string($value->data)) {
                            Utils\log_sync("✅ Am găsit proprietatea data în sub-obiectul {$key}");
                            return $value->data;
                        } else if (method_exists($value, '__toString')) {
                            $str_value = (string)$value;
                            $length = strlen($str_value);

                            if ($length > 100) {
                                Utils\log_sync("✅ Am convertit sub-obiectul {$key} la string (lungime: {$length})");
                                return $str_value;
                            } else {
                                Utils\log_sync("⚠️ String-ul convertit este prea scurt: " . $str_value);
                            }
                        }
                    }
                }
            }
        }
        // If no return property, check if the response itself is a string
        else if (is_string($data)) {
            $length = strlen($data);
            Utils\log_sync("✅ Răspunsul este direct un string (lungime: {$length})");

            if ($length < 100) {
                Utils\log_sync("⚠️ Conținutul string-ului este prea scurt: " . $data);
                return false;
            }

            return $data;
        }
        // If response is an object, try to find a property that might contain our data
        else if (is_object($data)) {
            $data_props = get_object_vars($data);
            $props_list = implode(', ', array_keys($data_props));
            Utils\log_sync("⚠️ Răspunsul nu conține proprietatea 'return'. Proprietăți disponibile: {$props_list}");

            // Try to find data in a different property
            foreach ($data_props as $key => $value) {
                // If it's a string and reasonably long, it might be our XML data
                if (is_string($value)) {
                    $length = strlen($value);
                    Utils\log_sync("✅ Proprietatea de nivel superior '{$key}' conține date de tip string (lungime: {$length})");

                    if ($length < 100) {
                        Utils\log_sync("⚠️ Conținutul string-ului este prea scurt: " . $value);
                        continue;
                    }

                    return $value;
                }
                // If it's an object, check if it has a 'data' property
                else if (is_object($value)) {
                    if (isset($value->data) && is_string($value->data)) {
                        Utils\log_sync("✅ Am găsit proprietatea data în obiectul de nivel superior {$key}");
                        return $value->data;
                    }
                }
            }
        }

        // LAST RESORT: Try to get the raw XML from the SOAP client
        Utils\log_sync("🔄 Încercăm să obținem XML-ul brut din răspunsul SOAP...");
        $raw_response = $client->__getLastResponse();
        if (!empty($raw_response) && strlen($raw_response) > 500) {
            Utils\log_sync("✅ Am obținut răspunsul SOAP brut (lungime: " . strlen($raw_response) . ")");

            // Try to extract XML from the SOAP envelope
            if (preg_match('/<return[^>]*>(.*)<\/return>/s', $raw_response, $matches)) {
                $xml_content = $matches[1];
                Utils\log_sync("✅ Am extras conținutul XML din envelope SOAP (lungime: " . strlen($xml_content) . ")");

                // Decode HTML entities if needed
                $decoded = html_entity_decode($xml_content);
                return $decoded;
            }
        }

        // If we've tried everything and still can't find the data
        Utils\log_sync('❌ Nu am putut identifica datele în răspunsul SOAP după toate încercările.');
        return false;
    } catch (\Exception $e) {
        Utils\log_sync('❌ Eroare la obținerea datelor: ' . $e->getMessage());

        // Try to get more details about the exception
        Utils\log_sync('🔍 Detalii excepție: ' . get_class($e) . ' în fișierul ' . $e->getFile() . ' la linia ' . $e->getLine());

        // If it's a SoapFault, log the fault code and details
        if ($e instanceof \SoapFault) {
            Utils\log_sync('🔍 SoapFault code: ' . ($e->faultcode ?? 'N/A') . ', string: ' . ($e->faultstring ?? 'N/A'));
            if (isset($e->detail)) {
                Utils\log_sync('🔍 SoapFault detail: ' . print_r($e->detail, true));
            }

            // Try to get the raw request and response
            if (method_exists($client, '__getLastRequest')) {
                $last_request = $client->__getLastRequest();
                Utils\log_sync('🔍 Last SOAP Request: ' . substr($last_request, 0, 500) . '...');
            }

            if (method_exists($client, '__getLastResponse')) {
                $last_response = $client->__getLastResponse();
                Utils\log_sync('🔍 Last SOAP Response: ' . substr($last_response, 0, 500) . '...');
            }
        }

        return false;
    }
}

/**
 * Wait for data to be ready and then get it
 * 
 * @param string $id Request ID
 * @param int $max_attempts Maximum number of attempts
 * @param int $sleep_seconds Seconds to sleep between attempts
 * @return mixed Data or false on error/timeout
 */
function wait_and_get_data($id, $max_attempts = 20, $sleep_seconds = 3)
{
    Utils\log_sync("🔄 Așteptăm datele să fie gata (max {$max_attempts} încercări, {$sleep_seconds}s între încercări)");

    $start_time = time();
    $total_wait_time = $max_attempts * $sleep_seconds;
    $estimated_end_time = date('H:i:s', $start_time + $total_wait_time);

    Utils\log_sync("ℹ️ Timp maxim de așteptare: {$total_wait_time} secunde (până la aproximativ {$estimated_end_time})");

    for ($i = 0; $i < $max_attempts; $i++) {
        sleep($sleep_seconds);

        $elapsed = time() - $start_time;

        try {
            $isReady = is_data_ready($id);

            // Log progress every 5 attempts
            if ($i % 5 === 0 || $isReady) {
                $percent = round(($i / $max_attempts) * 100);
                Utils\log_sync("🔄 Verificare status ({$i}/{$max_attempts}, {$percent}%, {$elapsed}s scurși): " . ($isReady ? 'gata!' : 'încă se procesează...'));
            }

            if ($isReady) {
                Utils\log_sync("✅ Datele sunt gata după {$i} verificări ({$elapsed} secunde)");

                // Try to get the data with multiple attempts
                for ($retry = 0; $retry < 3; $retry++) {
                    $data = get_data_by_id($id);
                    if ($data !== false) {
                        return $data;
                    }

                    Utils\log_sync("⚠️ Încercare " . ($retry + 1) . "/3 eșuată, reîncerc...");
                    sleep(3);
                }

                Utils\log_sync("❌ Toate încercările de a obține datele au eșuat");
                return false;
            }
        } catch (\Exception $e) {
            Utils\log_sync("⚠️ Eroare la verificarea {$i}: " . $e->getMessage());
            sleep(2);
        }
    }

    Utils\log_sync("❌ Timeout după {$max_attempts} încercări ({$elapsed} secunde).");
    return false;
}
