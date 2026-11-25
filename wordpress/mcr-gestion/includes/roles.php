<?php
/**
 * Staff roles for the store.
 *  - Administrator (manager): everything.
 *  - Employé (MCR): products, orders and shop settings (delivery, pickup, hours, announcement),
 *    with no sales figures, reports, payments or site settings.
 *  - Promotions (MCR): promotions, flash sales, promo of the week, welcome wheel and referrals.
 *
 * Three store permissions, given automatically to anyone who manages WooCommerce (administrators, shop managers):
 *  - mcr_manage_shop    shop settings and expiry dates
 *  - mcr_manage_promos  promotions, flash sales, wheel
 *  - mcr_view_sales     sales figures in the app (reports use WooCommerce's own view_woocommerce_reports)
 */
defined('ABSPATH') || exit;

const MCR_ROLES_VERSION = 2;

add_filter('user_has_cap', function ($all, $caps, $args) {
    if (!empty($all['manage_woocommerce'])) {
        $all['mcr_manage_shop'] = $all['mcr_manage_promos'] = $all['mcr_view_sales'] = true;
    }
    // Promotions staff pick products in the promotion forms (WooCommerce's product search asks for edit_products).
    if (!empty($all['mcr_manage_promos']) && wp_doing_ajax() && in_array($_REQUEST['action'] ?? '', ['woocommerce_json_search_products', 'woocommerce_json_search_products_and_variations', 'woocommerce_json_search_categories'], true)) { // phpcs:ignore WordPress.Security.NonceVerification
        $all['edit_products'] = true;
    }
    return $all;
}, 10, 3);

add_action('init', function () {
    if ((int) get_option('mcr_roles_version') === MCR_ROLES_VERSION) {
        return;
    }
    $products = ['edit_products', 'edit_others_products', 'edit_published_products', 'publish_products', 'read_product', 'read_private_products', 'edit_private_products',
        'manage_product_terms', 'edit_product_terms', 'assign_product_terms'];
    $orders = ['edit_shop_orders', 'edit_others_shop_orders', 'edit_published_shop_orders', 'publish_shop_orders', 'read_shop_order', 'read_private_shop_orders', 'edit_private_shop_orders'];
    remove_role('mcr_employe');
    add_role('mcr_employe', 'Employé (MCR)', array_fill_keys(array_merge(['read', 'view_admin_dashboard', 'upload_files', 'mcr_manage_shop'], $products, $orders), true));
    remove_role('mcr_promo');
    add_role('mcr_promo', 'Promotions (MCR)', array_fill_keys(['read', 'view_admin_dashboard', 'upload_files', 'mcr_manage_promos'], true));
    update_option('mcr_roles_version', MCR_ROLES_VERSION);
}, 5);

// Staff without the WordPress dashboard widgets they cannot use go straight to their work.
add_filter('login_redirect', function ($to, $requested, $user) {
    if ($user instanceof WP_User && !$user->has_cap('manage_woocommerce') && ($requested === '' || str_ends_with((string) $requested, '/wp-admin/'))) {
        if ($user->has_cap('mcr_manage_promos')) {
            return admin_url('admin.php?page=mcr-promos');
        }
        if ($user->has_cap('edit_shop_orders')) {
            return admin_url('edit.php?post_type=product');
        }
    }
    return $to;
}, 20, 3);
