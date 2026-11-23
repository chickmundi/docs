<?php
/**
 * Plugin Name: Chick Licences
 * Description: Abonnements mensuels des modules MCR Gestion : clients, forfaits, essai gratuit de 30 jours, paiement Stripe, portail client. À installer sur le site de CHICK Service (pas chez le client).
 * Version: 1.0.0
 * Author: CHICK Service
 * Requires PHP: 8.0
 * Text Domain: chick-licences
 */
defined('ABSPATH') || exit;

const CHICK_LIC_VERSION = '1.0.0';
const CHICK_LIC_DB = 1;

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

if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}
