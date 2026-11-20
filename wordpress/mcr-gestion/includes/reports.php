<?php
/**
 * Reports from real orders: sales by department (Épicerie, Beauté et
 * cheveux, Soins corporels), delivery vs pickup, best sellers, and the
 * "today" figures on the dashboard. Amounts are item totals after
 * discounts and before taxes; delivery fees are shown apart.
 * Counted orders: paid ones (En cours, En préparation, Prête, En livraison, Terminée).
 */
defined('ABSPATH') || exit;

function mcr_paid_statuses(): array
{
    return ['processing', 'mcr-preparing', 'mcr-ready', 'mcr-out', 'completed'];
}

/** Period from the request: [from, to (both Y-m-d), label]. */
function mcr_report_period(): array
{
    $tz = wp_timezone();
    $today = new DateTimeImmutable('today', $tz);
    $p = sanitize_key($_GET['period'] ?? '30d');
    switch ($p) {
        case 'today':
            return [$today, $today, 'Aujourd’hui', $p];
        case '7d':
            return [$today->modify('-6 days'), $today, '7 derniers jours', $p];
        case 'month':
            return [$today->modify('first day of this month'), $today, 'Ce mois-ci', $p];
        case 'lastmonth':
            return [$today->modify('first day of last month'), $today->modify('last day of last month'), 'Le mois dernier', $p];
        case 'custom':
            $f = DateTimeImmutable::createFromFormat('!Y-m-d', sanitize_text_field($_GET['from'] ?? ''), $tz) ?: $today->modify('-29 days');
            $t = DateTimeImmutable::createFromFormat('!Y-m-d', sanitize_text_field($_GET['to'] ?? ''), $tz) ?: $today;
            if ($t < $f) {
                [$f, $t] = [$t, $f];
            }
            return [$f, $t, 'Du ' . date_i18n('j F Y', $f->getTimestamp()) . ' au ' . date_i18n('j F Y', $t->getTimestamp()), $p];
        default:
            return [$today->modify('-29 days'), $today, '30 derniers jours', '30d'];
    }
}

/** Figures for a period (cached 10 minutes; cleared when an order changes). */
function mcr_report_data(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $key = 'mcr_rep_' . md5($from->format('Ymd') . $to->format('Ymd') . get_option('mcr_report_gen', 0));
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $depts = ['' => ['name' => 'Sans département', 'sales' => 0.0, 'qty' => 0, 'orders' => []]];
    foreach (get_terms(['taxonomy' => 'mcr_department', 'hide_empty' => false]) as $t) {
        $depts[$t->slug] = ['name' => $t->name, 'sales' => 0.0, 'qty' => 0, 'orders' => []];
    }
    $r = [
        'orders' => 0, 'items_total' => 0.0, 'shipping' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'refunded' => 0.0,
        'delivery' => 0, 'pickup' => 0, 'whatsapp' => 0, 'products' => [], 'days' => [],
    ];
    $dept_cache = [];
    $page = 1;
    do {
        $orders = wc_get_orders([
            'status' => mcr_paid_statuses(),
            'type' => 'shop_order',
            'date_created' => $from->format('Y-m-d') . '...' . $to->format('Y-m-d') . ' 23:59:59',
            'limit' => 200,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        foreach ($orders as $order) {
            $r['orders']++;
            $r['shipping'] += (float) $order->get_shipping_total();
            $r['tax'] += (float) $order->get_total_tax();
            $r['total'] += (float) $order->get_total();
            $r['refunded'] += (float) $order->get_total_refunded();
            $mode = function_exists('mcr_order_fulfilment') ? mcr_order_fulfilment($order) : '';
            if ($mode === 'pickup') {
                $r['pickup']++;
            } elseif ($mode === 'delivery') {
                $r['delivery']++;
            }
            $day = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d') : '';
            $r['days'][$day] = ($r['days'][$day] ?? 0) + (float) $order->get_total() - (float) $order->get_total_refunded();
            foreach ($order->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $pid = (int) $item->get_product_id();
                $line = (float) $item->get_total() - (float) $order->get_total_refunded_for_item($item->get_id());
                $qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item->get_id());
                if (!isset($dept_cache[$pid])) {
                    $dept_cache[$pid] = (string) (mcr_product_department($pid) ?? '');
                }
                $d = isset($depts[$dept_cache[$pid]]) ? $dept_cache[$pid] : '';
                $depts[$d]['sales'] += $line;
                $depts[$d]['qty'] += $qty;
                $depts[$d]['orders'][$order->get_id()] = 1;
                $r['items_total'] += $line;
                if (!isset($r['products'][$pid])) {
                    $r['products'][$pid] = ['name' => $item->get_name(), 'qty' => 0, 'sales' => 0.0, 'dept' => $d];
                }
                $r['products'][$pid]['qty'] += $qty;
                $r['products'][$pid]['sales'] += $line;
            }
        }
        $page++;
    } while (count($orders) === 200 && $page < 50);

    foreach ($depts as &$d) {
        $d['orders'] = count($d['orders']);
    }
    unset($d);
    uasort($r['products'], fn($a, $b) => $b['sales'] <=> $a['sales']);
    $r['products'] = array_slice($r['products'], 0, 15, true);
    $r['depts'] = $depts;
    set_transient($key, $r, 10 * MINUTE_IN_SECONDS);
    return $r;
}

// Any order change makes the cached figures stale.
foreach (['woocommerce_new_order', 'woocommerce_order_status_changed', 'woocommerce_order_refunded'] as $hook) {
    add_action($hook, function () {
        update_option('mcr_report_gen', time(), false);
    });
}

function mcr_money_admin(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' $';
}

/** Products that track stock and are at or under their low-stock level. */
function mcr_low_stock(int $limit = 50): array
{
    global $wpdb;
    $default = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_parent, CAST(s.meta_value AS SIGNED) AS stock, l.meta_value AS low
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes'
         JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock'
         LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_low_stock_amount'
         WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish'
         HAVING stock <= COALESCE(NULLIF(low, ''), %d)
         ORDER BY stock ASC LIMIT %d",
        $default,
        $limit
    ));
    return $rows ?: [];
}

