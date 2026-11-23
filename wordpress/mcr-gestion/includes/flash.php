<?php
/**
 * Flash sales ("Ventes éclair") and real social proof.
 *
 * A flash sale = one product, a discount (% or a set price), a start time, a duration and a
 * limited quantity. While it runs and units are left, the flash price applies everywhere
 * (cards, product page, cart, WhatsApp orders); then the normal price comes back by itself.
 * Units sold are counted from real orders (and given back if an order is cancelled).
 *
 * Social proof, only from real data:
 *  - "Plus que N en stock" when the product's stock quantity is managed and low;
 *  - "Commandé N fois ce mois-ci" from the last 30 days of real orders (minimum threshold).
 */
defined('ABSPATH') || exit;

// ---------------------------------------------------------------- Data

function mcr_flash_all(): array
{
    $all = get_option('mcr_flash', []);
    return is_array($all) ? $all : [];
}

function mcr_flash_save(array $all): void
{
    update_option('mcr_flash', $all, false);
    mcr_flash_schedule($all);
}

function mcr_flash_settings(): array
{
    return wp_parse_args((array) get_option('mcr_flash_settings', []), ['low_stock' => 5, 'sold_min' => 5, 'show_sold' => true]);
}

function mcr_flash_tz(): DateTimeZone
{
    return function_exists('mcr_store_tz') ? mcr_store_tz() : wp_timezone();
}

/** [start, end] timestamps. */
function mcr_flash_window(array $d): array
{
    try {
        $start = (new DateTimeImmutable((string) $d['start'], mcr_flash_tz()))->getTimestamp();
    } catch (Exception $e) {
        return [0, 0];
    }
    return [$start, $start + (int) round((float) $d['hours'] * HOUR_IN_SECONDS)];
}

function mcr_flash_left(array $d): int
{
    return max(0, (int) $d['qty'] - (int) ($d['sold'] ?? 0));
}

/** 'scheduled', 'active', 'soldout' or 'ended'. */
function mcr_flash_state(array $d, ?int $now = null): string
{
    $now = $now ?? time();
    [$s, $e] = mcr_flash_window($d);
    if (!$s || $now >= $e) {
        return 'ended';
    }
    if ($now < $s) {
        return 'scheduled';
    }
    return mcr_flash_left($d) > 0 ? 'active' : 'soldout';
}

/** The running flash sale of a product (or of its parent for a variation), or null. */
function mcr_flash_for_product(int $product_id, string $want = 'active'): ?array
{
    static $cache = [];
    $key = $product_id . '|' . $want;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $parent = (int) wp_get_post_parent_id($product_id);
    foreach (mcr_flash_all() as $id => $d) {
        if (in_array((int) $d['product'], array_filter([$product_id, $parent]), true) && mcr_flash_state($d) === $want) {
            return $cache[$key] = $d + ['id' => $id];
        }
    }
    return $cache[$key] = null;
}

/** Flash sales to show: running ones first, then the next ones starting within 48 h. */
function mcr_flash_showcase(): array
{
    $now = time();
    $active = $next = [];
    foreach (mcr_flash_all() as $id => $d) {
        $st = mcr_flash_state($d, $now);
        [$s] = mcr_flash_window($d);
        if ($st === 'active' || $st === 'soldout') {
            $active[] = $d + ['id' => $id, 'state' => $st];
        } elseif ($st === 'scheduled' && $s - $now < 2 * DAY_IN_SECONDS) {
            $next[] = $d + ['id' => $id, 'state' => $st];
        }
    }
    usort($active, fn($a, $b) => ($a['state'] === 'soldout') <=> ($b['state'] === 'soldout'));
    usort($next, fn($a, $b) => mcr_flash_window($a)[0] <=> mcr_flash_window($b)[0]);
    return ['active' => $active, 'next' => $next];
}

// ---------------------------------------------------------------- Price

/** Flash price for a product object (simple or variation), or null when no flash sale runs. */
function mcr_flash_price_for(WC_Product $p): ?float
{
    $d = mcr_flash_for_product($p->get_id());
    if (!$d || $p->is_type('variable')) {
        return null;
    }
    $regular = (float) $p->get_regular_price('edit');
    if ($regular <= 0) {
        return null;
    }
    $price = $d['mode'] === 'price' ? min((float) $d['value'], $regular) : $regular * (1 - min(95, max(1, (float) $d['value'])) / 100);
    return round($price, 2);
}

