<?php
/** MCR Gestion > Roue et parrainage: prizes, odds, referral amounts, winners, rewards to send. */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Roue de bienvenue et parrainage', 'Roue et parrainage', 'manage_woocommerce', 'mcr-rewards', 'mcr_render_rewards_page');
}, 31);

add_action('admin_post_mcr_rw', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('', 403);
    }
    check_admin_referer('mcr_rw');
    global $wpdb;
    $do = sanitize_key($_POST['do'] ?? '');
    if ($do === 'settings') {
        $in = wp_unslash($_POST);
        $prizes = [];
        foreach ((array) ($in['p'] ?? []) as $i => $p) {
            $fr = sanitize_text_field($p['fr'] ?? '');
            if ($fr === '' || (float) ($p['weight'] ?? 0) <= 0) {
                continue;
            }
            $type = in_array($p['type'] ?? '', ['percent', 'fixed', 'ship'], true) ? $p['type'] : 'percent';
            $prizes[] = [
                'id' => sanitize_key($p['id'] ?? '') ?: 'x' . substr(md5($fr . $i . microtime()), 0, 6),
                'fr' => $fr, 'en' => sanitize_text_field($p['en'] ?? ''), 'type' => $type,
                'amount' => max(0, (float) ($p['amount'] ?? 0)), 'min' => max(0, (float) ($p['min'] ?? 0)),
                'weight' => max(0, (float) ($p['weight'] ?? 0)), 'limit' => max(0, (int) ($p['limit'] ?? 0)),
            ];
        }
        update_option('mcr_rewards', [
            'wheel' => !empty($in['wheel']), 'hours' => max(1, (int) ($in['hours'] ?? 72)), 'first_only' => !empty($in['first_only']),
            'prizes' => array_slice($prizes, 0, 8),
            'ref' => !empty($in['ref']), 'ref_friend' => max(0, (float) ($in['ref_friend'] ?? 5)), 'ref_min' => max(0, (float) ($in['ref_min'] ?? 40)),
            'ref_reward' => max(0, (float) ($in['ref_reward'] ?? 5)), 'ref_days' => max(7, (int) ($in['ref_days'] ?? 60)),
        ], false);
    } elseif ($do === 'delete') {
        // Remove a winner (e.g. a test) and their unused gift code.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mcr_rw_table() . ' WHERE id = %d', absint($_POST['id'] ?? 0))); // phpcs:ignore
        if ($row) {
            foreach ([$row->coupon, 'ami-' . strtolower($row->ref_code)] as $code) {
                $cid = $code ? wc_get_coupon_id_by_code($code) : 0;
                if ($cid && !(new WC_Coupon($cid))->get_usage_count()) {
                    wp_delete_post($cid, true);
                }
            }
            $wpdb->delete(mcr_rw_table(), ['id' => $row->id]); // phpcs:ignore
        }
    } elseif ($do === 'sent') {
        $wpdb->update(mcr_rw_table('referrals'), ['sent_at' => gmdate('Y-m-d H:i:s')], ['id' => absint($_POST['id'] ?? 0)]); // phpcs:ignore
    }
    wp_safe_redirect(admin_url('admin.php?page=mcr-rewards&saved=1'));
    exit;
});

function mcr_render_rewards_page(): void
{
    global $wpdb;
    $s = mcr_rw_settings();
    $t = mcr_rw_table();
    $r = mcr_rw_table('referrals');
    $spins = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE prize <> ''"); // phpcs:ignore
    $codes = $wpdb->get_col("SELECT coupon FROM $t WHERE coupon <> ''"); // phpcs:ignore
    $used = 0;
    $sales = 0.0;
    if ($codes) {
        $orders = wc_get_orders(['limit' => 200, 'status' => ['processing', 'completed', 'on-hold'], 'date_created' => '>' . (time() - 90 * DAY_IN_SECONDS)]);
        foreach ($orders as $o) {
            if (array_intersect(array_map('strtolower', $o->get_coupon_codes()), array_map('strtolower', $codes))) {
                $used++;
                $sales += (float) $o->get_total();
            }
        }
    }
    $refs = $wpdb->get_results("SELECT r.*, w.phone, w.name, w.lang FROM $r r LEFT JOIN $t w ON w.ref_code = r.ref_code ORDER BY r.id DESC LIMIT 100"); // phpcs:ignore
    $winners = $wpdb->get_results("SELECT * FROM $t WHERE prize <> '' ORDER BY id DESC LIMIT 50"); // phpcs:ignore
    $form = function (string $do, array $fields = []) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('mcr_rw');
        echo '<input type="hidden" name="action" value="mcr_rw"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
    };
    $prizes = $s['prizes'];
    while (count($prizes) < 8) {
        $prizes[] = ['id' => '', 'fr' => '', 'en' => '', 'type' => 'percent', 'amount' => '', 'min' => '', 'weight' => '', 'limit' => ''];
    }
    $totalw = array_sum(array_map(fn($p) => (float) $p['weight'], $s['prizes'])) ?: 1;
    ?>
