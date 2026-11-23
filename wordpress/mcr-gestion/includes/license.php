<?php
/**
 * Subscription ("Abonnement"): which extra modules are switched on.
 *
 * - No licence key entered: everything stays on, exactly as before (nothing changes).
 * - With a key: once a day the site asks the licence server (Chick Licences) which plan and
 *   modules it has. The answer is signed by the server, so it cannot be edited in the database.
 * - 30-day free trial with every module, then the plan chosen by the client (Stripe or manual).
 * - Payment late, trial over or server unreachable: 14 days of grace with a notice, then only the
 *   paid modules pause. The shop itself (products, orders, delivery, WhatsApp orders, SEO) is
 *   never switched off and no data is ever deleted.
 */
defined('ABSPATH') || exit;

const MCR_LICENSE_GRACE_DAYS = 14;

/** The extra modules (the shop core is always on). */
function mcr_modules(): array
{
    return [
        'promotions' => ['Promotions, affiche et fenêtre promo', 'Campagnes, offres « 2 pour 5 $ », kits, promo de la semaine, affiche à partager, fenêtre des promotions sur l’accueil.'],
        'flash' => ['Ventes éclair et badges', 'Ventes éclair avec compte à rebours et quantité limitée, « Plus que N en stock », « Commandé N fois ce mois-ci ».'],
        'fbt' => ['« Pour compléter »', 'Produits souvent achetés ensemble, sur les fiches et dans le panier.'],
        'reminders' => ['Relances automatiques', 'Courriels de panier oublié et « on refait le plein ? ».'],
        'wagroup' => ['Groupe WhatsApp', 'Invitation au groupe « Nouveaux arrivages » et liste des numéros.'],
        'rewards' => ['Roue de bienvenue et parrainage', 'Roue animée avec cadeaux, liens de parrainage, protection contre les abus.'],
        'ai' => ['Optimiseur IA', 'Améliorer les fiches produits avec l’IA.'],
        'app_manage' => ['App : gestion de la boutique', 'Dans l’app MCR Gestion : produits (prix, stock, photos, nouveaux produits), promotions et ventes éclair, livraison, retrait et bandeau.'],
        'app_insights' => ['App : tableau de bord des ventes', 'Ventes du jour et des 7 derniers jours, panier moyen, meilleures ventes, alertes de stock.'],
    ];
}

function mcr_license(): array
{
    return wp_parse_args((array) get_option('mcr_license', []), ['server' => '', 'key' => '', 'payload' => '', 'sig' => '', 'pubkey' => '', 'checked' => 0, 'error' => '', 'contact' => 0]);
}

function mcr_license_configured(): bool
{
    $l = mcr_license();
    // Once a licence has been activated, removing the key does not switch everything back on.
    return ($l['server'] !== '' && $l['key'] !== '') || (bool) get_option('mcr_license_activated');
}

/** The last answer from the licence server, only if its signature is valid. */
function mcr_license_data(): ?array
{
    static $data = false;
    if ($data !== false) {
        return $data;
    }
    $l = mcr_license();
    $data = null;
    if ($l['payload'] === '' || $l['sig'] === '' || $l['pubkey'] === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
        return $data;
    }
    try {
        $ok = sodium_crypto_sign_verify_detached(base64_decode($l['sig']), $l['payload'], base64_decode($l['pubkey']));
    } catch (Throwable $e) {
        $ok = false;
    }
    if ($ok) {
        $d = json_decode($l['payload'], true);
        if (is_array($d) && hash_equals((string) ($d['key'] ?? ''), $l['key'])) {
            $data = $d;
        }
    }
    return $data;
}

/** Last day the current plan is paid for (or trial end), as a timestamp. */
function mcr_license_until(?array $d = null): int
{
    $d = $d ?? mcr_license_data();
    return $d ? (int) ($d['until'] ?? 0) : 0;
}

/** Is a module switched on? */
function mcr_module_on(string $id): bool
{
    if (!mcr_license_configured()) {
        return true; // no subscription set up: nothing changes
    }
    $d = mcr_license_data();
    if (!$d || !in_array($id, (array) ($d['modules'] ?? []), true)) {
        return false;
    }
    if (in_array($d['status'] ?? '', ['suspended', 'other_site'], true)) {
        return false;
    }
    return time() <= mcr_license_until($d) + MCR_LICENSE_GRACE_DAYS * DAY_IN_SECONDS;
}

