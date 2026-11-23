<?php
/**
 * Plugin Name: Chick Licences
 * Description: Abonnements mensuels des modules MCR Gestion : clients, forfaits, essai gratuit de 30 jours, paiement par virement Interac (ou Stripe), rappels et reçus. À installer sur le site de CHICK Service (pas chez le client).
 * Version: 1.1.0
 * Author: CHICK Service
 * Requires PHP: 8.0
 * Text Domain: chick-licences
 */
defined('ABSPATH') || exit;

const CHICK_LIC_VERSION = '1.1.0';
const CHICK_LIC_DB = 2;

// ---------------------------------------------------------------- Storage

function chick_lic_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'chick_licences';
}

function chick_lic_install(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . chick_lic_table() . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  lkey varchar(40) NOT NULL,
  client varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  site varchar(255) NOT NULL DEFAULT '',
  plan varchar(30) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'trial',
  trial_end datetime NULL,
  period_end datetime NULL,
  extra_modules text NULL,
  stripe_customer varchar(64) NOT NULL DEFAULT '',
  stripe_sub varchar(64) NOT NULL DEFAULT '',
  last_seen datetime NULL,
  last_site varchar(255) NOT NULL DEFAULT '',
  last_version varchar(20) NOT NULL DEFAULT '',
  notes text NULL,
  req_plan varchar(30) NOT NULL DEFAULT '',
  req_months smallint NOT NULL DEFAULT 0,
  req_at datetime NULL,
  req_note varchar(255) NOT NULL DEFAULT '',
  reminded varchar(40) NOT NULL DEFAULT '',
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY lkey (lkey),
  KEY stripe_sub (stripe_sub),
  KEY stripe_customer (stripe_customer)
) " . $wpdb->get_charset_collate() . ';');
    update_option('chick_lic_db', CHICK_LIC_DB);
    chick_lic_keys(); // signing keys
}
register_activation_hook(__FILE__, 'chick_lic_install');
add_action('plugins_loaded', function () {
    if ((int) get_option('chick_lic_db') < CHICK_LIC_DB) {
        chick_lic_install();
    }
});

/** Ed25519 key pair used to sign answers (the client sites check the signature). */
function chick_lic_keys(): array
{
    $k = get_option('chick_lic_keys');
    if (!is_array($k) || empty($k['secret'])) {
        $pair = sodium_crypto_sign_keypair();
        $k = ['secret' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
        update_option('chick_lic_keys', $k, false);
    }
    return $k;
}

/** Modules of MCR Gestion that plans can include (ids must match the client plugin). */
function chick_lic_modules(): array
{
    return [
        'promotions' => 'Promotions, affiche et fenêtre promo',
        'flash' => 'Ventes éclair et badges',
        'fbt' => '« Pour compléter »',
        'reminders' => 'Relances automatiques',
        'wagroup' => 'Groupe WhatsApp',
        'rewards' => 'Roue de bienvenue et parrainage',
        'ai' => 'Optimiseur IA',
    ];
}

function chick_lic_settings(): array
{
    $all = array_keys(chick_lic_modules());
    $defaults = [
        'trial_days' => 30,
        'trial_plan' => 'premium',
        'plans' => [
            'essentiel' => ['name' => 'Essentiel', 'price' => '59 $', 'modules' => [], 'price_id' => '', 'link' => ''],
            'croissance' => ['name' => 'Croissance', 'price' => '149 $', 'modules' => ['promotions', 'flash', 'fbt', 'reminders', 'wagroup'], 'price_id' => '', 'link' => ''],
            'premium' => ['name' => 'Premium', 'price' => '279 $', 'modules' => $all, 'price_id' => '', 'link' => ''],
        ],
        'stripe_secret' => '',
        'webhook_secret' => '',
        'support_url' => '',
        'pay_mode' => 'interac',
        'interac_email' => '',
        'interac_name' => 'CHICK Service',
        'interac_note' => 'Indiquez votre clé de licence dans le message du virement. Le dépôt automatique est activé : aucune question de sécurité.',
        'notify_email' => '',
    ];
    $s = get_option('chick_lic_settings', []);
    $s = is_array($s) ? array_replace($defaults, $s) : $defaults;
    $s['plans'] = array_replace($defaults['plans'], (array) $s['plans']);
    return $s;
}

function chick_lic_get(string $field, string $value): ?object
{
    global $wpdb;
    if (!in_array($field, ['lkey', 'stripe_sub', 'stripe_customer', 'id'], true) || $value === '') {
        return null;
    }
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . chick_lic_table() . " WHERE $field = %s", $value)) ?: null; // phpcs:ignore
}

