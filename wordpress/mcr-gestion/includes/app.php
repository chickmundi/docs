<?php
/**
 * "MCR Commandes": a small installable web app (PWA) for the store team.
 * - /mcr-app/            the app (staff only: sign-in required)
 * - /mcr-app/sw.js       service worker (push notifications, offline shell)
 * - /mcr-app/manifest.json
 * - REST: /wp-json/mcr/v1/app/*  orders, order, status, subscribe, test
 * A push notification is sent to every subscribed phone when an order is paid
 * (or placed with "pay on delivery"), and when an order is cancelled.
 */
defined('ABSPATH') || exit;

const MCR_APP_PATH = '/mcr-app';

function mcr_app_can(): bool
{
    return current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce');
}

function mcr_app_url(string $path = '/'): string
{
    return set_url_scheme(get_option('home'), 'https') . MCR_APP_PATH . $path;
}

// ---------------------------------------------------------------- Routes (served before WordPress picks a page)

add_action('init', function () {
    $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    $base = preg_replace('#/(fr|en)$#', '', $base);
    if (strpos($path, $base . MCR_APP_PATH) !== 0) {
        return;
    }
    $rest = substr($path, strlen($base . MCR_APP_PATH));
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('X-LiteSpeed-Cache-Control: no-cache');
    if ($rest === '/sw.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . $base . MCR_APP_PATH . '/');
        readfile(MCR_DIR . 'assets/app/sw.js');
        exit;
    }
    if ($rest === '/manifest.json') {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode(mcr_app_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($rest === '' || $rest === '/' || $rest === '/index.html') {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(mcr_app_url('/')));
            exit;
        }
        if (!mcr_app_can()) {
            wp_die(esc_html__('Accès réservé à l’équipe du magasin.', 'mcr'), '', ['response' => 403]);
        }
        mcr_app_render();
        exit;
    }
}, 1);

// Keep staff on the app after signing in from it.
add_filter('login_redirect', function ($to, $requested) {
    return $requested && strpos($requested, MCR_APP_PATH) !== false ? $requested : $to;
}, 10, 2);

