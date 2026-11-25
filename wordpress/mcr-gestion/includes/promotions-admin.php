<?php
/**
 * MCR Gestion > Promotions: campaigns (percent, quantity deal, kit), "promo de la semaine",
 * and the promo flyer for WhatsApp / Facebook / Instagram.
 */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Promotions', 'Promotions', 'mcr_manage_promos', 'mcr-promos', 'mcr_render_promos_page');
}, 22);

add_action('admin_enqueue_scripts', function ($hook) {
    if (!str_contains((string) $hook, 'mcr-promos')) {
        return;
    }
    wp_enqueue_script('wc-enhanced-select');
    wp_enqueue_style('woocommerce_admin_styles');
});

/** Admin page address, with extra arguments. */
function mcr_promos_admin_url(array $args = []): string
{
    return add_query_arg($args, admin_url('admin.php?page=mcr-promos'));
}

add_action('admin_post_mcr_promo_save', function () {
    if (!current_user_can('mcr_manage_promos')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_promo_save');
    $in = wp_unslash($_POST);
    $res = mcr_promo_upsert($in);
    if (is_wp_error($res)) {
        $e = $res->get_error_data();
        set_transient('mcr_promo_notice', ['error', $res->get_error_message(), $e['c'] + ['id' => $e['id']]], 120);
        wp_safe_redirect(mcr_promos_admin_url(['edit' => $e['old'] ? $e['id'] : 'new']));
        exit;
    }
    $type = mcr_promos()[$res]['type'] ?? 'percent';
    set_transient('mcr_promo_notice', ['success', $type === 'percent' ? 'Promotion enregistrée. Les prix sont mis à jour en arrière-plan (quelques secondes à quelques minutes).' : 'Offre enregistrée. Elle s’applique dans le panier pendant ses dates.'], 120);
    wp_safe_redirect(mcr_promos_admin_url());
    exit;
});

add_action('admin_post_mcr_promo_delete', function () {
    if (!current_user_can('mcr_manage_promos')) {
        wp_die('Accès refusé');
    }
    $id = sanitize_key(wp_unslash($_GET['id'] ?? ''));
    check_admin_referer('mcr_promo_delete_' . $id);
    if (mcr_promo_remove($id)) {
        set_transient('mcr_promo_notice', ['success', 'Promotion supprimée. Les prix d’origine sont remis.'], 120);
    }
    wp_safe_redirect(mcr_promos_admin_url());
    exit;
});

add_action('admin_post_mcr_promo_week', function () {
    if (!current_user_can('mcr_manage_promos')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_promo_week');
    update_option('mcr_promo_week', absint($_POST['week'] ?? 0));
    set_transient('mcr_promo_notice', ['success', 'Promo de la semaine enregistrée.'], 120);
    wp_safe_redirect(mcr_promos_admin_url());
    exit;
});

/** <option>s of products already chosen (for the search box). */
function mcr_promo_product_options(array $ids): string
{
    $out = '';
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if ($p) {
            $out .= '<option value="' . (int) $id . '" selected>' . esc_html(wp_strip_all_tags($p->get_formatted_name())) . '</option>';
        }
    }
    return $out;
}

function mcr_render_promos_page(): void
{
    $notice = get_transient('mcr_promo_notice');
    delete_transient('mcr_promo_notice');
    $edit = sanitize_key(wp_unslash($_GET['edit'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    $all = mcr_promos();
    $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'list')); // phpcs:ignore WordPress.Security.NonceVerification
    echo '<div class="wrap mcr-promos"><h1 class="wp-heading-inline">Promotions</h1>';
    if (!$edit && $tab === 'list') {
        echo ' <a class="page-title-action" href="' . esc_url(mcr_promos_admin_url(['edit' => 'new'])) . '">Nouvelle promotion</a>';
    }
    echo '<hr class="wp-header-end">';
    if (is_array($notice)) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1]));
    }
    echo '<nav class="nav-tab-wrapper">';
    foreach (['list' => 'Promotions et offres', 'flyer' => 'Affiche à partager'] as $k => $label) {
        printf('<a class="nav-tab%s" href="%s">%s</a>', $tab === $k ? ' nav-tab-active' : '', esc_url(mcr_promos_admin_url(['tab' => $k])), esc_html($label));
    }
    echo '</nav>';
    if ($tab === 'flyer') {
        mcr_render_promo_flyer();
        echo '</div>';
        return;
    }
    if ($edit) {
        $c = ($notice[0] ?? '') === 'error' && !empty($notice[2]) ? $notice[2] : ($all[$edit] ?? ['type' => 'percent', 'percent' => 15, 'qty' => 2, 'banner' => true, 'skip_existing' => true, 'start' => wp_date('Y-m-d', null, mcr_store_tz()), 'end' => wp_date('Y-m-d', strtotime('+14 days'), mcr_store_tz())]);
        mcr_render_promo_form($edit === 'new' ? '' : $edit, $c);
        echo '</div>';
        return;
    }
    $states = ['active' => ['En cours', '#13693a', '#d9f7e3'], 'scheduled' => ['Programmée', '#8a5a00', '#fdf0c8'], 'ended' => ['Terminée', '#555', '#eee']];
    $types = ['percent' => 'Rabais en %', 'qty' => 'Prix par quantité', 'bundle' => 'Kit'];
    echo '<p style="max-width:780px">Créez une promotion en quelques secondes : un rabais en % sur une catégorie ou des produits (les prix changent seuls au début et reviennent seuls à la fin), une offre « 2 pour 5 $ », ou un kit de produits à prix fixe. Les promotions apparaissent sur la page <a href="' . esc_url(mcr_promotions_url()) . '" target="_blank">Promotions</a>, sur l’accueil et sur les fiches produits.</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Promotion</th><th>Type</th><th>Offre</th><th>Ciblage</th><th>Dates</th><th>État</th><th></th></tr></thead><tbody>';
    if (!$all) {
        echo '<tr><td colspan="7">Aucune promotion pour le moment. <a href="' . esc_url(mcr_promos_admin_url(['edit' => 'new'])) . '">Créer la première</a>.</td></tr>';
    }
    uasort($all, fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
    foreach ($all as $id => $c) {
        if (!empty($c['deleted'])) {
            continue;
        }
        $st = $states[mcr_promo_state($c)];
        $target = [];
        foreach ((array) $c['cats'] as $cid) {
            $t = get_term($cid, 'product_cat');
            if ($t && !is_wp_error($t)) {
                $target[] = $t->name;
            }
        }
        if ($c['products']) {
            $target[] = count($c['products']) . ' produit' . (count($c['products']) > 1 ? 's' : '');
        }
        $job = $c['job'] ?? null;
        $progress = $job && empty($job['finished']) ? sprintf(' · mise à jour %d/%d', $job['done'], $job['total']) : (($c['type'] === 'percent' && isset($c['applied'])) ? sprintf(' · %d produit%s en promo', $c['applied'], $c['applied'] > 1 ? 's' : '') : '');
        printf(
            '<tr><td><strong><a href="%s">%s</a></strong>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td><span style="padding:2px 8px;border-radius:99px;background:%s;color:%s;font-weight:600">%s</span><br><small>%s</small></td><td><a href="%s">Modifier</a> · <a href="%s" onclick="return confirm(\'Supprimer cette promotion ? Les prix d’origine seront remis.\')" style="color:#b32d2e">Supprimer</a></td></tr>',
            esc_url(mcr_promos_admin_url(['edit' => $id])),
            esc_html(mcr_promo_title($c, false)),
            !empty($c['banner']) ? '<br><small>Bannière affichée</small>' : '',
            esc_html($types[$c['type']] ?? ''),
            esc_html(mcr_promo_label($c, false)),
            esc_html(implode(', ', $target)),
            esc_html(($c['start'] ? wp_date('j M Y', mcr_promo_range($c)[0], mcr_store_tz()) : 'Dès maintenant') . ' → ' . ($c['end'] ? wp_date('j M Y', mcr_promo_range($c)[1], mcr_store_tz()) : 'sans fin')),
            esc_attr($st[2]),
            esc_attr($st[1]),
            esc_html($st[0]),
            esc_html(ltrim($progress, ' ·')),
            esc_url(mcr_promos_admin_url(['edit' => $id])),
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=mcr_promo_delete&id=' . $id), 'mcr_promo_delete_' . $id))
        );
    }
    echo '</tbody></table>';

    // Promo de la semaine
    $week = (int) get_option('mcr_promo_week', 0);
    $wp = $week ? wc_get_product($week) : null;
    echo '<h2 style="margin-top:32px">Promo de la semaine</h2><p>Un produit mis en vedette en grand sur l’accueil et en haut de la page Promotions. Il ne s’affiche que s’il est vraiment en promotion (ou dans une offre) et en stock.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
    wp_nonce_field('mcr_promo_week');
    echo '<input type="hidden" name="action" value="mcr_promo_week">';
    echo '<select class="wc-product-search" name="week" style="width:420px" data-placeholder="Rechercher un produit…" data-action="woocommerce_json_search_products" data-allow_clear="true">' . ($wp ? mcr_promo_product_options([$week]) : '') . '</select>';
    submit_button('Enregistrer', 'secondary', 'submit', false);
    if ($wp) {
        echo $wp->is_on_sale() || mcr_product_deals($wp) ? ' <span style="color:#13693a">✓ affichée</span>' : ' <span style="color:#b32d2e">Ce produit n’est pas en promotion : il n’est pas affiché.</span>';
    }
    echo '</form>';
    echo '<script>jQuery(function($){$(document.body).trigger("wc-enhanced-select-init");});</script>';
}