/** "trial", "active", "grace" (paused soon), "paused", "none". */
function mcr_license_state(): string
{
    if (!mcr_license_configured()) {
        return 'none';
    }
    $d = mcr_license_data();
    if (!$d) {
        return 'paused';
    }
    $until = mcr_license_until($d);
    if (time() <= $until && in_array($d['status'], ['trial', 'active'], true)) {
        return $d['status'];
    }
    return time() <= $until + MCR_LICENSE_GRACE_DAYS * DAY_IN_SECONDS && !in_array($d['status'], ['suspended', 'other_site'], true) ? 'grace' : 'paused';
}

// ---------------------------------------------------------------- Asking the server

function mcr_license_check(): bool
{
    $l = mcr_license();
    if ($l['server'] === '' || $l['key'] === '') {
        return false;
    }
    $res = wp_remote_post(trailingslashit($l['server']) . 'wp-json/chick/v1/check', [
        'timeout' => 12,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode(['key' => $l['key'], 'site' => home_url('/'), 'version' => defined('MCR_VERSION') ? MCR_VERSION : '']),
    ]);
    $l['contact'] = time();
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 500) {
        $l['error'] = is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res);
        update_option('mcr_license', $l, false); // keep the last good answer (grace period)
        return false;
    }
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    if (!is_array($body) || empty($body['payload']) || empty($body['sig'])) {
        $l['error'] = (string) ($body['message'] ?? 'Réponse invalide du serveur de licences.');
        if ((int) wp_remote_retrieve_response_code($res) === 404) {
            $l['payload'] = $l['sig'] = ''; // unknown key: modules pause
        }
        update_option('mcr_license', $l, false);
        return false;
    }
    if ($l['pubkey'] === '' && !empty($body['pubkey'])) {
        $l['pubkey'] = (string) $body['pubkey']; // first contact: remember the server's public key
    }
    $l['payload'] = (string) $body['payload'];
    $l['sig'] = (string) $body['sig'];
    $l['checked'] = time();
    $l['error'] = '';
    update_option('mcr_license', $l, false);
    update_option('mcr_license_activated', 1, false);
    return true;
}

add_action('mcr_license_daily', 'mcr_license_check');
add_action('init', function () {
    if (mcr_license_configured() && !wp_next_scheduled('mcr_license_daily')) {
        wp_schedule_event(time() + 300, 'twicedaily', 'mcr_license_daily');
    }
});
// Admin: refresh if the last contact is older than 12 h.
add_action('admin_init', function () {
    $l = mcr_license();
    if (mcr_license_configured() && (int) $l['contact'] < time() - 12 * HOUR_IN_SECONDS && current_user_can('manage_woocommerce') && !wp_doing_ajax()) {
        mcr_license_check();
    }
});

// ---------------------------------------------------------------- Admin: MCR Gestion > Abonnement

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Abonnement', 'Abonnement', 'manage_options', 'mcr-license', 'mcr_render_license_page');
}, 99);

add_action('admin_post_mcr_license', function () {
    if (!current_user_can('manage_options')) {
        wp_die('', 403);
    }
    check_admin_referer('mcr_license');
    $l = mcr_license();
    $do = sanitize_key($_POST['do'] ?? '');
    if ($do === 'save') {
        $server = esc_url_raw(trim((string) wp_unslash($_POST['server'] ?? '')));
        $key = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) wp_unslash($_POST['key'] ?? '')));
        if ($server !== $l['server'] || $key !== $l['key']) {
            $l = ['server' => untrailingslashit($server), 'key' => $key, 'payload' => '', 'sig' => '', 'pubkey' => '', 'checked' => 0, 'error' => '', 'contact' => 0];
            update_option('mcr_license', $l, false);
        }
        mcr_license_check();
    } elseif ($do === 'check') {
        mcr_license_check();
    } elseif ($do === 'request' && $l['key'] && $l['server']) {
        // "I sent the Interac transfer": tell the licence server, which notifies the provider.
        $months = (int) ($_POST['months'] ?? 1);
        $res = wp_remote_post($l['server'] . '/wp-json/chick/v1/request', [
            'timeout' => 15,
            'body' => [
                'key' => $l['key'],
                'plan' => sanitize_key($_POST['plan'] ?? ''),
                'months' => in_array($months, [1, 3, 6, 12], true) ? $months : 1,
                'note' => sanitize_text_field(wp_unslash($_POST['note'] ?? '')),
            ],
        ]);
        $body = is_wp_error($res) ? [] : (array) json_decode((string) wp_remote_retrieve_body($res), true);
        $ok = !is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200;
        set_transient('mcr_license_msg', [$ok, $body['message'] ?? ($ok ? 'Demande envoyée.' : 'Le serveur de licences est injoignable. Réessayez plus tard.')], 300);
        mcr_license_check();
    } elseif ($do === 'reset' && str_starts_with(strtolower((string) parse_url(home_url(), PHP_URL_HOST)), 'staging.')) {
        // Test sites only: forget the licence, everything back on.
        delete_option('mcr_license');
        delete_option('mcr_license_activated');
        wp_clear_scheduled_hook('mcr_license_daily');
    }
    wp_safe_redirect(admin_url('admin.php?page=mcr-license&done=1'));
    exit;
});

