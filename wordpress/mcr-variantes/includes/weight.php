<?php
/**
 * Selling by weight (meat, fish, produce): the product's price is per kg (or per lb). The customer
 * types the weight they want, sees the price, and that line costs price x weight everywhere:
 * cart, checkout, order, WhatsApp orders, the store app and reports.
 *
 * Product meta: _mcrw_on (yes), _mcrw_unit (kg|lb), _mcrw_min, _mcrw_step, _mcrw_default, _mcrw_max (0 = none).
 * Cart item: mcr_weight. Order item: visible "Poids" + hidden _mcr_weight and _mcr_weight_unit.
 */
defined('ABSPATH') || exit;

/** Settings of a product sold by weight, or null. Variations follow their parent. */
function mcrw_settings($p): ?array
{
    if (!$p instanceof WC_Product) {
        $p = wc_get_product($p);
    }
    if (!$p || !$p->is_type('simple') || $p->get_meta('_mcrw_on') !== 'yes') {
        return null;
    }
    $num = fn($k, $d) => ($v = (float) str_replace(',', '.', (string) $p->get_meta($k))) > 0 ? $v : $d;
    $step = $num('_mcrw_step', 0.1);
    $min = max($step, $num('_mcrw_min', $step));
    $max = $num('_mcrw_max', 0);
    $default = min($max ?: PHP_INT_MAX, max($min, $num('_mcrw_default', $min)));
    return ['unit' => $p->get_meta('_mcrw_unit') === 'lb' ? 'lb' : 'kg', 'min' => $min, 'step' => $step, 'max' => $max, 'default' => $default];
}

function mcrw_unit_label(string $unit): string
{
    return $unit === 'lb' ? 'lb' : 'kg';
}

/** A posted weight made valid: rounded to the step, inside min and max. WP_Error if missing or out of range. */
function mcrw_clean(WC_Product $p, $raw)
{
    $s = mcrw_settings($p);
    $w = (float) str_replace(',', '.', (string) $raw);
    $en = mcrv_en();
    if ($w <= 0) {
        return new WP_Error('mcrw', $en ? 'Enter the weight you want.' : 'Indiquez le poids voulu.');
    }
    $w = round(round($w / $s['step']) * $s['step'], 3);
    if ($w < $s['min'] - 1e-9) {
        return new WP_Error('mcrw', sprintf($en ? 'The minimum is %s.' : 'Le minimum est de %s.', mcrw_format($s['min'], $s['unit'])));
    }
    if ($s['max'] && $w > $s['max'] + 1e-9) {
        return new WP_Error('mcrw', sprintf($en ? 'Maximum %s per order line.' : 'Maximum %s par ligne de commande.', mcrw_format($s['max'], $s['unit'])));
    }
    return $w;
}

function mcrw_format(float $w, string $unit): string
{
    $txt = rtrim(rtrim(number_format($w, 3, mcrv_en() ? '.' : ',', ''), '0'), '.,');
    return $txt . "\u{00a0}" . mcrw_unit_label($unit);
}

/** Price of one line: price per unit (sale and flash prices included) x weight. */
function mcrw_line_price(WC_Product $p, float $w): float
{
    return round((float) $p->get_price() * $w, wc_get_price_decimals());
}

// ---------------------------------------------------------------- Admin: product settings (General tab)

add_action('woocommerce_product_options_pricing', function () {
    global $product_object;
    $p = $product_object;
    echo '<div class="options_group show_if_simple mcrw-admin">';
    woocommerce_wp_checkbox(['id' => '_mcrw_on', 'label' => 'Vendu au poids', 'description' => 'Le prix ci-dessus est le prix au kilo (ou à la livre). Le client choisit le poids.', 'value' => $p ? $p->get_meta('_mcrw_on') : '', 'cbvalue' => 'yes']);
    woocommerce_wp_select(['id' => '_mcrw_unit', 'label' => 'Unité', 'options' => ['kg' => 'Kilogramme (kg)', 'lb' => 'Livre (lb)'], 'value' => $p ? ($p->get_meta('_mcrw_unit') ?: 'kg') : 'kg']);
    foreach ([['_mcrw_default', 'Poids proposé', '1'], ['_mcrw_min', 'Poids minimum', '0.5'], ['_mcrw_step', 'Pas', '0.1'], ['_mcrw_max', 'Poids maximum (facultatif)', '']] as [$id, $label, $ph]) {
        woocommerce_wp_text_input(['id' => $id, 'label' => $label, 'placeholder' => $ph, 'data_type' => 'decimal', 'value' => $p ? $p->get_meta($id) : '']);
    }
    echo '</div>';
});