<div class="wrap">
  <h1>Roue de bienvenue et parrainage</h1>
  <?php if (isset($_GET['saved'])) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Enregistré.</p></div><?php endif; ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 20px">
    <?php foreach ([['Roues tournées', $spins], ['Cadeaux utilisés (90 j)', $used], ['Ventes avec un cadeau (90 j)', wp_strip_all_tags(wc_price($sales))], ['Amis parrainés', count($refs)]] as [$l, $v]) : ?>
      <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;min-width:170px"><div style="color:#646970"><?php echo esc_html($l); ?></div><div style="font-size:24px;font-weight:600"><?php echo esc_html((string) $v); ?></div></div>
    <?php endforeach; ?>
  </div>

  <?php $form('settings'); ?>
  <h2>Roue de bienvenue</h2>
  <p>Chaque essai gagne un vrai cadeau : un code à usage unique, valable quelques heures, appliqué automatiquement au panier. Le visiteur donne son numéro WhatsApp (avec son accord) et rejoint votre liste « Nouveaux arrivages ».</p>
  <table class="form-table" role="presentation">
    <tr><th>Afficher la roue</th><td><label><input type="checkbox" name="wheel" value="1" <?php checked($s['wheel']); ?>> Oui (bouton cadeau sur toutes les pages, ouverture automatique une fois)</label></td></tr>
    <tr><th>Durée du cadeau</th><td><input type="number" name="hours" min="1" value="<?php echo (int) $s['hours']; ?>" style="width:80px"> heures <span class="description">Le compte à rebours affiché est la vraie date d’expiration du code.</span></td></tr>
    <tr><th>Première commande</th><td><label><input type="checkbox" name="first_only" value="1" <?php checked($s['first_only']); ?>> Cadeau valable seulement sur une première commande</label></td></tr>
  </table>
  <table class="widefat striped" style="max-width:1100px">
    <thead><tr><th>Texte sur la roue (FR)</th><th>Anglais</th><th>Type</th><th>Valeur</th><th>Achat minimum ($)</th><th>Chances</th><th>Quantité limitée</th><th>Gagnés</th></tr></thead>
    <tbody>
    <?php foreach ($prizes as $i => $p) : $left = !empty($p['id']) ? mcr_rw_left($p) : null; ?>
      <tr>
        <td><input type="hidden" name="p[<?php echo $i; ?>][id]" value="<?php echo esc_attr($p['id']); ?>"><input type="text" name="p[<?php echo $i; ?>][fr]" value="<?php echo esc_attr($p['fr']); ?>" placeholder="ex. -10 %" style="width:140px"></td>
        <td><input type="text" name="p[<?php echo $i; ?>][en]" value="<?php echo esc_attr($p['en']); ?>" placeholder="10% off" style="width:120px"></td>
        <td><select name="p[<?php echo $i; ?>][type]"><?php foreach (['percent' => '% de rabais', 'fixed' => '$ de rabais', 'ship' => 'Livraison gratuite'] as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($p['type'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></td>
        <td><input type="number" step="0.01" min="0" name="p[<?php echo $i; ?>][amount]" value="<?php echo esc_attr((string) $p['amount']); ?>" style="width:80px"></td>
        <td><input type="number" step="0.01" min="0" name="p[<?php echo $i; ?>][min]" value="<?php echo esc_attr((string) $p['min']); ?>" style="width:90px"></td>
        <td><input type="number" step="1" min="0" name="p[<?php echo $i; ?>][weight]" value="<?php echo esc_attr((string) $p['weight']); ?>" style="width:70px"> <?php if ((float) $p['weight'] > 0) : ?><span class="description"><?php echo esc_html(round((float) $p['weight'] / $totalw * 100)); ?> %</span><?php endif; ?></td>
        <td><input type="number" step="1" min="0" name="p[<?php echo $i; ?>][limit]" value="<?php echo esc_attr((string) $p['limit']); ?>" placeholder="illimitée" style="width:90px"></td>
        <td><?php echo $left !== null ? esc_html(((int) $p['limit'] - $left) . ' / ' . (int) $p['limit']) : (!empty($p['id']) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE prize = %s", $p['id'])) : ''); // phpcs:ignore ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="description">Jusqu’à 8 cases. « Chances » : poids relatif (le pourcentage s’affiche). « Quantité limitée » : quand il n’en reste plus, la case affiche « Épuisé » et ne peut plus sortir. Videz le texte d’une ligne pour la retirer.</p>

  <h2>Parrainage</h2>
  <table class="form-table" role="presentation">
    <tr><th>Activer</th><td><label><input type="checkbox" name="ref" value="1" <?php checked($s['ref']); ?>> Lien personnel « Invitez un ami » (après la roue et sur la page de confirmation de commande)</label></td></tr>
    <tr><th>Cadeau de l’ami</th><td><input type="number" step="0.01" min="0" name="ref_friend" value="<?php echo esc_attr((string) $s['ref_friend']); ?>" style="width:80px"> $ sur sa première commande, dès <input type="number" step="0.01" min="0" name="ref_min" value="<?php echo esc_attr((string) $s['ref_min']); ?>" style="width:80px"> $ d’achat</td></tr>
    <tr><th>Récompense du parrain</th><td><input type="number" step="0.01" min="0" name="ref_reward" value="<?php echo esc_attr((string) $s['ref_reward']); ?>" style="width:80px"> $ quand la commande de l’ami passe à « Terminée », valable <input type="number" min="7" name="ref_days" value="<?php echo (int) $s['ref_days']; ?>" style="width:70px"> jours</td></tr>
  </table>
  <?php submit_button('Enregistrer'); ?>
  </form>

  <h2>Récompenses de parrainage</h2>
  <p>Quand la commande d’un ami est terminée, un code est créé pour le parrain. Envoyez-le par WhatsApp en un clic.</p>
  <table class="widefat striped" style="max-width:1100px">
    <thead><tr><th>Parrain</th><th>Ami (commande)</th><th>État</th><th>Code du parrain</th><th></th></tr></thead>
    <tbody>
    <?php if (!$refs) : ?><tr><td colspan="5">Aucun parrainage pour l’instant.</td></tr><?php endif; ?>
    <?php foreach ($refs as $x) :
        $en = $x->lang === 'en';
        $msg = $x->reward_coupon ? sprintf($en ? 'Hi %1$s! Thank you for recommending Marché Chemin du Roy. Here is your %2$s reward code: %3$s (valid %4$d days, online or in store).' : 'Bonjour %1$s ! Merci d’avoir recommandé le Marché Chemin du Roy. Voici votre code de récompense de %2$s : %3$s (valable %4$d jours, en ligne ou en magasin).', $x->name ?: '', mcr_rw_money((float) $s['ref_reward']), strtoupper($x->reward_coupon), (int) $s['ref_days']) : '';
        ?>
      <tr>
        <td><?php echo esc_html(trim(($x->name ?: '') . ' ' . ($x->phone ? mcr_wag_pretty($x->phone) : ''))); ?><br><code><?php echo esc_html($x->ref_code); ?></code></td>
        <td><?php echo esc_html($x->friend); ?> · <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $x->order_id . '&action=edit')); ?>">#<?php echo (int) $x->order_id; ?></a></td>
        <td><?php echo esc_html(['pending' => 'En attente (commande pas encore terminée)', 'rewarded' => 'Récompense créée', 'cancelled' => 'Annulé'][$x->status] ?? $x->status); ?><?php echo $x->sent_at ? '<br><span class="description">Envoyée</span>' : ''; ?></td>
        <td><?php echo $x->reward_coupon ? '<code>' . esc_html(strtoupper($x->reward_coupon)) . '</code>' : '–'; ?></td>
        <td><?php if ($msg && $x->phone) : ?><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url('https://wa.me/' . $x->phone . '?text=' . rawurlencode($msg)); ?>">Envoyer par WhatsApp</a> <?php if (!$x->sent_at) : $form('sent', ['id' => $x->id]); ?><button class="button">Marquer envoyé</button></form><?php endif; endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Derniers gagnants de la roue</h2>
  <table class="widefat striped" style="max-width:1100px">
    <thead><tr><th>Numéro</th><th>Prénom</th><th>Cadeau</th><th>Code</th><th>Expire</th><th>Utilisé</th><th>Parrainé par</th><th></th></tr></thead>
    <tbody>
    <?php if (!$winners) : ?><tr><td colspan="8">Personne n’a encore tourné la roue.</td></tr><?php endif; ?>
    <?php foreach ($winners as $w) : $p = mcr_rw_prize($w->prize); $c = new WC_Coupon($w->coupon); ?>
      <tr>
        <td><a href="<?php echo esc_url('https://wa.me/' . $w->phone); ?>" target="_blank" rel="noopener"><?php echo esc_html(mcr_wag_pretty($w->phone)); ?></a></td>
        <td><?php echo esc_html($w->name); ?></td>
        <td><?php echo esc_html($p ? $p['fr'] : $w->prize); ?></td>
        <td><code><?php echo esc_html(strtoupper($w->coupon)); ?></code></td>
        <td><?php echo esc_html($w->expires_at ? wp_date('j M H:i', strtotime($w->expires_at . ' UTC'), function_exists('mcr_store_tz') ? mcr_store_tz() : null) : ''); ?></td>
        <td><?php echo $c->get_id() && $c->get_usage_count() ? 'Oui' : 'Non'; ?></td>
        <td><?php echo esc_html($w->referred_by); ?></td>
        <td><?php $form('delete', ['id' => $w->id]); ?><button class="button-link button-link-delete" onclick="return confirm('Supprimer ce gagnant et son code ?')">Supprimer</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
    <?php
}
