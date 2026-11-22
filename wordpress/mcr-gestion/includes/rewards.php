<?php
/**
 * Welcome wheel ("Roue de bienvenue") and referral ("Parrainage").
 *
 * Wheel: the visitor gives their WhatsApp number (with consent) and spins. Every spin wins a real
 * prize chosen in MCR Gestion > Roue et parrainage (odds, minimum order, optional limited quantity).
 * The prize is a one-time WooCommerce coupon, valid a set number of hours (72 by default), on a
 * first order, for that phone number. The number also joins the "Nouveaux arrivages" WhatsApp list.
 *
 * Referral: every winner (and every customer, on the order confirmation page) gets a personal
 * link /?ref=CODE. A friend who opens it gets X $ off their first order (coupon AMI-CODE, applied
 * automatically). When that order is completed, the referrer earns an X $ coupon (MERCI-…), listed
 * in the admin page with a ready-to-send WhatsApp message, and on the referrer's own page.
 *
 * Nothing is invented: prizes, quantities left and expiry times are real.
 */
defined('ABSPATH') || exit;

const MCR_RW_DB = 1;
const MCR_RW_TOKEN = 'mcr_rw';      // secret token of the visitor's reward row (httponly)
const MCR_RW_SPUN = 'mcr_spun';     // readable flag: this browser already spun
const MCR_RW_PENDING = 'mcr_pc';    // coupon waiting to be applied when the cart allows it
const MCR_RW_REF = 'mcr_ref';       // referral code the visitor arrived with

// ---------------------------------------------------------------- Settings and storage

function mcr_rw_default_prizes(): array
{
    return [
        ['id' => 'p5', 'fr' => '-5 %', 'en' => '5% off', 'type' => 'percent', 'amount' => 5, 'min' => 0, 'weight' => 35, 'limit' => 0],
        ['id' => 'ship', 'fr' => 'Livraison gratuite', 'en' => 'Free delivery', 'type' => 'ship', 'amount' => 0, 'min' => 30, 'weight' => 20, 'limit' => 0],
        ['id' => 'f3', 'fr' => '3 $', 'en' => '$3 off', 'type' => 'fixed', 'amount' => 3, 'min' => 30, 'weight' => 20, 'limit' => 0],
        ['id' => 'p10', 'fr' => '-10 %', 'en' => '10% off', 'type' => 'percent', 'amount' => 10, 'min' => 50, 'weight' => 15, 'limit' => 0],
        ['id' => 'f5', 'fr' => '5 $', 'en' => '$5 off', 'type' => 'fixed', 'amount' => 5, 'min' => 50, 'weight' => 8, 'limit' => 0],
        ['id' => 'p15', 'fr' => '-15 %', 'en' => '15% off', 'type' => 'percent', 'amount' => 15, 'min' => 75, 'weight' => 2, 'limit' => 20],
    ];
}

function mcr_rw_settings(): array
{
    $s = wp_parse_args((array) get_option('mcr_rewards', []), [
        'wheel' => true, 'hours' => 72, 'first_only' => true, 'prizes' => mcr_rw_default_prizes(),
        'ref' => true, 'ref_friend' => 5, 'ref_min' => 40, 'ref_reward' => 5, 'ref_days' => 60,
    ]);
    $s['prizes'] = array_values(array_filter((array) $s['prizes'], fn($p) => !empty($p['id']) && (float) ($p['weight'] ?? 0) > 0));
    return $s;
}

function mcr_rw_wheel_on(): bool
{
    $s = mcr_rw_settings();
    return !empty($s['wheel']) && $s['prizes'];
}

function mcr_rw_table(string $t = 'rewards'): string
{
    global $wpdb;
    return $wpdb->prefix . 'mcr_' . $t;
}

add_action('init', function () {
    if ((int) get_option('mcr_rw_db') >= MCR_RW_DB) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . mcr_rw_table() . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  phone varchar(20) NOT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  lang varchar(5) NOT NULL DEFAULT 'fr',
  token varchar(40) NOT NULL,
  ref_code varchar(12) NOT NULL,
  prize varchar(20) NOT NULL DEFAULT '',
  coupon varchar(40) NOT NULL DEFAULT '',
  expires_at datetime NULL,
  referred_by varchar(12) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY phone (phone),
  UNIQUE KEY ref_code (ref_code),
  KEY token (token)
) $c;");
    dbDelta('CREATE TABLE ' . mcr_rw_table('referrals') . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  ref_code varchar(12) NOT NULL,
  order_id bigint unsigned NOT NULL,
  friend varchar(190) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'pending',
  reward_coupon varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  rewarded_at datetime NULL,
  sent_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY ref_code (ref_code)
) $c;");
    update_option('mcr_rw_db', MCR_RW_DB);
});

function mcr_rw_code(int $len = 6): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return $s;
}

function mcr_rw_row_by(string $field, string $value): ?object
{
    global $wpdb;
    if (!in_array($field, ['phone', 'token', 'ref_code'], true) || $value === '') {
        return null;
    }
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mcr_rw_table() . " WHERE $field = %s", $value)) ?: null; // phpcs:ignore
}