add_action('woocommerce_admin_process_product_object', function (WC_Product $p) {
    if (!isset($_POST['woocommerce_meta_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks this nonce before this hook
        return;
    }
    $p->update_meta_data('_mcrw_on', !empty($_POST['_mcrw_on']) ? 'yes' : ''); // phpcs:ignore
    $p->update_meta_data('_mcrw_unit', ($_POST['_mcrw_unit'] ?? '') === 'lb' ? 'lb' : 'kg'); // phpcs:ignore
    foreach (['_mcrw_default', '_mcrw_min', '_mcrw_step', '_mcrw_max'] as $k) {
        $v = wc_format_decimal(wp_unslash($_POST[$k] ?? '')); // phpcs:ignore
        $p->update_meta_data($k, $v === '' ? '' : (string) max(0, (float) $v));
    }
});

// ---------------------------------------------------------------- Shop: "25,50 $ / kg", and cards open the product page

add_filter('woocommerce_get_price_html', function ($html, $p) {
    $s = mcrw_settings($p);
    if (!$s || $html === '' || preg_match('/\b(kg|lb|lbs|livre|kilo)\b/i', wp_strip_all_tags($html))) {
        return $html; // the store already shows "par kg"
    }
    return $html . '<span class="mcrw-per">&nbsp;/&nbsp;' . esc_html(mcrw_unit_label($s['unit'])) . '</span>';
}, 200, 2);

// The quick "Add" button on product cards cannot ask for a weight: send customers to the product page.
add_filter('woocommerce_product_add_to_cart_url', fn($url, $p) => mcrw_settings($p) ? $p->get_permalink() : $url, 20, 2);
add_filter('woocommerce_product_add_to_cart_text', fn($t, $p) => mcrw_settings($p) ? (mcrv_en() ? 'Choose weight' : 'Choisir le poids') : $t, 20, 2);
add_filter('woocommerce_product_supports', fn($yes, $feature, $p) => $feature === 'ajax_add_to_cart' && mcrw_settings($p) ? false : $yes, 20, 3);
add_filter('mcr_quick_add', fn($yes, $p) => mcrw_settings($p) ? false : $yes, 10, 2);

// ---------------------------------------------------------------- Product page: the weight picker

add_filter('woocommerce_quantity_input_args', function ($args, $p) {
    if (mcrw_settings($p) && is_product()) {
        $args['min_value'] = 1;
        $args['max_value'] = 1; // one line per weight: the customer changes the weight, not the quantity
    }
    return $args;
}, 20, 2);

add_action('woocommerce_before_add_to_cart_button', function () {
    global $product;
    $s = mcrw_settings($product);
    if (!$s) {
        return;
    }
    $en = mcrv_en();
    $unit = mcrw_unit_label($s['unit']);
    $price = (float) wc_get_price_to_display($product);
    $posted = isset($_POST['mcr_weight']) ? mcrw_clean($product, wp_unslash($_POST['mcr_weight'])) : null; // phpcs:ignore WordPress.Security.NonceVerification
    $value = is_float($posted) ? $posted : $s['default'];
    $chips = array_values(array_filter($s['unit'] === 'lb' ? [1, 2, 5] : [0.5, 1, 2], fn($c) => $c >= $s['min'] - 1e-9 && (!$s['max'] || $c <= $s['max'])));
    echo '<div class="mcrw" data-price="' . esc_attr((string) $price) . '" data-step="' . esc_attr((string) $s['step']) . '" data-min="' . esc_attr((string) $s['min']) . '" data-max="' . esc_attr((string) $s['max']) . '" data-unit="' . esc_attr($unit) . '">';
    echo '<label class="mcrw__label" for="mcr_weight">' . esc_html($en ? 'Weight you want' : 'Poids voulu') . '</label>';
    echo '<div class="mcrw__row"><div class="mcrw__field"><button type="button" class="mcrw__step" data-d="-1" aria-label="' . esc_attr($en ? 'Less' : 'Moins') . '">−</button>';
    echo '<input type="number" id="mcr_weight" name="mcr_weight" inputmode="decimal" step="' . esc_attr((string) $s['step']) . '" min="' . esc_attr((string) $s['min']) . '"' . ($s['max'] ? ' max="' . esc_attr((string) $s['max']) . '"' : '') . ' value="' . esc_attr((string) $value) . '" required>';
    echo '<span class="mcrw__unit">' . esc_html($unit) . '</span><button type="button" class="mcrw__step" data-d="1" aria-label="' . esc_attr($en ? 'More' : 'Plus') . '">+</button></div>';
    if ($chips) {
        echo '<div class="mcrw__chips">';
        foreach ($chips as $c) {
            echo '<button type="button" class="mcrw__chip" data-w="' . esc_attr((string) $c) . '">' . esc_html(mcrw_format($c, $s['unit'])) . '</button>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '<p class="mcrw__total" aria-live="polite"><span>' . esc_html($en ? 'Price' : 'Prix') . '</span> <b data-mcrw-total>' . wp_kses_post(wc_price($price * $value)) . '</b> <small>' . wp_kses_post(wc_price($price)) . '&nbsp;/&nbsp;' . esc_html($unit) . '</small></p>';
    echo '</div>';
});

add_action('wp_enqueue_scripts', function () {
    if (is_product() && mcrw_settings(get_queried_object_id())) {
        wp_enqueue_style('mcrv', MCRV_URL . 'assets/front.css', [], MCRV_VERSION);
        wp_enqueue_script('mcrv', MCRV_URL . 'assets/front.js', ['jquery'], MCRV_VERSION, true);
        wp_localize_script('mcrv', 'MCRV', ['en' => mcrv_en(), 'currency' => get_woocommerce_currency_symbol(), 'decimal' => wc_get_price_decimal_separator(), 'pos' => get_option('woocommerce_currency_pos')]);
    }
}, 20);

// ---------------------------------------------------------------- Cart

add_filter('woocommerce_add_to_cart_validation', function ($ok, $product_id, $qty) {
    $p = wc_get_product($product_id);
    if (!$ok || !mcrw_settings($p)) {
        return $ok;
    }
    $w = mcrw_clean($p, wp_unslash($_REQUEST['mcr_weight'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    if (is_wp_error($w)) {
        wc_add_notice($w->get_error_message() . ' ' . $p->get_name(), 'error');
        return false;
    }
    return true;
}, 20, 3);

add_filter('woocommerce_add_cart_item_data', function ($data, $product_id) {
    $p = wc_get_product($product_id);
    if (mcrw_settings($p)) {
        $w = mcrw_clean($p, wp_unslash($_REQUEST['mcr_weight'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if (is_float($w)) {
            $data['mcr_weight'] = $w;
        }
    }
    return $data;
}, 10, 2);

add_filter('woocommerce_get_cart_item_from_session', function ($item, $values) {
    if (isset($values['mcr_weight'])) {
        $item['mcr_weight'] = (float) $values['mcr_weight'];
    }
    return $item;
}, 10, 2);

// Line price = price per unit x weight. Stored on the cart's own product copy, read by the price filter below,
// so sale and flash prices per kg are taken into account.
add_action('woocommerce_before_calculate_totals', function (WC_Cart $cart) {
    foreach ($cart->get_cart() as $ci) {
        if (!empty($ci['mcr_weight']) && mcrw_settings($ci['data'])) {
            $ci['data']->delete_meta_data('_mcrw_line');
            $per = (float) $ci['data']->get_price();
            $ci['data']->add_meta_data('_mcrw_line', round($per * (float) $ci['mcr_weight'], wc_get_price_decimals()), true);
        }
    }
}, 5);
foreach (['woocommerce_product_get_price', 'woocommerce_product_get_sale_price'] as $hook) {
    add_filter($hook, function ($price, $p) {
        $line = $p->get_meta('_mcrw_line', true);
        return $line !== '' && $line !== null ? $line : $price;
    }, 1000, 2);
}
add_filter('woocommerce_product_get_regular_price', function ($price, $p) {
    $line = $p->get_meta('_mcrw_line', true);
    return $line !== '' && $line !== null ? $line : $price;
}, 1000, 2);

add_filter('woocommerce_get_item_data', function ($data, $ci) {
    if (!empty($ci['mcr_weight']) && ($s = mcrw_settings($ci['data']))) {
        $data[] = ['key' => mcrv_en() ? 'Weight' : 'Poids', 'value' => mcrw_format((float) $ci['mcr_weight'], $s['unit'])];
    }
    return $data;
}, 10, 2);

// ---------------------------------------------------------------- Order

/** Add the weight to an order line (checkout, WhatsApp orders, admin). */
function mcrw_add_order_meta(WC_Order_Item_Product $item, float $w, string $unit): void
{
    $item->add_meta_data(mcrv_en() ? 'Weight' : 'Poids', mcrw_format($w, $unit), true);
    $item->add_meta_data('_mcr_weight', $w, true);
    $item->add_meta_data('_mcr_weight_unit', $unit, true);
}

add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values) {
    if (!empty($values['mcr_weight']) && ($s = mcrw_settings($values['data']))) {
        mcrw_add_order_meta($item, (float) $values['mcr_weight'], $s['unit']);
    }
}, 10, 3);

add_filter('woocommerce_hidden_order_itemmeta', fn($keys) => array_merge($keys, ['_mcr_weight', '_mcr_weight_unit']));