function chick_lic_update(int $id, array $data): void
{
    global $wpdb;
    $wpdb->update(chick_lic_table(), $data, ['id' => $id]); // phpcs:ignore
}

function chick_lic_new_key(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($p = 0; $p < 3; $p++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $abc[random_int(0, 31)];
        }
        $parts[] = $s;
    }
    return 'MCR-' . implode('-', $parts);
}

function chick_lic_ts(?string $dt): int
{
    return $dt ? (int) strtotime($dt . ' UTC') : 0;
}

/**
 * What a licence gives right now.
 * status: trial | active | past_due | canceled | expired | suspended
 */
function chick_lic_effective(object $l): array
{
    $s = chick_lic_settings();
    $now = time();
    $trial_end = chick_lic_ts($l->trial_end);
    $period_end = chick_lic_ts($l->period_end);
    $status = $l->status;
    if ($status === 'trial' && $trial_end && $now > $trial_end) {
        $status = 'expired';
    }
    $plan = $status === 'trial' ? $s['trial_plan'] : ($l->plan ?: $s['trial_plan']);
    $modules = (array) ($s['plans'][$plan]['modules'] ?? []);
    $extra = json_decode((string) $l->extra_modules, true);
    if (is_array($extra)) {
        $modules = array_values(array_unique(array_merge($modules, $extra)));
    }
    $until = $status === 'trial' || ($status === 'expired' && !$period_end) ? $trial_end : $period_end;
    return ['status' => $status, 'plan' => $plan, 'plan_name' => (string) ($s['plans'][$plan]['name'] ?? $plan), 'modules' => $modules, 'until' => $until];
}

/** Checkout address for a plan, with the licence key attached (Stripe Payment Link). */
function chick_lic_checkout(array $plan, object $l): string
{
    if (empty($plan['link'])) {
        return '';
    }
    return add_query_arg(array_filter(['client_reference_id' => $l->lkey, 'prefilled_email' => $l->email]), $plan['link']);
}

// ---------------------------------------------------------------- API for client sites

add_action('rest_api_init', function () {
    register_rest_route('chick/v1', '/check', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'chick_lic_rest_check']);
    register_rest_route('chick/v1', '/portal', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'chick_lic_rest_portal']);
    register_rest_route('chick/v1', '/stripe', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'chick_lic_rest_stripe']);
    register_rest_route('chick/v1', '/request', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'chick_lic_rest_request']);
});

