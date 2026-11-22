<?php
/**
 * "Souvent achetés ensemble" (frequently bought together), honestly labelled:
 *  - pairs really bought together in at least MCR_FBT_MIN orders → "Souvent achetés ensemble";
 *  - otherwise the WooCommerce cross-sells chosen in the product editor (or approved from the
 *    AI optimizer) → "Pour compléter".
 * Shown on the product page (tick the items, add them in one tap) and in the cart.
 */
defined('ABSPATH') || exit;

const MCR_FBT_MIN = 2;

/** Pairs from real orders of the last 12 months: [product_id => [partner_id => orders]]. Rebuilt daily. */
function mcr_fbt_pairs(): array
{
    $pairs = get_option('mcr_fbt_pairs');
    if (is_array($pairs)) {
        return $pairs;
    }
    return mcr_fbt_rebuild();
}

function mcr_fbt_rebuild(): array
{
    $pairs = [];
    $page = 1;
    do {
        $orders = wc_get_orders([
            'limit' => 200, 'page' => $page, 'type' => 'shop_order',
            'status' => ['processing', 'completed', 'on-hold', 'mcr-preparing', 'mcr-ready', 'mcr-out'],
            'date_created' => '>' . (time() - YEAR_IN_SECONDS),
        ]);
        foreach ($orders as $o) {
            $ids = [];
            foreach ($o->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $ids[(int) $item->get_product_id()] = true;
            }
            $ids = array_keys($ids);
            foreach ($ids as $a) {
                foreach ($ids as $b) {
                    if ($a !== $b) {
                        $pairs[$a][$b] = ($pairs[$a][$b] ?? 0) + 1;
                    }
                }
            }
        }
        $page++;
    } while (count($orders) === 200 && $page < 50);
    foreach ($pairs as $a => $m) {
        arsort($m);
        $pairs[$a] = array_slice(array_filter($m, fn($n) => $n >= MCR_FBT_MIN), 0, 8, true);
        if (!$pairs[$a]) {
            unset($pairs[$a]);
        }
    }
    update_option('mcr_fbt_pairs', $pairs, false);
    return $pairs;
}
add_action('mcr_fbt_rebuild', 'mcr_fbt_rebuild');
add_action('init', function () {
    if (!wp_next_scheduled('mcr_fbt_rebuild')) {
        wp_schedule_event(strtotime('tomorrow 04:00'), 'daily', 'mcr_fbt_rebuild');
    }
});

/** Can this product be added in one tap (no options to choose)? */
function mcr_fbt_buyable(?WC_Product $p): bool
{
    return $p && $p->is_visible() && $p->is_purchasable() && $p->is_in_stock() && $p->is_type('simple');
}

/**
 * Suggestions for a product: ['label' => 'together'|'complete', 'products' => WC_Product[]].
 */
