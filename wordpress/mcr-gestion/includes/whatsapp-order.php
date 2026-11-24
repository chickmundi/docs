<?php
/**
 * Smart "Commander via WhatsApp".
 *
 * The customer fills a short form (name, phone, delivery with its real fee or a pickup time).
 * The order is saved in WooCommerce with the status "WhatsApp – à confirmer" (staff app and
 * notification included), and WhatsApp opens with a complete message: customer, one line per
 * product with its price, subtotal, delivery, taxes, total, and a link to a summary page
 * with the product photos (WhatsApp can't carry pictures in a link, the page can).
 * Unconfirmed orders cancel themselves after a delay (setting "store.wa_expire_hours").
 */
defined('ABSPATH') || exit;

const MCR_WA_STATUS = 'mcr-whatsapp';

// ---------------------------------------------------------------- Status

add_action('init', function () {
    register_post_status('wc-' . MCR_WA_STATUS, [
        'label' => 'WhatsApp – à confirmer',
        'public' => false,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: number of orders */
        'label_count' => _n_noop('WhatsApp – à confirmer <span class="count">(%s)</span>', 'WhatsApp – à confirmer <span class="count">(%s)</span>'),
    ]);
});

add_filter('wc_order_statuses', function ($statuses) {
    $label = !is_admin() && mcr_is_en() ? 'WhatsApp – to be confirmed' : 'WhatsApp – à confirmer';
    $out = [];
    foreach ($statuses as $k => $v) {
        if ($k === 'wc-processing') {
            $out['wc-' . MCR_WA_STATUS] = $label;
        }
        $out[$k] = $v;
    }
    return $out;
}, 20);

// Can still be edited and cancelled like a pending order.
add_filter('wc_order_is_editable', fn($editable, $order) => $editable || $order->has_status(MCR_WA_STATUS), 10, 2);
add_filter('woocommerce_valid_order_statuses_for_cancel', fn($s) => array_merge((array) $s, [MCR_WA_STATUS]));

// Quick "Confirmer" button in the orders list.
add_filter('woocommerce_admin_order_actions', function ($actions, WC_Order $order) {
    if ($order->has_status(MCR_WA_STATUS)) {
        $actions = ['mcr_wa_confirm' => [
            'url' => wp_nonce_url(admin_url('admin-ajax.php?action=woocommerce_mark_order_status&status=processing&order_id=' . $order->get_id()), 'woocommerce-mark-order-status'),
            'name' => 'Confirmer (en cours)',
            'action' => 'processing',
        ]] + $actions;
    }
    return $actions;
}, 5, 2);
foreach (['bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders'] as $hook) {
    add_filter($hook, function ($actions) {
        $actions['mark_' . MCR_WA_STATUS] = 'Changer l’état : WhatsApp – à confirmer';
        return $actions;
    }, 21);
}
add_action('admin_head', function () {
    echo '<style>.order-status.status-mcr-whatsapp{background:#d9f7e3;color:#13693a}</style>';
});

// ---------------------------------------------------------------- Helpers

function mcr_wa_enabled(): bool
{
    return preg_replace('/\D/', '', (string) mcr_setting('store', 'whatsapp')) !== '';
}

function mcr_wa_money(float $v): string
{
    return html_entity_decode(wp_strip_all_tags(wc_price($v)));
}

function mcr_wa_ref(WC_Order $o): string
{
    return 'WA-' . $o->get_order_number();
}

/** Public summary page of an order (photos), e.g. https://site/fr/c/1042-k3j9x2m1q8zp/ */
function mcr_wa_summary_url(WC_Order $o, ?string $lang = null): string
{
    $key = (string) $o->get_meta('_mcr_wa_key');
    $url = untrailingslashit(get_option('home')) . '/c/' . $o->get_id() . '-' . $key . '/';
    return function_exists('mcr_prefix_url') ? mcr_prefix_url($url, $lang ?: ($o->get_meta('_mcr_lang') ?: 'fr')) : $url;
}

/** "Couleur : Noir, Longueur : 18 po" for a line, or "". */
function mcr_wa_item_options(WC_Order_Item_Product $item): string
{
    $out = [];
    foreach ($item->get_formatted_meta_data('_', true) as $m) {
        $out[] = wp_strip_all_tags($m->display_key) . ' : ' . wp_strip_all_tags($m->display_value);
    }
    return implode(', ', $out);
}

