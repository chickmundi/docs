<?php
/**
 * Tax setup copied from the current site (data/tax.json, read from marchecheminduroy.com):
 * the WooCommerce tax options, the tax classes "1-Taxe" and "Zero tax", the Canadian rates
 * (TPS, TVQ, TVH, TVP) and each product's tax status and class.
 * Runs once on the test site. Never runs on the current site itself, which already has it.
 */
defined('ABSPATH') || exit;

function mcr_tax_data(): array
{
    static $d = null;
    if ($d === null) {
        $file = MCR_DIR . 'data/tax.json';
        $d = is_readable($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }
    return $d;
}

/** Status and class from the current site for a product ID of that site, or null. */
function mcr_tax_for_original(int $old_id): ?array
{
    $p = mcr_tax_data()['products'][(string) $old_id] ?? null;
    return is_array($p) ? ['status' => $p[0], 'class' => $p[1]] : null;
}

/** Apply the current site's tax status and class to a product; variations follow their product. */
function mcr_tax_apply_product(WC_Product $product, int $old_id): bool
{
    $t = mcr_tax_for_original($old_id);
    if (!$t) {
        return false;
    }
    $product->set_tax_status($t['status']);
    $product->set_tax_class($t['class']);
    $product->save();
    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if ($v && ($v->get_tax_class('edit') !== 'parent' || $v->get_tax_status('edit') !== 'taxable')) {
                $v->set_tax_status('taxable');
                $v->set_tax_class('parent');
                $v->save();
            }
        }
    }
    return true;
}

/** Options, classes and rates. Existing rates are replaced by the current site's. */
function mcr_tax_apply_settings(): array
{
    global $wpdb;
    $d = mcr_tax_data();
    foreach ((array) ($d['options'] ?? []) as $k => $v) {
        update_option($k, $v);
    }
    $have = array_column(WC_Tax::get_tax_rate_classes(), 'slug');
    foreach ((array) ($d['classes'] ?? []) as $name) {
        if (!in_array(sanitize_title($name), $have, true)) {
            WC_Tax::create_tax_class($name, sanitize_title($name));
        }
    }
    foreach ($wpdb->get_col("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates") as $id) {
        WC_Tax::_delete_tax_rate((int) $id);
    }
    foreach ((array) ($d['rates'] ?? []) as $r) {
        $id = WC_Tax::_insert_tax_rate([
            'tax_rate_country' => $r['country'],
            'tax_rate_state' => $r['state'],
            'tax_rate' => $r['rate'],
            'tax_rate_name' => $r['name'],
            'tax_rate_priority' => (int) $r['priority'],
            'tax_rate_compound' => $r['compound'] ? 1 : 0,
            'tax_rate_shipping' => $r['shipping'] ? 1 : 0,
            'tax_rate_order' => (int) $r['order'],
            'tax_rate_class' => $r['class'] === 'standard' ? '' : $r['class'],
        ]);
        if ($r['postcodes']) {
            WC_Tax::_update_tax_rate_postcodes($id, implode(';', $r['postcodes']));
        }
        if ($r['cities']) {
            WC_Tax::_update_tax_rate_cities($id, implode(';', $r['cities']));
        }
    }
    return ['rates' => count((array) ($d['rates'] ?? []))];
}

// One-time run on the test site, in batches so a page load never times out.
add_action('admin_init', function () {
    $state = get_option('mcr_tax_v1');
    if ((is_array($state) && !empty($state['done'])) || !current_user_can('manage_woocommerce') || wp_doing_ajax() || !class_exists('WC_Tax')) {
        return;
    }
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (in_array($host, ['marchecheminduroy.com', 'www.marchecheminduroy.com'], true) || !mcr_tax_data()) {
        return;
    }
    $state = is_array($state) ? $state : ['offset' => 0, 'products' => 0];
    if (empty($state['settings'])) {
        $state['settings'] = mcr_tax_apply_settings();
    }
    $ids = array_keys((array) (mcr_tax_data()['products'] ?? []));
    $batch = array_slice($ids, (int) $state['offset'], 250);
    foreach ($batch as $old) {
        $cur = function_exists('mcr_current_product_id') ? mcr_current_product_id((int) $old) : 0;
        $product = $cur ? wc_get_product($cur) : null;
        if ($product && mcr_tax_apply_product($product, (int) $old)) {
            $state['products']++;
        }
    }
    $state['offset'] += count($batch);
    $state['done'] = $state['offset'] >= count($ids);
    update_option('mcr_tax_v1', $state, false);
    if ($state['done']) {
        WC_Cache_Helper::invalidate_cache_group('taxes');
        wc_delete_product_transients();
    }
});
