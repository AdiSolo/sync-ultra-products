<?php

namespace UltraB2BProductSync;

if (!defined('ABSPATH')) exit;

use UltraB2BProductSync\Utils;
use UltraB2BProductSync\API;
use UltraB2BProductSync\Sync;
use UltraB2BProductSync\Core;

function add_admin_menu()
{
    add_submenu_page(
        'tools.php',
        'Ultra B2B Sync',
        'Ultra B2B Sync',
        'manage_woocommerce',
        'ultra-b2b-sync',
        __NAMESPACE__ . '\render_sync_page'
    );
}
add_action('admin_menu', __NAMESPACE__ . '\add_admin_menu');

// Process form submissions early before any output
function admin_init_handler()
{
    // Only run on our plugin page
    if (!isset($_GET['page']) || $_GET['page'] !== 'ultra-b2b-sync') {
        return;
    }

    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['download_nomenclature'])) {
            // Schedule the background download instead of running immediately
            API\schedule_nomenclature_download();
            wp_redirect(add_query_arg('action', 'download_scheduled', $_SERVER['REQUEST_URI']));
            exit;
        }

        if (isset($_POST['clear_logs'])) {
            // Use the Core function directly to ensure it works
            Core\clear_logs();
            // Redirect is handled in clear_logs()
        }

        if (isset($_POST['sync_categories'])) {
            Sync\sync_categories();
            wp_redirect(add_query_arg('action', 'categories_synced', $_SERVER['REQUEST_URI']));
            exit;
        }

        if (isset($_POST['reset_offset'])) {
            update_option('ultra_sync_offset', 0);
            Utils\log_sync('ℹ️ Offset resetat la 0. Procesarea va începe de la începutul fișierului.');
            wp_redirect(add_query_arg('action', 'offset_reset', $_SERVER['REQUEST_URI']));
            exit;
        }

        if (isset($_POST['set_offset'])) {
            $custom_offset = isset($_POST['custom_offset']) ? intval($_POST['custom_offset']) : 0;
            $custom_offset = max(0, $custom_offset); // Ensure non-negative

            update_option('ultra_sync_offset', $custom_offset);
            Utils\log_sync("ℹ️ Offset setat manual la {$custom_offset}.");
            wp_redirect(add_query_arg('action', 'offset_set', $_SERVER['REQUEST_URI']));
            exit;
        }
    }
}
add_action('admin_init', __NAMESPACE__ . '\admin_init_handler');

