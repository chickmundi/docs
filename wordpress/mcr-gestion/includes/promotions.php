<?php
/**
 * Promotions: campaigns, quantity deals, kits, "promo de la semaine", free-delivery progress.
 *
 * Three kinds of campaign (MCR Gestion > Promotions):
 *  - percent : "-15 % sur les crochets du 1er au 15 décembre". Written as real WooCommerce
 *              sale prices with sale dates, so prices start and end on their own and every
 *              part of the shop (cards, filters, sorting, cart, reports) sees them.
 *              The prices a product had before are kept and put back when the campaign is removed.
 *  - qty     : "2 pour 5 $" on chosen products, applied in the cart (and WhatsApp orders).
 *  - bundle  : "Kit fufu" = several products together for one price, applied in the cart.
 * Where a campaign applies ("where"): online, in store, or both.
 *  - online / both : as above (sale prices and cart deals on the website).
 *  - store         : nothing changes on the website's prices or cart; the campaign is shown as
 *                    "En magasin seulement" (Promotions page, product pages, banner), and the team
 *                    prints the price sheet for the cash register (MCR Gestion > Promotions).
 * Campaigns saved before this option existed were online, and stay online.
 * Only real prices are shown; a countdown appears only when a real end date exists.
 */
defined('ABSPATH') || exit;

// ---------------------------------------------------------------- Data

function mcr_promos(): array
{
    $all = get_option('mcr_promos', []);
    return is_array($all) ? $all : [];
}

function mcr_promo_save_all(array $all): void
{
    update_option('mcr_promos', $all, false);
    delete_transient('mcr_promo_active');
}

/** Start and end of a campaign as timestamps (store time zone, whole days). */
function mcr_promo_range(array $c): array
{
    $tz = function_exists('mcr_store_tz') ? mcr_store_tz() : wp_timezone();
    $from = !empty($c['start']) ? (new DateTimeImmutable($c['start'] . ' 00:00:00', $tz))->getTimestamp() : 0;
    $to = !empty($c['end']) ? (new DateTimeImmutable($c['end'] . ' 23:59:59', $tz))->getTimestamp() : 0;
    return [$from, $to];
}

/** 'scheduled', 'active' or 'ended'. */
function mcr_promo_state(array $c, ?int $now = null): string
{
    $now = $now ?? time();
    [$from, $to] = mcr_promo_range($c);
    if ($to && $now > $to) {
        return 'ended';
    }
    return $from && $now < $from ? 'scheduled' : 'active';
}

function mcr_promo_title(array $c, ?bool $en = null): string
{
    $en = $en ?? mcr_is_en();
    return trim((string) ($en && !empty($c['title_en']) ? $c['title_en'] : ($c['title_fr'] ?? '')));
}

/** Short deal label shown on cards: "2 pour 5,00 $", "Kit 24,99 $", "-15 %". */
function mcr_promo_label(array $c, ?bool $en = null): string
{
    $en = $en ?? mcr_is_en();
    $money = fn($v) => html_entity_decode(wp_strip_all_tags(wc_price((float) $v)));
    return match ($c['type'] ?? '') {
        'qty' => sprintf($en ? '%d for %s' : '%d pour %s', (int) $c['qty'], $money($c['price'])),
        'bundle' => sprintf($en ? 'Kit %s' : 'Kit %s', $money($c['price'])),
        default => '-' . (int) ($c['percent'] ?? 0) . ' %',
    };
}

/** 'online', 'store' or 'both'. */
function mcr_promo_where(array $c): string
{
    $w = $c['where'] ?? 'online';
    return in_array($w, ['online', 'store', 'both'], true) ? $w : 'online';
}

/** The campaign changes prices or the cart on the website. */
function mcr_promo_online(array $c): bool
{
    return mcr_promo_where($c) !== 'store';
}

/** Percent campaigns that write sale prices on the website. */
function mcr_promo_writes_prices(array $c): bool
{
    return ($c['type'] ?? '') === 'percent' && mcr_promo_online($c);
}