// Reminders in the admin: trial ending, payment late, modules paused.
add_action('admin_notices', function () {
    if (!current_user_can('manage_woocommerce') || !mcr_license_configured() || (($_GET['page'] ?? '') === 'mcr-license')) { // phpcs:ignore
        return;
    }
    $state = mcr_license_state();
    $until = mcr_license_until();
    $link = '<a href="' . esc_url(admin_url('admin.php?page=mcr-license')) . '">Voir mon abonnement</a>';
    $days = (int) ceil(($until - time()) / DAY_IN_SECONDS);
    $d = mcr_license_data();
    if (!empty($d['pending'])) {
        return; // Payment announced, waiting for confirmation: no reminder.
    }
    if ($state === 'active' && $days <= 7 && !empty($d['interac'])) {
        echo '<div class="notice notice-info"><p><strong>Abonnement :</strong> votre période payée se termine dans ' . (int) max(0, $days) . ' jour(s). Pensez à renouveler par virement Interac. ' . $link . '</p></div>'; // phpcs:ignore
    } elseif ($state === 'trial' && $days <= 7) {
        echo '<div class="notice notice-info"><p><strong>Essai gratuit :</strong> plus que ' . (int) $days . ' jour(s). Choisissez un forfait pour garder vos modules. ' . $link . '</p></div>'; // phpcs:ignore
    } elseif ($state === 'grace') {
        $left = (int) ceil(($until + MCR_LICENSE_GRACE_DAYS * DAY_IN_SECONDS - time()) / DAY_IN_SECONDS);
        echo '<div class="notice notice-warning"><p><strong>Abonnement :</strong> vos modules seront mis en pause dans ' . (int) $left . ' jour(s). ' . $link . '</p></div>'; // phpcs:ignore
    } elseif ($state === 'paused') {
        echo '<div class="notice notice-warning"><p><strong>Abonnement :</strong> certains modules sont en pause. La boutique fonctionne normalement. ' . $link . '</p></div>'; // phpcs:ignore
    }
});

function mcr_render_license_page(): void
{
    $l = mcr_license();
    $d = mcr_license_data();
    $state = mcr_license_state();
    $until = mcr_license_until($d);
    $tz = function_exists('mcr_store_tz') ? mcr_store_tz() : wp_timezone();
    $labels = ['none' => ['Non configuré', '#646970'], 'trial' => ['Essai gratuit', '#2271b1'], 'active' => ['Actif', '#00a32a'], 'grace' => ['Délai de grâce', '#dba617'], 'paused' => ['Modules en pause', '#d63638']];
    [$label, $color] = $labels[$state];
    $form = function (string $do) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('mcr_license');
        echo '<input type="hidden" name="action" value="mcr_license"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
    };
    ?>