/** The WhatsApp message for an order, in the customer's language. */
function mcr_wa_message(WC_Order $o): string
{
    $en = $o->get_meta('_mcr_lang') === 'en';
    $mode = mcr_order_fulfilment($o);
    $L = [];
    $L[] = ($en ? '🛒 *New order ' : '🛒 *Nouvelle commande ') . mcr_wa_ref($o) . '* — ' . mcr_setting('store', 'name');
    $L[] = '';
    $L[] = '👤 ' . trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) . ' · ' . $o->get_billing_phone();
    if ($mode === 'pickup') {
        $slot = (string) $o->get_meta('_mcr_pickup_slot');
        $L[] = '🏪 ' . ($en ? 'In-store pickup' : 'Retrait en magasin') . ($slot && function_exists('mcr_slot_label') ? ' : ' . mcr_slot_label($slot, $en) : ' : ' . ($en ? 'as soon as possible' : 'dès que possible'));
    } else {
        $addr = implode(', ', array_filter([$o->get_billing_address_1(), $o->get_billing_city(), $o->get_billing_postcode()]));
        $km = '';
        foreach ($o->get_shipping_methods() as $sm) {
            $km = (string) $sm->get_meta('Distance');
        }
        $L[] = '🚚 ' . ($en ? 'Delivery: ' : 'Livraison : ') . $addr . ($km ? ' (' . $km . ')' : '');
    }
    $L[] = '';
    foreach ($o->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        $qty = max(1, (int) $item->get_quantity());
        $line = (float) $item->get_subtotal();
        $opts = mcr_wa_item_options($item);
        $L[] = sprintf('%d × %s%s — %s%s', $qty, $item->get_name(), $opts ? ' (' . $opts . ')' : '', mcr_wa_money($line / $qty), $qty > 1 ? ' = ' . mcr_wa_money($line) : '');
    }
    $L[] = '';
    $L[] = ($en ? 'Subtotal: ' : 'Sous-total : ') . mcr_wa_money((float) $o->get_subtotal());
    if ($mode === 'pickup') {
        $L[] = ($en ? 'Pickup: free' : 'Retrait : gratuit');
    } else {
        $L[] = ($en ? 'Delivery: ' : 'Livraison : ') . ((float) $o->get_shipping_total() > 0 ? mcr_wa_money((float) $o->get_shipping_total()) : ($en ? 'free' : 'gratuite'));
    }
    foreach ($o->get_tax_totals() as $tax) {
        $L[] = $tax->label . ($en ? ': ' : ' : ') . html_entity_decode(wp_strip_all_tags($tax->formatted_amount));
    }
    $L[] = '*' . ($en ? 'TOTAL: ' : 'TOTAL : ') . mcr_wa_money((float) $o->get_total()) . '*';
    $L[] = $en ? '💵 Payment on delivery or at pickup' : '💵 Paiement à la livraison ou au retrait';
    if ($o->get_customer_note()) {
        $L[] = '';
        $L[] = '📝 ' . $o->get_customer_note();
    }
    $L[] = '';
    $L[] = ($en ? '📸 My order with photos: ' : '📸 Ma commande avec photos : ') . mcr_wa_summary_url($o);
    return implode("\n", $L);
}

function mcr_wa_link(WC_Order $o): string
{
    $n = preg_replace('/\D/', '', (string) mcr_setting('store', 'whatsapp'));
    return 'https://wa.me/' . $n . '?text=' . rawurlencode(mcr_wa_message($o));
}

// ---------------------------------------------------------------- Creating the order

/**
 * Lines to order: the whole cart, or one product from its page.
 * Returns [[product WC_Product, qty int, variation array, weight float], …] or a WP_Error.
 * Weight is 0, or the weight chosen for a product sold by weight (MCR Variantes).
 */
function mcr_wa_requested_lines(array $in)
{
    $lines = [];
    if (($in['source'] ?? '') === 'cart') {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return new WP_Error('empty', 'empty');
        }
        foreach (WC()->cart->get_cart() as $ci) {
            $fresh = wc_get_product($ci['variation_id'] ?: $ci['product_id']);
            if ($fresh) {
                $lines[] = [$fresh, (int) $ci['quantity'], (array) ($ci['variation'] ?? []), (float) ($ci['mcr_weight'] ?? 0)];
            }
        }
        return $lines;
    }
    $pid = absint($in['product_id'] ?? 0);
    $vid = absint($in['variation_id'] ?? 0);
    $qty = max(1, min(99, absint($in['qty'] ?? 1)));
    $product = wc_get_product($vid ?: $pid);
    if (!$product || ($vid && $product->get_parent_id() !== $pid)) {
        return new WP_Error('product', 'product');
    }
    if ($product->is_type('variable')) {
        return new WP_Error('variation', 'variation');
    }
    $variation = [];
    if ($vid) {
        foreach ((array) ($in['attributes'] ?? []) as $k => $v) {
            $k = sanitize_key((string) $k);
            if (str_starts_with($k, 'attribute_')) {
                $variation[$k] = sanitize_text_field(wp_unslash((string) $v));
            }
        }
        // Attributes set on the variation itself win over what was posted.
        foreach ($product->get_variation_attributes() as $k => $v) {
            if ($v !== '') {
                $variation[$k] = $v;
            }
        }
    }
    $weight = 0.0;
    if (function_exists('mcrw_settings') && mcrw_settings($product)) {
        $w = mcrw_clean($product, wp_unslash((string) ($in['weight'] ?? '')));
        if (is_wp_error($w)) {
            return new WP_Error('weight', $w->get_error_message());
        }
        $weight = $w;
    }
    $lines[] = [$product, $qty, $variation, $weight];
    return $lines;
}

