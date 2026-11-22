<?php
/**
 * Consent-based reminders by email (Québec Law 25 / Canada's anti-spam law: express consent,
 * sender identified, one-click unsubscribe in every message):
 *  - forgotten cart: one reminder after a delay, with a link that puts the same products back
 *    in the cart;
 *  - restock: "time to stock up again?" based on each customer's own buying rhythm;
 *  - MCR Gestion > Relances: contacts, carts, results, previews, and WhatsApp links the team
 *    can send by hand to customers who agreed.
 * Nothing is sent to someone who did not tick the box.
 */
defined('ABSPATH') || exit;

const MCR_REM_DB = 2;

function mcr_rem_table(string $t): string
{
    global $wpdb;
    return $wpdb->prefix . 'mcr_' . $t;
}

add_action('init', function () {
    if ((int) get_option('mcr_rem_db') >= MCR_REM_DB) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . mcr_rem_table('contacts') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  lang varchar(5) NOT NULL DEFAULT 'fr',
  token varchar(40) NOT NULL,
  consent_at datetime NULL,
  unsub_at datetime NULL,
  restock_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY token (token)
) $c;");
    dbDelta('CREATE TABLE ' . mcr_rem_table('carts') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  token varchar(40) NOT NULL,
  items longtext NOT NULL,
  total decimal(12,2) NOT NULL DEFAULT 0,
  lang varchar(5) NOT NULL DEFAULT 'fr',
  updated_at datetime NOT NULL,
  reminded_at datetime NULL,
  recovered_order bigint unsigned NULL,
  recovered_total decimal(12,2) NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY token (token)
) $c;");
    update_option('mcr_rem_db', MCR_REM_DB);
});

function mcr_rem_settings(): array
{
    return wp_parse_args((array) get_option('mcr_rem_settings', []), ['cart' => true, 'cart_delay' => 60, 'restock' => true, 'restock_default_days' => 30]);
}

function mcr_rem_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Contact row for an email (or null). */
function mcr_rem_contact(string $email): ?object
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mcr_rem_table('contacts') . ' WHERE email = %s', strtolower($email))) ?: null;
}

/** Record consent (or refresh name/phone/language). */
function mcr_rem_consent(string $email, string $name, string $phone, string $lang): void
{
    global $wpdb;
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) {
        return;
    }
    $c = mcr_rem_contact($email);
    if ($c) {
        $wpdb->update(mcr_rem_table('contacts'), ['name' => $name, 'phone' => $phone, 'lang' => $lang, 'consent_at' => $c->consent_at ?: mcr_rem_now(), 'unsub_at' => null], ['id' => $c->id]);
    } else {
        $wpdb->insert(mcr_rem_table('contacts'), ['email' => $email, 'name' => $name, 'phone' => $phone, 'lang' => $lang, 'token' => strtolower(wp_generate_password(32, false, false)), 'consent_at' => mcr_rem_now()]);
    }
}

function mcr_rem_can_email(?object $c): bool
{
    return $c && $c->consent_at && !$c->unsub_at;
}

// ---------------------------------------------------------------- Checkout: the opt-in box

add_filter('woocommerce_checkout_fields', function ($fields) {
    $en = mcr_is_en();
    $fields['billing']['billing_mcr_marketing'] = [
        'type' => 'checkbox',
        'label' => $en
            ? 'Email me a reminder if I don’t finish my order, a heads-up when it’s time to restock, and our deals. Unsubscribe in one click.'
            : 'Envoyez-moi par courriel un rappel si je ne termine pas ma commande, un avis quand il est temps de refaire le plein, et vos promotions. Désabonnement en un clic.',
        'required' => false,
        'default' => 0,
        'priority' => 42,
        'class' => ['form-row-wide', 'mcr-optin'],
    ];
    return $fields;
}, 30);

/** The cart as plain data. */
function mcr_rem_cart_items(): array
{
    $items = [];
    foreach (WC()->cart ? WC()->cart->get_cart() : [] as $ci) {
        $items[] = ['p' => (int) $ci['product_id'], 'v' => (int) $ci['variation_id'], 'q' => (int) $ci['quantity'], 'a' => (array) ($ci['variation'] ?? [])];
    }
    return $items;
}

