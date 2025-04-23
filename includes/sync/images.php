<?php

namespace UltraB2BProductSync\Sync\Images;

use UltraB2BProductSync\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Process product images
 * 
 * @param int $product_id WooCommerce product ID
 * @param \SimpleXMLElement $product Product XML node
 * @return bool True on success
 */
function process_product_images($product_id, $product)
{
    $images = $product->imageList->image;
    $image_count = count($images);
    $main_image_uuid = (string) $product->mainImage;

    Utils\log_sync("🔄 Produsul are {$image_count} imagini. UUID imagine principală: {$main_image_uuid}");

    // Array for all image IDs
    $all_image_ids = [];
    $main_image_id = 0;

    // Process each image
    foreach ($images as $image) {
        $image_uuid = (string) $image->UUID;
        $image_url = (string) $image->pathGlobal;

        if (empty($image_url)) {
            continue;
        }

        Utils\log_sync("🔄 Se descarcă imaginea {$image->name} de la {$image_url}");

        // Download image
        $image_id = download_image($image_url, $product_id);

        if ($image_id > 0) {
            $all_image_ids[] = $image_id;

            // If this is the main image, mark it
            if ($image_uuid === $main_image_uuid) {
                $main_image_id = $image_id;
                Utils\log_sync("✅ Imaginea principală a fost identificată și descărcată");
            }
        }
    }

    // Set main image
    set_main_image($product_id, $main_image_id, $all_image_ids);

    // Set gallery images
    set_gallery_images($product_id, $main_image_id, $all_image_ids);

    return true;
}

/**
 * Download an image
 * 
 * @param string $url Image URL
 * @param int $product_id WooCommerce product ID
 * @return int Attachment ID or 0 on error
 */
function download_image($url, $product_id)
{
    // Skip if URL is empty
    if (empty($url)) {
        return 0;
    }

    try {
        // Download and attach image
        $image_id = media_sideload_image($url, $product_id, null, 'id');

        if (is_wp_error($image_id)) {
            Utils\log_sync("⚠️ Eroare la descărcarea imaginii: " . $image_id->get_error_message());
            return 0;
        }

        return $image_id;
    } catch (\Exception $e) {
        Utils\log_sync("⚠️ Excepție la descărcarea imaginii: " . $e->getMessage());
        return 0;
    }
}

/**
 * Set main product image
 * 
 * @param int $product_id WooCommerce product ID
 * @param int $main_image_id Main image ID
 * @param array $all_image_ids All image IDs
 * @return bool True on success
 */
function set_main_image($product_id, $main_image_id, $all_image_ids)
{
    // If we have a main image, use it
    if ($main_image_id > 0) {
        set_post_thumbnail($product_id, $main_image_id);
    } elseif (!empty($all_image_ids)) {
        // If not, use the first image
        set_post_thumbnail($product_id, $all_image_ids[0]);
    } else {
        // No images available
        return false;
    }

    return true;
}

/**
 * Set product gallery images
 * 
 * @param int $product_id WooCommerce product ID
 * @param int $main_image_id Main image ID
 * @param array $all_image_ids All image IDs
 * @return bool True on success
 */
function set_gallery_images($product_id, $main_image_id, $all_image_ids)
{
    // Need at least 2 images for a gallery
    if (count($all_image_ids) <= 1) {
        return false;
    }

    // Remove main image from gallery
    $gallery_images = $all_image_ids;
    if ($main_image_id > 0) {
        $gallery_images = array_diff($all_image_ids, [$main_image_id]);
    } else {
        // If there's no marked main image, we used the first one, so skip it in gallery
        array_shift($gallery_images);
    }

    // Set gallery
    if (!empty($gallery_images)) {
        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_images));
        Utils\log_sync("✅ Galerie de imagini setată cu " . count($gallery_images) . " imagini");
        return true;
    }

    return false;
}
