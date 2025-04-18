<?php

namespace UltraB2BProductSync;

if (!defined('ABSPATH')) exit;

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

function render_sync_page()
{
?>
    <div class="wrap">
        <h1>Ultra B2B - Sincronizare Produse</h1>
        <form method="post">
            <?php submit_button('📥 Descarcă Nomenclatura', 'primary', 'download_nomenclature'); ?>
            <?php submit_button('🔄 Procesează următorul batch (200)', 'secondary', 'process_batch'); ?>
        </form>
        <hr>
        <div>
            <h2>Log sincronizare</h2>
            <pre><?php
                    $log = get_option('ultra_b2b_sync_log', []);
                    if (is_array($log)) {
                        echo esc_html(implode("\n", array_slice($log, -50)));
                    } else {
                        echo esc_html($log);
                    }
                    ?></pre>
        </div>
        <form method="post">
            <?php submit_button('🗑️ Șterge Loguri', 'secondary', 'clear_logs'); ?>
        </form>
    </div>
<?php

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['download_nomenclature'])) {
            do_download_xml();  // Funcția care descarcă doar nomenclatura
        }

        if (isset($_POST['process_batch'])) {
            do_process_batch();
        }

        if (isset($_POST['clear_logs'])) {
            clear_logs();  // Funcția care șterge logurile
        }
    }
}