function mcr_wa_create_order(array $in)
{
    $en = ($in['lang'] ?? '') === 'en';
    $GLOBALS['mcr_lang'] = $en ? 'en' : 'fr';
    $name = trim(sanitize_text_field(wp_unslash($in['name'] ?? '')));
    $phone = preg_replace('/[^\d+]/', '', (string) ($in['phone'] ?? ''));
    $mode = ($in['mode'] ?? '') === 'pickup' ? 'pickup' : 'delivery';
    $note = trim(sanitize_textarea_field(wp_unslash($in['note'] ?? '')));
    $err = fn(string $fr, string $enm) => new WP_Error('invalid', $en ? $enm : $fr);

    if (mb_strlen($name) < 2) {
        return $err('Indiquez votre nom.', 'Please enter your name.');
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 10) {
        return $err('Indiquez un numéro de téléphone valide.', 'Please enter a valid phone number.');
    }
    if (!mcr_phone_canadian(mcr_phone_digits($digits))) {
        return $err('Seuls les numéros canadiens sont acceptés (indicatif +1, par ex. 819 372-1234).', 'Only Canadian phone numbers are accepted (+1, e.g. 819 372-1234).'); // Canada only: avoids spam and orders we cannot deliver
    }
    // North American numbers as 819-555-0123.
    if (strlen($digits) === 10 || (strlen($digits) === 11 && $digits[0] === '1')) {
        $d10 = substr($digits, -10);
        $phone = substr($d10, 0, 3) . '-' . substr($d10, 3, 3) . '-' . substr($d10, 6);
    }
    $lines = mcr_wa_requested_lines($in);
    if (is_wp_error($lines)) {
        $code = $lines->get_error_code();
        if ($code === 'weight') {
            return new WP_Error('invalid', $lines->get_error_message());
        }
        return $code === 'variation' ? $err('Choisissez d’abord une option (couleur, taille…).', 'Please choose an option first (colour, size…).') : $err('Votre panier est vide.', 'Your cart is empty.');
    }
    $subtotal = 0.0;
    foreach ($lines as [$p, $qty, , $weight]) {
        if (!$p->is_purchasable() || !$p->is_in_stock()) {
            return $err(sprintf('« %s » n’est plus disponible.', $p->get_name()), sprintf('“%s” is no longer available.', $p->get_name()));
        }
        if ($p->managing_stock() && !$p->backorders_allowed() && $p->get_stock_quantity() !== null && $qty > $p->get_stock_quantity()) {
            return $err(sprintf('Il ne reste que %d « %s ».', $p->get_stock_quantity(), $p->get_name()), sprintf('Only %d “%s” left.', $p->get_stock_quantity(), $p->get_name()));
        }
        $subtotal += $weight > 0 ? (float) wc_get_price_excluding_tax($p, ['qty' => 1, 'price' => mcrw_line_price($p, $weight)]) * $qty : (float) wc_get_price_excluding_tax($p, ['qty' => $qty]);
    }

    // Delivery: the real distance and fee. Pickup: a valid time.
    $street = trim(sanitize_text_field(wp_unslash($in['street'] ?? '')));
    $city = trim(sanitize_text_field(wp_unslash($in['city'] ?? '')));
    $postcode = strtoupper(trim(sanitize_text_field(wp_unslash($in['postcode'] ?? ''))));
    $slot = '';
    $quote = null;
    $coords = null;
    if ($mode === 'delivery') {
        if (mb_strlen($street) < 4) {
            return $err('Indiquez votre adresse de livraison.', 'Please enter your delivery address.');
        }
        $lat = (float) ($in['lat'] ?? 0);
        $lon = (float) ($in['lon'] ?? 0);
        if ($lat > 40 && $lat < 63 && $lon > -80 && $lon < -55) {
            $coords = ['lat' => $lat, 'lon' => $lon];
        } else {
            $coords = mcr_destination_coords(['address_1' => $street, 'city' => $city, 'postcode' => $postcode, 'state' => 'QC']);
        }
        if (!$coords) {
            return $err('Adresse introuvable. Choisissez-la dans la liste des suggestions.', 'Address not found. Please pick it from the suggestions.');
        }
        $quote = mcr_delivery_quote(mcr_distance_km($coords['lat'], $coords['lon']), $subtotal);
        if (!$quote['ok']) {
            return $err(sprintf('Désolé, nous ne livrons pas à cette adresse (%s). Choisissez le retrait en magasin.', mcr_format_km($quote['km'])), sprintf('Sorry, we do not deliver to this address (%s). Please choose in-store pickup.', mcr_format_km($quote['km'])));
        }
    } else {
        $slot = (string) ($in['slot'] ?? '');
        if (function_exists('mcr_slots_enabled') && mcr_slots_enabled()) {
            if ($slot === 'asap') {
                $days = mcr_pickup_slots();
                foreach ($days[0]['slots'] ?? [] as $s) {
                    if (!$s['full']) {
                        $slot = $s['value'];
                        break;
                    }
                }
            }
            if (!mcr_slot_is_valid($slot)) {
                return $err('Choisissez une heure de retrait.', 'Please choose a pickup time.');
            }
        } else {
            $slot = '';
        }
    }

    // Same request again (double tap, back button): reuse the order still waiting.
    $hash = md5(wp_json_encode([array_map(fn($l) => [$l[0]->get_id(), $l[1], $l[2]], $lines), $mode, $street, $city, $slot, $name, $phone, $note]));
    $last = WC()->session ? WC()->session->get('mcr_wa_last') : null;
    if (is_array($last) && ($last['hash'] ?? '') === $hash) {
        $prev = wc_get_order((int) $last['id']);
        if ($prev && $prev->has_status(MCR_WA_STATUS)) {
            return $prev;
        }
    }

    $order = wc_create_order(['customer_id' => get_current_user_id(), 'created_via' => 'whatsapp']);
    if (is_wp_error($order)) {
        return $order;
    }
    foreach ($lines as [$p, $qty, $variation, $weight]) {
        $args = $variation ? ['variation' => $variation] : [];
        if ($weight > 0) {
            // Sold by weight: the line costs price per kg (or lb) x weight.
            $line = wc_get_price_excluding_tax($p, ['qty' => 1, 'price' => mcrw_line_price($p, $weight)]) * $qty;
            $args += ['subtotal' => $line, 'total' => $line];
        }
        $item_id = $order->add_product($p, $qty, $args);
        if ($weight > 0 && $item_id && ($item = $order->get_item($item_id))) {
            mcrw_add_order_meta($item, $weight, mcrw_settings($p)['unit']);
            $item->save();
        }
    }
    if (function_exists('mcr_deals_apply_to_order')) {
        mcr_deals_apply_to_order($order); // "2 pour 5 $", kits
    }
    $parts = preg_split('/\s+/', $name, 2);
    $user = wp_get_current_user();
    $addr = [
        'first_name' => $parts[0],
        'last_name' => $parts[1] ?? '',
        'phone' => $phone,
        'email' => $user && $user->exists() ? $user->user_email : '',
        'address_1' => $mode === 'delivery' ? $street : '',
        'city' => $mode === 'delivery' ? $city : (string) mcr_setting('store', 'city'),
        'postcode' => $mode === 'delivery' ? $postcode : '',
        'state' => 'QC',
        'country' => 'CA',
    ];
    $order->set_address($addr, 'billing');
    if ($mode === 'delivery') {
        $order->set_address(array_diff_key($addr, ['email' => 1]), 'shipping');
    } else {
        // Taxed at the store's address.
        $order->set_address(['first_name' => $addr['first_name'], 'last_name' => $addr['last_name'], 'address_1' => (string) mcr_setting('store', 'street'), 'city' => (string) mcr_setting('store', 'city'), 'postcode' => (string) mcr_setting('store', 'postal_code'), 'state' => 'QC', 'country' => 'CA'], 'shipping');
    }

    $ship = new WC_Order_Item_Shipping();
    if ($mode === 'delivery') {
        $ship->set_method_id('mcr_delivery');
        $ship->set_method_title($en ? 'Delivery' : 'Livraison');
        $ship->set_total((string) $quote['fee']);
        $ship->add_meta_data('Distance', mcr_format_km($quote['km']), true);
        $order->update_meta_data('_mcr_delivery_coords', $coords['lat'] . ',' . $coords['lon']);
    } else {
        $ship->set_method_id('mcr_pickup');
        $ship->set_method_title(($en ? 'In-store pickup – ' : 'Retrait en magasin – ') . mcr_setting('store', 'street'));
        $ship->set_total('0');
        if ($slot) {
            $order->update_meta_data('_mcr_pickup_slot', $slot);
        }
    }
    $order->add_item($ship);
    $order->update_meta_data('_mcr_fulfilment', $mode);
    $order->update_meta_data('_mcr_lang', $en ? 'en' : 'fr');
    $order->update_meta_data('_mcr_wa_key', strtolower(wp_generate_password(12, false, false)));
    if ($note !== '') {
        $order->set_customer_note($note);
    }
    $order->set_payment_method('cod');
    $order->set_payment_method_title($en ? 'Pay on delivery or at pickup (WhatsApp order)' : 'Paiement à la réception (commande WhatsApp)');
    $order->set_customer_ip_address(WC_Geolocation::get_ip_address());
    $order->set_customer_user_agent(wc_get_user_agent());
    if (function_exists('mcr_rw_apply_to_order')) {
        mcr_rw_apply_to_order($order, $ship, ($in['source'] ?? '') === 'cart'); // welcome-wheel / referral gifts in the cart
    }
    $order->calculate_totals(true);
    $order->save();
    $order->update_status(MCR_WA_STATUS, 'Commande préparée par le client et envoyée sur WhatsApp.');

    // The previous waiting WhatsApp order of this visitor is replaced by this one.
    if (is_array($last) && !empty($last['id'])) {
        $prev = wc_get_order((int) $last['id']);
        if ($prev && $prev->has_status(MCR_WA_STATUS)) {
            $prev->update_meta_data('_mcr_push_cancelled', time());
            $prev->update_status('cancelled', sprintf('Remplacée par la commande WhatsApp n° %s.', $order->get_order_number()));
        }
    }
    if (WC()->session) {
        WC()->session->set('mcr_wa_last', ['id' => $order->get_id(), 'hash' => $hash]);
    }
    $hours = (int) (mcr_setting('store', 'wa_expire_hours') ?? 48);
    if ($hours > 0 && function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time() + $hours * HOUR_IN_SECONDS, 'mcr_wa_expire', [$order->get_id()], 'mcr');
    }
    return $order;
}