function mcr_fbt_for(int $product_id, int $limit = 2): array
{
    $pairs = mcr_fbt_pairs()[$product_id] ?? [];
    $out = [];
    foreach (array_keys($pairs) as $pid) {
        $p = wc_get_product($pid);
        if (mcr_fbt_buyable($p)) {
            $out[] = $p;
        }
        if (count($out) >= $limit) {
            return ['label' => 'together', 'products' => $out];
        }
    }
    if ($out) {
        return ['label' => 'together', 'products' => $out];
    }
    $base = wc_get_product($product_id);
    foreach ($base ? $base->get_cross_sell_ids() : [] as $pid) {
        $p = wc_get_product($pid);
        if (mcr_fbt_buyable($p)) {
            $out[] = $p;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return ['label' => 'complete', 'products' => $out];
}

// Product page: the product + its partners, ticked, with the total and one button.
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product instanceof WC_Product || !mcr_fbt_buyable($product)) {
        return;
    }
    $s = mcr_fbt_for($product->get_id(), 2);
    if (!$s['products']) {
        return;
    }
    $en = mcr_is_en();
    $items = array_merge([$product], $s['products']);
    $title = $s['label'] === 'together' ? ($en ? 'Frequently bought together' : 'Souvent achetés ensemble') : ($en ? 'Complete your order' : 'Pour compléter');
    $total = 0.0;
    echo '<section class="fbt" data-fbt aria-label="' . esc_attr($title) . '"><h2 class="fbt__title">' . esc_html($title) . '</h2><ul class="fbt__list">';
    foreach ($items as $i => $p) {
        $price = (float) wc_get_price_to_display($p);
        $total += $price;
        printf(
            '<li class="fbt__item"><label><input type="checkbox" value="%d" data-price="%s" checked%s><span class="fbt__img">%s</span><span class="fbt__name">%s%s</span><span class="fbt__price">%s</span></label></li>',
            $p->get_id(),
            esc_attr((string) $price),
            $i === 0 ? ' disabled' : '',
            $p->get_image('thumbnail', ['loading' => 'lazy', 'alt' => '']),
            $i === 0 ? '<small>' . esc_html($en ? 'This product' : 'Ce produit') . '</small>' : '',
            $i === 0 ? esc_html($p->get_name()) : '<a href="' . esc_url($p->get_permalink()) . '">' . esc_html($p->get_name()) . '</a>',
            wp_kses_post(wc_price($price))
        );
    }
    printf(
        '</ul><div class="fbt__foot"><span>%s <strong data-fbt-total>%s</strong></span><a class="btn btn--sm" data-fbt-add href="%s" rel="nofollow" data-base="%s">%s <span data-fbt-count>%d</span></a></div></section>',
        esc_html($en ? 'Total:' : 'Total :'),
        wp_kses_post(wc_price($total)),
        esc_url(add_query_arg('mcr_add', implode(',', array_map(fn($p) => $p->get_id(), $items)), $product->get_permalink())),
        esc_url($product->get_permalink()),
        esc_html($en ? 'Add to cart:' : 'Ajouter au panier :'),
        count($items)
    );
    ?>
<script>
(function () {
  var box = document.currentScript.previousElementSibling;
  if (!box || !box.matches('[data-fbt]')) return;
  var fmt = function (n) { var el = box.querySelector('[data-fbt-total]'); var cur = el.textContent; var num = cur.replace(/[^\d,.]/g, ''); var dec = num.indexOf(',') > -1 ? ',' : '.'; el.textContent = cur.replace(num, n.toFixed(2).replace('.', dec)); };
  box.addEventListener('change', function () {
    var ids = [], total = 0;
    box.querySelectorAll('input[type=checkbox]').forEach(function (c) { if (c.checked) { ids.push(c.value); total += parseFloat(c.getAttribute('data-price')) || 0; } });
    fmt(total);
    box.querySelector('[data-fbt-count]').textContent = ids.length;
    var a = box.querySelector('[data-fbt-add]');
    a.href = a.getAttribute('data-base') + (a.getAttribute('data-base').indexOf('?') > -1 ? '&' : '?') + 'mcr_add=' + ids.join(',');
  });
})();
</script>
    <?php
}, 37);

// Cart: partners of what is already in the cart.
add_action('woocommerce_after_cart_table', function () {
    if (!WC()->cart || WC()->cart->is_empty() || !function_exists('mcrt_product_row')) {
        return;
    }
    $in = [];
    foreach (WC()->cart->get_cart() as $ci) {
        $in[(int) $ci['product_id']] = true;
    }
    $together = false;
    $picked = [];
    foreach (array_keys($in) as $pid) {
        $s = mcr_fbt_for($pid, 4);
        foreach ($s['products'] as $p) {
            if (!isset($in[$p->get_id()]) && !isset($picked[$p->get_id()])) {
                $picked[$p->get_id()] = $p;
                $together = $together || $s['label'] === 'together';
            }
        }
    }
    if (!$picked) {
        return;
    }
    $en = mcr_is_en();
    echo '<section class="fbt-cart" aria-labelledby="h-fbt-cart"><h2 id="h-fbt-cart" class="fbt__title">' . esc_html($together ? ($en ? 'Often bought with your items' : 'Souvent achetés avec vos articles') : ($en ? 'Complete your order' : 'Pour compléter votre commande')) . '</h2>';
    mcrt_product_row(array_slice(array_values($picked), 0, 8), 'prow--scroll');
    echo '</section>';
});

// ?mcr_add=12,34,56 adds several simple products at once (like WooCommerce's ?add-to-cart).
add_action('wp_loaded', function () {
    if (empty($_GET['mcr_add']) || !WC()->cart) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }
    $ids = array_slice(array_filter(array_map('absint', explode(',', (string) wp_unslash($_GET['mcr_add'])))), 0, 6); // phpcs:ignore WordPress.Security.NonceVerification
    $added = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if (mcr_fbt_buyable($p) && WC()->cart->add_to_cart($id, 1)) {
            $added[] = $p->get_name();
        }
    }
    if ($added) {
        wc_add_notice(sprintf(mcr_is_en() ? 'Added to your cart: %s.' : 'Ajouté au panier : %s.', implode(', ', $added)));
    }
    wp_safe_redirect(remove_query_arg('mcr_add'));
    exit;
}, 25);
