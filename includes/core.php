<?php

namespace UltraB2BProductSync;

if (!defined('ABSPATH')) exit;

// Funcție pentru logare
function log_sync($message)
{
    $log = get_option('ultra_b2b_sync_log', '');
    $timestamp = date('Y-m-d H:i:s');
    $log .= "[{$timestamp}] $message\n";
    update_option('ultra_b2b_sync_log', $log);
}

// Funcție pentru descărcarea XML-ului cu produsele
function do_download_xml()
{
    $user = 'Cobileanschi Grigore';
    $pass = '11112222';
    $wsdl = 'https://web1c.it-ultra.com/b2b/ws/b2b.1cws?wsdl';

    if (!$user || !$pass) {
        log_sync('❌ Date API lipsă.');
        return;
    }

    try {
        $client = new \SoapClient($wsdl, [
            'login' => $user,
            'password' => $pass,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ])
        ]);

        // Apelul pentru requestData
        $response = $client->requestData([
            'Service' => 'NOMENCLATURE',
            'all' => false,
            'additionalParameters' => null,
            'compress' => false
        ]);

        $id = $response->return ?? null;
        if (!$id) {
            log_sync('❌ Request ID invalid.');
            return;
        }

        // Logarea ID-ului pentru verificare
        log_sync("✅ Request ID obținut: {$id}");

        // Verificarea dacă datele sunt gata cu isReady
        for ($i = 0; $i < 20; $i++) {
            sleep(3);
            $check = $client->isReady(['ID' => $id]);
            if ($check->return) {
                log_sync("✅ Datele sunt gata pentru descărcare.");
                // Obținem datele după ce sunt gata
                $data = $client->getDataByID(['ID' => $id]);
                $xml = $data->return->data ?? '';
                file_put_contents(WP_CONTENT_DIR . '/uploads/sync-ultra.xml', $xml);
                update_option('ultra_sync_offset', 0);
                log_sync('✅ XML salvat local.');
                return;
            }
        }

        log_sync('❌ Timeout: fișierul nu a fost pregătit.');
    } catch (\Exception $e) {
        log_sync('❌ Eroare SOAP: ' . $e->getMessage());
    }
}

// Funcție pentru sincronizarea batch-urilor de produse
function do_process_batch()
{
    $file = WP_CONTENT_DIR . '/uploads/sync-ultra.xml';
    if (!file_exists($file)) {
        log_sync('❌ XML-ul nu a fost găsit.');
        return;
    }

    $offset = intval(get_option('ultra_sync_offset', 0));
    $xml = simplexml_load_file($file);
    $items = $xml->nomenclature;
    $total = count($items);
    $count = 0;

    // Procesăm produsele în batch-uri de 200
    for ($i = $offset; $i < $total && $count < 5; $i++) {
        $product = $items[$i];
        $sku = (string) $product->article;
        $name = (string) $product->name;
        $desc = (string) $product->description;
        $active = ((string) $product->active === 'true');

        if (!$active || wc_get_product_id_by_sku($sku)) continue;

        $post_id = wp_insert_post([
            'post_title' => $name,
            'post_content' => $desc,
            'post_status' => 'publish',
            'post_type' => 'product'
        ]);

        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, '_sku', $sku);
            update_post_meta($post_id, '_uuid', (string) $product->UUID);

            // Obținem prețurile utilizând serviciul "PRICELIST"
            $price = get_product_price($sku);
            if ($price !== null) {
                update_post_meta($post_id, '_price', $price);
            }

            // Setăm prețul
            $price = (string) $product->priceGroup->name;
            update_post_meta($post_id, '_price', $price);

            // Setăm imaginea principală
            $main_image_url = (string) $product->imageList->image[0]->pathGlobal;
            $main_image_id = media_sideload_image($main_image_url, $post_id, null, 'id');
            if (!is_wp_error($main_image_id)) {
                set_post_thumbnail($post_id, $main_image_id); // Setează imaginea principală
            }

            // Setăm imagini suplimentare
            $additional_images = [];
            foreach ($product->imageList->image as $image) {
                $image_url = (string) $image->pathGlobal;
                $image_id = media_sideload_image($image_url, $post_id, null, 'id');
                if (!is_wp_error($image_id)) {
                    $additional_images[] = $image_id;
                }
            }

            if (!empty($additional_images)) {
                update_post_meta($post_id, '_product_image_gallery', implode(',', $additional_images));
            }

            // Setăm stocul
            $stock_quantity = get_product_stock($sku);
            if ($stock_quantity !== null) {
                update_post_meta($post_id, '_stock', $stock_quantity);
                update_post_meta($post_id, '_stock_status', $stock_quantity > 0 ? 'instock' : 'outofstock');
            }

            $count++;
        }
    }

    update_option('ultra_sync_offset', $offset + $count);
    log_sync("✅ Batch sincronizat: {$count} produse noi.");
}

