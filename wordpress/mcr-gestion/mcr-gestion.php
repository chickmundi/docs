<?php
/**
 * Plugin Name: MCR Gestion
 * Description: Fonctions propres au Marché Chemin-du-Roy : français et anglais, livraison selon la distance, retrait en magasin, lots et péremption, attributs épicerie et cheveux, WhatsApp, rapports par département, page d'accueil.
 * Version: 0.2.0
 * Author: Marché Chemin-du-Roy
 * Text Domain: mcr
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 */

defined('ABSPATH') || exit;

define('MCR_VERSION', '0.2.0');
define('MCR_FILE', __FILE__);
define('MCR_DIR', plugin_dir_path(__FILE__));
define('MCR_URL', plugin_dir_url(__FILE__));

// WooCommerce feature compatibility (order tables).
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MCR_FILE, true);
    }
});

require_once MCR_DIR . 'includes/settings.php';
require_once MCR_DIR . 'includes/i18n.php';
require_once MCR_DIR . 'includes/catalog.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>MCR Gestion nécessite WooCommerce.</p></div>';
        });
        return;
    }
    require_once MCR_DIR . 'includes/delivery.php';
    require_once MCR_DIR . 'includes/orders.php';
    if (is_admin()) {
        require_once MCR_DIR . 'includes/admin-settings.php';
        require_once MCR_DIR . 'includes/import.php';
        require_once MCR_DIR . 'includes/reports.php';
        require_once MCR_DIR . 'includes/english-import.php';
    }
}, 20);

register_activation_hook(__FILE__, function () {
    mcr_register_department_taxonomy();
    mcr_ensure_departments();
    mcr_settings_install_defaults();
    flush_rewrite_rules();
});