// Front-end endpoint: /?wc-ajax=mcr_wa_order (the cart and session are loaded).
add_action('wc_ajax_mcr_wa_order', function () {
    if (!mcr_wa_enabled()) {
        wp_send_json_error(['message' => 'off'], 403);
    }
    if (!empty($_POST['website'])) { // phpcs:ignore WordPress.Security.NonceVerification -- honeypot
        wp_send_json_error(['message' => 'bot'], 400);
    }
    $ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $key = 'mcr_wa_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= 8) {
        wp_send_json_error(['message' => ($_POST['lang'] ?? '') === 'en' ? 'Too many attempts, please wait a few minutes.' : 'Trop d’essais, réessayez dans quelques minutes.'], 429); // phpcs:ignore WordPress.Security.NonceVerification
    }
    $order = mcr_wa_create_order(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification -- public form, rate-limited; it only creates an unconfirmed order.
    if (is_wp_error($order)) {
        wp_send_json_error(['message' => $order->get_error_message()], 400);
    }
    set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
    wp_send_json_success([
        'ref' => mcr_wa_ref($order),
        'total' => mcr_wa_money((float) $order->get_total()),
        'wa' => mcr_wa_link($order),
        'summary' => mcr_wa_summary_url($order),
    ]);
});

// ---------------------------------------------------------------- Notifications, expiry