// Funcție pentru obținerea prețului din serviciul "PRICELIST"
function get_product_price($sku)
{
    $user = 'Cobileanschi Grigore';
    $pass = '11112222';
    $wsdl = 'https://web1c.it-ultra.com/b2b/ws/b2b.1cws?wsdl';

    try {
        $client = new \SoapClient($wsdl, [
            'login' => $user,
            'password' => $pass,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ])
        ]);

        $response = $client->requestData([
            'Service' => 'PRICELIST',
            'all' => true,
            'additionalParameters' => null,
            'compress' => false
        ]);

        $id = $response->return ?? null;
        if (!$id) {
            log_sync('❌ Request ID invalid pentru prețuri.');
            return null;
        }

        for ($i = 0; $i < 20; $i++) {
            sleep(3);
            $check = $client->isReady(['ID' => $id]);
            if ($check->return) {
                $data = $client->getDataByID(['ID' => $id]);
                $price_data = $data->return->data ?? '';
                // Căutăm prețul pe baza SKU-ului
                foreach ($price_data->price as $price_item) {
                    if ((string) $price_item->nomenclatureUUID === $sku) {
                        return (string) $price_item->Price;
                    }
                }
            }
        }

        log_sync('❌ Timeout: Prețurile nu sunt pregătite.');
    } catch (\Exception $e) {
        log_sync('❌ Eroare SOAP: ' . $e->getMessage());
    }

    return null;
}

// Funcție pentru obținerea stocului din serviciul "BALANCE"
function get_product_stock($sku)
{
    $user = 'Cobileanschi Grigore';
    $pass = '11112222';
    $wsdl = 'https://web1c.it-ultra.com/b2b/ws/b2b.1cws?wsdl';

    try {
        $client = new \SoapClient($wsdl, [
            'login' => $user,
            'password' => $pass,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ])
        ]);

        $response = $client->requestData([
            'Service' => 'BALANCE',
            'all' => true,
            'additionalParameters' => null,
            'compress' => false
        ]);

        $id = $response->return ?? null;
        if (!$id) {
            log_sync('❌ Request ID invalid pentru stocuri.');
            return null;
        }

        for ($i = 0; $i < 20; $i++) {
            sleep(3);
            $check = $client->isReady(['ID' => $id]);
            if ($check->return) {
                $data = $client->getDataByID(['ID' => $id]);
                $stock_data = $data->return->data ?? '';
                // Căutăm stocul pe baza SKU-ului
                foreach ($stock_data->balance as $stock_item) {
                    if ((string) $stock_item->nomenclatureUUID === $sku) {
                        return (int) $stock_item->quantity;
                    }
                }
            }
        }

        log_sync('❌ Timeout: Stocurile nu sunt pregătite.');
    } catch (\Exception $e) {
        log_sync('❌ Eroare SOAP: ' . $e->getMessage());
    }

    return null;
}