/** The reward row for a phone number, created if needed (referral code and secret token). */
function mcr_rw_ensure_row(string $phone, string $name = '', string $lang = 'fr', string $referred_by = ''): ?object
{
    global $wpdb;
    $row = mcr_rw_row_by('phone', $phone);
    if ($row) {
        return $row;
    }
    for ($i = 0; $i < 5; $i++) {
        $ok = $wpdb->insert(mcr_rw_table(), [ // phpcs:ignore
            'phone' => $phone, 'name' => $name, 'lang' => $lang, 'token' => wp_generate_password(32, false, false),
            'ref_code' => mcr_rw_code(), 'referred_by' => $referred_by, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok) {
            break;
        }
    }
    return mcr_rw_row_by('phone', $phone);
}

function mcr_rw_prize(string $id): ?array
{
    foreach (mcr_rw_settings()['prizes'] as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** How many of a limited prize are left (null = unlimited). */
function mcr_rw_left(array $p): ?int
{
    if (empty($p['limit'])) {
        return null;
    }
    global $wpdb;
    $won = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . mcr_rw_table() . ' WHERE prize = %s', $p['id'])); // phpcs:ignore
    return max(0, (int) $p['limit'] - $won);
}

function mcr_rw_money(float $v): string
{
    return html_entity_decode(wp_strip_all_tags(wc_price($v)));
}

/** "Dès 50 $ d'achat · 1re commande" */
function mcr_rw_conditions(array $p, bool $en): string
{
    $out = [];
    if ((float) $p['min'] > 0) {
        $out[] = sprintf($en ? 'on orders of %s or more' : 'dès %s d’achat', mcr_rw_money((float) $p['min']));
    }
    if (!empty(mcr_rw_settings()['first_only'])) {
        $out[] = $en ? 'first order' : '1re commande';
    }
    return implode(' · ', $out);
}

function mcr_rw_label(array $p, bool $en): string
{
    return (string) ($en ? ($p['en'] ?: $p['fr']) : $p['fr']);
}

function mcr_rw_ref_link(string $code, bool $en): string
{
    return add_query_arg('ref', $code, home_url($en ? '/en/' : '/fr/'));
}

// ---------------------------------------------------------------- Coupons

function mcr_rw_make_coupon(string $code, array $args): ?WC_Coupon
{
    if (wc_get_coupon_id_by_code($code)) {
        return new WC_Coupon($code);
    }
    $c = new WC_Coupon();
    $c->set_code($code);
    $c->set_discount_type($args['type'] === 'percent' ? 'percent' : 'fixed_cart');
    $c->set_amount($args['type'] === 'ship' ? 0 : (float) $args['amount']);
    $c->set_free_shipping($args['type'] === 'ship');
    $c->set_individual_use(true);
    $c->set_minimum_amount((float) ($args['min'] ?? 0) > 0 ? (string) $args['min'] : '');
    if (!empty($args['usage_limit'])) {
        $c->set_usage_limit((int) $args['usage_limit']);
    }
    if (!empty($args['expires'])) {
        $c->set_date_expires((int) $args['expires']);
    }
    $c->set_description((string) ($args['description'] ?? ''));
    foreach ((array) ($args['meta'] ?? []) as $k => $v) {
        $c->update_meta_data($k, $v);
    }
    $c->save();
    return $c;
}

/** Friend coupon of a referrer: AMI-CODE (created the first time it is needed). */
function mcr_rw_friend_coupon(string $ref): ?WC_Coupon
{
    $s = mcr_rw_settings();
    if (empty($s['ref']) || !mcr_rw_row_by('ref_code', $ref)) {
        return null;
    }
    return mcr_rw_make_coupon('ami-' . strtolower($ref), [
        'type' => 'fixed', 'amount' => (float) $s['ref_friend'], 'min' => (float) $s['ref_min'],
        'description' => 'Parrainage : cadeau de bienvenue offert par ' . $ref,
        'meta' => ['_mcr_reward' => 'friend', '_mcr_ref' => $ref],
    ]);
}

// The coupons work even when the "Codes promo" box is switched off in the settings,
// but the code box itself only appears if that setting is on.
add_filter('woocommerce_coupons_enabled', function ($on) {
    return $on || mcr_rw_wheel_on() || !empty(mcr_rw_settings()['ref']);
}, 20);
add_action('wp', function () {
    if (!mcr_setting('store', 'coupons_enabled')) {
        remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
    }
});

// Clear names in the cart instead of codes.
add_filter('woocommerce_cart_totals_coupon_label', function ($label, $coupon) {
    $kind = $coupon->get_meta('_mcr_reward');
    $en = mcr_is_en();
    return match ($kind) {
        'wheel' => $en ? 'Welcome wheel gift' : 'Cadeau de la roue',
        'friend' => $en ? 'Gift from your friend' : 'Cadeau de votre ami',
        'thanks' => $en ? 'Referral reward' : 'Récompense de parrainage',
        default => $label,
    };
}, 10, 2);

/** Digits of a phone number, with the North American "1". */
function mcr_rw_norm_phone(string $p): string
{
    return function_exists('mcr_wag_phone') ? mcr_wag_phone($p) : preg_replace('/\D+/', '', $p);
}

/** Has this customer (email or phone) already ordered? */
function mcr_rw_has_ordered(string $email, string $phone, int $exclude = 0): bool
{
    $statuses = array_merge(['processing', 'completed', 'on-hold'], defined('MCR_WA_STATUS') ? [str_replace('wc-', '', MCR_WA_STATUS)] : []);
    foreach (array_filter(['billing_email' => $email, 'billing_phone' => $phone]) as $k => $v) {
        $ids = wc_get_orders([$k => $v, 'status' => $statuses, 'limit' => 2, 'return' => 'ids', 'exclude' => $exclude ? [$exclude] : []]);
        if ($ids) {
            return true;
        }
    }
    return false;
}

/** Why a reward coupon can't be used by this customer ('' = fine). */
function mcr_rw_check(WC_Coupon $c, string $email, string $phone, bool $en, int $exclude = 0): string
{
    $kind = $c->get_meta('_mcr_reward');
    if (!$kind) {
        return '';
    }
    $digits = mcr_rw_norm_phone($phone);
    if ($kind === 'wheel' && $c->get_meta('_mcr_phone') && $digits !== $c->get_meta('_mcr_phone')) {
        return $en ? 'Your wheel gift is linked to the WhatsApp number used to spin: please use that phone number.' : 'Votre cadeau de la roue est lié au numéro WhatsApp utilisé pour tourner la roue : indiquez ce numéro de téléphone.';
    }
    if ($kind === 'friend') {
        $owner = mcr_rw_row_by('ref_code', (string) $c->get_meta('_mcr_ref'));
        if ($owner && $owner->phone === $digits) {
            return $en ? 'You cannot use your own referral gift: share your link with a friend!' : 'Vous ne pouvez pas utiliser votre propre cadeau de parrainage : partagez votre lien à un ami !';
        }
    }
    $first = $kind === 'friend' || ($kind === 'wheel' && !empty(mcr_rw_settings()['first_only']));
    if ($first && (mcr_rw_has_ordered($email, $phone, $exclude) || ($digits !== '' && (mcr_rw_has_ordered('', $digits, $exclude) || mcr_rw_has_ordered('', '+' . $digits, $exclude))))) {
        return $en ? 'This gift is for a first order only.' : 'Ce cadeau est réservé à une première commande.';
    }
    return '';
}

// Checkout: check the reward coupons against the customer's details.
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (!WC()->cart) {
        return;
    }
    $en = mcr_is_en();
    foreach (WC()->cart->get_coupons() as $code => $c) {
        $why = mcr_rw_check($c, (string) ($data['billing_email'] ?? ''), (string) ($data['billing_phone'] ?? ''), $en);
        if ($why !== '') {
            WC()->cart->remove_coupon($code);
            $errors->add('mcr_reward', $why);
        }
    }
}, 10, 2);

