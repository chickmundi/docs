<?php
/** Admin: Licences (clients) and Réglages (plans, trial, Stripe). */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_menu_page('Licences MCR', 'Licences MCR', 'manage_options', 'chick-lic', 'chick_lic_render_list', 'dashicons-admin-network', 58);
    add_submenu_page('chick-lic', 'Clients', 'Clients', 'manage_options', 'chick-lic', 'chick_lic_render_list');
    add_submenu_page('chick-lic', 'Réglages', 'Réglages', 'manage_options', 'chick-lic-settings', 'chick_lic_render_settings');
});

add_action('admin_post_chick_lic', function () {
    if (!current_user_can('manage_options')) {
        wp_die('', 403);
    }
    check_admin_referer('chick_lic');
    global $wpdb;
    $in = wp_unslash($_POST);
    $do = sanitize_key($in['do'] ?? '');
    $s = chick_lic_settings();
    $back = admin_url('admin.php?page=chick-lic&done=1');
    if ($do === 'create') {
        $days = max(0, (int) $s['trial_days']);
        $wpdb->insert(chick_lic_table(), [ // phpcs:ignore
            'lkey' => chick_lic_new_key(),
            'client' => sanitize_text_field($in['client'] ?? ''),
            'email' => sanitize_email($in['email'] ?? ''),
            'site' => strtolower((string) parse_url(esc_url_raw($in['site'] ?? ''), PHP_URL_HOST)),
            'plan' => sanitize_key($in['plan'] ?? 'premium'),
            'status' => 'trial',
            'trial_end' => gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS),
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
    } elseif ($do === 'update') {
        $id = absint($in['id'] ?? 0);
        $data = [
            'client' => sanitize_text_field($in['client'] ?? ''),
            'email' => sanitize_email($in['email'] ?? ''),
            'site' => strtolower(preg_replace('#^https?://#', '', trim((string) ($in['site'] ?? ''), '/ '))),
            'plan' => sanitize_key($in['plan'] ?? ''),
            'status' => in_array($in['status'] ?? '', ['trial', 'active', 'past_due', 'canceled', 'suspended'], true) ? $in['status'] : 'trial',
            'notes' => sanitize_textarea_field($in['notes'] ?? ''),
            'extra_modules' => wp_json_encode(array_values(array_intersect((array) ($in['extra'] ?? []), array_keys(chick_lic_modules())))),
        ];
        foreach (['trial_end', 'period_end'] as $f) {
            $data[$f] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$f] ?? '')) ? $in[$f] . ' 23:59:59' : null;
        }
        chick_lic_update($id, $data);
        $back = admin_url('admin.php?page=chick-lic&edit=' . $id . '&done=1');
    } elseif ($do === 'paid') {
        // Manual payment (e-transfer, cash): active one more month from the later of today / current end.
        $l = chick_lic_get('id', (string) absint($in['id'] ?? 0));
        if ($l) {
            $from = max(time(), chick_lic_ts($l->period_end));
            chick_lic_update((int) $l->id, ['status' => 'active', 'plan' => sanitize_key($in['plan'] ?? $l->plan) ?: 'croissance', 'period_end' => gmdate('Y-m-d H:i:s', strtotime('+1 month', $from))]);
        }
    } elseif ($do === 'delete') {
        $wpdb->delete(chick_lic_table(), ['id' => absint($in['id'] ?? 0)]); // phpcs:ignore
    } elseif ($do === 'settings') {
        $plans = [];
        foreach ($s['plans'] as $id => $p) {
            $x = (array) ($in['plans'][$id] ?? []);
            $plans[$id] = [
                'name' => sanitize_text_field($x['name'] ?? $p['name']),
                'price' => sanitize_text_field($x['price'] ?? $p['price']),
                'modules' => array_values(array_intersect((array) ($x['modules'] ?? []), array_keys(chick_lic_modules()))),
                'price_id' => sanitize_text_field($x['price_id'] ?? ''),
                'link' => esc_url_raw($x['link'] ?? ''),
            ];
        }
        $new = [
            'trial_days' => max(0, (int) ($in['trial_days'] ?? 30)),
            'trial_plan' => array_key_exists($in['trial_plan'] ?? '', $plans) ? $in['trial_plan'] : 'premium',
            'plans' => $plans,
            'support_url' => esc_url_raw($in['support_url'] ?? ''),
            'stripe_secret' => trim((string) ($in['stripe_secret'] ?? '')) !== '' ? trim((string) $in['stripe_secret']) : $s['stripe_secret'],
            'webhook_secret' => trim((string) ($in['webhook_secret'] ?? '')) !== '' ? trim((string) $in['webhook_secret']) : $s['webhook_secret'],
        ];
        update_option('chick_lic_settings', $new, false);
        $back = admin_url('admin.php?page=chick-lic-settings&done=1');
    }
    wp_safe_redirect($back);
    exit;
});