function mcr_render_promo_form(string $id, array $c): void
{
    $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
    $byParent = [];
    foreach ((array) $cats as $t) {
        $byParent[$t->parent][] = $t;
    }
    $opts = '';
    $walk = function ($parent, $depth) use (&$walk, $byParent, $c, &$opts) {
        foreach ($byParent[$parent] ?? [] as $t) {
            if (in_array($t->slug, ['uncategorized', 'non-classe'], true)) {
                continue;
            }
            $opts .= sprintf('<option value="%d"%s>%s%s (%d)</option>', $t->term_id, in_array($t->term_id, (array) ($c['cats'] ?? []), true) ? ' selected' : '', str_repeat('— ', $depth), esc_html($t->name), $t->count);
            $walk($t->term_id, $depth + 1);
        }
    };
    $walk(0, 0);
    $type = $c['type'] ?? 'percent';
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcr-promo-form" style="max-width:820px">
      <?php wp_nonce_field('mcr_promo_save'); ?>
      <input type="hidden" name="action" value="mcr_promo_save"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
      <h2><?php echo $id ? 'Modifier la promotion' : 'Nouvelle promotion'; ?></h2>
      <table class="form-table" role="presentation">
        <tr><th>Type d’offre</th><td>
          <?php foreach (['percent' => ['Rabais en %', 'ex. -15 % sur les crochets. Les prix changent sur tout le site.'], 'qty' => ['Prix par quantité', 'ex. 2 pour 5 $. Appliqué au panier.'], 'bundle' => ['Kit', 'ex. Kit fufu à 24,99 $ (plusieurs produits ensemble). Appliqué au panier.']] as $k => [$l, $h]) : ?>
            <label style="display:block;margin-bottom:6px"><input type="radio" name="type" value="<?php echo esc_attr($k); ?>" <?php checked($type, $k); ?>> <strong><?php echo esc_html($l); ?></strong> <span class="description"><?php echo esc_html($h); ?></span></label>
          <?php endforeach; ?>
        </td></tr>
        <tr><th><label for="t_fr">Nom</label></th><td><input id="t_fr" class="regular-text" name="title_fr" value="<?php echo esc_attr($c['title_fr'] ?? ''); ?>" placeholder="ex. Semaine des crochets" required> <input class="regular-text" name="title_en" value="<?php echo esc_attr($c['title_en'] ?? ''); ?>" placeholder="English (optional)"></td></tr>
        <tr><th><label for="x_fr">Phrase d’accroche</label></th><td><input id="x_fr" class="large-text" name="text_fr" value="<?php echo esc_attr($c['text_fr'] ?? ''); ?>" placeholder="ex. Tous les crochets à -15 % pour les fêtes"><br><input class="large-text" name="text_en" value="<?php echo esc_attr($c['text_en'] ?? ''); ?>" placeholder="English (optional)" style="margin-top:6px"></td></tr>
        <tr data-for="percent"><th><label for="pc">Rabais</label></th><td><input id="pc" type="number" min="1" max="90" name="percent" value="<?php echo esc_attr((string) ($c['percent'] ?? 15)); ?>" class="small-text"> %
          <p><label><input type="checkbox" name="skip_existing" value="1" <?php checked(!empty($c['skip_existing'])); ?>> Ne pas toucher les produits qui ont déjà leur propre promotion</label></p></td></tr>
        <tr data-for="qty"><th>Offre</th><td><input type="number" min="2" max="20" name="qty" value="<?php echo esc_attr((string) ($c['qty'] ?? 2)); ?>" class="small-text"> pour <input name="price" value="<?php echo esc_attr((string) ($c['price'] ?? '')); ?>" class="small-text" inputmode="decimal" data-price> $ <span class="description">par produit (ex. 2 boîtes du même produit pour 5 $)</span></td></tr>
        <tr data-for="bundle"><th>Prix du kit</th><td><input name="price" value="<?php echo esc_attr((string) ($c['price'] ?? '')); ?>" class="small-text" inputmode="decimal" data-price> $ <span class="description">pour un exemplaire de chaque produit du kit</span></td></tr>
        <tr data-for="percent qty"><th><label for="cats">Catégories</label></th><td><select id="cats" name="cats[]" multiple class="wc-enhanced-select" style="width:100%" data-placeholder="Toutes les catégories choisies (et leurs sous-catégories)"><?php echo $opts; // phpcs:ignore ?></select></td></tr>
        <tr><th><label for="prods">Produits</label></th><td><select id="prods" class="wc-product-search" multiple name="products[]" style="width:100%" data-placeholder="Rechercher des produits…" data-action="woocommerce_json_search_products"><?php echo mcr_promo_product_options((array) ($c['products'] ?? [])); // phpcs:ignore ?></select>
          <p class="description" data-for="percent qty">En plus des catégories (facultatif).</p><p class="description" data-for="bundle">Les produits du kit (au moins deux).</p></td></tr>
        <tr><th>Dates</th><td>du <input type="date" name="start" value="<?php echo esc_attr($c['start'] ?? ''); ?>"> au <input type="date" name="end" value="<?php echo esc_attr($c['end'] ?? ''); ?>">
          <p class="description">Le premier jour à 0 h jusqu’au dernier jour à 23 h 59. Les prix d’origine reviennent seuls à la fin. Un compte à rebours s’affiche seulement quand il y a une date de fin.</p></td></tr>
        <tr><th>Mise en avant</th><td><label><input type="checkbox" name="banner" value="1" <?php checked(!empty($c['banner'])); ?>> Afficher une bannière sur l’accueil et la page Promotions</label></td></tr>
      </table>
      <?php submit_button($id ? 'Enregistrer' : 'Créer la promotion'); ?>
      <a href="<?php echo esc_url(mcr_promos_admin_url()); ?>">Annuler</a>
    </form>
    <script>
    jQuery(function ($) {
      var $f = $('.mcr-promo-form');
      function sync() {
        var t = $f.find('[name=type]:checked').val();
        $f.find('[data-for]').each(function () { var on = (' ' + this.getAttribute('data-for') + ' ').indexOf(' ' + t + ' ') > -1; $(this).toggle(on).find('input,select').prop('disabled', !on); });
      }
      $f.on('change', '[name=type]', sync); sync();
      $(document.body).trigger('wc-enhanced-select-init');
    });
    </script>
    <?php
}

function mcr_render_promo_flyer(): void
{
    $file = MCR_DIR . 'includes/promo-flyer.php';
    if (is_readable($file)) {
        require $file;
    }
}