<div class="wrap">
  <h1>Abonnement</h1>
  <p>Votre boutique (produits, commandes, livraison, commandes WhatsApp, référencement) fonctionne toujours. L’abonnement active les modules qui vous aident à vendre plus, avec les mises à jour et le support.</p>

  <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
    <div style="background:#fff;border:1px solid #dcdcde;border-left:5px solid <?php echo esc_attr($color); ?>;border-radius:8px;padding:16px 20px;min-width:260px">
      <div style="color:#646970">État</div>
      <div style="font-size:22px;font-weight:600;color:<?php echo esc_attr($color); ?>"><?php echo esc_html($label); ?></div>
      <?php if ($d) : ?>
        <div style="margin-top:6px">Forfait : <strong><?php echo esc_html((string) ($d['plan_name'] ?? $d['plan'] ?? '')); ?></strong></div>
        <?php if ($until) : ?><div><?php echo esc_html((['trial' => 'Essai jusqu’au ', 'expired' => 'Essai terminé le ', 'past_due' => 'Paiement en retard — payé jusqu’au ', 'canceled' => 'Abonnement annulé — payé jusqu’au '][$d['status']] ?? 'Payé jusqu’au ') . wp_date('j F Y', $until, $tz)); ?><?php if ($d['status'] === 'trial' && $until > time()) : ?> — <strong><?php echo (int) ceil(($until - time()) / DAY_IN_SECONDS); ?> jour(s) restant(s)</strong><?php endif; ?></div><?php endif; ?>
      <?php elseif ($state === 'none') : ?>
        <div style="margin-top:6px">Tous les modules sont actifs (aucune clé de licence).</div>
      <?php endif; ?>
      <?php if ($l['checked']) : ?><div style="color:#646970;margin-top:6px;font-size:12px">Dernière vérification : <?php echo esc_html(wp_date('j M Y H:i', (int) $l['checked'], $tz)); ?></div><?php endif; ?>
      <?php if ($l['error']) : ?><div style="color:#d63638;margin-top:6px;font-size:12px"><?php echo esc_html($l['error']); ?></div><?php endif; ?>
    </div>
    <?php if ($d && !empty($d['portal'])) : ?>
      <div style="display:flex;flex-direction:column;gap:8px;justify-content:center"><a class="button button-primary button-hero" href="<?php echo esc_url($d['portal']); ?>" target="_blank" rel="noopener">Gérer mon abonnement et ma carte</a></div>
    <?php endif; ?>
  </div>

  <?php $msg = get_transient('mcr_license_msg'); if ($msg) : delete_transient('mcr_license_msg'); ?>
    <div class="notice <?php echo $msg[0] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html((string) $msg[1]); ?></p></div>
  <?php endif; ?>
  <?php if ($d && !empty($d['pending'])) : $pp = $d['pending']; $pname = ''; foreach ((array) ($d['plans'] ?? []) as $p) { if (($p['id'] ?? '') === $pp['plan']) { $pname = $p['name']; } } ?>
    <div style="background:#fcf9e8;border:1px solid #dba617;border-left:5px solid #dba617;border-radius:8px;padding:14px 18px;max-width:940px">
      <strong>Paiement en attente de confirmation</strong> — forfait <?php echo esc_html($pname ?: $pp['plan']); ?>, <?php echo (int) $pp['months']; ?> mois, annoncé le <?php echo esc_html(wp_date('j F Y', (int) $pp['at'], $tz)); ?>. Vos modules restent actifs; la confirmation arrive dès réception du virement, avec un reçu par courriel.
    </div>
  <?php endif; ?>

  <h2>Modules</h2>
  <table class="widefat striped" style="max-width:980px">
    <thead><tr><th>Module</th><th>Ce qu’il fait</th><th>État</th></tr></thead>
    <tbody>
      <tr><td><strong>Boutique</strong></td><td>Catalogue, panier, caisse, livraison, retrait, commandes WhatsApp, référencement, protection des images, et l’app MCR Gestion pour suivre les commandes avec alertes sur le téléphone.</td><td><span style="color:#00a32a">● Toujours inclus</span></td></tr>
      <?php foreach (mcr_modules() as $id => [$name, $desc]) : $on = mcr_module_on($id); ?>
        <tr><td><strong><?php echo esc_html($name); ?></strong></td><td><?php echo esc_html($desc); ?></td><td><?php echo $on ? '<span style="color:#00a32a">● Actif</span>' : '<span style="color:#d63638">● En pause</span>'; ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($d && !empty($d['plans'])) : ?>
    <h2>Forfaits</h2>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <?php foreach ((array) $d['plans'] as $p) : $current = ($p['id'] ?? '') === ($d['plan'] ?? '') && $d['status'] === 'active'; ?>
        <div style="background:#fff;border:1px solid <?php echo $current ? '#00a32a' : '#dcdcde'; ?>;border-radius:10px;padding:18px;width:280px">
          <div style="font-size:18px;font-weight:600"><?php echo esc_html((string) $p['name']); ?></div>
          <div style="font-size:26px;font-weight:700;margin:6px 0"><?php echo esc_html((string) $p['price']); ?> <span style="font-size:13px;font-weight:400;color:#646970">/ mois</span></div>
          <ul style="margin:8px 0 14px 18px;list-style:disc">
            <li>Boutique, mises à jour et support</li>
            <?php foreach ((array) ($p['modules'] ?? []) as $m) : ?><li><?php echo esc_html(mcr_modules()[$m][0] ?? $m); ?></li><?php endforeach; ?>
          </ul>
          <?php if (!empty($d['interac'])) : $ia = $d['interac']; $unit = (float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', (string) $p['price'])); ?>
            <details>
              <summary class="button <?php echo $current ? '' : 'button-primary'; ?>" style="list-style:none"><?php echo $current ? 'Renouveler' : 'Choisir ce forfait'; ?></summary>
              <div style="margin-top:12px;font-size:13px;line-height:1.6">
                <strong>Payer par virement Interac</strong>
                <ol style="margin:6px 0 10px 18px">
                  <li>Destinataire : <strong><?php echo esc_html((string) $ia['name']); ?></strong><br><code style="user-select:all"><?php echo esc_html((string) $ia['email']); ?></code></li>
                  <li>Montant : <?php foreach ([1, 3, 6, 12] as $m) : ?><span style="white-space:nowrap"><?php echo (int) $m; ?> mois = <strong><?php echo esc_html(number_format($unit * $m, 2, ',', ' ')); ?> $</strong></span><?php echo $m < 12 ? ' · ' : ''; ?><?php endforeach; ?></li>
                  <li>Message du virement : <code style="user-select:all"><?php echo esc_html($l['key']); ?></code></li>
                </ol>
                <?php if (!empty($ia['note'])) : ?><p style="color:#646970;margin:0 0 10px"><?php echo esc_html((string) $ia['note']); ?></p><?php endif; ?>
                <?php $form('request'); ?>
                  <input type="hidden" name="plan" value="<?php echo esc_attr((string) $p['id']); ?>">
                  <label>Durée payée <select name="months"><?php foreach ([1, 3, 6, 12] as $m) : ?><option value="<?php echo (int) $m; ?>"><?php echo (int) $m; ?> mois</option><?php endforeach; ?></select></label><br>
                  <input type="text" name="note" maxlength="250" placeholder="Note (facultatif)" style="width:100%;margin:6px 0">
                  <?php submit_button('J’ai envoyé le virement', 'primary', 'submit', false); ?>
                </form>
              </div>
            </details>
          <?php elseif ($current) : ?><span class="button" disabled>Forfait actuel</span>
          <?php elseif (!empty($p['checkout'])) : ?><a class="button button-primary" href="<?php echo esc_url($p['checkout']); ?>" target="_blank" rel="noopener">Choisir ce forfait</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>Clé de licence</h2>
  <?php $form('save'); ?>
    <table class="form-table" role="presentation">
      <tr><th><label for="lic-key">Clé</label></th><td><input id="lic-key" type="text" name="key" class="regular-text code" value="<?php echo esc_attr($l['key']); ?>" placeholder="MCR-XXXX-XXXX-XXXX"></td></tr>
      <tr><th><label for="lic-server">Serveur de licences</label></th><td><input id="lic-server" type="url" name="server" class="regular-text" value="<?php echo esc_attr($l['server']); ?>" placeholder="https://chickservice.com"></td></tr>
    </table>
    <?php submit_button('Enregistrer et vérifier', 'primary', 'submit', false); ?>
  </form>
  <?php if (mcr_license_configured()) : ?>
    <?php $form('check'); ?><?php submit_button('Vérifier maintenant', 'secondary', 'submit', false); ?></form>
    <?php if (str_starts_with(strtolower((string) parse_url(home_url(), PHP_URL_HOST)), 'staging.')) : $form('reset'); ?><button class="button-link button-link-delete">Réinitialiser (site de test seulement)</button></form><?php endif; ?>
  <?php endif; ?>
</div>
    <?php
}