// ---------------------------------------------------------------- Admin page

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Rapports', 'Rapports', 'view_woocommerce_reports', 'mcr-reports', 'mcr_render_reports_page');
}, 20);

function mcr_render_reports_page(): void
{
    [$from, $to, $label, $period] = mcr_report_period();
    $r = mcr_report_data($from, $to);
    $periods = ['today' => 'Aujourd’hui', '7d' => '7 jours', '30d' => '30 jours', 'month' => 'Ce mois-ci', 'lastmonth' => 'Mois dernier'];
    echo '<div class="wrap mcr-rep"><h1>Rapports</h1>';
    echo '<style>
.mcr-rep .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:16px 0}
.mcr-rep .card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:0;max-width:none}
.mcr-rep .card b{display:block;font-size:22px;margin-top:4px}
.mcr-rep .card small{color:#646970}
.mcr-rep .bar{height:10px;background:#f0f0f1;border-radius:5px;overflow:hidden;min-width:120px}
.mcr-rep .bar i{display:block;height:100%;background:#4f3be6}
.mcr-rep .cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px}
.mcr-rep .periods a{margin-right:6px}
</style>';
    echo '<p class="periods">';
    foreach ($periods as $k => $l) {
        printf('<a class="button%s" href="%s">%s</a>', $k === $period ? ' button-primary' : '', esc_url(add_query_arg(['page' => 'mcr-reports', 'period' => $k], admin_url('admin.php'))), esc_html($l));
    }
    printf(
        '<form method="get" style="display:inline-block;margin-left:8px"><input type="hidden" name="page" value="mcr-reports"><input type="hidden" name="period" value="custom">Du <input type="date" name="from" value="%s"> au <input type="date" name="to" value="%s"> <button class="button">Voir</button></form></p>',
        esc_attr($from->format('Y-m-d')),
        esc_attr($to->format('Y-m-d'))
    );
    echo '<h2>' . esc_html($label) . '</h2>';
    $avg = $r['orders'] ? $r['total'] / $r['orders'] : 0;
    $cards = [
        ['Ventes (produits)', mcr_money_admin($r['items_total']), 'après rabais et remboursements, avant taxes'],
        ['Commandes payées', (string) $r['orders'], $r['delivery'] . ' livraisons · ' . $r['pickup'] . ' retraits'],
        ['Panier moyen', mcr_money_admin($avg), 'total payé par commande'],
        ['Frais de livraison', mcr_money_admin($r['shipping']), 'facturés aux clients'],
        ['Taxes perçues', mcr_money_admin($r['tax']), ''],
        ['Remboursé', mcr_money_admin($r['refunded']), ''],
    ];
    echo '<div class="cards">';
    foreach ($cards as [$t, $v, $h]) {
        printf('<div class="card"><small>%s</small><b>%s</b><small>%s</small></div>', esc_html($t), esc_html($v), esc_html($h));
    }
    echo '</div><div class="cols"><div>';

    echo '<h2>Ventes par département</h2><table class="widefat striped"><thead><tr><th>Département</th><th>Ventes</th><th>Part</th><th>Articles</th><th>Commandes</th></tr></thead><tbody>';
    $max = max(0.01, $r['items_total']);
    foreach ($r['depts'] as $slug => $d) {
        if ($slug === '' && !$d['qty']) {
            continue;
        }
        $pct = $d['sales'] / $max * 100;
        printf(
            '<tr><td>%s</td><td>%s</td><td><div class="bar"><i style="width:%.1f%%"></i></div> %s %%</td><td>%d</td><td>%d</td></tr>',
            esc_html($d['name']),
            esc_html(mcr_money_admin($d['sales'])),
            max(0, min(100, $pct)),
            esc_html(number_format($pct, 1, ',', ' ')),
            $d['qty'],
            $d['orders']
        );
    }
    echo '</tbody></table>';
    if (!empty($r['depts']['']['qty'])) {
        echo '<p class="description">Des produits vendus n’ont pas de département : ajoutez-le dans la fiche du produit (encadré « Départements »).</p>';
    }

    echo '<h2>Ventes par jour</h2><table class="widefat striped"><thead><tr><th>Jour</th><th>Total payé (net)</th><th></th></tr></thead><tbody>';
    $dmax = $r['days'] ? max(0.01, max($r['days'])) : 1;
    if (!$r['days']) {
        echo '<tr><td colspan="3">Aucune commande payée dans cette période.</td></tr>';
    }
    foreach (array_reverse($r['days'], true) as $day => $v) {
        printf('<tr><td>%s</td><td>%s</td><td><div class="bar"><i style="width:%.1f%%"></i></div></td></tr>', esc_html(date_i18n('D j M', strtotime($day))), esc_html(mcr_money_admin($v)), $v / $dmax * 100);
    }
    echo '</tbody></table></div><div>';

    echo '<h2>Meilleurs vendeurs</h2><table class="widefat striped"><thead><tr><th>Produit</th><th>Département</th><th>Qté</th><th>Ventes</th></tr></thead><tbody>';
    if (!$r['products']) {
        echo '<tr><td colspan="4">Aucune vente dans cette période.</td></tr>';
    }
    foreach ($r['products'] as $pid => $p) {
        printf(
            '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d</td><td>%s</td></tr>',
            esc_url(get_edit_post_link($pid) ?: '#'),
            esc_html($p['name']),
            esc_html($r['depts'][$p['dept']]['name'] ?? ''),
            $p['qty'],
            esc_html(mcr_money_admin($p['sales']))
        );
    }
    echo '</tbody></table>';

    $low = mcr_low_stock(30);
    echo '<h2>Stock bas</h2><table class="widefat striped"><thead><tr><th>Produit</th><th>Stock</th></tr></thead><tbody>';
    if (!$low) {
        echo '<tr><td colspan="2">Aucun produit sous le seuil de stock bas (seuls les produits avec « Gérer le stock » sont suivis).</td></tr>';
    }
    foreach ($low as $row) {
        $edit = get_edit_post_link($row->post_parent ?: $row->ID);
        printf('<tr><td><a href="%s">%s</a></td><td><strong style="color:%s">%d</strong></td></tr>', esc_url($edit ?: '#'), esc_html($row->post_title), $row->stock <= 0 ? '#b32d2e' : '#996800', (int) $row->stock);
    }
    echo '</tbody></table></div></div></div>';
}

// ---------------------------------------------------------------- Dashboard

add_action('wp_dashboard_setup', function () {
    if (!current_user_can('view_woocommerce_reports')) {
        return;
    }
    wp_add_dashboard_widget('mcr_today_widget', 'Marché Chemin-du-Roy : aujourd’hui', function () {
        $today = new DateTimeImmutable('today', wp_timezone());
        $r = mcr_report_data($today, $today);
        $count = fn(string $st) => (int) (wc_orders_count($st) ?? 0);
        $orders_url = fn(string $st) => admin_url((class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'admin.php?page=wc-orders&status=wc-' : 'edit.php?post_type=shop_order&post_status=wc-') . $st);
        $rows = [
            ['Ventes du jour (produits, avant taxes)', mcr_money_admin($r['items_total']), admin_url('admin.php?page=mcr-reports&period=today')],
            ['Commandes payées aujourd’hui', $r['orders'] . ' (' . $r['delivery'] . ' livraisons, ' . $r['pickup'] . ' retraits)', admin_url('admin.php?page=mcr-reports&period=today')],
            ['À préparer (en cours)', (string) $count('processing'), $orders_url('processing')],
            ['En préparation', (string) $count('mcr-preparing'), $orders_url('mcr-preparing')],
            ['Prêtes au retrait', (string) $count('mcr-ready'), $orders_url('mcr-ready')],
            ['En livraison', (string) $count('mcr-out'), $orders_url('mcr-out')],
            ['Paiement en attente', (string) $count('pending'), $orders_url('pending')],
            ['Lots qui expirent sous 14 jours', (string) count(mcr_expiring_lots(14)), admin_url('admin.php?page=mcr-expiry')],
            ['Produits en stock bas', (string) count(mcr_low_stock(200)), admin_url('admin.php?page=mcr-reports')],
        ];
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as [$l, $v, $u]) {
            printf('<tr><td><a href="%s">%s</a></td><td style="text-align:right"><strong>%s</strong></td></tr>', esc_url($u), esc_html($l), esc_html($v));
        }
        echo '</tbody></table><p><a class="button" href="' . esc_url(admin_url('admin.php?page=mcr-reports')) . '">Tous les rapports</a></p>';
    });
}, 5);