/** WhatsApp orders from the cart: apply its reward coupons (and free delivery). */
function mcr_rw_apply_to_order(WC_Order $order, WC_Order_Item_Shipping $ship, bool $from_cart): void
{
    if (!$from_cart || !WC()->cart) {
        return;
    }
    foreach (WC()->cart->get_coupons() as $code => $c) {
        $why = mcr_rw_check($c, $order->get_billing_email(), $order->get_billing_phone(), $order->get_meta('_mcr_lang') === 'en' || mcr_is_en(), $order->get_id());
        if ($why !== '') {
            $order->add_order_note('Cadeau non appliqué (' . $code . ') : ' . $why);
            continue;
        }
        if ($c->get_free_shipping()) {
            $ship->set_total('0');
            $ship->set_method_title($ship->get_method_title() . (mcr_is_en() ? ' (free, gift)' : ' (gratuite, cadeau)'));
        }
        $res = $order->apply_coupon($code);
        if (is_wp_error($res)) {
            $order->add_order_note('Cadeau non appliqué (' . $code . ') : ' . $res->get_error_message());
        }
    }
}

/** Is a free-delivery gift in the cart? (used by the delivery price) */
function mcr_rw_cart_free_delivery(): bool
{
    if (!WC()->cart) {
        return false;
    }
    foreach (WC()->cart->get_coupons() as $c) {
        if ($c->get_free_shipping()) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------- Links: ?ref=CODE, ?mcr_coupon=CODE

function mcr_rw_set_cookie(string $name, string $value, int $ttl, bool $http = true): void
{
    if (headers_sent()) {
        return;
    }
    setcookie($name, $value, ['expires' => $ttl ? time() + $ttl : 0, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => $http, 'samesite' => 'Lax']);
    $_COOKIE[$name] = $value;
}

add_action('wp_loaded', function () {
    if (is_admin() || wp_doing_ajax() || !function_exists('WC')) {
        return;
    }
    // phpcs:disable WordPress.Security.NonceVerification
    if (!empty($_GET['ref']) && !empty(mcr_rw_settings()['ref'])) {
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['ref'])));
        if (mcr_rw_row_by('ref_code', $ref) && mcr_rw_friend_coupon($ref)) {
            mcr_rw_set_cookie(MCR_RW_REF, $ref, 30 * DAY_IN_SECONDS, false);
            mcr_rw_set_cookie(MCR_RW_PENDING, 'ami-' . strtolower($ref), 30 * DAY_IN_SECONDS);
        }
    }
    if (!empty($_GET['mcr_coupon'])) {
        $code = wc_format_coupon_code(wp_unslash($_GET['mcr_coupon']));
        if (wc_get_coupon_id_by_code($code)) {
            mcr_rw_set_cookie(MCR_RW_PENDING, $code, 30 * DAY_IN_SECONDS);
        }
    }
    // phpcs:enable
    // Apply the waiting coupon as soon as the cart reaches its minimum.
    $code = (string) ($_COOKIE[MCR_RW_PENDING] ?? '');
    if ($code === '' || !WC()->cart || WC()->cart->is_empty() || WC()->cart->has_discount($code)) {
        return;
    }
    $c = new WC_Coupon($code);
    if (!$c->get_id() || ($c->get_date_expires() && $c->get_date_expires()->getTimestamp() < time()) || ($c->get_usage_limit() && $c->get_usage_count() >= $c->get_usage_limit())) {
        mcr_rw_set_cookie(MCR_RW_PENDING, '', -3600);
        return;
    }
    WC()->cart->calculate_totals();
    if ((float) $c->get_minimum_amount() > 0 && (float) WC()->cart->get_subtotal() < (float) $c->get_minimum_amount()) {
        return;
    }
    $applied = WC()->cart->get_applied_coupons();
    if ($applied) {
        return; // one gift at a time: never replace one the customer already has
    }
    if (WC()->cart->apply_coupon($code)) {
        wc_clear_notices();
        wc_add_notice(mcr_is_en() ? 'Your gift has been applied to your cart.' : 'Votre cadeau a été appliqué à votre panier.', 'success');
    }
}, 30);