/** "En magasin seulement", "En ligne seulement", "En ligne et en magasin". */
function mcr_promo_where_label(array $c, ?bool $en = null): string
{
    $en = $en ?? mcr_is_en();
    return match (mcr_promo_where($c)) {
        'store' => $en ? 'In store only' : 'En magasin seulement',
        'both' => $en ? 'Online and in store' : 'En ligne et en magasin',
        default => $en ? 'Online only' : 'En ligne seulement',
    };
}

/**
 * Where a product's current sale price applies: '' (not on sale), 'online', 'store' or 'both'.
 * Prices written by a campaign follow the campaign; prices set by hand on the product are online only.
 */
function mcr_product_sale_where(WC_Product $p): string
{
    if (!$p->is_on_sale()) {
        return '';
    }
    $ids = $p->is_type('variable') ? array_merge([$p->get_id()], $p->get_children()) : [$p->get_id()];
    foreach ($ids as $id) {
        $promo = (string) get_post_meta($id, '_mcr_promo', true);
        if ($promo !== '' && ($c = mcr_promos()[$promo] ?? null)) {
            return mcr_promo_where($c);
        }
    }
    return 'online';
}

/** The product's current offer (sale price, or else its cart deals) is for online orders only. */
function mcr_product_promo_online_only(WC_Product $p): bool
{
    $w = mcr_product_sale_where($p);
    if ($w !== '') {
        return $w === 'online';
    }
    $deals = mcr_product_deals($p);
    return $deals && !array_filter($deals, fn($d) => mcr_promo_where($d) === 'both');
}

/** Product IDs targeted by a campaign (explicit products + every product of the chosen categories). */
function mcr_promo_target_ids(array $c): array
{
    $ids = array_map('absint', (array) ($c['products'] ?? []));
    $cats = array_filter(array_map('absint', (array) ($c['cats'] ?? [])));
    if ($cats && ($c['type'] ?? '') !== 'bundle') {
        $ids = array_merge($ids, get_posts([
            'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true,
            'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cats, 'include_children' => true]],
        ]));
    }
    return array_values(array_unique(array_filter($ids)));
}

/** Active cart deals (qty and bundle), cached for the request. */
function mcr_promo_active_deals(): array
{
    static $deals = null;
    if ($deals !== null) {
        return $deals;
    }
    $deals = [];
    foreach (mcr_promos() as $id => $c) {
        if (in_array($c['type'] ?? '', ['qty', 'bundle'], true) && mcr_promo_online($c) && mcr_promo_state($c) === 'active' && empty($c['deleted'])) {
            $c['id'] = $id;
            $c['targets'] = mcr_promo_target_ids($c);
            $deals[] = $c;
        }
    }
    return $deals;
}

/** Active deals for one product (by product or parent ID). */
function mcr_product_deals(WC_Product $p): array
{
    $ids = [$p->get_id(), $p->get_parent_id()];
    return array_values(array_filter(mcr_promo_active_deals(), fn($d) => (bool) array_intersect($ids, $d['targets'])));
}

/** Active in-store-only campaigns, with their products (cached for the request). */
function mcr_promo_store_campaigns(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    foreach (mcr_promos() as $id => $c) {
        if (mcr_promo_where($c) === 'store' && empty($c['deleted']) && mcr_promo_state($c) === 'active') {
            $c['id'] = $id;
            $c['targets'] = mcr_promo_target_ids($c);
            $list[] = $c;
        }
    }
    return $list;
}

/** In-store-only campaigns for one product. */
function mcr_product_store_deals(WC_Product $p): array
{
    $ids = [$p->get_id(), $p->get_parent_id()];
    return array_values(array_filter(mcr_promo_store_campaigns(), fn($d) => (bool) array_intersect($ids, $d['targets'])));
}

/** Price of a product with a percent campaign, from its regular price (0 when unknown). */
function mcr_promo_percent_price(WC_Product $p, array $c): float
{
    $reg = (float) ($p->is_type('variable') ? $p->get_variation_regular_price('min') : $p->get_regular_price('edit'));
    return $reg > 0 ? round($reg * (1 - (float) ($c['percent'] ?? 0) / 100), wc_get_price_decimals()) : 0.0;
}

