<?php
/**
 * Store app (/mcr-app/): shop management from the phone.
 * REST /wp-json/mcr/v1/app/*
 *  - shop         GET dashboard (sales, stock alerts, quick settings) · POST quick settings
 *  - products     GET search/filter · product/{id} GET/POST · product POST (create) · variation/{id} POST
 *  - media        POST photo upload (resized on the phone first)
 *  - cats         GET product categories
 *  - promos       GET campaigns, flash sales, promo of the week
 *  - promo        POST create/update · promo/{id}/delete POST
 *  - flash        POST create/update · flash/{id}/{stop|delete|tomorrow} POST
 *  - promo-week   POST
 * Products: edit_products. Promotions and shop settings: manage_woocommerce.
 * Only real prices: a promo price must be lower than the regular price, which is never raised by the app.
 */
defined('ABSPATH') || exit;

function mcr_app_caps(): array
{
    return [
        'orders' => mcr_app_can(),
        'products' => current_user_can('edit_products'),
        'shop' => current_user_can('manage_woocommerce'),
        'promotions' => function_exists('mcr_promo_upsert'),
        'flash' => function_exists('mcr_flash_upsert'),
    ];
}

function mcr_app_money(float $v): string
{
    return html_entity_decode(wp_strip_all_tags(wc_price($v)));
}

function mcr_app_thumb(WC_Product $p, string $size = 'woocommerce_thumbnail'): string
{
    $id = $p->get_image_id() ?: ($p->get_parent_id() ? (int) get_post_thumbnail_id($p->get_parent_id()) : 0);
    return $id ? (string) wp_get_attachment_image_url($id, $size) : '';
}

/** Compact product row for lists. */
function mcr_app_product_row(WC_Product $p): array
{
    $low = function_exists('mcr_low_stock_qty') ? mcr_low_stock_qty($p) : 0;
    return [
        'id' => $p->get_id(),
        'name' => $p->get_name(),
        'image' => mcr_app_thumb($p),
        'price' => html_entity_decode(wp_strip_all_tags($p->get_price_html())),
        'on_sale' => $p->is_on_sale(),
        'status' => $p->get_status(),
        'stock' => $p->get_stock_status(),
        'qty' => $p->managing_stock() ? $p->get_stock_quantity() : null,
        'low' => $low,
        'type' => $p->get_type(),
    ];
}

function mcr_app_price_fields(WC_Product $p): array
{
    $to = $p->get_date_on_sale_to('edit');
    $promo = (string) $p->get_meta('_mcr_promo');
    $campaign = $promo && function_exists('mcr_promos') ? (mcr_promos()[$promo]['title_fr'] ?? '') : '';
    return [
        'regular' => $p->get_regular_price('edit'),
        'sale' => $p->get_sale_price('edit'),
        'sale_to' => $to ? $to->date('Y-m-d') : '',
        'campaign' => $promo ? ($campaign ?: 'une promotion') : '',
        'manage' => $p->managing_stock(),
        'qty' => $p->get_stock_quantity(),
        'stock' => $p->get_stock_status(),
    ];
}

function mcr_app_product_full(WC_Product $p): array
{
    $flash = function_exists('mcr_flash_for_product') ? (mcr_flash_for_product($p->get_id()) ?: mcr_flash_for_product($p->get_id(), 'scheduled')) : null;
    $out = mcr_app_product_row($p) + mcr_app_price_fields($p) + [
        'name_en' => function_exists('mcr_en_meta') ? mcr_en_meta($p->get_id(), 'title') : '',
        'image_full' => mcr_app_thumb($p, 'medium_large'),
        'image_id' => (int) $p->get_image_id(),
        'sku' => $p->get_sku(),
        'cats' => array_map('intval', $p->get_category_ids()),
        'link' => get_permalink($p->get_id()),
        'flash' => $flash ? ['state' => mcr_flash_state($flash), 'left' => mcr_flash_left($flash), 'qty' => (int) $flash['qty']] : null,
        'variations' => [],
    ];
    if ($p->is_type('variable')) {
        foreach ($p->get_children() as $vid) {
            $v = wc_get_product($vid);
            if ($v) {
                $out['variations'][] = ['id' => $vid, 'name' => wc_get_formatted_variation($v, true, false, false), 'image' => mcr_app_thumb($v), 'enabled' => $v->get_status() === 'publish'] + mcr_app_price_fields($v);
            }
        }
    }
    return $out;
}