// Cart and checkout: "Add 12,50 $ to use your gift".
function mcr_rw_progress_html(): string
{
    $code = (string) ($_COOKIE[MCR_RW_PENDING] ?? '');
    if ($code === '' || !WC()->cart || WC()->cart->has_discount($code)) {
        return '';
    }
    $c = new WC_Coupon($code);
    $min = (float) $c->get_minimum_amount();
    $sub = (float) WC()->cart->get_subtotal();
    if (!$c->get_id() || $min <= 0 || $sub >= $min) {
        return '';
    }
    $en = mcr_is_en();
    $pct = max(4, min(100, round($sub / $min * 100)));
    $end = $c->get_date_expires() ? $c->get_date_expires()->getTimestamp() : 0;
    $ends = $end ? ' <span class="rwbar__end">' . esc_html($en ? 'Expires in' : 'Expire dans') . ' <b data-countdown="' . $end . '">' . esc_html(mcrt_end_label_safe($end)) . '</b></span>' : '';
    return '<div class="rwbar"><p><span class="rwbar__gift" aria-hidden="true"></span>' . '<span>' . wp_kses_post(sprintf($en ? 'Add <b>%s</b> to use your gift' : 'Plus que <b>%s</b> pour profiter de votre cadeau', wc_price($min - $sub))) . '</span>' . $ends . '</p><span class="rwbar__track"><span style="width:' . $pct . '%"></span></span></div>';
}
function mcrt_end_label_safe(int $end): string
{
    return function_exists('mcrt_end_label') ? mcrt_end_label($end) : '';
}
add_action('woocommerce_before_cart', fn() => print(mcr_rw_progress_html())); // phpcs:ignore
add_action('woocommerce_checkout_before_order_review', fn() => print(mcr_rw_progress_html()), 5); // phpcs:ignore

// ---------------------------------------------------------------- Spinning (server decides the prize)

add_action('wc_ajax_mcr_rw_spin', function () {
    // phpcs:disable WordPress.Security.NonceVerification -- public form: honeypot, consent, one spin per number, rate limit.
    $en = ($_POST['lang'] ?? '') === 'en';
    $err = fn($fr, $e, $code = 400) => wp_send_json_error(['message' => $en ? $e : $fr], $code);
    if (!mcr_rw_wheel_on() || !empty($_POST['website'])) {
        $err('La roue n’est pas disponible.', 'The wheel is not available.');
    }
    $ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $key = 'mcr_rw_ip_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= 6) {
        $err('Trop d’essais, réessayez demain.', 'Too many attempts, please try again tomorrow.', 429);
    }
    set_transient($key, $n + 1, DAY_IN_SECONDS);
    $phone = function_exists('mcr_wag_phone') ? mcr_wag_phone((string) wp_unslash($_POST['phone'] ?? '')) : '';
    if ($phone === '') {
        $err('Entrez un numéro WhatsApp valide, par ex. 819 555-1234.', 'Please enter a valid WhatsApp number, e.g. 819 555-1234.');
    }
    if (empty($_POST['consent'])) {
        $err('Cochez la case pour accepter nos messages WhatsApp.', 'Please tick the box to accept our WhatsApp messages.');
    }
    $name = mb_substr(sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 0, 120);
    $ref = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($_COOKIE[MCR_RW_REF] ?? '')));
    $row = mcr_rw_ensure_row($phone, $name, $en ? 'en' : 'fr', $ref);
    if (!$row) {
        $err('Erreur, réessayez.', 'Error, please try again.', 500);
    }
    // Also joins the "Nouveaux arrivages" WhatsApp list (same consent).
    if (function_exists('mcr_wag_table')) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . mcr_wag_table() . ' (phone, name, lang, user_id, page, consent_at) VALUES (%s, %s, %s, %d, %s, %s)', $phone, $name, $en ? 'en' : 'fr', get_current_user_id(), 'roue', gmdate('Y-m-d H:i:s'))); // phpcs:ignore
        mcr_rw_set_cookie('mcr_wag', '1', 2 * YEAR_IN_SECONDS, false);
    }
    mcr_rw_set_cookie(MCR_RW_TOKEN, $row->token, YEAR_IN_SECONDS);
    mcr_rw_set_cookie(MCR_RW_SPUN, '1', YEAR_IN_SECONDS, false);
    if ($row->prize !== '') {
        // Already spun with this number: show the same prize again, no second chance.
        wp_send_json_success(mcr_rw_state($row, $en) + ['again' => true]);
    }
    // Draw among the prizes still available, by their weight.
    $prizes = mcr_rw_settings()['prizes'];
    $pool = array_values(array_filter($prizes, fn($p) => mcr_rw_left($p) !== 0));
    if (!$pool) {
        $err('Tous les cadeaux ont été gagnés. Merci !', 'All the gifts have been won. Thank you!');
    }
    $total = array_sum(array_map(fn($p) => (float) $p['weight'], $pool));
    $r = random_int(1, 1000000) / 1000000 * $total;
    $won = end($pool);
    foreach ($pool as $p) {
        $r -= (float) $p['weight'];
        if ($r <= 0) {
            $won = $p;
            break;
        }
    }
    $hours = max(1, (int) mcr_rw_settings()['hours']);
    $expires = time() + $hours * HOUR_IN_SECONDS;
    $code = 'roue-' . strtolower(mcr_rw_code(7));
    mcr_rw_make_coupon($code, [
        'type' => $won['type'], 'amount' => $won['amount'], 'min' => $won['min'], 'usage_limit' => 1, 'expires' => $expires,
        'description' => 'Roue de bienvenue : ' . $won['fr'] . ' pour +' . $phone,
        'meta' => ['_mcr_reward' => 'wheel', '_mcr_phone' => $phone, '_mcr_prize' => $won['id']],
    ]);
    global $wpdb;
    $wpdb->update(mcr_rw_table(), ['prize' => $won['id'], 'coupon' => $code, 'expires_at' => gmdate('Y-m-d H:i:s', $expires), 'name' => $name ?: $row->name], ['id' => $row->id]); // phpcs:ignore
    mcr_rw_set_cookie(MCR_RW_PENDING, $code, $hours * HOUR_IN_SECONDS);
    wp_send_json_success(mcr_rw_state(mcr_rw_row_by('phone', $phone), $en));
    // phpcs:enable
});