/** Save (or update) the visitor's cart while they fill in the checkout, if they agreed. */
add_action('woocommerce_checkout_update_order_review', function ($post_data) {
    parse_str((string) $post_data, $d);
    $email = strtolower(sanitize_email($d['billing_email'] ?? ''));
    if (empty($d['billing_mcr_marketing']) || !is_email($email) || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    $lang = mcr_is_en() ? 'en' : 'fr';
    mcr_rem_consent($email, trim(sanitize_text_field(($d['billing_first_name'] ?? '') . ' ' . ($d['billing_last_name'] ?? ''))), sanitize_text_field($d['billing_phone'] ?? ''), $lang);
    global $wpdb;
    $t = mcr_rem_table('carts');
    $key = WC()->session ? WC()->session->get('mcr_rem_cart') : null;
    $row = $key ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token = %s AND recovered_order IS NULL", $key)) : null;
    $data = ['email' => $email, 'items' => wp_json_encode(mcr_rem_cart_items()), 'total' => (float) WC()->cart->get_subtotal(), 'lang' => $lang, 'updated_at' => mcr_rem_now()];
    if ($row) {
        $wpdb->update($t, $data, ['id' => $row->id]);
    } else {
        $data['token'] = strtolower(wp_generate_password(32, false, false));
        $wpdb->insert($t, $data);
        WC()->session->set('mcr_rem_cart', $data['token']);
    }
});

// Order placed: consent kept on the order, the cart counts as recovered (or simply finished).
add_action('woocommerce_checkout_order_processed', function ($order_id, $posted) {
    $o = wc_get_order($order_id);
    if (!$o) {
        return;
    }
    $email = strtolower($o->get_billing_email());
    if (!empty($posted['billing_mcr_marketing'])) {
        $o->update_meta_data('_mcr_marketing_consent', mcr_rem_now());
        $o->save_meta_data();
        mcr_rem_consent($email, trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()), $o->get_billing_phone(), $o->get_meta('_mcr_lang') ?: (mcr_is_en() ? 'en' : 'fr'));
    }
    global $wpdb;
    $t = mcr_rem_table('carts');
    $wpdb->query($wpdb->prepare("UPDATE $t SET recovered_order = %d, recovered_total = %f WHERE email = %s AND recovered_order IS NULL", $order_id, (float) $o->get_total(), $email));
    if (WC()->session) {
        WC()->session->set('mcr_rem_cart', null);
    }
}, 20, 2);

// ---------------------------------------------------------------- Links in the emails