add_action('woocommerce_order_status_' . MCR_WA_STATUS, function ($id, $order = null) {
    $order = $order instanceof WC_Order ? $order : wc_get_order($id);
    if (!$order) {
        return;
    }
    if (function_exists('mcr_push_order')) {
        mcr_push_order($order, 'new');
    }
    // E-mail to the store too, like any new order.
    $mails = WC()->mailer()->get_emails();
    if (!empty($mails['WC_Email_New_Order']) && !$order->get_meta('_new_order_email_sent')) {
        $mails['WC_Email_New_Order']->trigger($order->get_id(), $order);
    }
}, 10, 2);

add_action('mcr_wa_expire', function ($id) {
    $o = wc_get_order((int) $id);
    if ($o && $o->has_status(MCR_WA_STATUS)) {
        $o->update_meta_data('_mcr_push_cancelled', time());
        $o->update_status('cancelled', 'Commande WhatsApp non confirmée dans le délai : annulée automatiquement.');
    }
});

// ---------------------------------------------------------------- Summary page with photos

add_action('template_redirect', function () {
    $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!preg_match('#/c/(\d+)-([a-z0-9]{12})/?$#', $path, $m)) {
        return;
    }
    $o = wc_get_order((int) $m[1]);
    if (!$o || !hash_equals((string) $o->get_meta('_mcr_wa_key'), $m[2])) {
        return; // normal 404
    }
    status_header(200);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    mcr_wa_render_summary($o);
    exit;
}, 0);