/** Prices and stock from the app, on a simple product or a variation. Returns an error message or ''. */
function mcr_app_apply_prices(WC_Product $p, array $in): string
{
    $num = fn($v) => $v === '' || $v === null ? '' : wc_format_decimal(str_replace(',', '.', (string) $v));
    if (array_key_exists('regular', $in)) {
        $reg = $num($in['regular']);
        if ($reg === '' || (float) $reg <= 0) {
            return 'Indiquez un prix supérieur à 0.';
        }
        $p->set_regular_price($reg);
    }
    $locked = (string) $p->get_meta('_mcr_promo') !== '';
    if (array_key_exists('sale', $in) && !$locked) {
        $sale = $num($in['sale']);
        $reg = (float) $p->get_regular_price('edit');
        if ($sale !== '' && ((float) $sale <= 0 || (float) $sale >= $reg)) {
            return 'Le prix promo doit être plus bas que le prix régulier (' . mcr_app_money($reg) . ').';
        }
        $p->set_sale_price($sale);
        $p->set_date_on_sale_from($sale === '' ? null : $p->get_date_on_sale_from('edit'));
        $to = (string) ($in['sale_to'] ?? '');
        if ($sale === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $p->set_date_on_sale_to(null);
        } else {
            $tz = function_exists('mcr_store_tz') ? mcr_store_tz() : wp_timezone();
            $p->set_date_on_sale_to((new DateTimeImmutable($to . ' 23:59:59', $tz))->getTimestamp());
        }
    }
    if (array_key_exists('manage', $in)) {
        $p->set_manage_stock(!empty($in['manage']));
    }
    if ($p->managing_stock() && array_key_exists('qty', $in) && $in['qty'] !== '' && $in['qty'] !== null) {
        $p->set_stock_quantity(max(0, (int) $in['qty']));
    } elseif (!$p->managing_stock() && !empty($in['stock'])) {
        $p->set_stock_status($in['stock'] === 'outofstock' ? 'outofstock' : 'instock');
    }
    return '';
}

function mcr_app_err(string $msg, int $code = 400): WP_Error
{
    return new WP_Error('mcr_app', $msg, ['status' => $code]);
}

/** Sales of the last N days (real orders), cached for a few minutes. */
function mcr_app_sales(): array
{
    $cached = get_transient('mcr_app_sales');
    if (is_array($cached)) {
        return $cached;
    }
    $tz = wp_timezone();
    $today = (new DateTimeImmutable('today', $tz))->getTimestamp();
    $week = (new DateTimeImmutable('today -6 days', $tz))->getTimestamp();
    $orders = wc_get_orders(['limit' => -1, 'type' => 'shop_order', 'status' => array_merge(MCR_APP_OPEN, ['completed']), 'date_created' => '>=' . $week]);
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = new DateTimeImmutable("today -{$i} days", $tz);
        $days[$d->format('Y-m-d')] = ['label' => wp_date('D', $d->getTimestamp()), 'total' => 0.0, 'count' => 0];
    }
    $top = [];
    $sum = ['today' => [0.0, 0], 'week' => [0.0, 0]];
    foreach ($orders as $o) {
        $ts = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
        $k = wp_date('Y-m-d', $ts);
        $t = (float) $o->get_total();
        if (isset($days[$k])) {
            $days[$k]['total'] += $t;
            $days[$k]['count']++;
        }
        $sum['week'][0] += $t;
        $sum['week'][1]++;
        if ($ts >= $today) {
            $sum['today'][0] += $t;
            $sum['today'][1]++;
        }
        foreach ($o->get_items() as $it) {
            $pid = $it->get_product_id();
            $top[$pid] = ($top[$pid] ?? 0) + $it->get_quantity();
        }
    }
    arsort($top);
    $best = [];
    foreach (array_slice($top, 0, 5, true) as $pid => $q) {
        $p = wc_get_product($pid);
        if ($p) {
            $best[] = ['id' => $pid, 'name' => $p->get_name(), 'image' => mcr_app_thumb($p), 'qty' => $q];
        }
    }
    $out = [
        'today' => ['total' => mcr_app_money($sum['today'][0]), 'count' => $sum['today'][1]],
        'week' => ['total' => mcr_app_money($sum['week'][0]), 'count' => $sum['week'][1], 'avg' => $sum['week'][1] ? mcr_app_money($sum['week'][0] / $sum['week'][1]) : '–'],
        'days' => array_values(array_map(fn($d) => ['label' => $d['label'], 'total' => round($d['total'], 2), 'count' => $d['count']], $days)),
        'best' => $best,
    ];
    set_transient('mcr_app_sales', $out, 5 * MINUTE_IN_SECONDS);
    return $out;
}