// Enqueue necessary scripts
function enqueue_admin_scripts($hook)
{
    if ($hook != 'tools_page_ultra-b2b-sync') {
        return;
    }

    wp_enqueue_script('jquery');

    // Add custom styles
    echo '<style>
        .ultra-progress-bar {
            height: 20px;
            background: #f1f1f1;
            width: 100%;
            border-radius: 3px;
            margin: 10px 0;
        }
        .ultra-progress-bar-inner {
            height: 100%;
            width: 0%;
            background: #0073aa;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .ultra-progress-bar-products {
            height: 100%;
            width: 0%;
            background: #46b450;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .ultra-progress-bar-batch {
            height: 100%;
            width: 0%;
            background: #ffba00;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .ultra-hidden {
            display: none;
        }
        .ultra-settings-table {
            border-collapse: collapse;
            width: 100%;
        }
        .ultra-settings-table td {
            padding: 8px;
        }
        .ultra-number-input {
            width: 80px;
        }
        .ultra-progress-text {
            margin-top: 5px;
            font-size: 13px;
            color: #555;
        }
        .ultra-stats {
            margin-top: 10px;
            font-size: 13px;
            color: #555;
        }
        .ultra-stats span {
            font-weight: bold;
        }
    </style>';
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_scripts');

function render_sync_page()
{
    // Display status messages
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        if ($action === 'download_scheduled') {
            echo '<div class="notice notice-info is-dismissible">
                <p><strong>Descărcare programată:</strong> Descărcarea XML a fost programată în fundal. 
                Acest proces poate dura 5-10 minute. Verificați logurile pentru actualizări sau reîmprospătați această pagină mai târziu.</p>
            </div>';
        } elseif ($action === 'batch_processed') {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>Succes:</strong> Batch-ul de produse a fost procesat.</p>
            </div>';
        } elseif ($action === 'logs_cleared') {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>Succes:</strong> Logurile au fost șterse.</p>
            </div>';
        } elseif ($action === 'categories_synced') {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>Succes:</strong> Categoriile au fost sincronizate.</p>
            </div>';
        } elseif ($action === 'offset_reset') {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>Succes:</strong> Offset-ul a fost resetat la 0.</p>
            </div>';
        } elseif ($action === 'offset_set') {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>Succes:</strong> Offset-ul a fost setat manual.</p>
            </div>';
        }
    }

    // Get current status
    $xml_file = WP_CONTENT_DIR . '/uploads/sync-ultra.xml';
    $xml_exists = file_exists($xml_file);
    $xml_size = $xml_exists ? size_format(filesize($xml_file)) : 'N/A';
    $xml_date = $xml_exists ? date('Y-m-d H:i:s', filemtime($xml_file)) : 'N/A';
    $offset = intval(get_option('ultra_sync_offset', 0));
    $batch_size = intval(get_option('ultra_b2b_batch_size', 5));
    $last_download = get_option('ultra_b2b_last_download', 0);
    $last_download_date = $last_download ? date('Y-m-d H:i:s', $last_download) : 'Niciodată';
    $total_products = $xml_exists ? API\count_products() : 0;

    // Calculate progress percentage
    $progress = [
        'current' => $offset,
        'total' => $total_products,
        'percentage' => ($total_products > 0) ? min(100, round(($offset / $total_products) * 100, 1)) : 0
    ];

    // Check if background process is running
    $is_download_scheduled = wp_next_scheduled('ultra_b2b_background_download');

    // Check if a batch is in progress
    $batch_data = get_option('ultra_b2b_batch_state', []);
    $is_batch_in_progress = !empty($batch_data) && isset($batch_data['in_progress']) && $batch_data['in_progress'];
?>
    <div class="wrap">
        <h1>Ultra B2B - Sincronizare Produse</h1>

        <?php if ($is_download_scheduled): ?>
            <div class="notice notice-warning">
                <p><strong>Descărcare în curs:</strong> O descărcare XML rulează în fundal. Verificați logurile pentru actualizări.</p>
            </div>
        <?php endif; ?>

        <!-- Status information -->
        <div class="card" style="max-width: 600px; margin-bottom: 20px; padding: 10px;">
            <h2>Status Sincronizare</h2>
            <table class="form-table">
                <tr>
                    <th>Fișier XML</th>
                    <td><?php echo $xml_exists ? '✅ Disponibil' : '❌ Lipsă'; ?></td>
                </tr>
                <?php if ($xml_exists): ?>
                    <tr>
                        <th>Mărime XML</th>
                        <td><?php echo esc_html($xml_size); ?></td>
                    </tr>
                    <tr>
                        <th>Dată XML</th>
                        <td><?php echo esc_html($xml_date); ?></td>
                    </tr>
                    <tr>
                        <th>Total produse în XML</th>
                        <td><?php echo esc_html($total_products); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>Ultima descărcare</th>
                    <td><?php echo esc_html($last_download_date); ?></td>
                </tr>
                <tr>
                    <th>Poziție curentă (offset)</th>
                    <td><?php echo esc_html($offset); ?></td>
                </tr>
                <tr>
                    <th>Categorii</th>
                    <td>
                        <?php
                        $category_mapping = get_option('ultra_b2b_category_mapping', []);
                        $category_count = count($category_mapping);
                        echo $category_count > 0 ? "✅ {$category_count} categorii sincronizate" : '❌ Nesincronizate';
                        ?>
                    </td>
                </tr>
            </table>

            <?php if ($xml_exists && $total_products > 0): ?>
                <h3>Progres Sincronizare Produse</h3>
                <div class="ultra-progress-bar">
                    <div class="ultra-progress-bar-products" style="width: <?php echo esc_attr($progress['percentage']); ?>%;"></div>
                </div>
                <div class="ultra-progress-text">
                    <?php echo esc_html($progress['current']); ?> din <?php echo esc_html($progress['total']); ?> produse (<?php echo esc_html($progress['percentage']); ?>%)
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="max-width: 600px; margin-bottom: 20px; padding: 10px;">
            <h2>Acțiuni</h2>

            <!-- AJAX Download Section -->
            <div id="download-section">
                <p>Descărcați fișierul XML cu nomenclatura produselor. Acest proces poate dura câteva minute.</p>
                <button id="download-button" class="button button-primary">📥 Descarcă Nomenclatura</button>

                <div id="download-progress" class="ultra-hidden">
                    <div class="ultra-progress-bar">
                        <div class="ultra-progress-bar-inner"></div>
                    </div>
                    <p class="progress-text">Pregătire descărcare...</p>
                </div>
            </div>
            <!-- Translation Download Section -->
            <?php if (!$is_download_scheduled): ?>
                <hr style="margin: 15px 0;">
                <p>Descărcați fișierul de traduceri din sistemul B2B.</p>
                <button id="translations-button" class="button button-secondary">📥 Descarcă Traduceri</button>

                <div id="translations-progress" class="ultra-hidden">
                    <div class="ultra-progress-bar">
                        <div class="ultra-progress-bar-inner"></div>
                    </div>
                    <p class="translations-progress-text">Pregătire descărcare traduceri...</p>
                </div>

                <div class="translations-status">
                    <?php
                    $translations_file = WP_CONTENT_DIR . '/uploads/translations-ultra.xml';
                    $translations_exist = file_exists($translations_file);
                    $translations_count = 0;

                    if (API\has_translations()) {
                        $translations = get_option('ultra_b2b_nomenclature_translations', []);
                        $translations_count = count($translations);
                    }

                    if ($translations_exist && $translations_count > 0):
                    ?>
                        <p style="margin-top: 10px;">✅ Traduceri disponibile: <?php echo esc_html($translations_count); ?> traduceri</p>
                    <?php else: ?>
                        <p style="margin-top: 10px;">❌ Traduceri nedisponibile sau neprocesate</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <!-- Category Sync Section -->
            <?php if (!$is_download_scheduled): ?>
                <hr style="margin: 15px 0;">
                <p>Sincronizați categoriile din sistemul B2B în WooCommerce.</p>
                <form method="post">
                    <?php submit_button('🔄 Sincronizează Categorii', 'secondary', 'sync_categories'); ?>
                </form>
            <?php endif; ?>



            <!-- Batch Processing Section -->
            <?php if ($xml_exists): ?>
                <hr style="margin: 15px 0;">
                <p>Procesați produsele din fișierul XML în timp real.</p>

                <div id="batch-controls">
                    <table class="ultra-settings-table">
                        <tr>
                            <td>Număr produse de procesat:</td>
                            <td>
                                <input type="number" id="batch-size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50" class="ultra-number-input">
                            </td>
                        </tr>
                    </table>
                    <button id="start-batch-button" class="button button-secondary">🔄 Procesează Produse</button>
                </div>

                <div id="batch-progress" class="ultra-hidden">
                    <h4>Progres procesare batch curent</h4>
                    <div class="ultra-progress-bar">
                        <div class="ultra-progress-bar-batch"></div>
                    </div>
                    <div class="batch-progress-text ultra-progress-text">Pregătire procesare...</div>

                    <div class="ultra-stats">
                        <div>Produse noi adăugate: <span id="batch-added">0</span></div>
                        <div>Produse sărite: <span id="batch-skipped">0</span></div>
                        <div>Timp scurs: <span id="batch-time">0:00</span></div>
                    </div>

                    <div style="margin-top: 10px;">
                        <button id="stop-batch-button" class="button">⏹️ Oprește Procesarea</button>
                    </div>
                </div>

                <hr style="margin: 15px 0;">
                <p>Gestionare poziție procesare (offset):</p>
                <form method="post" style="margin-bottom: 10px;">
                    <?php submit_button('⏮️ Resetează la început', 'secondary small', 'reset_offset'); ?>
                </form>

                <form method="post">
                    <table class="ultra-settings-table">
                        <tr>
                            <td>Setare manuală offset:</td>
                            <td>
                                <input type="number" name="custom_offset" value="<?php echo esc_attr($offset); ?>" min="0" max="<?php echo esc_attr($total_products ? $total_products - 1 : 999999); ?>" class="ultra-number-input">
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('💾 Salvează poziție', 'secondary small', 'set_offset'); ?>
                </form>

                <hr style="margin: 15px 0;">
                <p>Descărcați manual fișierul XML curent.</p>
                <a href="<?php echo esc_url(content_url('/uploads/sync-ultra.xml')); ?>" class="button" download>📁 Descarcă XML Manual</a>
            <?php endif; ?>
        </div>

        <div class="card" style="max-width: 800px; margin-bottom: 20px; padding: 10px;">
            <h2>Log sincronizare</h2>
            <div style="max-height: 400px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
                <pre id="sync-log" style="white-space: pre-wrap; word-wrap: break-word;">
                    <?php
                    $log = Utils\get_log();
                    echo esc_html($log);
                    ?></pre>
            </div>
            <form method="post" style="margin-top: 10px;">
                <?php submit_button('🗑️ Șterge Loguri', 'secondary', 'clear_logs'); ?>
            </form>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            var checkInterval;
            var elapsedSeconds = 0;
            var downloadId = '';

            // Batch processing variables
            var batchInterval;
            var batchElapsedSeconds = 0;
            var batchInProgress = false;

            // Attach click handler to download button
            $('#download-button').on('click', function(e) {
                e.preventDefault();
                startDownload();
            });

            // Attach click handler to batch start button
            $('#start-batch-button').on('click', function(e) {
                e.preventDefault();
                startBatchProcessing();
            });

            // Attach click handler to batch stop button
            $('#stop-batch-button').on('click', function(e) {
                e.preventDefault();
                stopBatchProcessing();
            });

            // Function to start the download process
            function startDownload() {
                $('#download-button').prop('disabled', true);
                $('#download-progress').removeClass('ultra-hidden');
                $('.progress-text').text('Inițiere descărcare...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_start_download',
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            downloadId = response.data.id;
                            $('.progress-text').text('Cerere trimisă. Așteptăm pregătirea datelor...');
                            startStatusCheck();
                        } else {
                            handleError(response.data);
                        }
                    },
                    error: function() {
                        handleError('Eroare la comunicarea cu serverul');
                    }
                });
            }

            // Function to start checking the status
            function startStatusCheck() {
                elapsedSeconds = 0;
                updateProgressBar(5); // Start with 5%

                // Check status every 10 seconds
                checkInterval = setInterval(function() {
                    elapsedSeconds += 10;
                    checkStatus();
                }, 10000);
            }

            // Function to check the status of the download
            function checkStatus() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_check_status',
                        id: downloadId,
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update log display
                            refreshLog();

                            // Calculate progress (max 80% until complete)
                            var progress = Math.min(80, elapsedSeconds / 3.6);
                            updateProgressBar(progress);

                            // Format elapsed time
                            var minutes = Math.floor(elapsedSeconds / 60);
                            var seconds = elapsedSeconds % 60;
                            var timeStr = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

                            $('.progress-text').text('Așteptăm pregătirea datelor... ' + timeStr);

                            // If data is ready, get the file
                            if (response.data.status === 'ready') {
                                clearInterval(checkInterval);
                                getFile();
                            }
                        } else {
                            handleError(response.data);
                        }
                    },
                    error: function() {
                        handleError('Eroare la verificarea statusului');
                    }
                });
            }

            // Function to get the file when it's ready
            function getFile() {
                $('.progress-text').text('Fișier pregătit! Se descarcă...');
                updateProgressBar(90);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_get_file',
                        id: downloadId,
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateProgressBar(100);
                            $('.progress-text').html('Descărcare completă! <a href="' + response.data.file_url + '" target="_blank">Descarcă fișierul</a>');

                            // Refresh the log
                            refreshLog();

                            // Refresh the page after 3 seconds to show updated status
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else {
                            handleError(response.data);
                        }
                    },
                    error: function() {
                        handleError('Eroare la descărcarea fișierului');
                    }
                });
            }

            // Function to start batch processing
            function startBatchProcessing() {
                if (batchInProgress) return;

                var batchSize = parseInt($('#batch-size').val(), 10);
                if (isNaN(batchSize) || batchSize < 1) {
                    batchSize = 5; // Default
                }

                $('#batch-controls').addClass('ultra-hidden');
                $('#batch-progress').removeClass('ultra-hidden');
                $('.batch-progress-text').text('Inițiere procesare batch...');

                // Reset stats
                $('#batch-added').text('0');
                $('#batch-skipped').text('0');
                $('#batch-time').text('0:00');

                // Reset progress bar
                $('.ultra-progress-bar-batch').css('width', '0%');

                // Start timing
                batchElapsedSeconds = 0;
                batchInProgress = true;

                // Update timer every second
                batchInterval = setInterval(function() {
                    batchElapsedSeconds++;
                    var minutes = Math.floor(batchElapsedSeconds / 60);
                    var seconds = batchElapsedSeconds % 60;
                    var timeStr = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                    $('#batch-time').text(timeStr);
                }, 1000);

                // Start the batch process
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_start_batch',
                        batch_size: batchSize,
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Process first chunk
                            processBatchChunk();
                        } else {
                            handleBatchError(response.data);
                        }
                    },
                    error: function() {
                        handleBatchError('Eroare la comunicarea cu serverul');
                    }
                });
            }

            // Function to process a chunk of the batch
            function processBatchChunk() {
                if (!batchInProgress) return;

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_process_batch_chunk',
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the progress bar
                            $('.ultra-progress-bar-batch').css('width', response.data.percentage + '%');

                            // Update stats
                            $('#batch-added').text(response.data.batch_data.processed);
                            $('#batch-skipped').text(response.data.batch_data.skipped);

                            // Update progress text
                            $('.batch-progress-text').text('Procesare: ' + response.data.percentage + '%');

                            // Refresh log
                            refreshLog();

                            // Check if complete
                            if (response.data.status === 'complete') {
                                finishBatchProcessing('Procesare completă!');
                            } else {
                                // Process next chunk
                                setTimeout(processBatchChunk, 500); // Short delay to avoid overwhelming the server
                            }
                        } else {
                            handleBatchError(response.data);
                        }
                    },
                    error: function() {
                        handleBatchError('Eroare la procesarea batch-ului');
                    }
                });
            }

            // Function to stop batch processing
            function stopBatchProcessing() {
                if (!batchInProgress) return;

                finishBatchProcessing('Procesare oprită manual.');
            }

            // Function to finish batch processing
            function finishBatchProcessing(message) {
                clearInterval(batchInterval);
                batchInProgress = false;

                $('.batch-progress-text').text(message);

                // Refresh overall progress
                refreshProgress();

                // Show controls again after a delay
                setTimeout(function() {
                    $('#batch-progress').addClass('ultra-hidden');
                    $('#batch-controls').removeClass('ultra-hidden');
                }, 5000);
            }

            // Function to handle batch errors
            function handleBatchError(message) {
                finishBatchProcessing('Eroare: ' + message);
            }

            // Function to refresh overall progress
            function refreshProgress() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_get_batch_progress',
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the overall progress bar
                            var percentage = response.data.progress.percentage;
                            $('.ultra-progress-bar-products').css('width', percentage + '%');

                            // Update progress text
                            var current = response.data.progress.current;
                            var total = response.data.progress.total;
                            $('.ultra-progress-text').text(current + ' din ' + total + ' produse (' + percentage + '%)');
                        }
                    }
                });
            }

            // Function to update the progress bar
            function updateProgressBar(percentage) {
                $('.ultra-progress-bar-inner').css('width', percentage + '%');
            }

            // Function to handle errors
            function handleError(message) {
                clearInterval(checkInterval);
                $('.progress-text').html('<span style="color:red;">Eroare: ' + message + '</span>');
                $('#download-button').prop('disabled', false);

                // Refresh the log to see error details
                refreshLog();
            }

            // Function to refresh the log display
            function refreshLog() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_get_log',
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#sync-log').html(response.data.log);
                        }
                    }
                });
            }

            // Attach click handler to translations button
            $('#translations-button').on('click', function(e) {
                e.preventDefault();
                startTranslationsDownload();
            });

            // Variables for translations download
            var translationsCheckInterval;
            var translationsElapsedSeconds = 0;

            // Function to start translations download
            function startTranslationsDownload() {
                $('#translations-button').prop('disabled', true);
                $('#translations-progress').removeClass('ultra-hidden');
                $('.translations-progress-text').text('Inițiere descărcare traduceri...');

                // Update progress bar
                $('.ultra-progress-bar-inner').css('width', '10%');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_start_translations',
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.translations-progress-text').text('Descărcare și procesare traduceri...');
                            $('.ultra-progress-bar-inner').css('width', '20%');

                            // Start polling with a delay to allow background process to start
                            translationsElapsedSeconds = 0;
                            startTranslationsStatusCheck();
                        } else {
                            handleTranslationsError(response.data);
                        }
                    },
                    error: function() {
                        handleTranslationsError('Eroare la comunicarea cu serverul');
                    }
                });
            }

            // Function to start checking translations status
            function startTranslationsStatusCheck() {
                translationsElapsedSeconds = 0;

                // First delay to give time for the background process to start and download
                setTimeout(function() {
                    // Update progress to show we're waiting
                    $('.ultra-progress-bar-inner').css('width', '30%');
                    $('.translations-progress-text').text('Se așteaptă descărcarea traducerilor...');

                    // Check status every 5 seconds
                    translationsCheckInterval = setInterval(function() {
                        translationsElapsedSeconds += 5;
                        checkTranslationsStatus();

                        // Update progress based on elapsed time (max 80% until complete)
                        var progress = Math.min(80, 30 + (translationsElapsedSeconds / 2));
                        $('.ultra-progress-bar-inner').css('width', progress + '%');

                        // Format elapsed time
                        var minutes = Math.floor(translationsElapsedSeconds / 60);
                        var seconds = translationsElapsedSeconds % 60;
                        var timeStr = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

                        $('.translations-progress-text').text('Se așteaptă descărcarea traducerilor... ' + timeStr);

                        // If it's taking too long (over 2 minutes), stop checking
                        if (translationsElapsedSeconds > 120) {
                            clearInterval(translationsCheckInterval);
                            handleTranslationsError('Timpul de așteptare a expirat. Verificați logurile pentru detalii.');
                        }
                    }, 5000);
                }, 3000); // Wait 3 seconds before starting to check
            }

            // Function to check translations status
            function checkTranslationsStatus() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ultra_b2b_check_translations',
                        security: '<?php echo wp_create_nonce('ultra_b2b_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Stop checking
                            clearInterval(translationsCheckInterval);

                            // Update progress
                            $('.ultra-progress-bar-inner').css('width', '100%');
                            $('.translations-progress-text').text('Traduceri descărcate și procesate cu succes!');

                            // Update log
                            refreshLog();

                            // Refresh the page after 2 seconds
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            // Continue checking - this is expected until the download completes
                            console.log('Încă se așteaptă: ' + response.data);
                        }
                    },
                    error: function() {
                        // Only handle as error if it's a server error, not just "translations not ready yet"
                        console.log('Eroare la verificarea statusului traducerilor');
                    }
                });
            }

            // Function to handle translations errors
            function handleTranslationsError(message) {
                // Stop checking if interval is active
                if (translationsCheckInterval) {
                    clearInterval(translationsCheckInterval);
                }

                $('.translations-progress-text').html('<span style="color:red;">Eroare: ' + message + '</span>');
                $('#translations-button').prop('disabled', false);
                $('.ultra-progress-bar-inner').css('width', '0%');

                // Refresh the log to see error details
                refreshLog();
            }
        });
    </script>
<?php
}
