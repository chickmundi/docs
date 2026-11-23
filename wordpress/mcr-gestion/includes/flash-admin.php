<?php
/** MCR Gestion > Ventes éclair: create, follow, duplicate and stop flash sales; social-proof settings. */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Ventes éclair', 'Ventes éclair', 'manage_woocommerce', 'mcr-flash', 'mcr_render_flash_page');
}, 23);

add_action('admin_enqueue_scripts', function ($hook) {
    if (str_contains((string) $hook, 'mcr-flash')) {
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
    }
});

add_action('admin_post_mcr_flash', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('', 403);
    }
    check_admin_referer('mcr_flash');
    $in = wp_unslash($_POST);
    $all = mcr_flash_all();
    $do = sanitize_key($in['do'] ?? '');
    $msg = 'ok';
    if ($do === 'save') {
        $pid = absint($in['product'] ?? 0);
        $p = $pid ? wc_get_product($pid) : null;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['date'] ?? '')) ? $in['date'] : '';
        $time = preg_match('/^\d{2}:\d{2}$/', (string) ($in['time'] ?? '')) ? $in['time'] : '';
        $value = (float) str_replace(',', '.', (string) ($in['value'] ?? 0));
        if (!$p || !$date || !$time || $value <= 0 || (int) ($in['qty'] ?? 0) < 1) {
            $msg = 'missing';
        } else {
            $id = sanitize_key($in['id'] ?? '') ?: 'f' . substr(md5(uniqid('', true)), 0, 8);
            $all[$id] = [
                'product' => $pid,
                'mode' => ($in['mode'] ?? '') === 'price' ? 'price' : 'percent',
                'value' => $value,
                'start' => $date . ' ' . $time,
                'hours' => max(0.25, min(72, (float) str_replace(',', '.', (string) ($in['hours'] ?? 3)))),
                'qty' => max(1, (int) $in['qty']),
                'per_order' => max(0, (int) ($in['per_order'] ?? 0)),
                'sold' => (int) ($all[$id]['sold'] ?? 0),
            ];
            mcr_flash_save($all);
            wc_delete_product_transients($pid);
        }
    } elseif ($do === 'delete' || $do === 'stop') {
        $id = sanitize_key($in['id'] ?? '');
        if (isset($all[$id])) {
            if ($do === 'stop') {
                // End it now: keep it in the history with the units sold.
                [$s] = mcr_flash_window($all[$id]);
                $all[$id]['hours'] = max(0.01, (time() - $s) / HOUR_IN_SECONDS);
            } else {
                unset($all[$id]);
            }
            mcr_flash_save($all);
        }
    } elseif ($do === 'tomorrow') {
        $id = sanitize_key($in['id'] ?? '');
        if (isset($all[$id])) {
            $d = $all[$id];
            $start = (new DateTimeImmutable($d['start'], mcr_flash_tz()))->modify('+1 day');
            while ($start->getTimestamp() < time()) {
                $start = $start->modify('+1 day');
            }
            $new = 'f' . substr(md5(uniqid('', true)), 0, 8);
            $all[$new] = ['start' => $start->format('Y-m-d H:i'), 'sold' => 0] + $d;
            mcr_flash_save($all);
        }
    } elseif ($do === 'settings') {
        update_option('mcr_flash_settings', ['low_stock' => max(1, (int) ($in['low_stock'] ?? 5)), 'sold_min' => max(1, (int) ($in['sold_min'] ?? 5)), 'show_sold' => !empty($in['show_sold'])], false);
    }
    wp_safe_redirect(admin_url('admin.php?page=mcr-flash&msg=' . $msg));
    exit;
});