function chick_lic_form(string $do, array $fields = []): void
{
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
    wp_nonce_field('chick_lic');
    echo '<input type="hidden" name="action" value="chick_lic"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
    foreach ($fields as $k => $v) {
        echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
}

function chick_lic_badge(string $status): string
{
    $c = ['trial' => ['Essai', '#2271b1'], 'active' => ['Actif', '#00a32a'], 'past_due' => ['Paiement en retard', '#dba617'], 'canceled' => ['Annulé', '#646970'], 'expired' => ['Essai terminé', '#d63638'], 'suspended' => ['Suspendu', '#d63638'], 'other_site' => ['Autre site', '#d63638']][$status] ?? [$status, '#646970'];
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . $c[1] . ';color:#fff;font-size:12px">' . esc_html($c[0]) . '</span>';
}

function chick_lic_render_list(): void
{
    global $wpdb;
    $s = chick_lic_settings();
    $edit = absint($_GET['edit'] ?? 0); // phpcs:ignore
    if ($edit && ($l = chick_lic_get('id', (string) $edit))) {
        chick_lic_render_edit($l);
        return;
    }
    $rows = $wpdb->get_results('SELECT * FROM ' . chick_lic_table() . ' ORDER BY id DESC'); // phpcs:ignore
    $mrr = 0.0;
    foreach ($rows as $r) {
        $e = chick_lic_effective($r);
        if ($e['status'] === 'active') {
            $mrr += (float) preg_replace('/[^0-9.,]/', '', str_replace(',', '.', (string) ($s['plans'][$r->plan]['price'] ?? 0)));
        }
    }
    ?>
<div class="wrap">
  <h1>Licences MCR</h1>
  <?php if (isset($_GET['done'])) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Enregistré.</p></div><?php endif; ?>
  <p>Revenu mensuel actif : <strong><?php echo esc_html(number_format_i18n($mrr, 2)); ?> $</strong> · <?php echo count($rows); ?> client(s)</p>
  <h2>Nouveau client</h2>
  <?php chick_lic_form('create'); ?>
    <input type="text" name="client" placeholder="Nom du commerce" required style="width:220px">
    <input type="email" name="email" placeholder="Courriel" style="width:220px">
    <input type="url" name="site" placeholder="https://site-du-client.com" style="width:240px">
    <select name="plan"><?php foreach ($s['plans'] as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($id, 'premium'); ?>>Après l’essai : <?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select>
    <?php submit_button('Créer la licence (essai ' . (int) $s['trial_days'] . ' jours)', 'primary', 'submit', false); ?>
  </form>
  <h2>Clients</h2>
  <table class="widefat striped">
    <thead><tr><th>Client</th><th>Clé</th><th>Site</th><th>Forfait</th><th>État</th><th>Jusqu’au</th><th>Dernière visite</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows) : ?><tr><td colspan="8">Aucun client pour l’instant.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r) : $e = chick_lic_effective($r); ?>
      <tr>
        <td><strong><?php echo esc_html($r->client); ?></strong><br><span style="color:#646970"><?php echo esc_html($r->email); ?></span></td>
        <td><code style="user-select:all"><?php echo esc_html($r->lkey); ?></code></td>
        <td><?php echo esc_html($r->site); ?></td>
        <td><?php echo esc_html($e['plan_name']); ?><?php echo $r->stripe_sub ? '<br><span style="color:#646970;font-size:12px">Stripe</span>' : ''; ?></td>
        <td><?php echo chick_lic_badge($e['status']); // phpcs:ignore ?></td>
        <td><?php echo $e['until'] ? esc_html(wp_date('j M Y', $e['until'])) : '–'; ?></td>
        <td><?php echo $r->last_seen ? esc_html(wp_date('j M H:i', chick_lic_ts($r->last_seen))) . '<br><span style="color:#646970;font-size:12px">v' . esc_html($r->last_version) . '</span>' : 'jamais'; ?></td>
        <td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=chick-lic&edit=' . (int) $r->id)); ?>">Gérer</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
    <?php
}

