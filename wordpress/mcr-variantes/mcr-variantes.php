<?php
/**
 * Plugin Name: MCR Variantes et vente au poids
 * Description: Choix des variantes par images (échantillons de couleur, longueurs…) et vente au poids (kg ou lb) avec prix calculé, pour le panier, la caisse, les commandes WhatsApp et l'application.
 * Version: 1.0.0
 * Author: CHICK Service
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: mcr-variantes
 */
defined('ABSPATH') || exit;

const MCRV_VERSION = '1.0.2';
define('MCRV_DIR', plugin_dir_path(__FILE__));
define('MCRV_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once MCRV_DIR . 'includes/swatches.php';
    require_once MCRV_DIR . 'includes/weight.php';
    require_once MCRV_DIR . 'includes/importer.php';
}, 15);

/** French or English, following MCR Gestion when it is there. */
function mcrv_en(): bool
{
    return function_exists('mcr_is_en') ? mcr_is_en() : str_starts_with(determine_locale(), 'en');
}