foreach (['woocommerce_product_get_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_sale_price'] as $hook) {
    add_filter($hook, function ($value, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $value; // the admin always shows the stored prices
        }
        $f = $product instanceof WC_Product ? mcr_flash_price_for($product) : null;
        return $f !== null ? (string) $f : $value;
    }, 99, 2);
}
add_filter('woocommerce_product_is_on_sale', function ($on, $product) {
    if (!$on && !(is_admin() && !wp_doing_ajax()) && $product instanceof WC_Product) {
        if ($product->is_type('variable')) {
            return (bool) mcr_flash_for_product($product->get_id());
        }
        return mcr_flash_price_for($product) !== null;
    }
    return $on;
}, 99, 2);
// Variable products: WooCommerce keeps variation prices in a cache; include running flash sales in its key.
add_filter('woocommerce_get_variation_prices_hash', function ($hash, $product) {
    $d = mcr_flash_for_product($product->get_id());
    $hash[] = $d ? 'flash-' . $d['id'] : 'noflash';
    return $hash;
}, 99, 2);

// ---------------------------------------------------------------- Quantity limits in the cart

function mcr_flash_cart_qty(int $product_id): int
{
    $n = 0;
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $ci) {
            if ((int) $ci['product_id'] === $product_id || (int) ($ci['variation_id'] ?? 0) === $product_id) {
                $n += (int) $ci['quantity'];
            }
        }
    }
    return $n;
}

function mcr_flash_max(array $d): int
{
    $per = (int) ($d['per_order'] ?? 0);
    return $per > 0 ? min($per, mcr_flash_left($d)) : mcr_flash_left($d);
}

add_filter('woocommerce_add_to_cart_validation', function ($ok, $pid, $qty, $vid = 0) {
    $d = mcr_flash_for_product((int) $pid);
    if ($ok && $d) {
        $max = mcr_flash_max($d);
        if (mcr_flash_cart_qty((int) $pid) + (int) $qty > $max) {
            wc_add_notice(sprintf(mcr_is_en() ? 'Flash sale: maximum %d per order for this product.' : 'Vente éclair : maximum %d par commande pour ce produit.', $max), 'error');
            return false;
        }
    }
    return $ok;
}, 20, 4);

add_action('woocommerce_check_cart_items', function () {
    $cart = WC()->cart ? WC()->cart->get_cart() : [];
    foreach ($cart as $ci) {
        $d = mcr_flash_for_product((int) $ci['product_id']);
        if ($d && mcr_flash_cart_qty((int) $ci['product_id']) > mcr_flash_max($d)) {
            wc_add_notice(sprintf(mcr_is_en() ? 'Flash sale “%1$s”: maximum %2$d per order. Please lower the quantity.' : 'Vente éclair « %1$s » : maximum %2$d par commande. Réduisez la quantité.', get_the_title((int) $ci['product_id']), mcr_flash_max($d)), 'error');
            return;
        }
    }
});

// ---------------------------------------------------------------- Counting units sold (real orders)

function mcr_flash_record_order($order_id): void
{
    $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
    if (!$order || $order->get_meta('_mcr_flash_done')) {
        return;
    }
    $all = mcr_flash_all();
    $changed = false;
    foreach ($order->get_items() as $item) {
        $pid = (int) $item->get_product_id();
        $vid = (int) $item->get_variation_id();
        $d = mcr_flash_for_product($vid ?: $pid) ?? mcr_flash_for_product($pid);
        if (!$d || !isset($all[$d['id']])) {
            continue;
        }
        $qty = (int) $item->get_quantity();
        $all[$d['id']]['sold'] = min((int) $all[$d['id']]['qty'], (int) ($all[$d['id']]['sold'] ?? 0) + $qty);
        $item->update_meta_data('_mcr_flash_id', $d['id']);
        $item->update_meta_data(mcr_is_en() ? 'Flash sale' : 'Vente éclair', mcr_is_en() ? 'yes' : 'oui');
        $item->save();
        $changed = true;
    }
    $order->update_meta_data('_mcr_flash_done', 1);
    $order->save_meta_data();
    if ($changed) {
        mcr_flash_save($all);
    }
}
add_action('woocommerce_checkout_order_processed', 'mcr_flash_record_order', 30);
add_action('woocommerce_store_api_checkout_order_processed', 'mcr_flash_record_order', 30);
add_action('init', function () { // WhatsApp orders (their status is defined by whatsapp-order.php)
    if (defined('MCR_WA_STATUS')) {
        add_action('woocommerce_order_status_' . MCR_WA_STATUS, 'mcr_flash_record_order', 30);
    }
});