/** What the visitor's gift panel shows. */
function mcr_rw_state(object $row, bool $en): array
{
    $p = $row->prize ? mcr_rw_prize($row->prize) : null;
    $prizes = mcr_rw_settings()['prizes'];
    $idx = $p ? (int) array_search($row->prize, array_column($prizes, 'id'), true) : -1;
    $c = $row->coupon ? new WC_Coupon($row->coupon) : null;
    $used = $c && $c->get_id() && $c->get_usage_count() > 0;
    global $wpdb;
    $thanks = $wpdb->get_results($wpdb->prepare('SELECT reward_coupon FROM ' . mcr_rw_table('referrals') . " WHERE ref_code = %s AND status = 'rewarded'", $row->ref_code)); // phpcs:ignore
    $friends = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . mcr_rw_table('referrals') . ' WHERE ref_code = %s', $row->ref_code)); // phpcs:ignore
    $s = mcr_rw_settings();
    return [
        'segment' => $idx,
        'prize' => $p ? mcr_rw_label($p, $en) : '',
        'conditions' => $p ? mcr_rw_conditions($p, $en) : '',
        'coupon' => $row->coupon ? strtoupper($row->coupon) : '',
        'expires' => $row->expires_at ? strtotime($row->expires_at . ' UTC') : 0,
        'used' => $used,
        'use' => add_query_arg('mcr_coupon', $row->coupon, function_exists('mcr_shop_url') ? mcr_shop_url() : home_url('/')),
        'ref' => !empty($s['ref']) ? [
            'code' => $row->ref_code, 'link' => mcr_rw_ref_link($row->ref_code, $en),
            'friend' => mcr_rw_money((float) $s['ref_friend']), 'reward' => mcr_rw_money((float) $s['ref_reward']), 'min' => mcr_rw_money((float) $s['ref_min']),
            'friends' => $friends,
            'rewards' => array_map(fn($t) => strtoupper($t->reward_coupon), $thanks),
        ] : null,
    ];
}

// The visitor's own gift and referral link (loaded by the page, so pages stay the same for everyone).
add_action('wc_ajax_mcr_rw_me', function () {
    $en = ($_GET['lang'] ?? '') === 'en'; // phpcs:ignore WordPress.Security.NonceVerification
    $row = mcr_rw_row_by('token', preg_replace('/[^A-Za-z0-9]/', '', (string) ($_COOKIE[MCR_RW_TOKEN] ?? '')));
    if (!$row) {
        wp_send_json_error(null, 404);
    }
    wp_send_json_success(mcr_rw_state($row, $en));
});

// ---------------------------------------------------------------- Referral: order completed → reward

add_action('woocommerce_checkout_order_processed', 'mcr_rw_track_order', 20);
add_action('woocommerce_order_status_processing', 'mcr_rw_track_order', 20);
function mcr_rw_track_order($order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    foreach ($order->get_coupon_codes() as $code) {
        $c = new WC_Coupon($code);
        if ($c->get_meta('_mcr_reward') === 'friend') {
            global $wpdb;
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . mcr_rw_table('referrals') . ' (ref_code, order_id, friend, status, created_at) VALUES (%s, %d, %s, %s, %s)', (string) $c->get_meta('_mcr_ref'), $order->get_id(), trim($order->get_formatted_billing_full_name() . ' ' . $order->get_billing_phone()), 'pending', gmdate('Y-m-d H:i:s'))); // phpcs:ignore
        }
    }
}

add_action('woocommerce_order_status_completed', function ($order_id) {
    mcr_rw_track_order($order_id);
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mcr_rw_table('referrals') . " WHERE order_id = %d AND status = 'pending'", $order_id)); // phpcs:ignore
    if (!$r) {
        return;
    }
    $s = mcr_rw_settings();
    $code = 'merci-' . strtolower(mcr_rw_code(7));
    mcr_rw_make_coupon($code, [
        'type' => 'fixed', 'amount' => (float) $s['ref_reward'], 'min' => 0, 'usage_limit' => 1, 'expires' => time() + max(7, (int) $s['ref_days']) * DAY_IN_SECONDS,
        'description' => 'Récompense de parrainage (commande #' . $order_id . ')',
        'meta' => ['_mcr_reward' => 'thanks', '_mcr_ref' => $r->ref_code],
    ]);
    $wpdb->update(mcr_rw_table('referrals'), ['status' => 'rewarded', 'reward_coupon' => $code, 'rewarded_at' => gmdate('Y-m-d H:i:s')], ['id' => $r->id]); // phpcs:ignore
});
add_action('woocommerce_order_status_cancelled', fn($id) => $GLOBALS['wpdb']->update(mcr_rw_table('referrals'), ['status' => 'cancelled'], ['order_id' => $id, 'status' => 'pending']));
add_action('woocommerce_order_status_refunded', fn($id) => $GLOBALS['wpdb']->update(mcr_rw_table('referrals'), ['status' => 'cancelled'], ['order_id' => $id, 'status' => 'pending']));