function mcr_app_manifest(): array
{
    $icons = MCR_URL . 'assets/app/';
    return [
        'name' => 'MCR Commandes',
        'short_name' => 'Commandes',
        'description' => 'Commandes du Marché Chemin-du-Roy en temps réel',
        'id' => MCR_APP_PATH . '/',
        'start_url' => MCR_APP_PATH . '/',
        'scope' => MCR_APP_PATH . '/',
        'display' => 'standalone',
        'orientation' => 'portrait',
        'background_color' => '#103b2b',
        'theme_color' => '#174b35',
        'lang' => 'fr-CA',
        'icons' => [
            ['src' => $icons . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icons . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icons . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];
}

function mcr_app_render(): void
{
    $user = wp_get_current_user();
    $config = [
        'rest' => esc_url_raw(rest_url('mcr/v1/app/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'vapid' => mcr_vapid_keys()['public'],
        'sw' => MCR_APP_PATH . '/sw.js',
        'scope' => MCR_APP_PATH . '/',
        'admin' => admin_url('post.php?action=edit&post='),
        'hpos' => class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
        'adminHpos' => admin_url('admin.php?page=wc-orders&action=edit&id='),
        'user' => $user->display_name,
        'logout' => wp_logout_url(mcr_app_url('/')),
        'store' => mcr_setting('store', 'name'),
    ];
    $v = MCR_VERSION;
    ?><!doctype html>
<html lang="fr-CA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#174b35">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Commandes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>MCR Commandes</title>
<link rel="manifest" href="<?php echo esc_url(MCR_APP_PATH . '/manifest.json'); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url(MCR_URL . 'assets/app/icon-180.png'); ?>">
<link rel="icon" href="<?php echo esc_url(MCR_URL . 'assets/app/icon-192.png'); ?>">
<link rel="stylesheet" href="<?php echo esc_url(MCR_URL . 'assets/app/app.css?ver=' . $v); ?>">
</head>
<body>
<div id="app" aria-live="polite"></div>
<script>window.MCR_APP = <?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo esc_url(MCR_URL . 'assets/app/app.js?ver=' . $v); ?>" defer></script>
</body>
</html>
    <?php
}

// ---------------------------------------------------------------- Data for the app

/** Money received? "Pay on delivery / at pickup" orders are only paid once completed. */
function mcr_app_is_paid(WC_Order $o): bool
{
    if ($o->get_payment_method() === 'cod') {
        return $o->has_status('completed');
    }
    return $o->is_paid();
}

function mcr_app_order_data(WC_Order $o, bool $full = false): array
{
    $mode = function_exists('mcr_order_fulfilment') ? mcr_order_fulfilment($o) : '';
    $d = [
        'id' => $o->get_id(),
        'number' => $o->get_order_number(),
        'status' => $o->get_status(),
        'status_label' => wc_get_order_status_name($o->get_status()),
        'date' => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0,
        'total' => (float) $o->get_total(),
        'total_label' => html_entity_decode(wp_strip_all_tags(wc_price($o->get_total(), ['currency' => $o->get_currency()]))),
        'name' => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
        'mode' => $mode,
        'items_count' => $o->get_item_count(),
        'paid' => mcr_app_is_paid($o),
        'payment' => $o->get_payment_method_title(),
    ];
    if ($full) {
        $items = [];
        foreach ($o->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $p = $item->get_product();
            $meta = [];
            foreach ($item->get_formatted_meta_data('_', true) as $m) {
                $meta[] = wp_strip_all_tags($m->display_key) . ' : ' . wp_strip_all_tags($m->display_value);
            }
            $img = $p ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : '';
            $items[] = [
                'name' => $item->get_name(),
                'qty' => $item->get_quantity(),
                'total' => html_entity_decode(wp_strip_all_tags(wc_price((float) $item->get_total() + (float) $item->get_total_tax()))),
                'meta' => $meta,
                'sku' => $p ? $p->get_sku() : '',
                'image' => $img ?: '',
            ];
        }
        $address = $mode === 'pickup' ? '' : trim(implode(', ', array_filter([$o->get_billing_address_1(), $o->get_billing_address_2(), $o->get_billing_city(), $o->get_billing_postcode()])));
        $distance = '';
        foreach ($o->get_shipping_methods() as $sm) {
            $distance = (string) $sm->get_meta('Distance');
        }
        $d += [
            'phone' => $o->get_billing_phone(),
            'email' => $o->get_billing_email(),
            'address' => $address,
            'distance' => $distance,
            'shipping_label' => $o->get_shipping_method(),
            'shipping_total' => html_entity_decode(wp_strip_all_tags(wc_price((float) $o->get_shipping_total() + (float) $o->get_shipping_tax()))),
            'note' => $o->get_customer_note(),
            'items' => $items,
            'lang' => $o->get_meta('_mcr_lang') ?: 'fr',
            'next' => mcr_app_next_steps($o, $mode),
        ];
    }
    return $d;
}

/** The next steps the team can choose for an order. */
function mcr_app_next_steps(WC_Order $o, string $mode): array
{
    $s = $o->get_status();
    $labels = ['mcr-preparing' => 'En préparation', 'mcr-ready' => 'Prête au retrait', 'mcr-out' => 'En livraison', 'completed' => $mode === 'pickup' ? 'Récupérée (terminée)' : 'Livrée (terminée)'];
    $next = [];
    if (in_array($s, ['processing', 'on-hold', 'pending'], true)) {
        $next[] = 'mcr-preparing';
    }
    if (in_array($s, ['processing', 'on-hold', 'mcr-preparing'], true)) {
        $next[] = $mode === 'pickup' ? 'mcr-ready' : 'mcr-out';
    }
    if (in_array($s, ['mcr-ready', 'mcr-out', 'mcr-preparing', 'processing'], true)) {
        $next[] = 'completed';
    }
    return array_map(fn($st) => ['status' => $st, 'label' => $labels[$st]], array_values(array_unique($next)));
}

const MCR_APP_OPEN = ['processing', 'on-hold', 'mcr-preparing', 'mcr-ready', 'mcr-out'];

add_action('rest_api_init', function () {
    $perm = fn() => mcr_app_can();
    register_rest_route('mcr/v1', '/app/orders', [
        'methods' => 'GET',
        'permission_callback' => $perm,
        'callback' => function (WP_REST_Request $r) {
            $tab = $r->get_param('tab') === 'done' ? 'done' : ($r->get_param('tab') === 'all' ? 'all' : 'open');
            $args = ['limit' => 60, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order'];
            if ($tab === 'open') {
                $args['status'] = MCR_APP_OPEN;
            } elseif ($tab === 'done') {
                $args['status'] = ['completed'];
                $args['limit'] = 40;
            } else {
                $args['status'] = array_keys(wc_get_order_statuses());
                $args['limit'] = 60;
            }
            $orders = array_map(fn($o) => mcr_app_order_data($o), wc_get_orders($args));
            // Today's figures (store time zone).
            $tz = wp_timezone();
            $start = (new DateTimeImmutable('today', $tz))->getTimestamp();
            $today = wc_get_orders(['limit' => -1, 'type' => 'shop_order', 'status' => array_merge(MCR_APP_OPEN, ['completed']), 'date_created' => '>=' . $start, 'return' => 'objects']);
            $sum = 0.0;
            foreach ($today as $t) {
                $sum += (float) $t->get_total();
            }
            $open = (int) count(wc_get_orders(['limit' => -1, 'status' => MCR_APP_OPEN, 'type' => 'shop_order', 'return' => 'ids']));
            return [
                'orders' => $orders,
                'open' => $open,
                'today' => ['count' => count($today), 'total' => html_entity_decode(wp_strip_all_tags(wc_price($sum)))],
                'nonce' => wp_create_nonce('wp_rest'),
            ];
        },
    ]);
    register_rest_route('mcr/v1', '/app/order/(?P<id>\d+)', [
        'methods' => 'GET',
        'permission_callback' => $perm,
        'callback' => function (WP_REST_Request $r) {
            $o = wc_get_order((int) $r['id']);
            return $o ? mcr_app_order_data($o, true) : new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);
        },
    ]);
    register_rest_route('mcr/v1', '/app/order/(?P<id>\d+)/status', [
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function (WP_REST_Request $r) {
            $o = wc_get_order((int) $r['id']);
            $to = sanitize_key((string) $r->get_param('status'));
            if (!$o) {
                return new WP_Error('not_found', 'Commande introuvable', ['status' => 404]);
            }
            $allowed = array_column(mcr_app_next_steps($o, function_exists('mcr_order_fulfilment') ? mcr_order_fulfilment($o) : ''), 'status');
            if (!in_array($to, $allowed, true)) {
                return new WP_Error('bad_status', 'Étape non permise', ['status' => 400]);
            }
            $o->update_status($to, sprintf('Changé depuis l’application par %s.', wp_get_current_user()->display_name));
            return mcr_app_order_data(wc_get_order($o->get_id()), true);
        },
    ]);
    register_rest_route('mcr/v1', '/app/subscribe', [
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function (WP_REST_Request $r) {
            $sub = (array) $r->get_param('subscription');
            $endpoint = esc_url_raw((string) ($sub['endpoint'] ?? ''));
            $keys = (array) ($sub['keys'] ?? []);
            if (!$endpoint || !preg_match('#^https://#', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
                return new WP_Error('bad_sub', 'Abonnement invalide', ['status' => 400]);
            }
            $all = (array) get_option('mcr_push_subs', []);
            $all[md5($endpoint)] = [
                'endpoint' => $endpoint,
                'p256dh' => sanitize_text_field($keys['p256dh']),
                'auth' => sanitize_text_field($keys['auth']),
                'user' => get_current_user_id(),
                'device' => sanitize_text_field(substr((string) $r->get_param('device'), 0, 120)),
                'created' => time(),
            ];
            update_option('mcr_push_subs', $all, false);
            return ['ok' => true, 'devices' => count($all)];
        },
    ]);
    register_rest_route('mcr/v1', '/app/unsubscribe', [
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function (WP_REST_Request $r) {
            $all = (array) get_option('mcr_push_subs', []);
            unset($all[md5((string) $r->get_param('endpoint'))]);
            update_option('mcr_push_subs', $all, false);
            return ['ok' => true];
        },
    ]);
    register_rest_route('mcr/v1', '/app/test', [
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function (WP_REST_Request $r) {
            $res = mcr_push_all(['title' => 'Test réussi ✅', 'body' => 'Les notifications de commandes fonctionnent sur cet appareil.', 'tag' => 'mcr-test', 'url' => MCR_APP_PATH . '/'], (string) $r->get_param('endpoint'));
            return ['sent' => $res];
        },
    ]);
});

// ---------------------------------------------------------------- Sending

/** Send to every subscribed device (or one endpoint). Removes dead subscriptions. Returns [sent, failed]. */
function mcr_push_all(array $data, string $only_endpoint = ''): array
{
    $data += ['icon' => MCR_URL . 'assets/app/icon-192.png', 'badge' => MCR_URL . 'assets/app/badge-96.png'];
    $all = (array) get_option('mcr_push_subs', []);
    $sent = 0;
    $failed = 0;
    $changed = false;
    $codes = [];
    foreach ($all as $key => $sub) {
        if ($only_endpoint && $sub['endpoint'] !== $only_endpoint) {
            continue;
        }
        $code = mcr_webpush_send($sub, $data);
        $codes[] = (string) wp_parse_url($sub['endpoint'], PHP_URL_HOST) . ' ' . $code;
        if ($code >= 200 && $code < 300) {
            $sent++;
        } else {
            $failed++;
            if (in_array($code, [404, 410], true)) {
                unset($all[$key]);
                $changed = true;
            }
        }
    }
    if ($changed) {
        update_option('mcr_push_subs', $all, false);
    }
    update_option('mcr_push_last', ['time' => time(), 'sent' => $sent, 'failed' => $failed, 'title' => $data['title'] ?? '', 'codes' => $codes], false);
    return [$sent, $failed];
}

/** Queue a notification and send it after the page has been returned to the customer. */
function mcr_push_later(array $data): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = [];
        add_action('shutdown', function () use (&$queue) {
            if (!$queue) {
                return;
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            foreach ($queue as $d) {
                mcr_push_all($d);
            }
        }, 1000);
    }
    $queue[] = $data;
}

function mcr_push_order(WC_Order $o, string $event): void
{
    if ($o->get_meta('_mcr_push_' . $event) || !get_option('mcr_push_subs')) {
        return;
    }
    $o->update_meta_data('_mcr_push_' . $event, time());
    $o->save_meta_data();
    $mode = function_exists('mcr_order_fulfilment') ? mcr_order_fulfilment($o) : '';
    $total = html_entity_decode(wp_strip_all_tags(wc_price($o->get_total())));
    $name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
    if ($event === 'new') {
        $title = sprintf('🛒 Nouvelle commande n° %s : %s', $o->get_order_number(), $total);
        $body = sprintf('%s · %s · %d article%s · %s%s', $name ?: 'Client', $mode === 'pickup' ? 'Retrait en magasin' : 'Livraison', $o->get_item_count(), $o->get_item_count() > 1 ? 's' : '', mcr_app_is_paid($o) ? 'payée' : 'à encaisser', $o->get_customer_note() ? ' · 📝 note' : '');
    } else {
        $title = sprintf('❌ Commande n° %s annulée', $o->get_order_number());
        $body = sprintf('%s · %s', $name ?: 'Client', $total);
    }
    mcr_push_later([
        'title' => $title,
        'body' => $body,
        'tag' => 'mcr-order-' . $o->get_id() . '-' . $event,
        'url' => MCR_APP_PATH . '/#order-' . $o->get_id(),
        'order' => $o->get_id(),
        'renotify' => true,
    ]);
}

// Paid orders (card) and "pay on delivery" orders reach "processing" or "on-hold".
add_action('woocommerce_order_status_changed', function ($id, $from, $to, $order) {
    if (!$order instanceof WC_Order) {
        return;
    }
    if (in_array($to, ['processing', 'on-hold'], true) && in_array($from, ['pending', 'failed', 'checkout-draft', ''], true)) {
        mcr_push_order($order, 'new');
    } elseif ($to === 'cancelled' && in_array($from, MCR_APP_OPEN, true)) {
        mcr_push_order($order, 'cancelled');
    }
}, 20, 4);
// Orders created directly as processing (some gateways, admin).
add_action('woocommerce_new_order', function ($id, $order = null) {
    $order = $order instanceof WC_Order ? $order : wc_get_order($id);
    if ($order && in_array($order->get_status(), ['processing', 'on-hold'], true)) {
        mcr_push_order($order, 'new');
    }
}, 20, 2);

// ---------------------------------------------------------------- Admin page

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Application Commandes', 'Application mobile', 'edit_shop_orders', 'mcr-app', 'mcr_render_app_admin');
}, 27);

function mcr_render_app_admin(): void
{
    if (isset($_POST['mcr_app_remove']) && check_admin_referer('mcr_app_devices')) {
        $all = (array) get_option('mcr_push_subs', []);
        unset($all[sanitize_key($_POST['mcr_app_remove'])]);
        update_option('mcr_push_subs', $all, false);
    }
    if (isset($_POST['mcr_app_test']) && check_admin_referer('mcr_app_devices')) {
        [$sent, $failed] = mcr_push_all(['title' => 'Test réussi ✅', 'body' => 'Envoyé depuis WordPress : les notifications fonctionnent.', 'tag' => 'mcr-test', 'url' => MCR_APP_PATH . '/']);
        printf('<div class="notice notice-success"><p>Test envoyé : %d appareil(s), %d échec(s).</p></div>', $sent, $failed);
    }
    $url = mcr_app_url('/');
    $subs = (array) get_option('mcr_push_subs', []);
    $last = get_option('mcr_push_last');
    echo '<div class="wrap"><h1>Application mobile « MCR Commandes »</h1>';
    echo '<p style="font-size:15px">Recevez une notification sur votre téléphone dès qu’une commande est payée (ou passée « payer à la livraison »), et gérez-la en un geste.</p>';
    printf('<p><a class="button button-primary button-hero" href="%1$s" target="_blank">Ouvrir l’application</a> &nbsp; <code style="font-size:14px">%1$s</code></p>', esc_url($url));
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;max-width:1100px">';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px"><h2 style="margin-top:0">📱 Android (Chrome)</h2><ol><li>Ouvrez l’adresse ci-dessus dans Chrome et connectez-vous.</li><li>Touchez <strong>Installer l’application</strong> (ou menu ⋮ → <em>Ajouter à l’écran d’accueil</em>).</li><li>Ouvrez « Commandes » depuis l’écran d’accueil et touchez <strong>Activer les notifications</strong>.</li></ol></div>';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px"><h2 style="margin-top:0">🍎 iPhone (Safari, iOS 16.4 ou plus)</h2><ol><li>Ouvrez l’adresse dans <strong>Safari</strong> et connectez-vous.</li><li>Touchez <strong>Partager</strong> → <em>Sur l’écran d’accueil</em>.</li><li>Ouvrez « Commandes » <strong>depuis l’écran d’accueil</strong>, reconnectez-vous si demandé, puis touchez <strong>Activer les notifications</strong>.</li></ol><p class="description">Sur iPhone, les notifications ne fonctionnent que dans l’application installée, pas dans Safari.</p></div>';
    echo '</div>';
    echo '<h2>Appareils abonnés (' . count($subs) . ')</h2>';
    echo '<form method="post">';
    wp_nonce_field('mcr_app_devices');
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Appareil</th><th>Personne</th><th>Depuis</th><th></th></tr></thead><tbody>';
    if (!$subs) {
        echo '<tr><td colspan="4">Aucun appareil pour le moment. Installez l’application et activez les notifications.</td></tr>';
    }
    foreach ($subs as $key => $s) {
        $u = get_userdata((int) $s['user']);
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td><button class="button" name="mcr_app_remove" value="%s">Retirer</button></td></tr>', esc_html($s['device'] ?: wp_parse_url($s['endpoint'], PHP_URL_HOST)), esc_html($u ? $u->display_name : '—'), esc_html(date_i18n('j M Y H:i', (int) $s['created'])), esc_attr($key));
    }
    echo '</tbody></table>';
    if ($subs) {
        echo '<p><button class="button button-secondary" name="mcr_app_test" value="1">Envoyer une notification de test à tous les appareils</button></p>';
    }
    echo '</form>';
    if (is_array($last)) {
        printf('<p class="description">Dernier envoi : %s — « %s » — %d reçu(s) par le service, %d échec(s). <small>%s</small></p>', esc_html(date_i18n('j M Y H:i', $last['time'])), esc_html($last['title']), (int) $last['sent'], (int) $last['failed'], esc_html(implode(' · ', (array) ($last['codes'] ?? []))));
    }
    echo '<p class="description">Qui peut utiliser l’application : les comptes qui peuvent gérer les commandes (Administrateur, Gestionnaire de boutique).</p></div>';
}