add_action('wp_loaded', function () {
    global $wpdb;
    // Put a saved cart back: ?mcr_cart=token
    if (!empty($_GET['mcr_cart']) && WC()->cart) { // phpcs:ignore WordPress.Security.NonceVerification
        $token = sanitize_key(wp_unslash($_GET['mcr_cart'])); // phpcs:ignore WordPress.Security.NonceVerification
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mcr_rem_table('carts') . ' WHERE token = %s', $token));
        if ($row) {
            foreach ((array) json_decode((string) $row->items, true) as $it) {
                $p = wc_get_product($it['v'] ?: $it['p']);
                if ($p && $p->is_purchasable() && $p->is_in_stock()) {
                    WC()->cart->add_to_cart((int) $it['p'], max(1, (int) $it['q']), (int) $it['v'], (array) $it['a']);
                }
            }
            WC()->session->set('mcr_rem_cart', $row->token);
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }
    // Restock link: ?mcr_restock=token&p=1,2,3
    if (!empty($_GET['mcr_restock']) && WC()->cart) { // phpcs:ignore WordPress.Security.NonceVerification
        $c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mcr_rem_table('contacts') . ' WHERE token = %s', sanitize_key(wp_unslash($_GET['mcr_restock'])))); // phpcs:ignore WordPress.Security.NonceVerification
        if ($c) {
            foreach (array_slice(array_filter(array_map('absint', explode(',', (string) wp_unslash($_GET['p'] ?? '')))), 0, 10) as $pid) { // phpcs:ignore WordPress.Security.NonceVerification
                $p = wc_get_product($pid);
                if ($p && $p->is_type('simple') && $p->is_purchasable() && $p->is_in_stock()) {
                    WC()->cart->add_to_cart($pid, 1);
                }
            }
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }
    // Unsubscribe: ?mcr_unsub=token
    if (!empty($_GET['mcr_unsub'])) { // phpcs:ignore WordPress.Security.NonceVerification
        $token = sanitize_key(wp_unslash($_GET['mcr_unsub'])); // phpcs:ignore WordPress.Security.NonceVerification
        $found = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . mcr_rem_table('contacts') . ' WHERE token = %s', $token));
        $wpdb->query($wpdb->prepare('UPDATE ' . mcr_rem_table('contacts') . ' SET unsub_at = %s WHERE token = %s AND unsub_at IS NULL', mcr_rem_now(), $token));
        $en = mcr_is_en();
        if (!$found) {
            wp_die('<h1>' . esc_html($en ? 'This link is not valid' : 'Ce lien n’est pas valide') . '</h1><p>' . esc_html($en ? 'To stop receiving our emails, use the link at the bottom of one of them, or write to us.' : 'Pour ne plus recevoir nos courriels, utilisez le lien au bas de l’un d’eux, ou écrivez-nous.') . ' ' . esc_html((string) mcr_setting('store', 'email')) . '</p>', esc_html($en ? 'Unsubscribe' : 'Désabonnement'), ['response' => 404]);
        }
        wp_die(
            '<h1>' . esc_html($en ? 'You are unsubscribed' : 'Vous êtes désabonné') . '</h1><p>' . esc_html($en ? 'You will no longer receive reminders or deals by email. Your orders are not affected.' : 'Vous ne recevrez plus de rappels ni de promotions par courriel. Vos commandes ne sont pas touchées.') . '</p><p><a href="' . esc_url(home_url('/')) . '">' . esc_html(mcr_setting('store', 'name')) . '</a></p>',
            esc_html($en ? 'Unsubscribed' : 'Désabonnement'),
            ['response' => 200]
        );
    }
}, 30);

// ---------------------------------------------------------------- The emails

function mcr_rem_money(float $v): string
{
    return wc_price($v);
}

/** Product lines (image, name, price) for an email. */
function mcr_rem_lines_html(array $products, array $qty = []): string
{
    $h = '<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin:12px 0">';
    foreach ($products as $p) {
        $img = wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail');
        $q = $qty[$p->get_id()] ?? 1;
        $h .= '<tr style="border-bottom:1px solid #eee"><td style="width:64px"><img src="' . esc_url($img) . '" width="56" height="56" alt="" style="border-radius:8px;object-fit:contain;border:1px solid #eee"></td><td>' . ($q > 1 ? (int) $q . ' × ' : '') . esc_html($p->get_name()) . '</td><td style="text-align:right;white-space:nowrap;font-weight:bold;color:#d9631e">' . wp_kses_post(wc_price(wc_get_price_to_display($p) * $q)) . '</td></tr>';
    }
    return $h . '</table>';
}

function mcr_rem_button(string $url, string $label): string
{
    return '<p style="margin:18px 0"><a href="' . esc_url($url) . '" style="display:inline-block;background:#174b35;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px">' . esc_html($label) . '</a></p>';
}

function mcr_rem_footer(object $c, bool $en): string
{
    $s = mcr_setting('store');
    $unsub = add_query_arg('mcr_unsub', $c->token, function_exists('mcr_prefix_url') ? mcr_prefix_url(home_url('/'), $en ? 'en' : 'fr') : home_url('/'));
    return '<p style="font-size:12px;color:#777;margin-top:24px">' . esc_html($s['name'] . ' · ' . $s['street'] . ', ' . $s['city'] . ', ' . $s['region'] . ' ' . $s['postal_code'] . ' · ' . $s['phone']) . '<br>'
        . esc_html($en ? 'You receive this email because you agreed to it at checkout.' : 'Vous recevez ce courriel parce que vous l’avez accepté lors d’une commande.')
        . ' <a href="' . esc_url($unsub) . '">' . esc_html($en ? 'Unsubscribe' : 'Se désabonner') . '</a></p>';
}