function chick_lic_rest_check(WP_REST_Request $r)
{
    $key = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) $r->get_param('key')));
    $site = esc_url_raw((string) $r->get_param('site'));
    $l = chick_lic_get('lkey', $key);
    if (!$l) {
        return new WP_REST_Response(['message' => 'Clé de licence inconnue.'], 404);
    }
    $host = strtolower((string) parse_url($site, PHP_URL_HOST));
    $data = ['last_seen' => gmdate('Y-m-d H:i:s'), 'last_site' => $site, 'last_version' => substr((string) $r->get_param('version'), 0, 20)];
    if ($l->site === '' && $host) {
        $data['site'] = $host; // the first site to use the key keeps it
        $l->site = $host;
    }
    chick_lic_update((int) $l->id, $data);
    $e = chick_lic_effective($l);
    $same = !$host || preg_replace('/^(www|staging)\./', '', $host) === preg_replace('/^(www|staging)\./', '', strtolower($l->site));
    if (!$same) {
        $e['status'] = 'other_site';
        $e['modules'] = [];
    }
    $s = chick_lic_settings();
    $plans = [];
    foreach ($s['plans'] as $id => $p) {
        $plans[] = ['id' => $id, 'name' => $p['name'], 'price' => $p['price'], 'modules' => array_values((array) $p['modules']), 'checkout' => chick_lic_checkout($p, $l)];
    }
    $payload = wp_json_encode([
        'key' => $l->lkey,
        'status' => $e['status'],
        'plan' => $e['plan'],
        'plan_name' => $e['plan_name'],
        'modules' => $e['modules'],
        'until' => $e['until'],
        'plans' => $plans,
        'portal' => $l->stripe_customer ? rest_url('chick/v1/portal') . '?key=' . rawurlencode($l->lkey) : ($s['support_url'] ?: ''),
        'interac' => $s['pay_mode'] === 'interac' && $s['interac_email'] ? ['email' => $s['interac_email'], 'name' => $s['interac_name'], 'note' => $s['interac_note'], 'request' => rest_url('chick/v1/request')] : null,
        'pending' => $l->req_plan ? ['plan' => $l->req_plan, 'months' => (int) $l->req_months, 'at' => chick_lic_ts($l->req_at)] : null,
        'server_time' => time(),
    ]);
    $k = chick_lic_keys();
    $sig = base64_encode(sodium_crypto_sign_detached($payload, base64_decode($k['secret'])));
    return new WP_REST_Response(['payload' => $payload, 'sig' => $sig, 'pubkey' => $k['public']], 200);
}

/** Stripe customer portal (change card, plan, cancel). */
function chick_lic_rest_portal(WP_REST_Request $r)
{
    $l = chick_lic_get('lkey', strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) $r->get_param('key'))));
    $s = chick_lic_settings();
    if (!$l || !$l->stripe_customer || !$s['stripe_secret']) {
        return new WP_REST_Response(['message' => 'Portail indisponible.'], 404);
    }
    $res = chick_lic_stripe('POST', 'billing_portal/sessions', ['customer' => $l->stripe_customer, 'return_url' => $l->last_site ?: home_url('/')]);
    if (empty($res['url'])) {
        return new WP_REST_Response(['message' => 'Portail indisponible.'], 502);
    }
    wp_redirect($res['url']); // phpcs:ignore WordPress.Security.SafeRedirect -- Stripe's own page
    exit;
}

function chick_lic_stripe(string $method, string $path, array $body = []): array
{
    $s = chick_lic_settings();
    $res = wp_remote_request('https://api.stripe.com/v1/' . $path, [
        'method' => $method,
        'timeout' => 20,
        'headers' => ['Authorization' => 'Bearer ' . $s['stripe_secret']],
        'body' => $method === 'GET' ? null : $body,
    ]);
    return is_wp_error($res) ? [] : (array) json_decode((string) wp_remote_retrieve_body($res), true);
}

/** Which plan a Stripe price belongs to. */
function chick_lic_plan_for_price(string $price_id, int $amount = 0): string
{
    foreach (chick_lic_settings()['plans'] as $id => $p) {
        if ($price_id !== '' && $p['price_id'] === $price_id) {
            return $id;
        }
    }
    return '';
}

function chick_lic_apply_subscription(object $l, array $sub): void
{
    $map = ['active' => 'active', 'trialing' => 'active', 'past_due' => 'past_due', 'unpaid' => 'past_due', 'incomplete' => 'past_due', 'canceled' => 'canceled', 'incomplete_expired' => 'canceled'];
    $price = (string) ($sub['items']['data'][0]['price']['id'] ?? '');
    $plan = chick_lic_plan_for_price($price);
    $end = (int) ($sub['current_period_end'] ?? ($sub['items']['data'][0]['current_period_end'] ?? 0));
    $data = ['status' => $map[$sub['status'] ?? ''] ?? $l->status, 'stripe_sub' => (string) ($sub['id'] ?? $l->stripe_sub), 'stripe_customer' => (string) ($sub['customer'] ?? $l->stripe_customer)];
    if ($plan) {
        $data['plan'] = $plan;
    }
    if ($end) {
        $data['period_end'] = gmdate('Y-m-d H:i:s', $end);
    }
    chick_lic_update((int) $l->id, $data);
}