function mcr_app_promos_data(): array
{
    $tz = function_exists('mcr_store_tz') ? mcr_store_tz() : wp_timezone();
    $out = ['promos' => [], 'flash' => [], 'week' => null];
    if (function_exists('mcr_promos')) {
        foreach (mcr_promos() as $id => $c) {
            if (!empty($c['deleted'])) {
                continue;
            }
            $names = [];
            foreach (array_slice((array) ($c['products'] ?? []), 0, 40) as $pid) {
                $pp = wc_get_product($pid);
                if ($pp) {
                    $names[] = ['id' => (int) $pid, 'name' => $pp->get_name(), 'image' => mcr_app_thumb($pp)];
                }
            }
            $job = $c['job'] ?? null;
            $out['promos'][] = [
                'id' => $id, 'type' => $c['type'] ?? 'percent', 'title_fr' => $c['title_fr'] ?? '', 'title_en' => $c['title_en'] ?? '',
                'text_fr' => $c['text_fr'] ?? '', 'text_en' => $c['text_en'] ?? '',
                'percent' => (int) ($c['percent'] ?? 10), 'qty' => (int) ($c['qty'] ?? 2), 'price' => (float) ($c['price'] ?? 0),
                'cats' => array_map('intval', (array) ($c['cats'] ?? [])), 'products' => $names,
                'start' => $c['start'] ?? '', 'end' => $c['end'] ?? '', 'banner' => !empty($c['banner']), 'skip_existing' => !empty($c['skip_existing']),
                'state' => mcr_promo_state($c), 'label' => mcr_promo_label($c, false), 'applied' => (int) ($c['applied'] ?? 0),
                'working' => $job && empty($job['finished']) ? ['done' => (int) $job['done'], 'total' => (int) $job['total']] : null,
            ];
        }
        usort($out['promos'], fn($a, $b) => (['active' => 0, 'scheduled' => 1, 'ended' => 2][$a['state']] <=> ['active' => 0, 'scheduled' => 1, 'ended' => 2][$b['state']]) ?: strcmp($b['start'], $a['start']));
        $w = mcr_promo_of_week();
        $out['week'] = $w ? ['id' => $w->get_id(), 'name' => $w->get_name(), 'image' => mcr_app_thumb($w), 'price' => html_entity_decode(wp_strip_all_tags($w->get_price_html()))] : null;
    }
    if (function_exists('mcr_flash_all')) {
        foreach (mcr_flash_all() as $id => $d) {
            $p = wc_get_product((int) $d['product']);
            [$s, $e] = mcr_flash_window($d);
            $reg = $p ? (float) $p->get_regular_price('edit') : 0;
            $out['flash'][] = [
                'id' => $id, 'product' => $p ? ['id' => $p->get_id(), 'name' => $p->get_name(), 'image' => mcr_app_thumb($p), 'regular' => $reg] : null,
                'mode' => $d['mode'], 'value' => (float) $d['value'], 'qty' => (int) $d['qty'], 'sold' => (int) ($d['sold'] ?? 0),
                'per_order' => (int) ($d['per_order'] ?? 0), 'hours' => (float) $d['hours'],
                'date' => wp_date('Y-m-d', $s, $tz), 'time' => wp_date('H:i', $s, $tz), 'start' => $s, 'end' => $e,
                'state' => mcr_flash_state($d),
                'flash_price' => $reg > 0 ? mcr_app_money($d['mode'] === 'price' ? min((float) $d['value'], $reg) : $reg * (1 - min(95, max(1, (float) $d['value'])) / 100)) : '',
            ];
        }
        $rank = ['active' => 0, 'soldout' => 1, 'scheduled' => 2, 'ended' => 3];
        usort($out['flash'], fn($a, $b) => ($rank[$a['state']] <=> $rank[$b['state']]) ?: ($a['state'] === 'ended' ? $b['start'] <=> $a['start'] : $a['start'] <=> $b['start']));
        $out['flash'] = array_slice($out['flash'], 0, 40);
    }
    return $out;
}