// ---------------------------------------------------------------- Percent campaigns: real sale prices

function mcr_promo_apply_product(WC_Product $p, string $id, array $c): bool
{
    $targets = $p->is_type('variable') ? array_filter(array_map('wc_get_product', $p->get_children())) : [$p];
    $done = false;
    [$from, $to] = mcr_promo_range($c);
    foreach ($targets as $t) {
        $reg = (float) $t->get_regular_price('edit');
        if ($reg <= 0) {
            continue;
        }
        $mine = (string) $t->get_meta('_mcr_promo');
        $has_own = $mine === '' && $t->get_sale_price('edit') !== '';
        if ($has_own && !empty($c['skip_existing'])) {
            continue;
        }
        if ($mine !== '' && $mine !== $id) {
            continue; // already in another campaign
        }
        if ($mine === '') {
            $t->update_meta_data('_mcr_promo_prev', [
                'sale' => $t->get_sale_price('edit'),
                'from' => $t->get_date_on_sale_from('edit') ? $t->get_date_on_sale_from('edit')->getTimestamp() : null,
                'to' => $t->get_date_on_sale_to('edit') ? $t->get_date_on_sale_to('edit')->getTimestamp() : null,
            ]);
        }
        $t->update_meta_data('_mcr_promo', $id);
        $t->set_sale_price((string) wc_format_decimal($reg * (1 - (float) $c['percent'] / 100), wc_get_price_decimals()));
        $t->set_date_on_sale_from($from ?: null);
        $t->set_date_on_sale_to($to ?: null);
        $t->save();
        $done = true;
    }
    if ($done && $p->is_type('variable')) {
        WC_Product_Variable::sync($p->get_id());
        wc_delete_product_transients($p->get_id());
    }
    return $done;
}

function mcr_promo_restore_product(WC_Product $p, string $id): void
{
    $targets = $p->is_type('variable') ? array_filter(array_map('wc_get_product', $p->get_children())) : [$p];
    foreach ($targets as $t) {
        if ((string) $t->get_meta('_mcr_promo') !== $id) {
            continue;
        }
        $prev = (array) $t->get_meta('_mcr_promo_prev');
        $t->set_sale_price((string) ($prev['sale'] ?? ''));
        $t->set_date_on_sale_from($prev['from'] ?? null);
        $t->set_date_on_sale_to($prev['to'] ?? null);
        $t->delete_meta_data('_mcr_promo');
        $t->delete_meta_data('_mcr_promo_prev');
        $t->save();
    }
    if ($p->is_type('variable')) {
        WC_Product_Variable::sync($p->get_id());
        wc_delete_product_transients($p->get_id());
    }
}