/** Stripe webhook: payments and subscription changes. */
function chick_lic_rest_stripe(WP_REST_Request $r)
{
    $s = chick_lic_settings();
    $payload = $r->get_body();
    $header = (string) $r->get_header('stripe-signature');
    if (!$s['webhook_secret'] || !preg_match('/t=(\d+)/', $header, $t) || !preg_match_all('/v1=([a-f0-9]+)/', $header, $v)) {
        return new WP_REST_Response(['message' => 'signature'], 400);
    }
    $expected = hash_hmac('sha256', $t[1] . '.' . $payload, $s['webhook_secret']);
    $valid = false;
    foreach ($v[1] as $sig) {
        $valid = $valid || hash_equals($expected, $sig);
    }
    if (!$valid || abs(time() - (int) $t[1]) > 600) {
        return new WP_REST_Response(['message' => 'signature'], 400);
    }
    $event = json_decode($payload, true);
    $o = (array) ($event['data']['object'] ?? []);
    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
            $l = chick_lic_get('lkey', strtoupper((string) ($o['client_reference_id'] ?? '')));
            if ($l) {
                chick_lic_update((int) $l->id, ['stripe_customer' => (string) ($o['customer'] ?? ''), 'stripe_sub' => (string) ($o['subscription'] ?? ''), 'status' => 'active']);
                if (!empty($o['subscription']) && $s['stripe_secret']) {
                    $sub = chick_lic_stripe('GET', 'subscriptions/' . rawurlencode($o['subscription']));
                    if (!empty($sub['id'])) {
                        chick_lic_apply_subscription(chick_lic_get('id', (string) $l->id), $sub);
                    }
                }
            }
            break;
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $l = chick_lic_get('stripe_sub', (string) ($o['id'] ?? '')) ?: chick_lic_get('stripe_customer', (string) ($o['customer'] ?? ''));
            if ($l) {
                chick_lic_apply_subscription($l, $o);
            }
            break;
        case 'invoice.paid':
        case 'invoice.payment_failed':
            $l = chick_lic_get('stripe_sub', (string) ($o['subscription'] ?? '')) ?: chick_lic_get('stripe_customer', (string) ($o['customer'] ?? ''));
            if ($l) {
                $data = ['status' => $event['type'] === 'invoice.paid' ? 'active' : 'past_due'];
                $end = (int) ($o['lines']['data'][0]['period']['end'] ?? 0);
                if ($event['type'] === 'invoice.paid' && $end) {
                    $data['period_end'] = gmdate('Y-m-d H:i:s', $end);
                }
                chick_lic_update((int) $l->id, $data);
            }
            break;
    }
    return new WP_REST_Response(['received' => true], 200);
}



// ---------------------------------------------------------------- Interac (manual payments)

/** Plan price as a number ("149 $" -> 149.0). */
function chick_lic_amount(string $plan, int $months = 1): float
{
    $p = chick_lic_settings()['plans'][$plan]['price'] ?? '0';
    return round((float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', (string) $p)) * max(1, $months), 2);
}

function chick_lic_money(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' $';
}

function chick_lic_admin_email(): string
{
    $s = chick_lic_settings();
    return $s['notify_email'] ?: (string) get_option('admin_email');
}

/** The client says "I sent the Interac transfer": keep the request and tell the admin. */
function chick_lic_rest_request(WP_REST_Request $r)
{
    $l = chick_lic_get('lkey', strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) $r->get_param('key'))));
    if (!$l) {
        return new WP_REST_Response(['message' => 'Clé de licence inconnue.'], 404);
    }
    $key = 'chick_lic_req_' . $l->id;
    if (get_transient($key)) {
        return new WP_REST_Response(['message' => 'Demande déjà reçue, merci. Elle sera confirmée dès réception du virement.'], 200);
    }
    $plans = chick_lic_settings()['plans'];
    $plan = sanitize_key((string) $r->get_param('plan'));
    $months = (int) $r->get_param('months');
    if (!isset($plans[$plan]) || !in_array($months, [1, 3, 6, 12], true)) {
        return new WP_REST_Response(['message' => 'Forfait ou durée invalide.'], 400);
    }
    $note = mb_substr(sanitize_text_field((string) $r->get_param('note')), 0, 250);
    chick_lic_update((int) $l->id, ['req_plan' => $plan, 'req_months' => $months, 'req_at' => gmdate('Y-m-d H:i:s'), 'req_note' => $note]);
    set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
    $amount = chick_lic_money(chick_lic_amount($plan, $months));
    wp_mail(chick_lic_admin_email(), 'Virement Interac à confirmer — ' . $l->client,
        "Le client {$l->client} indique avoir envoyé un virement Interac.\n\nForfait : {$plans[$plan]['name']}\nDurée : {$months} mois\nMontant attendu : {$amount}\nClé : {$l->lkey}\nMessage : {$note}\n\nAprès réception, confirmez ici :\n" . admin_url('admin.php?page=chick-lic&edit=' . (int) $l->id));
    return new WP_REST_Response(['message' => 'Merci ! Votre paiement sera confirmé dès réception du virement.'], 200);
}