// Order confirmation: "Invite your friends" with the customer's own link.
add_action('woocommerce_thankyou', function ($order_id) {
    $s = mcr_rw_settings();
    $order = $order_id ? wc_get_order($order_id) : null;
    if (empty($s['ref']) || !$order) {
        return;
    }
    $phone = mcr_rw_norm_phone($order->get_billing_phone());
    if ($phone === '') {
        return;
    }
    $en = mcr_is_en();
    $row = mcr_rw_ensure_row($phone, $order->get_billing_first_name(), $en ? 'en' : 'fr');
    if (!$row) {
        return;
    }
    mcr_rw_set_cookie(MCR_RW_TOKEN, $row->token, YEAR_IN_SECONDS);
    echo mcr_rw_share_block($row->ref_code, $en); // phpcs:ignore
}, 4);

function mcr_rw_share_block(string $code, bool $en): string
{
    $s = mcr_rw_settings();
    $link = mcr_rw_ref_link($code, $en);
    $msg = sprintf($en ? 'Hi! Here is %s off your first order at Marché Chemin du Roy, the African grocery in Trois-Rivières: %s' : 'Salut ! Voici %s de rabais sur ta première commande au Marché Chemin du Roy, l’épicerie africaine de Trois-Rivières : %s', mcr_rw_money((float) $s['ref_friend']), $link);
    $wa = 'https://wa.me/?text=' . rawurlencode($msg);
    $t = $en ? sprintf('Give %1$s, get %2$s', mcr_rw_money((float) $s['ref_friend']), mcr_rw_money((float) $s['ref_reward'])) : sprintf('Offrez %1$s, recevez %2$s', mcr_rw_money((float) $s['ref_friend']), mcr_rw_money((float) $s['ref_reward']));
    $p = $en ? sprintf('Your friends get %1$s off their first order (from %3$s). When their order is completed, you get %2$s too.', mcr_rw_money((float) $s['ref_friend']), mcr_rw_money((float) $s['ref_reward']), mcr_rw_money((float) $s['ref_min'])) : sprintf('Vos amis reçoivent %1$s sur leur première commande (dès %3$s). Quand leur commande est terminée, vous recevez %2$s vous aussi.', mcr_rw_money((float) $s['ref_friend']), mcr_rw_money((float) $s['ref_reward']), mcr_rw_money((float) $s['ref_min']));
    return '<section class="rwref"><div class="rwref__art" aria-hidden="true"><span class="rwref__coin rwref__coin--a">$</span><span class="rwref__coin rwref__coin--b">$</span><span class="rwref__gift"></span></div>'
        . '<div class="rwref__body"><p class="rwref__k">' . esc_html($en ? 'Referral' : 'Parrainage') . '</p><h2>' . esc_html($t) . '</h2><p>' . esc_html($p) . '</p>'
        . '<div class="rwref__link"><input type="text" readonly value="' . esc_attr($link) . '" aria-label="' . esc_attr($en ? 'Your link' : 'Votre lien') . '"><button type="button" class="btn btn--ghost" data-rw-copy="' . esc_attr($link) . '">' . esc_html($en ? 'Copy' : 'Copier') . '</button></div>'
        . '<a class="btn btn--wa btn--block rw-shine" href="' . esc_url($wa) . '" target="_blank" rel="noopener">' . (function_exists('mcr_icon') ? mcr_icon('whatsapp', 18) : '') . ' ' . esc_html($en ? 'Share on WhatsApp' : 'Partager sur WhatsApp') . '</a></div></section>';
}

// ---------------------------------------------------------------- Front end: wheel, gift button, friend welcome