function mcr_wa_render_summary(WC_Order $o): void
{
    $en = mcr_is_en();
    $mode = mcr_order_fulfilment($o);
    $ref = mcr_wa_ref($o);
    $total = mcr_wa_money((float) $o->get_total());
    $first_img = '';
    $items = [];
    foreach ($o->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        $p = $item->get_product();
        $img_id = $p ? ($p->get_image_id() ?: ($p->get_parent_id() ? wc_get_product($p->get_parent_id())->get_image_id() : 0)) : 0;
        $img = $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail') : wc_placeholder_img_src();
        if (!$first_img && $img_id) {
            $first_img = wp_get_attachment_image_url($img_id, 'large');
        }
        $qty = max(1, (int) $item->get_quantity());
        $items[] = ['name' => $item->get_name(), 'opts' => mcr_wa_item_options($item), 'qty' => $qty, 'unit' => mcr_wa_money((float) $item->get_subtotal() / $qty), 'line' => mcr_wa_money((float) $item->get_subtotal()), 'img' => $img, 'url' => $p ? get_permalink($p->get_parent_id() ?: $p->get_id()) : ''];
    }
    $status = $o->get_status();
    $state = match (true) {
        $status === MCR_WA_STATUS => [$en ? 'Waiting for confirmation on WhatsApp' : 'En attente de confirmation sur WhatsApp', 'wait'],
        $status === 'cancelled' => [$en ? 'Cancelled' : 'Annulée', 'off'],
        $status === 'completed' => [$en ? 'Completed' : 'Terminée', 'ok'],
        default => [wc_get_order_status_name($status), 'ok'],
    };
    $slot = (string) $o->get_meta('_mcr_pickup_slot');
    $city = $o->get_billing_city();
    $count = $o->get_item_count();
    $desc = sprintf($en ? '%d item%s · %s · Total %s' : '%d article%s · %s · Total %s', $count, $count > 1 ? 's' : '', $mode === 'pickup' ? ($en ? 'In-store pickup' : 'Retrait en magasin') : ($en ? 'Delivery' : 'Livraison'), $total);
    $store = (string) mcr_setting('store', 'name');
    $logo = defined('MCRT_URL') ? MCRT_URL . '/assets/MRC-logo-whit.png' : '';
    $wa = 'https://wa.me/' . preg_replace('/\D/', '', (string) mcr_setting('store', 'whatsapp')) . '?text=' . rawurlencode(($en ? 'Hello, about my order ' : 'Bonjour, au sujet de ma commande ') . $ref);
    ?><!doctype html>
<html lang="<?php echo $en ? 'en' : 'fr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html("$ref · $total — $store"); ?></title>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?php echo esc_attr($store); ?>">
<meta property="og:title" content="<?php echo esc_attr(($en ? 'Order ' : 'Commande ') . "$ref · $total"); ?>">
<meta property="og:description" content="<?php echo esc_attr($desc); ?>">
<?php if ($first_img) : ?><meta property="og:image" content="<?php echo esc_url($first_img); ?>"><?php endif; ?>
<link rel="icon" href="<?php echo esc_url(get_site_icon_url(64) ?: ''); ?>">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--g9:#0f3324;--g7:#174b35;--g1:#e6efe8;--terra:#c96b43;--gold:#e6b74f;--ivory:#f8f6ef;--ink:#1d1d1b;--muted:#6b6a63;--orange:#d9631e;--line:#ebe6d8}
*{box-sizing:border-box}body{margin:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:var(--ivory);color:var(--ink)}
.top{background:var(--g7);padding:14px 16px;text-align:center;border-bottom:6px solid transparent;border-image:repeating-linear-gradient(90deg,#e6b74f 0 16px,#174b35 16px 22px,#c96b43 22px 38px,#1d1d1b 38px 42px) 6}
.top img{height:38px}
main{max-width:620px;margin:0 auto;padding:18px 16px 40px}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}
h1{font-family:Fraunces,serif;font-size:1.6rem;margin:0;color:#123d2c}
.sub{color:var(--muted);font-size:.9rem;margin:4px 0 0}
.chip{flex:none;font-size:.75rem;font-weight:800;border-radius:99px;padding:6px 10px;text-align:center;max-width:46%}
.chip--wait{background:#d9f7e3;color:#13693a}.chip--ok{background:var(--g1);color:var(--g7)}.chip--off{background:#fbe1dc;color:#a33}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:6px 14px;margin-bottom:12px}
.it{display:grid;grid-template-columns:64px 1fr auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.it:last-child{border-bottom:0}
.it img{width:64px;height:64px;object-fit:contain;border-radius:12px;background:#fff;border:1px solid var(--line)}
.it b{display:block;font-size:.93rem;line-height:1.3}.it small{display:block;color:var(--muted);font-size:.78rem;margin-top:2px}
.it .p{text-align:right;font-weight:800;color:var(--orange);white-space:nowrap}
.it .p small{color:var(--muted);font-weight:600}
.row{display:flex;justify-content:space-between;padding:8px 0;font-size:.93rem}.row+.row{border-top:1px dashed var(--line)}
.row--total{font-size:1.15rem;font-weight:800;color:#123d2c}
.info{display:flex;gap:12px;align-items:flex-start;padding:12px 0}.info+.info{border-top:1px solid var(--line)}
.info span{font-size:1.3rem}.info b{display:block;font-size:.93rem}.info small{color:var(--muted);font-size:.84rem}
.btns{display:grid;gap:10px;margin-top:16px}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;min-height:50px;border-radius:99px;font-weight:700;text-decoration:none;font-size:1rem}
.btn--wa{background:#1fa855;color:#fff}.btn--ghost{background:#fff;color:var(--ink);border:1.5px solid var(--line)}
.fine{color:var(--muted);font-size:.8rem;text-align:center;margin-top:18px}
</style>
</head>
<body>
<header class="top"><a href="<?php echo esc_url(home_url('/')); ?>"><?php if ($logo) : ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($store); ?>"><?php else : echo esc_html($store); endif; ?></a></header>
<main>
  <div class="head">
    <div><h1><?php echo esc_html(($en ? 'Order ' : 'Commande ') . $ref); ?></h1>
    <p class="sub"><?php echo esc_html(sprintf($en ? 'For %s · %s' : 'Pour %s · %s', $o->get_billing_first_name(), wc_format_datetime($o->get_date_created(), $en ? 'F j, Y' : 'j F Y'))); ?></p></div>
    <span class="chip chip--<?php echo esc_attr($state[1]); ?>"><?php echo esc_html($state[0]); ?></span>
  </div>

  <section class="card" aria-label="<?php echo esc_attr($en ? 'Products' : 'Produits'); ?>">
    <?php foreach ($items as $it) : ?>
      <a class="it" href="<?php echo esc_url($it['url']); ?>">
        <img src="<?php echo esc_url($it['img']); ?>" alt="" width="64" height="64">
        <span><b><?php echo esc_html($it['name']); ?></b><?php if ($it['opts']) : ?><small><?php echo esc_html($it['opts']); ?></small><?php endif; ?><small><?php echo esc_html($it['qty'] . ' × ' . $it['unit']); ?></small></span>
        <span class="p"><?php echo esc_html($it['line']); ?></span>
      </a>
    <?php endforeach; ?>
  </section>

  <section class="card">
    <div class="row"><span><?php echo esc_html($en ? 'Subtotal' : 'Sous-total'); ?></span><span><?php echo esc_html(mcr_wa_money((float) $o->get_subtotal())); ?></span></div>
    <div class="row"><span><?php echo esc_html($mode === 'pickup' ? ($en ? 'Pickup' : 'Retrait') : ($en ? 'Delivery' : 'Livraison')); ?></span><span><?php echo esc_html((float) $o->get_shipping_total() > 0 ? mcr_wa_money((float) $o->get_shipping_total()) : ($en ? 'Free' : 'Gratuit')); ?></span></div>
    <?php foreach ($o->get_tax_totals() as $tax) : ?>
      <div class="row"><span><?php echo esc_html($tax->label); ?></span><span><?php echo esc_html(html_entity_decode(wp_strip_all_tags($tax->formatted_amount))); ?></span></div>
    <?php endforeach; ?>
    <div class="row row--total"><span>Total</span><span><?php echo esc_html($total); ?></span></div>
  </section>

  <section class="card">
    <?php if ($mode === 'pickup') : ?>
      <div class="info"><span>🏪</span><div><b><?php echo esc_html($en ? 'In-store pickup' : 'Retrait en magasin'); ?></b><small><?php echo esc_html(($slot && function_exists('mcr_slot_label') ? mcr_slot_label($slot, $en) . ' · ' : '') . mcr_setting('store', 'street') . ', ' . mcr_setting('store', 'city')); ?></small></div></div>
    <?php else : ?>
      <div class="info"><span>🚚</span><div><b><?php echo esc_html($en ? 'Delivery' : 'Livraison'); ?></b><small><?php echo esc_html($city ?: ($en ? 'Address given on WhatsApp' : 'Adresse indiquée sur WhatsApp')); ?></small></div></div>
    <?php endif; ?>
    <div class="info"><span>💵</span><div><b><?php echo esc_html($en ? 'Payment on delivery or at pickup' : 'Paiement à la livraison ou au retrait'); ?></b><small><?php echo esc_html($en ? 'Our team confirms the order with you on WhatsApp.' : 'Notre équipe confirme la commande avec vous sur WhatsApp.'); ?></small></div></div>
  </section>

  <div class="btns">
    <a class="btn btn--wa" href="<?php echo esc_url($wa); ?>"><?php echo esc_html($en ? 'Open the WhatsApp chat' : 'Ouvrir la discussion WhatsApp'); ?></a>
    <a class="btn btn--ghost" href="<?php echo esc_url(function_exists('mcr_shop_url') ? mcr_shop_url() : home_url('/')); ?>"><?php echo esc_html($en ? 'Continue shopping' : 'Continuer mes achats'); ?></a>
  </div>
  <p class="fine"><?php echo esc_html($store . ' · ' . mcr_setting('store', 'street') . ', ' . mcr_setting('store', 'city') . ' · ' . mcr_setting('store', 'phone')); ?></p>
</main>
</body>
</html>
    <?php
}

// ---------------------------------------------------------------- The form (product page and cart)

add_action('wp_enqueue_scripts', function () {
    if (!mcr_wa_enabled() || !(is_product() || is_cart())) {
        return;
    }
    wp_enqueue_script('mcr-wa-order', MCR_URL . 'assets/wa-order.js', [], MCR_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    $en = mcr_is_en();
    $d = mcr_setting('delivery');
    wp_localize_script('mcr-wa-order', 'MCR_WA', [
        'endpoint' => WC_AJAX::get_endpoint('mcr_wa_order'),
        'quote' => admin_url('admin-ajax.php?action=mcr_delivery_quote'),
        'lang' => $en ? 'en' : 'fr',
        'lat' => (float) mcr_setting('store', 'lat'),
        'lon' => (float) mcr_setting('store', 'lon'),
        'freeMin' => (float) ($d['free_min_subtotal'] ?? 0),
        'cartSubtotal' => is_cart() && WC()->cart ? (float) WC()->cart->get_subtotal() : 0,
        'currency' => ['decimals' => wc_get_price_decimals(), 'dec' => wc_get_price_decimal_separator(), 'thousand' => wc_get_price_thousand_separator(), 'symbol' => html_entity_decode(get_woocommerce_currency_symbol()), 'format' => html_entity_decode(get_woocommerce_price_format())],
        't' => $en ? [
            'variation' => 'Please choose an option first (colour, size…).', 'searching' => 'Searching…', 'notfound' => 'Address not found. Check it or choose pickup.', 'pick' => 'Choose your address in the list.',
            'far' => 'Too far for delivery (%s). Please choose pickup.', 'free' => 'Free delivery', 'fee' => 'Delivery: %s', 'freeFrom' => 'free from %s', 'sending' => 'Preparing your order…', 'error' => 'Something went wrong. Please try again.',
        ] : [
            'variation' => 'Choisissez d’abord une option (couleur, taille…).', 'searching' => 'Recherche…', 'notfound' => 'Adresse introuvable. Vérifiez-la ou choisissez le retrait.', 'pick' => 'Choisissez votre adresse dans la liste.',
            'far' => 'Trop loin pour la livraison (%s). Choisissez le retrait.', 'free' => 'Livraison gratuite', 'fee' => 'Livraison : %s', 'freeFrom' => 'gratuite dès %s', 'sending' => 'Préparation de votre commande…', 'error' => 'Un problème est survenu. Réessayez.',
        ],
    ]);
});

add_action('wp_footer', function () {
    if (!mcr_wa_enabled() || !(is_product() || is_cart())) {
        return;
    }
    $en = mcr_is_en();
    $c = WC()->customer;
    $user = wp_get_current_user();
    $prefill = [
        'name' => $c ? trim($c->get_billing_first_name() . ' ' . $c->get_billing_last_name()) : '',
        'phone' => $c ? $c->get_billing_phone() : '',
        'street' => $c ? $c->get_billing_address_1() : '',
        'city' => $c ? $c->get_billing_city() : '',
        'postcode' => $c ? $c->get_billing_postcode() : '',
    ];
    if ($prefill['name'] === '' && $user->exists()) {
        $prefill['name'] = $user->display_name;
    }
    $slots = function_exists('mcr_slots_enabled') && mcr_slots_enabled() ? mcr_pickup_slots() : [];
    $d = mcr_setting('delivery');
    $fees = array_map(fn($t) => (float) $t['fee'], (array) ($d['tiers'] ?? []));
    ?>
<dialog class="wa-sheet" id="wa-sheet" aria-labelledby="wa-sheet-title" data-prefill="<?php echo esc_attr(wp_json_encode($prefill)); ?>">
  <form class="wa-sheet__form" method="dialog" novalidate data-wa-form>
    <header class="wa-sheet__head">
      <span class="wa-sheet__ic"><?php echo function_exists('mcr_icon') ? mcr_icon('whatsapp', 22) : ''; ?></span>
      <div><h2 id="wa-sheet-title"><?php echo esc_html($en ? 'Order on WhatsApp' : 'Commander via WhatsApp'); ?></h2><p data-wa-summary></p></div>
      <button type="button" class="wa-sheet__x" data-wa-close aria-label="<?php echo esc_attr($en ? 'Close' : 'Fermer'); ?>">×</button>
    </header>
    <div class="wa-sheet__body" data-wa-step="form">
      <div class="wa-f2">
        <label class="wa-field"><span><?php echo esc_html($en ? 'Full name' : 'Nom complet'); ?></span><input name="name" autocomplete="name" required maxlength="80"></label>
        <label class="wa-field"><span><?php echo esc_html($en ? 'Phone' : 'Téléphone'); ?></span><input name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="20" placeholder="819 555-0123"></label>
      </div>
      <div class="wa-modes" role="radiogroup" aria-label="<?php echo esc_attr($en ? 'Delivery or pickup' : 'Livraison ou retrait'); ?>">
        <label class="wa-mode"><input type="radio" name="mode" value="delivery" checked><span><b>🚚 <?php echo esc_html($en ? 'Delivery' : 'Livraison'); ?></b><small><?php echo esc_html(sprintf($en ? 'From %s' : 'Dès %s', $fees ? mcr_wa_money(min($fees)) : '')); ?></small></span></label>
        <label class="wa-mode"><input type="radio" name="mode" value="pickup"><span><b>🏪 <?php echo esc_html($en ? 'Pickup' : 'Retrait'); ?></b><small><?php echo esc_html($en ? 'Free, in store' : 'Gratuit, en magasin'); ?></small></span></label>
      </div>
      <div class="wa-block" data-wa-delivery>
        <label class="wa-field wa-field--ac"><span><?php echo esc_html($en ? 'Delivery address' : 'Adresse de livraison'); ?></span>
          <input name="street" autocomplete="off" placeholder="<?php echo esc_attr($en ? 'e.g. 1234 rue des Forges' : 'ex. 1234 rue des Forges'); ?>" data-wa-address role="combobox" aria-expanded="false" aria-controls="wa-ac" aria-autocomplete="list">
          <ul class="wa-ac" id="wa-ac" role="listbox" hidden></ul>
        </label>
        <input type="hidden" name="city"><input type="hidden" name="postcode"><input type="hidden" name="lat"><input type="hidden" name="lon">
        <p class="wa-quote" data-wa-quote aria-live="polite"></p>
      </div>
      <div class="wa-block" data-wa-pickup hidden>
        <?php if ($slots) : ?>
          <label class="wa-field"><span><?php echo esc_html($en ? 'Pickup time' : 'Heure de retrait'); ?></span>
            <select name="slot">
              <option value="asap"><?php echo esc_html(($en ? '⚡ As soon as possible' : '⚡ Le plus tôt possible') . ' (' . ($slots[0]['label'] ?? '') . ', ' . ($slots[0]['slots'][0]['label'] ?? '') . ')'); ?></option>
              <?php foreach ($slots as $day) : ?>
                <optgroup label="<?php echo esc_attr($day['label']); ?>">
                  <?php foreach ($day['slots'] as $s) : ?>
                    <option value="<?php echo esc_attr($s['value']); ?>"<?php disabled($s['full']); ?>><?php echo esc_html($day['label'] . ' · ' . $s['label'] . ($s['full'] ? ($en ? ' (full)' : ' (complet)') : '')); ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <p class="wa-quote"><?php echo esc_html(mcr_setting('store', 'street') . ', ' . mcr_setting('store', 'city')); ?></p>
      </div>
      <details class="wa-note"><summary><?php echo esc_html($en ? 'Add a note (optional)' : 'Ajouter une note (facultatif)'); ?></summary><textarea name="note" rows="2" maxlength="300"></textarea></details>
      <input type="text" name="website" class="wa-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <p class="wa-err" data-wa-error role="alert" hidden></p>
      <button type="submit" class="btn btn--wa wa-send" data-wa-send><?php echo function_exists('mcr_icon') ? mcr_icon('whatsapp', 20) : ''; ?> <?php echo esc_html($en ? 'Send on WhatsApp' : 'Envoyer sur WhatsApp'); ?></button>
      <p class="wa-fine"><?php echo esc_html($en ? 'You will see the full order (products, prices, total) before sending it. Our team confirms it on WhatsApp. Payment on delivery or at pickup.' : 'Vous verrez la commande complète (produits, prix, total) avant de l’envoyer. Notre équipe la confirme sur WhatsApp. Paiement à la livraison ou au retrait.'); ?></p>
    </div>
    <div class="wa-sheet__body wa-done" data-wa-step="done" hidden>
      <div class="wa-done__ok">✓</div>
      <h3 data-wa-done-title></h3>
      <p><?php echo esc_html($en ? 'WhatsApp is opening with your order. Just press send.' : 'WhatsApp s’ouvre avec votre commande. Il ne reste qu’à appuyer sur Envoyer.'); ?></p>
      <a class="btn btn--wa wa-send" data-wa-open href="#"><?php echo function_exists('mcr_icon') ? mcr_icon('whatsapp', 20) : ''; ?> <?php echo esc_html($en ? 'Open WhatsApp' : 'Ouvrir WhatsApp'); ?></a>
      <a class="wa-link" data-wa-summary-link href="#" target="_blank" rel="noopener"><?php echo esc_html($en ? 'See my order with photos' : 'Voir ma commande avec photos'); ?></a>
    </div>
  </form>
</dialog>
    <?php
}, 20);