/** Products (or parents of variations) currently written by a campaign. */
function mcr_promo_written_ids(string $id): array
{
    $ids = get_posts(['post_type' => ['product', 'product_variation'], 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_mcr_promo', 'meta_value' => $id]);
    return array_values(array_unique(array_map(fn($pid) => wp_get_post_parent_id($pid) ?: $pid, $ids)));
}

/** Queue the work: restore what was written, then apply (in batches, in the background). */
function mcr_promo_queue(string $id, string $job): void
{
    $all = mcr_promos();
    if (!isset($all[$id])) {
        return;
    }
    $ids = $job === 'apply' ? mcr_promo_target_ids($all[$id]) : mcr_promo_written_ids($id);
    $all[$id]['job'] = ['type' => $job, 'total' => count($ids), 'done' => 0, 'count' => (int) ($all[$id]['job']['count'] ?? 0)];
    if ($job === 'apply') {
        $all[$id]['job']['count'] = 0;
    }
    mcr_promo_save_all($all);
    foreach (array_chunk($ids, 40) as $chunk) {
        as_enqueue_async_action('mcr_promo_batch', [$id, $job, $chunk], 'mcr');
    }
    if (!$ids) {
        as_enqueue_async_action('mcr_promo_batch', [$id, $job, []], 'mcr');
    }
}

add_action('mcr_promo_batch', function ($id, $job, $chunk) {
    $all = mcr_promos();
    $c = $all[$id] ?? null;
    $n = 0;
    foreach ((array) $chunk as $pid) {
        $p = wc_get_product((int) $pid);
        if (!$p) {
            continue;
        }
        if ($job === 'apply' && $c) {
            $n += mcr_promo_apply_product($p, $id, $c) ? 1 : 0;
        } else {
            mcr_promo_restore_product($p, $id);
        }
    }
    $all = mcr_promos(); // fresh copy: other batches may have run
    if (isset($all[$id]['job'])) {
        $all[$id]['job']['done'] += count((array) $chunk);
        $all[$id]['job']['count'] += $n;
        if ($all[$id]['job']['done'] >= $all[$id]['job']['total']) {
            $all[$id]['applied'] = $job === 'apply' ? $all[$id]['job']['count'] : 0;
            $all[$id]['job']['finished'] = time();
            if ($job === 'restore' && !empty($all[$id]['deleted'])) {
                unset($all[$id]);
            } elseif ($job === 'restore' && !empty($all[$id]['reapply'])) {
                unset($all[$id]['reapply']);
                mcr_promo_save_all($all);
                mcr_promo_queue($id, 'apply');
                return;
            }
        }
        mcr_promo_save_all($all);
    }
    delete_transient('wc_products_onsale');
}, 10, 3);

// Prices switch exactly at the start and the end, not only at WooCommerce's nightly run.
add_action('mcr_promo_switch', function () {
    if (function_exists('wc_scheduled_sales')) {
        wc_scheduled_sales();
    }
    delete_transient('wc_products_onsale');
    delete_transient('mcr_promo_active');
    if (function_exists('mcrt_cache_flush')) {
        mcrt_cache_flush(); // home page sections show the new prices right away
    }
});
function mcr_promo_schedule_switches(array $c): void
{
    [$from, $to] = mcr_promo_range($c);
    foreach ([$from, $to ? $to + 60 : 0] as $ts) {
        if ($ts > time()) {
            as_schedule_single_action($ts, 'mcr_promo_switch', [], 'mcr');
        }
    }
}

/**
 * Create or update a campaign from raw input (admin form or the store app).
 * Returns the campaign ID, or a WP_Error with a message for the user.
 */
function mcr_promo_upsert(array $in)
{
    $all = mcr_promos();
    $id = sanitize_key($in['id'] ?? '') ?: 'p' . strtolower(wp_generate_password(6, false, false));
    $old = $all[$id] ?? null;
    $type = in_array($in['type'] ?? '', ['percent', 'qty', 'bundle'], true) ? $in['type'] : 'percent';
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
    $c = [
        'type' => $type,
        'title_fr' => sanitize_text_field($in['title_fr'] ?? ''),
        'title_en' => sanitize_text_field($in['title_en'] ?? ''),
        'text_fr' => sanitize_text_field($in['text_fr'] ?? ''),
        'text_en' => sanitize_text_field($in['text_en'] ?? ''),
        'percent' => max(1, min(90, (int) ($in['percent'] ?? 10))),
        'qty' => max(2, min(20, (int) ($in['qty'] ?? 2))),
        'price' => max(0, (float) str_replace(',', '.', (string) ($in['price'] ?? 0))),
        'cats' => array_map('absint', (array) ($in['cats'] ?? [])),
        'products' => array_values(array_filter(array_map('absint', (array) ($in['products'] ?? [])))),
        'start' => $date($in['start'] ?? ''),
        'end' => $date($in['end'] ?? ''),
        'banner' => !empty($in['banner']),
        'skip_existing' => !empty($in['skip_existing']),
        'where' => in_array($in['where'] ?? '', ['online', 'store', 'both'], true) ? $in['where'] : ($old ? mcr_promo_where($old) : 'both'),
        'created' => $old['created'] ?? time(),
    ];
    if ($type === 'bundle') {
        $c['cats'] = [];
    }
    $error = '';
    if ($c['title_fr'] === '') {
        $error = 'Donnez un nom à la promotion.';
    } elseif (!$c['cats'] && !$c['products']) {
        $error = 'Choisissez au moins une catégorie ou un produit.';
    } elseif ($type === 'bundle' && count($c['products']) < 2) {
        $error = 'Un kit doit contenir au moins deux produits.';
    } elseif ($type !== 'percent' && $c['price'] <= 0) {
        $error = 'Indiquez le prix de l’offre.';
    } elseif ($c['start'] && $c['end'] && $c['end'] < $c['start']) {
        $error = 'La date de fin est avant la date de début.';
    } elseif (mcr_promo_writes_prices($c) && !$c['end']) {
        $error = 'Une promotion en % doit avoir une date de fin (les prix reviennent seuls à la fin).';
    }
    if ($error) {
        return new WP_Error('mcr_promo', $error, ['status' => 400, 'id' => $id, 'c' => $c, 'old' => (bool) $old]);
    }
    $all[$id] = $c + ['applied' => $old['applied'] ?? 0];
    mcr_promo_save_all($all);
    if (mcr_promo_writes_prices($c)) {
        // Rewrite the prices: put back what this campaign wrote, then apply again.
        if ($old && mcr_promo_writes_prices($old)) {
            $all[$id]['reapply'] = true;
            mcr_promo_save_all($all);
            mcr_promo_queue($id, 'restore');
        } else {
            mcr_promo_queue($id, 'apply');
        }
        mcr_promo_schedule_switches($c);
    } elseif ($old && mcr_promo_writes_prices($old)) {
        mcr_promo_queue($id, 'restore'); // now in store only, or no longer a % campaign: website prices go back
    }
    return $id;
}

/** Remove a campaign (percent campaigns first put the original prices back). */
function mcr_promo_remove(string $id): bool
{
    $all = mcr_promos();
    if (!isset($all[$id])) {
        return false;
    }
    if (mcr_promo_writes_prices($all[$id])) {
        $all[$id]['deleted'] = true;
        mcr_promo_save_all($all);
        mcr_promo_queue($id, 'restore');
    } else {
        unset($all[$id]);
        mcr_promo_save_all($all);
    }
    return true;
}

// ---------------------------------------------------------------- Cart deals (qty, bundle)

/**
 * Discount per line for a list of lines [key => [product, qty, unit_price]].
 * Returns [key => ['discount' => float, 'labels' => string[]]].
 */
function mcr_deals_for_lines(array $lines): array
{
    $out = [];
    $deals = mcr_promo_active_deals();
    if (!$deals) {
        return $out;
    }
    $idOf = fn(WC_Product $p) => [$p->get_id(), $p->get_parent_id()];
    foreach ($deals as $d) {
        $label = mcr_promo_title($d) ?: mcr_promo_label($d);
        if ($d['type'] === 'qty') {
            $n = max(2, (int) $d['qty']);
            $price = (float) $d['price'];
            foreach ($lines as $k => [$p, $qty, $unit]) {
                if (!array_intersect($idOf($p), $d['targets']) || $qty < $n) {
                    continue;
                }
                $save = floor($qty / $n) * ($n * $unit - $price);
                if ($save > 0.004) {
                    $out[$k]['discount'] = ($out[$k]['discount'] ?? 0) + $save;
                    $out[$k]['labels'][] = mcr_promo_label($d);
                }
            }
        } elseif ($d['type'] === 'bundle') {
            $need = array_map('absint', (array) $d['products']);
            if (count($need) < 2) {
                continue;
            }
            $have = [];
            foreach ($need as $pid) {
                foreach ($lines as $k => [$p, $qty, $unit]) {
                    if (in_array($pid, $idOf($p), true)) {
                        $have[$pid][] = $k;
                    }
                }
            }
            if (count($have) < count($need)) {
                continue;
            }
            // Number of complete kits, and the normal price of one kit.
            $sets = PHP_INT_MAX;
            $normal = 0.0;
            foreach ($need as $pid) {
                $q = array_sum(array_map(fn($k) => $lines[$k][1], $have[$pid]));
                $sets = min($sets, $q);
                $normal += (float) $lines[$have[$pid][0]][2];
            }
            $save = $sets * ($normal - (float) $d['price']);
            if ($save <= 0.004) {
                continue;
            }
            foreach ($need as $pid) {
                $k = $have[$pid][0];
                $share = $save * ((float) $lines[$k][2] / $normal);
                $out[$k]['discount'] = ($out[$k]['discount'] ?? 0) + $share;
                $out[$k]['labels'][] = $label;
            }
        }
    }
    return $out;
}

add_action('woocommerce_before_calculate_totals', function (WC_Cart $cart) {
    if (!mcr_promo_active_deals()) {
        return;
    }
    $lines = [];
    foreach ($cart->get_cart() as $k => $ci) {
        $fresh = wc_get_product($ci['variation_id'] ?: $ci['product_id']);
        if ($fresh) {
            $lines[$k] = [$fresh, (int) $ci['quantity'], (float) $fresh->get_price()];
        }
    }
    $deals = mcr_deals_for_lines($lines);
    foreach ($cart->get_cart() as $k => $ci) {
        if (!isset($lines[$k])) {
            continue;
        }
        [, $qty, $unit] = $lines[$k];
        $disc = $deals[$k]['discount'] ?? 0;
        $cart->cart_contents[$k]['mcr_deal'] = $disc > 0 ? ['labels' => $deals[$k]['labels'], 'save' => $disc] : null;
        $ci['data']->set_price($disc > 0 ? max(0, ($qty * $unit - $disc) / $qty) : $unit);
    }
}, 20);

// Show the deal under the product name in the cart and in orders.
add_filter('woocommerce_get_item_data', function ($data, $ci) {
    if (!empty($ci['mcr_deal'])) {
        $data[] = ['key' => mcr_is_en() ? 'Deal' : 'Offre', 'value' => esc_html(implode(', ', array_unique($ci['mcr_deal']['labels'])) . ' · ' . sprintf(mcr_is_en() ? 'you save %s' : 'vous économisez %s', html_entity_decode(wp_strip_all_tags(wc_price($ci['mcr_deal']['save'])))))];
    }
    return $data;
}, 10, 2);
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values) {
    if (!empty($values['mcr_deal'])) {
        $item->add_meta_data(mcr_is_en() ? 'Deal' : 'Offre', implode(', ', array_unique($values['mcr_deal']['labels'])), true);
    }
}, 10, 3);

