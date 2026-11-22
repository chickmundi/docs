<?php
/** MCR Gestion > Relances: results, forgotten carts (email + WhatsApp by hand), settings, previews. */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Relances clients', 'Relances', 'manage_woocommerce', 'mcr-reminders', 'mcr_render_reminders_page');
}, 23);

add_action('admin_post_mcr_rem_settings', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_rem_settings');
    update_option('mcr_rem_settings', [
        'cart' => !empty($_POST['cart']),
        'cart_delay' => max(15, min(1440, (int) ($_POST['cart_delay'] ?? 60))),
        'restock' => !empty($_POST['restock']),
        'restock_default_days' => max(7, min(120, (int) ($_POST['restock_default_days'] ?? 30))),
    ]);
    wp_safe_redirect(admin_url('admin.php?page=mcr-reminders&saved=1'));
    exit;
});

add_action('admin_post_mcr_rem_run', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_rem_run');
    $a = mcr_rem_run_carts();
    $b = mcr_rem_run_restock();
    wp_safe_redirect(admin_url('admin.php?page=mcr-reminders&ran=' . $a . '-' . $b));
    exit;
});

// Preview of an email in the browser (no email is sent).
add_action('admin_post_mcr_rem_preview', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_rem_preview');
    global $wpdb;
    $kind = sanitize_key($_GET['kind'] ?? 'cart');
    $demo = (object) ['email' => 'client@example.com', 'name' => 'Aminata', 'phone' => '', 'lang' => sanitize_key($_GET['lang'] ?? 'fr') === 'en' ? 'en' : 'fr', 'token' => 'apercu', 'consent_at' => mcr_rem_now(), 'unsub_at' => null];
    $popular = wc_get_products(['limit' => 3, 'orderby' => 'popularity', 'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple']);
    if ($kind === 'restock') {
        $mail = mcr_rem_restock_email($demo, $popular);
    } else {
        $cart = (object) ['token' => 'apercu', 'lang' => $demo->lang, 'items' => wp_json_encode(array_map(fn($p) => ['p' => $p->get_id(), 'v' => 0, 'q' => 1, 'a' => []], $popular))];
        $mail = mcr_rem_cart_email($cart, $demo);
    }
    echo '<div style="background:#fff8e1;padding:8px 14px;font:14px sans-serif;border-bottom:1px solid #e6b74f"><strong>Aperçu</strong> · Objet : ' . esc_html($mail[0] ?? '') . ' · Exemple avec des produits populaires, aucun courriel envoyé.</div>';
    echo mcr_rem_styled((string) ($mail[1] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput -- WooCommerce email HTML
    exit;
});

function mcr_render_reminders_page(): void
{
    global $wpdb;
    $set = mcr_rem_settings();
    $ct = mcr_rem_table('contacts');
    $cart = mcr_rem_table('carts');
    $consented = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ct WHERE consent_at IS NOT NULL AND unsub_at IS NULL");
    $unsub = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ct WHERE unsub_at IS NOT NULL");
    $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $reminded = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $cart WHERE reminded_at > %s", $since));
    $recovered = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, COALESCE(SUM(recovered_total),0) s FROM $cart WHERE reminded_at > %s AND recovered_order IS NOT NULL", $since));
    $rows = $wpdb->get_results("SELECT * FROM $cart ORDER BY updated_at DESC LIMIT 25");
    echo '<div class="wrap"><h1>Relances clients</h1>';
    if (!empty($_GET['saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';
    }
    if (isset($_GET['ran'])) {
        [$a, $b] = array_map('intval', explode('-', sanitize_text_field(wp_unslash($_GET['ran'])) . '-0'));
        echo '<div class="notice notice-success is-dismissible"><p>Relances envoyées maintenant : ' . (int) $a . ' panier(s), ' . (int) $b . ' rappel(s) de réapprovisionnement.</p></div>';
    }
    echo '<p style="max-width:820px">Des rappels envoyés <strong>uniquement aux clients qui ont coché la case</strong> à la caisse (loi 25 et loi anti-pourriel) : un rappel si la commande n’est pas terminée, et un avis quand il est temps de racheter un produit qu’ils achètent régulièrement. Chaque courriel contient un lien de désabonnement.</p>';
    $card = fn($label, $value, $hint = '') => '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;min-width:170px"><div style="color:#646970">' . esc_html($label) . '</div><div style="font-size:24px;font-weight:600;margin-top:4px">' . $value . '</div>' . ($hint ? '<div style="color:#646970;font-size:12px">' . esc_html($hint) . '</div>' : '') . '</div>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">'
        . $card('Clients qui acceptent', (string) $consented, $unsub ? $unsub . ' désabonné(s)' : '')
        . $card('Paniers relancés (30 j)', (string) $reminded)
        . $card('Commandes récupérées (30 j)', (string) (int) $recovered->n, $reminded ? round($recovered->n / max(1, $reminded) * 100) . ' % des relances' : '')
        . $card('Ventes récupérées (30 j)', wp_strip_all_tags(wc_price((float) $recovered->s)))
        . '</div>';

    echo '<h2>Paniers récents</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Client</th><th>Panier</th><th>Dernière activité</th><th>État</th><th>Relancer à la main</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="5">Aucun panier enregistré pour le moment. Ils apparaissent quand un client coche la case à la caisse.</td></tr>';
    }
    foreach ($rows as $r) {
        $c = mcr_rem_contact($r->email);
        $items = (array) json_decode((string) $r->items, true);
        $names = [];
        foreach (array_slice($items, 0, 3) as $it) {
            $p = wc_get_product($it['v'] ?: $it['p']);
            if ($p) {
                $names[] = (int) $it['q'] . ' × ' . $p->get_name();
            }
        }
        $state = $r->recovered_order ? '<a href="' . esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . (int) $r->recovered_order)) . '">Commande n° ' . (int) $r->recovered_order . '</a> ✓' : ($r->reminded_at ? 'Relancé par courriel' : 'En attente');
        $wa = '';
        $digits = $c ? preg_replace('/\D/', '', (string) $c->phone) : '';
        if (!$r->recovered_order && $c && mcr_rem_can_email($c) && strlen($digits) >= 10) {
            $en = $r->lang === 'en';
            $link = add_query_arg('mcr_cart', $r->token, function_exists('mcr_prefix_url') ? mcr_prefix_url(home_url('/'), $en ? 'en' : 'fr') : home_url('/'));
            $first = trim(explode(' ', (string) $c->name)[0]);
            $msg = $en
                ? "Hello $first! It’s " . mcr_setting('store', 'name') . ". Your cart is still waiting for you, here is the link to finish your order: $link"
                : "Bonjour $first ! Ici " . mcr_setting('store', 'name') . ". Votre panier vous attend toujours, voici le lien pour terminer votre commande : $link";
            $wa = '<a class="button" target="_blank" rel="noopener" href="' . esc_attr('https://wa.me/' . (strlen($digits) === 10 ? '1' . $digits : $digits) . '?text=' . rawurlencode($msg)) . '">WhatsApp</a>';
        }
        printf(
            '<tr><td><strong>%s</strong><br><small>%s</small></td><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($c ? ($c->name ?: '—') : '—'),
            esc_html($r->email),
            esc_html(implode(', ', $names) . (count($items) > 3 ? '…' : '')),
            wp_kses_post(wc_price((float) $r->total)),
            esc_html(wp_date('j M, H:i', strtotime($r->updated_at . ' UTC'), function_exists('mcr_store_tz') ? mcr_store_tz() : null)),
            wp_kses_post($state),
            $wa // phpcs:ignore WordPress.Security.EscapeOutput -- built above
        );
    }
    echo '</tbody></table>';

    $prev = fn($kind, $lang) => esc_url(wp_nonce_url(admin_url('admin-post.php?action=mcr_rem_preview&kind=' . $kind . '&lang=' . $lang), 'mcr_rem_preview'));
    echo '<h2 style="margin-top:28px">Réglages</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('mcr_rem_settings');
    echo '<input type="hidden" name="action" value="mcr_rem_settings"><table class="form-table" role="presentation">';
    echo '<tr><th>Panier non terminé</th><td><label><input type="checkbox" name="cart" value="1" ' . checked(!empty($set['cart']), true, false) . '> Envoyer un rappel</label> après <input type="number" min="15" max="1440" name="cart_delay" value="' . (int) $set['cart_delay'] . '" class="small-text"> minutes sans commande. <a target="_blank" href="' . $prev('cart', 'fr') . '">Aperçu</a> · <a target="_blank" href="' . $prev('cart', 'en') . '">English</a></td></tr>';
    echo '<tr><th>Réapprovisionnement</th><td><label><input type="checkbox" name="restock" value="1" ' . checked(!empty($set['restock']), true, false) . '> Prévenir quand il est temps de racheter</label> (au rythme d’achat du client ; pour l’épicerie achetée une seule fois : après <input type="number" min="7" max="120" name="restock_default_days" value="' . (int) $set['restock_default_days'] . '" class="small-text"> jours). Au plus un avis tous les 14 jours. <a target="_blank" href="' . $prev('restock', 'fr') . '">Aperçu</a> · <a target="_blank" href="' . $prev('restock', 'en') . '">English</a></td></tr>';
    echo '</table>';
    submit_button('Enregistrer', 'primary', 'submit', false);
    echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
    wp_nonce_field('mcr_rem_run');
    echo '<input type="hidden" name="action" value="mcr_rem_run">';
    submit_button('Lancer les relances maintenant', 'secondary', 'submit', false);
    echo ' <span class="description">Elles partent aussi automatiquement (paniers : toutes les 15 minutes ; réapprovisionnement : une fois par jour).</span></form></div>';
}