/** Receipt to the client after a confirmed payment. */
function chick_lic_send_receipt(object $l, string $plan, int $months, int $until): void
{
    if (!is_email($l->email)) {
        return;
    }
    $s = chick_lic_settings();
    $name = $s['plans'][$plan]['name'] ?? $plan;
    $amount = chick_lic_money(chick_lic_amount($plan, $months));
    wp_mail($l->email, 'Reçu — abonnement ' . $name . ' (' . $s['interac_name'] . ')',
        "Bonjour,\n\nNous avons bien reçu votre paiement de {$amount} par virement Interac. Merci !\n\nForfait : {$name}\nDurée : {$months} mois\nActif jusqu’au : " . wp_date('j F Y', $until) . "\nClé : {$l->lkey}\n\nVos modules sont actifs. Aucune action n’est nécessaire de votre part.\n\n— " . $s['interac_name']);
}

// Reminder 7 days before the end of the trial or of the paid period (once per period).
add_action('chick_lic_daily', function () {
    global $wpdb;
    $s = chick_lic_settings();
    foreach ($wpdb->get_results('SELECT * FROM ' . chick_lic_table()) as $l) { // phpcs:ignore
        $e = chick_lic_effective($l);
        $until = (int) $e['until'];
        $left = $until - time();
        $tag = (string) $until;
        if (!$until || $left > 7 * DAY_IN_SECONDS || $left < -DAY_IN_SECONDS || $l->reminded === $tag || !is_email($l->email) || in_array($e['status'], ['canceled', 'suspended'], true)) {
            continue;
        }
        $plan = $l->plan ?: 'croissance';
        $amount = chick_lic_money(chick_lic_amount($plan));
        $what = $e['status'] === 'trial' ? 'Votre essai gratuit se termine' : 'Votre abonnement arrive à échéance';
        $how = $s['interac_email'] ? "Pour continuer, envoyez un virement Interac de {$amount} (forfait " . ($s['plans'][$plan]['name'] ?? $plan) . ", 1 mois) à {$s['interac_email']}, avec votre clé {$l->lkey} dans le message.\nVous pouvez aussi choisir un autre forfait ou payer plusieurs mois d’avance depuis MCR Gestion › Abonnement." : 'Pour continuer, rendez-vous dans MCR Gestion › Abonnement.';
        wp_mail($l->email, $what . ' le ' . wp_date('j F Y', $until), "Bonjour,\n\n{$what} le " . wp_date('j F Y', $until) . ".\n\n{$how}\n\nSans paiement, les modules (promotions, ventes éclair, roue…) seront mis en pause 14 jours après cette date. Votre boutique, elle, continue de fonctionner normalement.\n\n— {$s['interac_name']}");
        chick_lic_update((int) $l->id, ['reminded' => $tag]);
    }
});
add_action('init', function () {
    if (!wp_next_scheduled('chick_lic_daily')) {
        wp_schedule_event(time() + 600, 'daily', 'chick_lic_daily');
    }
});

if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}