/** Same deals for an order built by hand (WhatsApp orders). */
function mcr_deals_apply_to_order(WC_Order $order): void
{
    if (!mcr_promo_active_deals()) {
        return;
    }
    $lines = [];
    foreach ($order->get_items() as $k => $item) {
        /** @var WC_Order_Item_Product $item */
        $p = $item->get_product();
        if ($p) {
            $lines[$k] = [$p, (int) $item->get_quantity(), (float) $p->get_price()];
        }
    }
    foreach (mcr_deals_for_lines($lines) as $k => $d) {
        $item = $order->get_item($k);
        $total = max(0, $lines[$k][1] * $lines[$k][2] - $d['discount']);
        $item->set_subtotal((string) $total);
        $item->set_total((string) $total);
        $item->add_meta_data(mcr_is_en() ? 'Deal' : 'Offre', implode(', ', array_unique($d['labels'])), true);
        $item->save();
    }
}

// ---------------------------------------------------------------- Kits: add every product in one tap

add_action('template_redirect', function () {
    if (empty($_GET['mcr_kit'])) { // phpcs:ignore WordPress.Security.NonceVerification -- like WooCommerce's ?add-to-cart
        return;
    }
    $id = sanitize_key(wp_unslash($_GET['mcr_kit'])); // phpcs:ignore WordPress.Security.NonceVerification
    $c = mcr_promos()[$id] ?? null;
    if ($c && ($c['type'] ?? '') === 'bundle' && mcr_promo_state($c) === 'active' && WC()->cart) {
        foreach ((array) $c['products'] as $pid) {
            $p = wc_get_product((int) $pid);
            if ($p && $p->is_purchasable() && $p->is_in_stock() && !$p->is_type('variable')) {
                WC()->cart->add_to_cart($p->get_parent_id() ?: $p->get_id(), 1, $p->get_parent_id() ? $p->get_id() : 0, $p->get_parent_id() ? $p->get_variation_attributes() : []);
            }
        }
        wc_add_notice(sprintf(mcr_is_en() ? '“%s” added to your cart.' : '« %s » ajouté au panier.', mcr_promo_title($c) ?: mcr_promo_label($c)));
    }
    wp_safe_redirect(wc_get_cart_url());
    exit;
}, 5);