// A cancelled order gives its flash units back.
function mcr_flash_release_order($order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order || !$order->get_meta('_mcr_flash_done') || $order->get_meta('_mcr_flash_released')) {
        return;
    }
    $all = mcr_flash_all();
    foreach ($order->get_items() as $item) {
        $fid = (string) $item->get_meta('_mcr_flash_id');
        if ($fid !== '' && isset($all[$fid])) {
            $all[$fid]['sold'] = max(0, (int) ($all[$fid]['sold'] ?? 0) - (int) $item->get_quantity());
        }
    }
    $order->update_meta_data('_mcr_flash_released', 1);
    $order->save_meta_data();
    mcr_flash_save($all);
}
add_action('woocommerce_order_status_cancelled', 'mcr_flash_release_order');
add_action('woocommerce_order_status_failed', 'mcr_flash_release_order');

// ---------------------------------------------------------------- Start / end: refresh prices and caches

function mcr_flash_schedule(array $all): void
{
    foreach ($all as $id => $d) {
        foreach (mcr_flash_window($d) as $ts) {
            if ($ts > time() && !wp_next_scheduled('mcr_flash_switch', [$id, $ts])) {
                wp_schedule_single_event($ts + 5, 'mcr_flash_switch', [$id, $ts]);
            }
        }
    }
}
add_action('mcr_flash_switch', function ($id) {
    $d = mcr_flash_all()[$id] ?? null;
    if ($d && function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients((int) $d['product']);
    }
    update_option('mcrt_cache_ver', (string) time()); // homepage cache rebuilt with the new price
});

// ---------------------------------------------------------------- "Commandé N fois ce mois-ci" (real orders, last 30 days)

function mcr_sold30(): array
{
    $v = get_option('mcr_sold30');
    if (!is_array($v) || (int) ($v['t'] ?? 0) < time() - DAY_IN_SECONDS) {
        $v = mcr_sold30_build();
    }
    return (array) ($v['n'] ?? []);
}

function mcr_sold30_build(): array
{
    $counts = [];
    $statuses = function_exists('mcr_real_order_statuses') ? array_diff(mcr_real_order_statuses(), ['refunded']) : ['processing', 'completed', 'on-hold'];
    $page = 1;
    do {
        $orders = wc_get_orders(['status' => $statuses, 'type' => 'shop_order', 'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS), 'limit' => 200, 'paged' => $page]);
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                $pid = (int) $item->get_product_id();
                $counts[$pid] = ($counts[$pid] ?? 0) + 1; // one per order, not per unit
            }
        }
        $page++;
    } while (count($orders) === 200 && $page < 50);
    $v = ['t' => time(), 'n' => $counts];
    update_option('mcr_sold30', $v, false);
    return $v;
}
add_action('mcr_sold30_daily', 'mcr_sold30_build');
add_action('init', function () {
    if (!wp_next_scheduled('mcr_sold30_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'mcr_sold30_daily');
    }
});

/** "Commandé 12 fois ce mois-ci", or '' below the threshold. */
function mcr_sold30_label(WC_Product $p): string
{
    $s = mcr_flash_settings();
    if (empty($s['show_sold'])) {
        return '';
    }
    $id = $p->get_parent_id() ?: $p->get_id();
    $n = (int) (mcr_sold30()[$id] ?? 0);
    if ($n < max(1, (int) $s['sold_min'])) {
        return '';
    }
    return sprintf(mcr_is_en() ? 'Ordered %d times this month' : 'Commandé %d fois ce mois-ci', $n);
}

/** Low stock quantity to show ("Plus que N"), or 0. */
function mcr_low_stock_qty(WC_Product $p): int
{
    if (!$p->managing_stock() || !$p->is_in_stock()) {
        return 0;
    }
    $q = (int) $p->get_stock_quantity();
    return $q > 0 && $q <= max(1, (int) mcr_flash_settings()['low_stock']) ? $q : 0;
}