function mcr_render_flash_page(): void
{
    $all = mcr_flash_all();
    uasort($all, fn($a, $b) => mcr_flash_window($b)[0] <=> mcr_flash_window($a)[0]);
    $edit = sanitize_key($_GET['edit'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
    $e = $all[$edit] ?? null;
    $tz = mcr_flash_tz();
    $now = new DateTimeImmutable('now', $tz);
    $def_start = $now->modify('+1 day')->setTime(17, 0);
    $s = mcr_flash_settings();
    $form = function (string $do, array $fields = []) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('mcr_flash');
        echo '<input type="hidden" name="action" value="mcr_flash"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
    };
    $labels = ['scheduled' => 'Programmée', 'active' => 'En cours', 'soldout' => 'Épuisée', 'ended' => 'Terminée'];
    ?>
<div class="wrap">
  <h1>Ventes éclair</h1>
  <?php if (($_GET['msg'] ?? '') === 'missing') : // phpcs:ignore ?><div class="notice notice-error"><p>Choisissez un produit, une date, une heure, un rabais et une quantité.</p></div><?php elseif (isset($_GET['msg'])) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Enregistré.</p></div><?php endif; ?>
  <p>Un produit à prix réduit pendant quelques heures, en quantité limitée. Le prix éclair s’applique partout (fiches, panier, commandes WhatsApp) puis le prix normal revient tout seul. Les unités vendues sont comptées à partir des vraies commandes. Sur la page d’accueil, une section « Ventes éclair » montre le compte à rebours et la barre « vendus ».</p>

  <h2><?php echo $e ? 'Modifier la vente éclair' : 'Nouvelle vente éclair'; ?></h2>
  <?php $form('save', ['id' => $edit]); ?>
  <table class="form-table" role="presentation">
    <tr><th>Produit</th><td><select class="wc-product-search" name="product" style="width:420px" data-placeholder="Rechercher un produit…" data-action="woocommerce_json_search_products"><?php echo $e ? mcr_promo_product_options([(int) $e['product']]) : ''; // phpcs:ignore ?></select>
      <p class="description">Idéal pour un produit simple. Pour un produit avec options (couleurs, tailles), le rabais s’applique à toutes les options.</p></td></tr>
    <tr><th>Rabais</th><td>
      <select name="mode"><option value="percent" <?php selected(($e['mode'] ?? 'percent'), 'percent'); ?>>% de rabais</option><option value="price" <?php selected(($e['mode'] ?? ''), 'price'); ?>>Prix éclair ($)</option></select>
      <input type="number" step="0.01" min="0.01" name="value" value="<?php echo esc_attr((string) ($e['value'] ?? 20)); ?>" style="width:100px"></td></tr>
    <tr><th>Début</th><td><input type="date" name="date" value="<?php echo esc_attr($e ? substr($e['start'], 0, 10) : $def_start->format('Y-m-d')); ?>"> à <input type="time" name="time" value="<?php echo esc_attr($e ? substr($e['start'], 11, 5) : '17:00'); ?>"> <span class="description">heure de Trois-Rivières</span></td></tr>
    <tr><th>Durée</th><td><input type="number" step="0.25" min="0.25" max="72" name="hours" value="<?php echo esc_attr((string) ($e['hours'] ?? 3)); ?>" style="width:80px"> heures</td></tr>
    <tr><th>Quantité en vente éclair</th><td><input type="number" min="1" name="qty" value="<?php echo esc_attr((string) ($e['qty'] ?? 20)); ?>" style="width:80px"> unités <span class="description">(affiché aux clients : « 12 vendus sur 20 »)</span></td></tr>
    <tr><th>Maximum par commande</th><td><input type="number" min="0" name="per_order" value="<?php echo esc_attr((string) ($e['per_order'] ?? 3)); ?>" style="width:80px"> <span class="description">0 = sans limite</span></td></tr>
  </table>
  <?php submit_button($e ? 'Enregistrer' : 'Programmer la vente éclair'); ?>
  </form>

  <h2>Ventes programmées et passées</h2>
  <table class="widefat striped" style="max-width:1150px">
    <thead><tr><th>Produit</th><th>Rabais</th><th>Quand</th><th>Vendus</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php if (!$all) : ?><tr><td colspan="6">Aucune vente éclair pour l’instant.</td></tr><?php endif; ?>
    <?php foreach ($all as $id => $d) : $p = wc_get_product((int) $d['product']); [$st, $en] = mcr_flash_window($d); $state = mcr_flash_state($d); ?>
      <tr>
        <td><?php echo $p ? '<a href="' . esc_url(get_edit_post_link($p->get_id())) . '">' . esc_html($p->get_name()) . '</a>' : '(produit supprimé)'; ?></td>
        <td><?php echo esc_html($d['mode'] === 'price' ? wp_strip_all_tags(wc_price((float) $d['value'])) : '-' . (float) $d['value'] . ' %'); ?></td>
        <td><?php echo esc_html(wp_date('j M H:i', $st, $tz) . ' → ' . wp_date('j M H:i', $en, $tz)); ?></td>
        <td><?php echo (int) ($d['sold'] ?? 0) . ' / ' . (int) $d['qty']; ?></td>
        <td><strong><?php echo esc_html($labels[$state]); ?></strong></td>
        <td>
          <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=mcr-flash&edit=' . $id)); ?>">Modifier</a>
          <?php $form('tomorrow', ['id' => $id]); ?><button class="button button-small">Refaire demain</button></form>
          <?php if (in_array($state, ['active', 'scheduled'], true)) : $form('stop', ['id' => $id]); ?><button class="button button-small" onclick="return confirm('Arrêter cette vente éclair maintenant ?')">Arrêter</button></form><?php endif; ?>
          <?php $form('delete', ['id' => $id]); ?><button class="button-link button-link-delete" onclick="return confirm('Supprimer cette vente éclair ?')">Supprimer</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Preuves sociales (données réelles)</h2>
  <?php $form('settings'); ?>
  <table class="form-table" role="presentation">
    <tr><th>« Plus que N en stock »</th><td>Afficher quand il reste <input type="number" min="1" name="low_stock" value="<?php echo (int) $s['low_stock']; ?>" style="width:70px"> unités ou moins. <span class="description">Fonctionne pour les produits dont la quantité en stock est suivie (fiche produit › Inventaire › « Gérer le stock »).</span></td></tr>
    <tr><th>« Commandé N fois ce mois-ci »</th><td><label><input type="checkbox" name="show_sold" value="1" <?php checked($s['show_sold']); ?>> Afficher</label> à partir de <input type="number" min="1" name="sold_min" value="<?php echo (int) $s['sold_min']; ?>" style="width:70px"> commandes sur 30 jours. <span class="description">Calculé chaque jour à partir des vraies commandes.</span></td></tr>
  </table>
  <?php submit_button('Enregistrer'); ?>
  </form>
</div>
    <?php
}