// ---------------------------------------------------------------- Free delivery progress

/** ['enabled' => bool, 'min' => float, 'left' => float, 'pct' => int] for the current cart. */
function mcr_free_delivery_progress(): array
{
    $d = mcr_setting('delivery');
    $min = (float) ($d['free_min_subtotal'] ?? 0);
    if (empty($d['enabled']) || $min <= 0 || !WC()->cart) {
        return ['enabled' => false];
    }
    $sub = (float) WC()->cart->get_subtotal() - (float) WC()->cart->get_discount_total();
    return ['enabled' => true, 'min' => $min, 'left' => max(0, $min - $sub), 'pct' => (int) min(100, round($sub / $min * 100)), 'km' => (float) ($d['free_radius_km'] ?? 0)];
}

function mcr_free_delivery_bar_html(): string
{
    $f = mcr_free_delivery_progress();
    if (empty($f['enabled']) || WC()->cart->is_empty()) {
        return '';
    }
    $en = mcr_is_en();
    $money = fn($v) => wc_price($v);
    $text = $f['left'] > 0
        ? sprintf($en ? 'Only %s more for <strong>free delivery</strong> (within %s km)' : 'Plus que %s pour la <strong>livraison gratuite</strong> (jusqu’à %s km)', '<strong>' . $money($f['left']) . '</strong>', $f['km'])
        : sprintf($en ? '🎉 <strong>Free delivery unlocked</strong> (within %s km)' : '🎉 <strong>Livraison gratuite débloquée</strong> (jusqu’à %s km)', $f['km']);
    return '<div class="mcr-freebar' . ($f['left'] > 0 ? '' : ' is-done') . '" data-mcr-freebar><p>' . wp_kses_post($text) . '</p><span class="mcr-freebar__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $f['pct'] . '"><span style="width:' . (int) $f['pct'] . '%"></span></span></div>';
}