add_action('wp_enqueue_scripts', function () {
    $s = mcr_rw_settings();
    if (!mcr_rw_wheel_on() && empty($s['ref'])) {
        return;
    }
    wp_enqueue_script('mcr-rewards', MCR_URL . 'assets/rewards.js', [], MCR_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
});

add_action('wp_footer', function () {
    $s = mcr_rw_settings();
    if (is_admin() || (!mcr_rw_wheel_on() && empty($s['ref']))) {
        return;
    }
    $en = mcr_is_en();
    $ic = fn($n, $z = 20) => function_exists('mcr_icon') ? mcr_icon($n, $z) : '';
    $checkout = function_exists('is_checkout') && (is_checkout() || is_cart());
    $prizes = $s['prizes'];
    $n = count($prizes);
    $colors = ['#174b35', '#c96b43', '#e6b74f', '#1f6145', '#a9532f', '#f2d48b', '#0f3324', '#d98a4c'];
    $ink = ['#fff', '#fff', '#0f3324', '#fff', '#fff', '#0f3324', '#fff', '#fff'];
    // Wheel drawn as SVG slices; slice 0 starts at the top pointer.
    $svg = '';
    $seg = 360 / max(1, $n);
    foreach ($prizes as $i => $p) {
        $a0 = deg2rad($i * $seg - 90);
        $a1 = deg2rad(($i + 1) * $seg - 90);
        $x0 = 100 + 96 * cos($a0); $y0 = 100 + 96 * sin($a0);
        $x1 = 100 + 96 * cos($a1); $y1 = 100 + 96 * sin($a1);
        $large = $seg > 180 ? 1 : 0;
        $col = $colors[$i % count($colors)];
        $txt = $ink[$i % count($ink)];
        $left = mcr_rw_left($p);
        $label = mcr_rw_label($p, $en);
        $mid = $i * $seg + $seg / 2;
        $lines = mb_strlen($label) > 9 ? explode(' ', $label, 2) : [$label];
        $tspans = '';
        foreach ($lines as $k => $l) {
            $tspans .= '<tspan x="100" dy="' . ($k ? '1.1em' : (count($lines) > 1 ? '-0.2em' : '0.35em')) . '">' . esc_html($l) . '</tspan>';
        }
        $sub = $left === 0 ? ($en ? 'All won' : 'Épuisé') : ($left !== null ? sprintf($en ? 'Only %d left' : 'Plus que %d', $left) : '');
        $svg .= sprintf('<path d="M100,100 L%.2f,%.2f A96,96 0 %d,1 %.2f,%.2f Z" fill="%s"%s/>', $x0, $y0, $large, $x1, $y1, $col, $left === 0 ? ' opacity=".45"' : '');
        $svg .= sprintf('<g transform="rotate(%.2f 100 100)"><text x="100" y="40" text-anchor="middle" fill="%s" class="rw-wheel__t%s">%s</text>%s</g>', $mid, $txt, count($lines) > 1 ? ' rw-wheel__t--2' : '', $tspans, $sub ? '<text x="100" y="64" text-anchor="middle" fill="' . $txt . '" class="rw-wheel__s">' . esc_html($sub) . '</text>' : '');
    }
    $bulbs = '';
    for ($b = 0; $b < 16; $b++) {
        $ang = deg2rad($b * 22.5);
        $bulbs .= sprintf('<circle cx="%.2f" cy="%.2f" r="3.2" class="rw-bulb rw-bulb--%d"/>', 100 + 99 * cos($ang), 100 + 99 * sin($ang), $b % 2);
    }
    $hours = (int) $s['hours'];
    ?>
<div class="rw" id="mcr-rw" data-ajax-spin="<?php echo esc_url(WC_AJAX::get_endpoint('mcr_rw_spin')); ?>" data-ajax-me="<?php echo esc_url(add_query_arg('lang', $en ? 'en' : 'fr', WC_AJAX::get_endpoint('mcr_rw_me'))); ?>" data-lang="<?php echo $en ? 'en' : 'fr'; ?>" data-segments="<?php echo (int) $n; ?>" data-auto="<?php echo mcr_rw_wheel_on() && !$checkout ? '1' : '0'; ?>">
  <?php if (mcr_rw_wheel_on() && !$checkout) : // not over the cart's sticky checkout bar ?>
  <button type="button" class="rw-fab" data-rw-open hidden aria-label="<?php echo esc_attr($en ? 'Open your welcome gift' : 'Ouvrir votre cadeau de bienvenue'); ?>">
    <span class="rw-fab__ring" aria-hidden="true"></span>
    <span class="rw-fab__box" aria-hidden="true"><svg viewBox="0 0 64 64"><rect x="10" y="28" width="44" height="30" rx="4" fill="#c96b43"/><rect x="6" y="20" width="52" height="12" rx="3" fill="#e6b74f"/><rect x="28" y="20" width="8" height="38" fill="#174b35"/><path d="M32 20c-6-10-18-10-16-2 1 4 10 3 16 2zm0 0c6-10 18-10 16-2-1 4-10 3-16 2z" fill="#174b35"/></svg></span>
    <span class="rw-fab__txt" data-rw-fab-txt><?php echo esc_html($en ? 'Gift' : 'Cadeau'); ?></span>
  </button>
  <?php endif; ?>

  <dialog class="rw-dlg" id="rw-dlg" aria-labelledby="rw-h">
    <button type="button" class="rw-x" data-rw-close aria-label="<?php echo esc_attr($en ? 'Close' : 'Fermer'); ?>">×</button>
    <canvas class="rw-confetti" aria-hidden="true"></canvas>
    <div class="rw-step" data-rw-step="wheel">
      <p class="rw-k"><?php echo esc_html($en ? 'Welcome gift' : 'Cadeau de bienvenue'); ?></p>
      <h2 id="rw-h" tabindex="-1" autofocus><?php echo esc_html($en ? 'Spin the wheel, every spin wins!' : 'Tournez la roue : chaque essai gagne !'); ?></h2>
      <div class="rw-wheel">
        <span class="rw-wheel__ptr" aria-hidden="true"><svg viewBox="0 0 40 48"><path d="M20 46 4 10a16 16 0 1 1 32 0Z" fill="#e6b74f" stroke="#0f3324" stroke-width="3"/><circle cx="20" cy="16" r="6" fill="#0f3324"/></svg></span>
        <svg class="rw-wheel__svg" viewBox="0 0 200 200" aria-hidden="true"><circle cx="100" cy="100" r="100" fill="#0f3324"/><g class="rw-wheel__rot"><?php echo $svg; // phpcs:ignore ?></g><?php echo $bulbs; // phpcs:ignore ?></svg>
        <button type="submit" form="rw-form" class="rw-wheel__hub"><span><?php echo esc_html($en ? 'SPIN' : 'TOURNER'); ?></span></button>
      </div>
      <form id="rw-form" class="rw-form" novalidate>
        <label class="rw-field"><span><?php echo esc_html($en ? 'Your WhatsApp number' : 'Votre numéro WhatsApp'); ?></span><input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="819 555-1234" required></label>
        <label class="rw-check"><input type="checkbox" name="consent" value="1" required> <span><?php echo esc_html($en ? 'I agree to receive WhatsApp messages from Marché Chemin du Roy (new arrivals, deals), once in a while. I can stop at any time.' : 'J’accepte de recevoir des messages WhatsApp de Marché Chemin du Roy (arrivages, promotions), de temps en temps. Je peux arrêter à tout moment.'); ?></span></label>
        <input type="text" name="website" tabindex="-1" autocomplete="off" class="wag__hp" aria-hidden="true">
        <p class="rw-err" role="alert" hidden></p>
        <button type="submit" class="btn btn--gold btn--block rw-go"><span class="rw-shine" aria-hidden="true"></span><?php echo esc_html($en ? 'Spin the wheel' : 'Tourner la roue'); ?></button>
        <p class="rw-fine"><?php echo esc_html(sprintf($en ? 'One spin per number. Gift valid %d h, on a first order, for the number used.' : 'Un essai par numéro. Cadeau valable %d h, sur une première commande, pour le numéro utilisé.', $hours)); ?></p>
        <button type="button" class="wag__later" data-rw-later><?php echo esc_html($en ? 'Maybe later' : 'Plus tard'); ?></button>
      </form>
    </div>

    <div class="rw-step rw-win" data-rw-step="win" hidden>
      <p class="rw-k"><?php echo esc_html($en ? 'Congratulations!' : 'Félicitations !'); ?></p>
      <h2><?php echo esc_html($en ? 'You won' : 'Vous avez gagné'); ?></h2>
      <div class="rw-ticket"><span class="rw-ticket__shine" aria-hidden="true"></span>
        <b class="rw-ticket__prize" data-rw="prize"></b>
        <span class="rw-ticket__cond" data-rw="conditions"></span>
        <span class="rw-ticket__code"><?php echo esc_html($en ? 'Code' : 'Code'); ?> <b data-rw="coupon"></b></span>
      </div>
      <p class="rw-timer" data-rw="timerwrap"><span class="rw-timer__ic"><?php echo $ic('clock', 18); // phpcs:ignore ?></span> <?php echo esc_html($en ? 'Expires in' : 'Expire dans'); ?> <b data-rw="timer">--</b></p>
      <p class="rw-used" data-rw="used" hidden><?php echo $ic('circle-check', 16); // phpcs:ignore ?> <?php echo esc_html($en ? 'Already used. Thank you!' : 'Déjà utilisé. Merci !'); ?></p>
      <a class="btn btn--gold btn--block rw-go" data-rw="use" href="#"><span class="rw-shine" aria-hidden="true"></span><?php echo esc_html($en ? 'Shop now with my gift' : 'Utiliser mon cadeau maintenant'); ?> <?php echo $ic('arrow-right', 18); // phpcs:ignore ?></a>
      <p class="rw-fine"><?php echo esc_html($en ? 'Your gift is added to your cart automatically.' : 'Votre cadeau s’ajoute automatiquement à votre panier.'); ?></p>
      <?php if (!empty($s['ref'])) : ?>
      <div class="rw-invite">
        <p class="rw-invite__t"><?php echo $ic('users', 18); // phpcs:ignore ?> <b data-rw="refTitle"></b></p>
        <p class="rw-invite__p" data-rw="refText"></p>
        <a class="btn btn--wa btn--block" data-rw="refWa" href="#" target="_blank" rel="noopener"><?php echo $ic('whatsapp', 18); // phpcs:ignore ?> <?php echo esc_html($en ? 'Invite on WhatsApp' : 'Inviter sur WhatsApp'); ?></a>
        <button type="button" class="rw-copy" data-rw-copy=""><?php echo esc_html($en ? 'Copy my link' : 'Copier mon lien'); ?></button>
        <p class="rw-invite__got" data-rw="refGot" hidden></p>
      </div>
      <?php endif; ?>
    </div>
  </dialog>

  <?php if (!empty($s['ref'])) : ?>
  <dialog class="rw-dlg rw-friend" id="rw-friend" aria-labelledby="rw-fh">
    <button type="button" class="rw-x" data-rw-close aria-label="<?php echo esc_attr($en ? 'Close' : 'Fermer'); ?>">×</button>
    <canvas class="rw-confetti" aria-hidden="true"></canvas>
    <div class="rw-friend__art" aria-hidden="true"><span class="rwref__coin rwref__coin--a">$</span><span class="rwref__coin rwref__coin--b">$</span><span class="rwref__gift"></span></div>
    <p class="rw-k"><?php echo esc_html($en ? 'A gift from your friend' : 'Un cadeau de votre ami'); ?></p>
    <h2 id="rw-fh"><?php echo esc_html(sprintf($en ? '%s off your first order' : '%s sur votre première commande', mcr_rw_money((float) $s['ref_friend']))); ?></h2>
    <p class="rw-friend__p"><?php echo esc_html(sprintf($en ? 'Already saved for you: it is applied automatically when your cart reaches %s.' : 'Déjà gardé pour vous : il s’applique tout seul quand votre panier atteint %s.', mcr_rw_money((float) $s['ref_min']))); ?></p>
    <a class="btn btn--gold btn--block rw-go" href="<?php echo esc_url(function_exists('mcr_shop_url') ? mcr_shop_url() : home_url('/')); ?>" data-rw-close><span class="rw-shine" aria-hidden="true"></span><?php echo esc_html($en ? 'Start shopping' : 'Commencer mes achats'); ?> <?php echo $ic('arrow-right', 18); // phpcs:ignore ?></a>
  </dialog>
  <?php endif; ?>
</div>
    <?php
}, 0);