add_action('rest_api_init', function () {
    $products = fn() => current_user_can('edit_products');
    $shop = fn() => current_user_can('manage_woocommerce');
    $route = fn(string $path, string $method, callable $cb, callable $perm) => register_rest_route('mcr/v1', '/app/' . $path, ['methods' => $method, 'permission_callback' => $perm, 'callback' => $cb]);

    // ----- Shop dashboard and quick settings
    $route('shop', 'GET', function () {
        $low = function_exists('mcr_flash_settings') ? (int) mcr_flash_settings()['low_stock'] : 5;
        $out = count(wc_get_products(['status' => 'publish', 'stock_status' => 'outofstock', 'limit' => -1, 'return' => 'ids']));
        global $wpdb;
        $lowc = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock' JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_manage_stock' AND s.meta_value = 'yes'
             WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND CAST(m.meta_value AS SIGNED) BETWEEN 1 AND %d", $low));
        $a = mcr_setting('announcement');
        return [
            'sales' => mcr_app_sales(),
            'stock' => ['out' => $out, 'low' => $lowc, 'low_at' => $low],
            'announcement' => ['enabled' => !empty($a['enabled']), 'text_fr' => (string) $a['text_fr'], 'text_en' => (string) $a['text_en']],
            'delivery' => !empty(mcr_setting('delivery', 'enabled')),
            'pickup' => !empty(mcr_setting('pickup', 'enabled')),
            'site' => home_url('/'),
            'admin' => admin_url(),
        ];
    }, $shop);
    $route('shop', 'POST', function (WP_REST_Request $r) {
        if ($r->has_param('announcement')) {
            $a = (array) $r->get_param('announcement');
            $cur = (array) get_option('mcr_announcement', []);
            update_option('mcr_announcement', array_merge($cur, [
                'enabled' => !empty($a['enabled']),
                'text_fr' => sanitize_text_field((string) ($a['text_fr'] ?? '')),
                'text_en' => sanitize_text_field((string) ($a['text_en'] ?? '')),
            ]));
        }
        foreach (['delivery', 'pickup'] as $g) {
            if ($r->has_param($g)) {
                $cur = (array) get_option("mcr_{$g}", []);
                $cur['enabled'] = (bool) $r->get_param($g);
                update_option("mcr_{$g}", $cur);
            }
        }
        update_option('mcrt_cache_ver', (string) time(), true);
        return ['ok' => true];
    }, $shop);

    // ----- Products
    $route('products', 'GET', function (WP_REST_Request $r) {
        $q = trim(sanitize_text_field((string) $r->get_param('q')));
        $filter = sanitize_key((string) $r->get_param('filter')) ?: 'all';
        $page = max(1, (int) $r->get_param('page'));
        $args = ['limit' => 30, 'page' => $page, 'paginate' => true, 'status' => ['publish', 'draft', 'private', 'pending'], 'orderby' => 'title', 'order' => 'ASC', 'type' => ['simple', 'variable']];
        if ($filter === 'out') {
            $args['stock_status'] = 'outofstock';
            $args['status'] = ['publish'];
        } elseif ($filter === 'hidden') {
            $args['status'] = ['draft', 'private', 'pending'];
        } elseif ($filter === 'sale') {
            $args['include'] = array_values(array_unique(array_map(fn($id) => wp_get_post_parent_id($id) ?: $id, wc_get_product_ids_on_sale()))) ?: [0];
        } elseif ($filter === 'low') {
            $low = function_exists('mcr_flash_settings') ? (int) mcr_flash_settings()['low_stock'] : 5;
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock' JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_manage_stock' AND s.meta_value = 'yes'
                 WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND CAST(m.meta_value AS SIGNED) BETWEEN 1 AND %d", $low));
            $args['include'] = array_map('intval', $ids) ?: [0];
        } elseif ($filter === 'new') {
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
        }
        if ($q !== '') {
            $ds = WC_Data_Store::load('product');
            $found = $ds->search_products($q, '', true, true, 400);
            $found = array_values(array_unique(array_map(fn($id) => wp_get_post_parent_id((int) $id) ?: (int) $id, array_filter($found))));
            $args['include'] = isset($args['include']) ? (array_values(array_intersect($args['include'], $found)) ?: [0]) : ($found ?: [0]);
        }
        $res = wc_get_products($args);
        return ['items' => array_map('mcr_app_product_row', $res->products), 'total' => (int) $res->total, 'pages' => (int) $res->max_num_pages, 'page' => $page];
    }, $products);

    $route('product/(?P<id>\d+)', 'GET', function (WP_REST_Request $r) {
        $p = wc_get_product((int) $r['id']);
        return $p ? mcr_app_product_full($p) : mcr_app_err('Produit introuvable', 404);
    }, $products);

    $save = function (WC_Product $p, array $in) {
        if (isset($in['name'])) {
            $name = sanitize_text_field((string) $in['name']);
            if ($name === '') {
                return mcr_app_err('Le nom ne peut pas être vide.');
            }
            $p->set_name($name);
        }
        if (isset($in['status'])) {
            $p->set_status($in['status'] === 'publish' ? 'publish' : 'draft');
        }
        if (isset($in['cats'])) {
            $p->set_category_ids(array_values(array_filter(array_map('absint', (array) $in['cats']))));
        }
        if (isset($in['image_id'])) {
            $img = absint($in['image_id']);
            if ($img && get_post_type($img) !== 'attachment') {
                return mcr_app_err('Photo introuvable.');
            }
            $p->set_image_id($img);
        }
        if (!$p->is_type('variable')) {
            $err = mcr_app_apply_prices($p, $in);
            if ($err) {
                return mcr_app_err($err);
            }
        }
        $id = $p->save();
        if (isset($in['name_en'])) {
            update_post_meta($id, '_mcr_title_en', sanitize_text_field((string) $in['name_en']));
        }
        if (!empty($in['image_id'])) {
            wp_update_post(['ID' => absint($in['image_id']), 'post_parent' => $id]);
        }
        wc_delete_product_transients($id);
        return mcr_app_product_full(wc_get_product($id));
    };
    $route('product/(?P<id>\d+)', 'POST', function (WP_REST_Request $r) use ($save) {
        $p = wc_get_product((int) $r['id']);
        if (!$p || $p->is_type('variation')) {
            return mcr_app_err('Produit introuvable', 404);
        }
        return $save($p, (array) $r->get_json_params());
    }, $products);
    $route('product', 'POST', function (WP_REST_Request $r) use ($save) {
        $in = (array) $r->get_json_params();
        if (empty($in['regular'])) {
            return mcr_app_err('Indiquez le prix.');
        }
        $p = new WC_Product_Simple();
        $in += ['status' => 'publish', 'name' => ''];
        if (!isset($in['manage']) && !isset($in['stock'])) {
            $in['stock'] = 'instock';
        }
        return $save($p, $in);
    }, $products);
    $route('variation/(?P<id>\d+)', 'POST', function (WP_REST_Request $r) {
        $v = wc_get_product((int) $r['id']);
        if (!$v || !$v->is_type('variation')) {
            return mcr_app_err('Variante introuvable', 404);
        }
        $in = (array) $r->get_json_params();
        $err = mcr_app_apply_prices($v, $in);
        if ($err) {
            return mcr_app_err($err);
        }
        if (isset($in['enabled'])) {
            $v->set_status($in['enabled'] ? 'publish' : 'private');
        }
        $v->save();
        WC_Product_Variable::sync($v->get_parent_id());
        wc_delete_product_transients($v->get_parent_id());
        return mcr_app_product_full(wc_get_product($v->get_parent_id()));
    }, $products);

    $route('media', 'POST', function (WP_REST_Request $r) {
        if (!current_user_can('upload_files')) {
            return mcr_app_err('Vous ne pouvez pas ajouter de photos.', 403);
        }
        $files = $r->get_file_params();
        if (empty($files['file']) || !is_uploaded_file($files['file']['tmp_name'])) {
            return mcr_app_err('Aucune photo reçue.');
        }
        $type = wp_check_filetype_and_ext($files['file']['tmp_name'], $files['file']['name']);
        if (!in_array($type['type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return mcr_app_err('Format non accepté (JPEG, PNG ou WebP).');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $_FILES['file'] = $files['file'];
        $title = sanitize_text_field((string) $r->get_param('title'));
        $id = media_handle_upload('file', 0, ['post_title' => $title ?: 'Photo produit']);
        if (is_wp_error($id)) {
            return mcr_app_err($id->get_error_message());
        }
        if ($title) {
            update_post_meta($id, '_wp_attachment_image_alt', $title);
        }
        return ['id' => $id, 'url' => wp_get_attachment_image_url($id, 'medium_large')];
    }, $products);

    $route('cats', 'GET', function () {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        $out = [];
        foreach (is_array($terms) ? $terms : [] as $t) {
            if ($t->slug === 'uncategorized' || $t->slug === 'non-classe') {
                continue;
            }
            $out[] = ['id' => $t->term_id, 'name' => html_entity_decode($t->name), 'parent' => $t->parent, 'count' => $t->count];
        }
        return $out;
    }, $products);

    // ----- Promotions and flash sales
    $route('promos', 'GET', fn() => mcr_app_promos_data(), $shop);
    $route('promo', 'POST', function (WP_REST_Request $r) {
        if (!function_exists('mcr_promo_upsert')) {
            return mcr_app_err('Module Promotions non inclus dans votre forfait.', 403);
        }
        $res = mcr_promo_upsert((array) $r->get_json_params());
        return is_wp_error($res) ? mcr_app_err($res->get_error_message()) : mcr_app_promos_data();
    }, $shop);
    $route('promo/(?P<id>[a-z0-9]+)/delete', 'POST', function (WP_REST_Request $r) {
        if (!function_exists('mcr_promo_remove')) {
            return mcr_app_err('Module Promotions non inclus dans votre forfait.', 403);
        }
        mcr_promo_remove((string) $r['id']);
        return mcr_app_promos_data();
    }, $shop);
    $route('promo-week', 'POST', function (WP_REST_Request $r) {
        update_option('mcr_promo_week', absint($r->get_param('product')));
        update_option('mcrt_cache_ver', (string) time(), true);
        return mcr_app_promos_data();
    }, $shop);
    $route('flash', 'POST', function (WP_REST_Request $r) {
        if (!function_exists('mcr_flash_upsert')) {
            return mcr_app_err('Module Ventes éclair non inclus dans votre forfait.', 403);
        }
        $res = mcr_flash_upsert((array) $r->get_json_params());
        return is_wp_error($res) ? mcr_app_err($res->get_error_message()) : mcr_app_promos_data();
    }, $shop);
    $route('flash/(?P<id>[a-z0-9]+)/(?P<do>stop|delete|tomorrow)', 'POST', function (WP_REST_Request $r) {
        if (!function_exists('mcr_flash_do')) {
            return mcr_app_err('Module Ventes éclair non inclus dans votre forfait.', 403);
        }
        mcr_flash_do((string) $r['do'], (string) $r['id']);
        return mcr_app_promos_data();
    }, $shop);
});

// New orders change the sales figures.
add_action('woocommerce_new_order', fn() => delete_transient('mcr_app_sales'));
add_action('woocommerce_order_status_changed', fn() => delete_transient('mcr_app_sales'));