// The cart page has its own bar (theme, cart-totals.php); this one is for the checkout.
add_action('woocommerce_checkout_before_order_review', fn() => print(mcr_free_delivery_bar_html())); // phpcs:ignore
// Keep it up to date when the cart changes by AJAX.
add_filter('woocommerce_update_order_review_fragments', function ($f) {
    $f['[data-mcr-freebar]'] = mcr_free_delivery_bar_html() ?: '<div data-mcr-freebar hidden></div>';
    return $f;
});

// ---------------------------------------------------------------- "Promo de la semaine"

/** The highlighted product, if it is really on sale (or in an active deal) and in stock. */
function mcr_promo_of_week(): ?WC_Product
{
    $id = (int) get_option('mcr_promo_week', 0);
    $p = $id ? wc_get_product($id) : null;
    return $p && $p->is_visible() && $p->is_in_stock() && ($p->is_on_sale() || mcr_product_deals($p)) ? $p : null;
}

/** Active campaigns to show as banners (with their end date). */
function mcr_promo_banners(): array
{
    $out = [];
    foreach (mcr_promos() as $id => $c) {
        if (!empty($c['banner']) && mcr_promo_state($c) === 'active' && empty($c['deleted'])) {
            [, $to] = mcr_promo_range($c);
            $out[] = ['id' => $id, 'title' => mcr_promo_title($c), 'text' => trim((string) (mcr_is_en() && !empty($c['text_en']) ? $c['text_en'] : ($c['text_fr'] ?? ''))), 'label' => mcr_promo_label($c), 'ends' => $to, 'type' => $c['type'], 'cats' => (array) ($c['cats'] ?? []), 'products' => (array) ($c['products'] ?? []), 'price' => (float) ($c['price'] ?? 0), 'where' => mcr_promo_where($c), 'where_label' => mcr_promo_where_label($c)];
        }
    }
    return $out;
}