/** Build the forgotten-cart email: [subject, html] or null. */
function mcr_rem_cart_email(object $cart, object $c): ?array
{
    $en = $cart->lang === 'en';
    $products = [];
    $qty = [];
    foreach ((array) json_decode((string) $cart->items, true) as $it) {
        $p = wc_get_product($it['v'] ?: $it['p']);
        if ($p && $p->is_in_stock()) {
            $products[] = $p;
            $qty[$p->get_id()] = (int) $it['q'];
        }
    }
    if (!$products) {
        return null;
    }
    $first = trim(explode(' ', (string) $c->name)[0]);
    $link = add_query_arg('mcr_cart', $cart->token, function_exists('mcr_prefix_url') ? mcr_prefix_url(home_url('/'), $en ? 'en' : 'fr') : home_url('/'));
    $free = (float) mcr_setting('delivery', 'free_min_subtotal');
    $body = '<p>' . esc_html(($en ? 'Hello' : 'Bonjour') . ($first ? ' ' . $first : '') . ',') . '</p>'
        . '<p>' . esc_html($en ? 'Your order is waiting for you: we kept your products aside.' : 'Votre commande vous attend : nous avons gardé vos produits de côté.') . '</p>'
        . mcr_rem_lines_html($products, $qty)
        . mcr_rem_button($link, $en ? 'Finish my order' : 'Terminer ma commande')
        . '<p>' . esc_html(sprintf($en ? 'Delivery 7 days a week in Trois-Rivières (free from %s) or free in-store pickup at a time you choose.' : 'Livraison 7 jours sur 7 à Trois-Rivières (gratuite dès %s) ou cueillette gratuite en magasin à l’heure de votre choix.', html_entity_decode(wp_strip_all_tags(wc_price($free))))) . '</p>'
        . mcr_rem_footer($c, $en);
    $subject = $en ? 'Your cart is waiting for you' : 'Votre panier vous attend';
    return [$subject, WC()->mailer()->wrap_message($en ? 'Your cart is waiting' : 'Votre panier vous attend', $body)];
}

/**
 * Products a contact is due to buy again: bought at least twice (their own rhythm) or once in the
 * grocery department (default rhythm), in stock, rhythm reached in the last 7 days.
 */
function mcr_rem_restock_due(object $c): array
{
    $orders = wc_get_orders(['billing_email' => $c->email, 'status' => ['completed', 'processing', 'mcr-preparing', 'mcr-ready', 'mcr-out'], 'limit' => 50, 'orderby' => 'date', 'order' => 'ASC']);
    $dates = [];
    foreach ($orders as $o) {
        foreach ($o->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $pid = (int) ($item->get_variation_id() ?: $item->get_product_id());
            $dates[$pid][] = $o->get_date_created()->getTimestamp();
        }
    }
    $default = max(7, (int) mcr_rem_settings()['restock_default_days']) * DAY_IN_SECONDS;
    $due = [];
    foreach ($dates as $pid => $ts) {
        $ts = array_values(array_unique($ts));
        sort($ts);
        $p = wc_get_product($pid);
        if (!$p || !$p->is_type('simple') || !$p->is_purchasable() || !$p->is_in_stock()) {
            continue;
        }
        if (count($ts) >= 2) {
            $gaps = [];
            for ($i = 1; $i < count($ts); $i++) {
                $gaps[] = $ts[$i] - $ts[$i - 1];
            }
            sort($gaps);
            $every = max(5 * DAY_IN_SECONDS, $gaps[intdiv(count($gaps), 2)]);
        } elseif (function_exists('mcr_product_department') && mcr_product_department($p->get_id()) === 'epicerie') {
            $every = $default;
        } else {
            continue;
        }
        $next = end($ts) + $every;
        if ($next <= time() && $next > time() - 7 * DAY_IN_SECONDS) {
            $due[] = $p;
        }
    }
    return array_slice($due, 0, 6);
}