function chick_lic_render_edit(object $l): void
{
    $s = chick_lic_settings();
    $e = chick_lic_effective($l);
    $extra = (array) json_decode((string) $l->extra_modules, true);
    $d = fn($dt) => $dt ? substr($dt, 0, 10) : '';
    ?>
<div class="wrap">
  <h1><?php echo esc_html($l->client); ?> <?php echo chick_lic_badge($e['status']); // phpcs:ignore ?></h1>
  <p><a href="<?php echo esc_url(admin_url('admin.php?page=chick-lic')); ?>">← Tous les clients</a></p>
  <?php if (isset($_GET['done'])) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Enregistré.</p></div><?php endif; ?>
  <p>Clé : <code style="font-size:15px;user-select:all"><?php echo esc_html($l->lkey); ?></code> · Serveur à indiquer chez le client : <code><?php echo esc_html(home_url()); ?></code></p>
  <p>Modules actifs maintenant : <strong><?php echo esc_html(implode(', ', array_map(fn($m) => chick_lic_modules()[$m] ?? $m, $e['modules'])) ?: 'aucun (boutique seulement)'); ?></strong></p>
  <?php chick_lic_form('update', ['id' => $l->id]); ?>
  <table class="form-table" role="presentation">
    <tr><th>Client</th><td><input type="text" name="client" value="<?php echo esc_attr($l->client); ?>" class="regular-text"> <input type="email" name="email" value="<?php echo esc_attr($l->email); ?>" class="regular-text" placeholder="Courriel"></td></tr>
    <tr><th>Site autorisé</th><td><input type="text" name="site" value="<?php echo esc_attr($l->site); ?>" class="regular-text" placeholder="marchecheminduroy.com"> <span class="description">Vide = le premier site qui utilise la clé. Le sous-domaine staging. est accepté.</span></td></tr>
    <tr><th>Forfait</th><td><select name="plan"><?php foreach ($s['plans'] as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($l->plan, $id); ?>><?php echo esc_html($p['name'] . ' — ' . $p['price']); ?></option><?php endforeach; ?></select></td></tr>
    <tr><th>État</th><td><select name="status"><?php foreach (['trial' => 'Essai', 'active' => 'Actif', 'past_due' => 'Paiement en retard', 'canceled' => 'Annulé', 'suspended' => 'Suspendu'] as $k => $v) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($l->status, $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></td></tr>
    <tr><th>Fin de l’essai</th><td><input type="date" name="trial_end" value="<?php echo esc_attr($d($l->trial_end)); ?>"></td></tr>
    <tr><th>Payé jusqu’au</th><td><input type="date" name="period_end" value="<?php echo esc_attr($d($l->period_end)); ?>"> <span class="description">Mis à jour automatiquement par Stripe.</span></td></tr>
    <tr><th>Modules en plus du forfait</th><td><?php foreach (chick_lic_modules() as $id => $name) : ?><label style="display:inline-block;margin-right:14px"><input type="checkbox" name="extra[]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, $extra, true)); ?>> <?php echo esc_html($name); ?></label><?php endforeach; ?></td></tr>
    <tr><th>Notes</th><td><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea((string) $l->notes); ?></textarea></td></tr>
  </table>
  <?php submit_button('Enregistrer'); ?>
  </form>
  <h2>Paiement reçu hors Stripe</h2>
  <?php chick_lic_form('paid', ['id' => $l->id]); ?>
    <select name="plan"><?php foreach ($s['plans'] as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($l->plan, $id); ?>><?php echo esc_html($p['name'] . ' — ' . $p['price']); ?></option><?php endforeach; ?></select>
    <?php submit_button('Marquer payé pour 1 mois (virement Interac, comptant…)', 'secondary', 'submit', false); ?>
  </form>
  <p style="margin-top:30px"><?php chick_lic_form('delete', ['id' => $l->id]); ?><button class="button-link button-link-delete" onclick="return confirm('Supprimer cette licence ? Les modules du client seront mis en pause.')">Supprimer la licence</button></form></p>
  <p style="color:#646970">Stripe : client <code><?php echo esc_html($l->stripe_customer ?: '–'); ?></code>, abonnement <code><?php echo esc_html($l->stripe_sub ?: '–'); ?></code></p>
</div>
    <?php
}

function chick_lic_render_settings(): void
{
    $s = chick_lic_settings();
    $k = chick_lic_keys();
    ?>
<div class="wrap">
  <h1>Réglages des licences</h1>
  <?php if (isset($_GET['done'])) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Enregistré.</p></div><?php endif; ?>
  <?php chick_lic_form('settings'); ?>
  <h2>Essai gratuit</h2>
  <p><input type="number" name="trial_days" min="0" value="<?php echo (int) $s['trial_days']; ?>" style="width:70px"> jours, avec le forfait
    <select name="trial_plan"><?php foreach ($s['plans'] as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($s['trial_plan'], $id); ?>><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select></p>
  <h2>Forfaits</h2>
  <table class="widefat" style="max-width:1200px">
    <thead><tr><th>Forfait</th><th>Prix affiché</th><th>Modules inclus</th><th>Stripe : ID du prix (price_…)</th><th>Stripe : lien de paiement (buy.stripe.com/…)</th></tr></thead>
    <tbody>
    <?php foreach ($s['plans'] as $id => $p) : ?>
      <tr>
        <td><input type="text" name="plans[<?php echo esc_attr($id); ?>][name]" value="<?php echo esc_attr($p['name']); ?>" style="width:120px"></td>
        <td><input type="text" name="plans[<?php echo esc_attr($id); ?>][price]" value="<?php echo esc_attr($p['price']); ?>" style="width:80px"></td>
        <td><?php foreach (chick_lic_modules() as $mid => $name) : ?><label style="display:block"><input type="checkbox" name="plans[<?php echo esc_attr($id); ?>][modules][]" value="<?php echo esc_attr($mid); ?>" <?php checked(in_array($mid, (array) $p['modules'], true)); ?>> <?php echo esc_html($name); ?></label><?php endforeach; ?></td>
        <td><input type="text" name="plans[<?php echo esc_attr($id); ?>][price_id]" value="<?php echo esc_attr($p['price_id']); ?>" placeholder="price_…" style="width:200px"></td>
        <td><input type="url" name="plans[<?php echo esc_attr($id); ?>][link]" value="<?php echo esc_attr($p['link']); ?>" placeholder="https://buy.stripe.com/…" style="width:260px"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <h2>Stripe</h2>
  <table class="form-table" role="presentation">
    <tr><th>Clé secrète (restricted key conseillée)</th><td><input type="password" name="stripe_secret" value="" class="regular-text" placeholder="<?php echo $s['stripe_secret'] ? 'Enregistrée — laisser vide pour la garder' : 'rk_live_… ou sk_live_…'; ?>" autocomplete="off">
      <p class="description">Sert au portail client et à lire les abonnements. Droits nécessaires : Customers, Subscriptions, Customer portal.</p></td></tr>
    <tr><th>Secret du webhook</th><td><input type="password" name="webhook_secret" value="" class="regular-text" placeholder="<?php echo $s['webhook_secret'] ? 'Enregistré — laisser vide pour le garder' : 'whsec_…'; ?>" autocomplete="off">
      <p class="description">Dans Stripe › Développeurs › Webhooks, ajoutez l’adresse <code><?php echo esc_html(rest_url('chick/v1/stripe')); ?></code> avec les événements : checkout.session.completed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed.</p></td></tr>
    <tr><th>Page d’aide (si pas de Stripe)</th><td><input type="url" name="support_url" value="<?php echo esc_attr($s['support_url']); ?>" class="regular-text" placeholder="https://chickservice.com/contact"></td></tr>
  </table>
  <?php submit_button('Enregistrer'); ?>
  </form>
  <h2>Signature</h2>
  <p>Les réponses aux sites clients sont signées. Clé publique : <code style="font-size:11px"><?php echo esc_html($k['public']); ?></code></p>
</div>
    <?php
}