/**
 * Promotions to announce with a countdown (bar on every page, Promotions page): campaigns with a banner
 * that start within the next 7 days ("Commence dans") or are running with a real end date ("Se termine dans").
 * Campaigns with the same name are shown together, e.g. "Black Friday Promo: -10 % Épicerie · -30 % Perruques".
 */
function mcr_promo_events(): array
{
    $now = time();
    $groups = [];
    foreach (mcr_promos() as $id => $c) {
        if (empty($c['banner']) || !empty($c['deleted'])) {
            continue;
        }
        [$from, $to] = mcr_promo_range($c);
        $state = mcr_promo_state($c, $now);
        if (!($state === 'scheduled' && $from - $now <= 7 * DAY_IN_SECONDS) && !($state === 'active' && $to)) {
            continue;
        }
        $title = mcr_promo_title($c);
        $key = strtolower(remove_accents($title)) . '|' . $state;
        $names = [];
        foreach ((array) ($c['cats'] ?? []) as $cid) {
            $t = get_term((int) $cid, 'product_cat');
            if ($t && !is_wp_error($t)) {
                $names[] = $t->name;
            }
        }
        if (!$names && !empty($c['products'])) {
            $p = wc_get_product((int) $c['products'][0]);
            $names[] = $p ? $p->get_name() . (count($c['products']) > 1 ? ' +' . (count($c['products']) - 1) : '') : '';
        }
        $g = $groups[$key] ?? ['title' => $title, 'state' => $state, 'starts' => $from, 'ends' => $to, 'parts' => [], 'where' => [], 'cats' => []];
        $g['parts'][] = trim(mcr_promo_label($c) . ' ' . implode(', ', array_filter($names)));
        $g['where'][mcr_promo_where($c)] = mcr_promo_where_label($c);
        $g['cats'] = array_merge($g['cats'], (array) ($c['cats'] ?? []));
        $g['starts'] = $g['starts'] ? min($g['starts'], $from) : $from;
        $g['ends'] = max($g['ends'], $to);
        $groups[$key] = $g;
    }
    foreach ($groups as &$g) {
        $g['where_label'] = count($g['where']) === 1 ? reset($g['where']) : '';
    }
    unset($g);
    // The next thing to happen comes first: a promo about to start, or one about to end.
    usort($groups, fn($a, $b) => ($a['state'] === 'scheduled' ? $a['starts'] : $a['ends']) <=> ($b['state'] === 'scheduled' ? $b['starts'] : $b['ends']));
    return $groups;
}

/** Address of the Promotions page. */
function mcr_promotions_url(): string
{
    $id = (int) get_option('mcr_promotions_page');
    return $id && get_post_status($id) === 'publish' ? get_permalink($id) : add_query_arg('promotions', '1', function_exists('mcr_shop_url') ? mcr_shop_url() : home_url('/'));
}

// The Promotions page, once.
add_action('admin_init', function () {
    if (get_option('mcr_promotions_page') || !current_user_can('manage_woocommerce')) {
        return;
    }
    $page = get_page_by_path('promotions');
    $id = $page ? $page->ID : wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'promotions', 'post_title' => 'Promotions', 'post_content' => '']);
    if ($id && !is_wp_error($id)) {
        update_post_meta($id, '_mcr_title_en', 'Deals');
        update_option('mcr_promotions_page', $id);
    }
});