function mcr_rem_restock_email(object $c, array $products): array
{
    $en = $c->lang === 'en';
    $first = trim(explode(' ', (string) $c->name)[0]);
    $link = add_query_arg(['mcr_restock' => $c->token, 'p' => implode(',', array_map(fn($p) => $p->get_id(), $products))], function_exists('mcr_prefix_url') ? mcr_prefix_url(home_url('/'), $en ? 'en' : 'fr') : home_url('/'));
    $body = '<p>' . esc_html(($en ? 'Hello' : 'Bonjour') . ($first ? ' ' . $first : '') . ',') . '</p>'
        . '<p>' . esc_html($en ? 'Going by your last orders, it may be time to stock up again on:' : 'D’après vos dernières commandes, c’est peut-être le moment de refaire le plein de :') . '</p>'
        . mcr_rem_lines_html($products)
        . mcr_rem_button($link, $en ? 'Add them to my cart' : 'Les ajouter à mon panier')
        . mcr_rem_footer($c, $en);
    return [$en ? 'Time to stock up?' : 'C’est le moment de refaire le plein ?', WC()->mailer()->wrap_message($en ? 'Time to stock up?' : 'On refait le plein ?', $body)];
}

/** WooCommerce's email look (colours, fonts) applied inline, as for its own emails. */
function mcr_rem_styled(string $html): string
{
    WC()->mailer();
    return class_exists('WC_Email') ? (new WC_Email())->style_inline($html) : $html;
}

function mcr_rem_send(string $to, array $mail): bool
{
    return (bool) WC()->mailer()->send($to, $mail[0], mcr_rem_styled($mail[1]), "Content-Type: text/html\r\n");
}

// ---------------------------------------------------------------- Scheduled runs

add_filter('cron_schedules', function ($s) {
    $s['mcr_quarter'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Toutes les 15 minutes'];
    return $s;
});
add_action('init', function () {
    if (!wp_next_scheduled('mcr_rem_carts')) {
        wp_schedule_event(time() + 300, 'mcr_quarter', 'mcr_rem_carts');
    }
    if (!wp_next_scheduled('mcr_rem_restock')) {
        wp_schedule_event(strtotime('tomorrow 14:00'), 'daily', 'mcr_rem_restock');
    }
});

function mcr_rem_run_carts(): int
{
    $set = mcr_rem_settings();
    if (empty($set['cart'])) {
        return 0;
    }
    global $wpdb;
    $t = mcr_rem_table('carts');
    $before = gmdate('Y-m-d H:i:s', time() - max(15, (int) $set['cart_delay']) * MINUTE_IN_SECONDS);
    $after = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE reminded_at IS NULL AND recovered_order IS NULL AND updated_at < %s AND updated_at > %s LIMIT 30", $before, $after));
    $n = 0;
    foreach ($rows as $row) {
        $c = mcr_rem_contact($row->email);
        $mail = mcr_rem_can_email($c) ? mcr_rem_cart_email($row, $c) : null;
        if ($mail && mcr_rem_send($c->email, $mail)) {
            $n++;
        }
        $wpdb->update($t, ['reminded_at' => mcr_rem_now()], ['id' => $row->id]);
    }
    return $n;
}
add_action('mcr_rem_carts', 'mcr_rem_run_carts');

function mcr_rem_run_restock(): int
{
    if (empty(mcr_rem_settings()['restock'])) {
        return 0;
    }
    global $wpdb;
    $t = mcr_rem_table('contacts');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE consent_at IS NOT NULL AND unsub_at IS NULL AND (restock_at IS NULL OR restock_at < %s) LIMIT 200", gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS)));
    $n = 0;
    foreach ($rows as $c) {
        $due = mcr_rem_restock_due($c);
        if ($due && mcr_rem_send($c->email, mcr_rem_restock_email($c, $due))) {
            $wpdb->update($t, ['restock_at' => mcr_rem_now()], ['id' => $c->id]);
            $n++;
        }
    }
    return $n;
}
add_action('mcr_rem_restock', 'mcr_rem_run_restock');

// Store emails in the site's green instead of WooCommerce's default purple (only if never changed).
add_action('admin_init', function () {
    if (get_option('mcr_email_colors_v1') || !current_user_can('manage_woocommerce')) {
        return;
    }
    if (in_array(strtolower((string) get_option('woocommerce_email_base_color')), ['', '#7f54b3', '#720eec', '#96588a'], true)) {
        update_option('woocommerce_email_base_color', '#174b35');
    }
    update_option('mcr_email_colors_v1', 1);
});
