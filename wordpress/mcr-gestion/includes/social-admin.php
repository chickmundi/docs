<?php
/**
 * MCR Gestion > Publications Facebook: the post of the day (image + text) ready to share or published
 * automatically, the plan of the week, the history, and the Facebook Page connection.
 */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Publications Facebook', 'Publications Facebook', 'mcr_manage_promos', 'mcr-social', 'mcr_render_social_page');
}, 23);

add_action('admin_enqueue_scripts', function ($hook) {
    if (str_contains((string) $hook, 'mcr-social')) {
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
    }
});

function mcr_social_admin_url(array $args = []): string
{
    return add_query_arg($args, admin_url('admin.php?page=mcr-social'));
}

/** Valid date (today … +6 days, or a past one in the history), else today. */
function mcr_social_admin_date(string $d): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : mcr_social_today();
}

add_action('admin_post_mcr_social', function () {
    if (!current_user_can('mcr_manage_promos')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_social');
    $in = wp_unslash($_POST);
    $do = sanitize_key($in['do'] ?? '');
    $date = mcr_social_admin_date((string) ($in['date'] ?? ''));
    $msg = ['success', 'Enregistré.'];
    $res = null;
    switch ($do) {
        case 'make':
            $res = mcr_social_make($date);
            $msg = ['success', 'Publication préparée.'];
            break;
        case 'type':
            $res = mcr_social_make($date, ['type' => sanitize_key($in['type'] ?? 'theme'), 'cat' => sanitize_title($in['cat'] ?? ''), 'hero' => absint($in['hero'] ?? 0)]);
            $msg = ['success', 'Publication préparée.'];
            break;
        case 'other':
            $res = mcr_social_make($date, ['other' => true]);
            $msg = ['success', 'Nouvelle sélection de produits.'];
            break;
        case 'products':
            $res = mcr_social_make($date, ['products' => (array) ($in['products'] ?? [])]);
            $msg = ['success', 'Image refaite avec vos produits.'];
            break;
        case 'rewrite':
            $res = mcr_social_rewrite($date);
            $msg = ['success', 'Texte réécrit par Claude.'];
            break;
        case 'caption':
            $post = mcr_social_posts()[$date] ?? null;
            if ($post) {
                $post['caption'] = sanitize_textarea_field($in['caption'] ?? '');
                $post['caption_by'] = 'modifié';
                mcr_social_save_post($post);
            }
            break;
        case 'done':
            $post = mcr_social_posts()[$date] ?? null;
            if ($post) {
                $post['status'] = 'posted';
                $post['published'] = time();
                mcr_social_save_post($post);
            }
            $msg = ['success', 'Marquée comme publiée.'];
            break;
        case 'publish':
            $res = mcr_social_publish($date);
            $msg = ['success', 'Publiée sur Facebook.'];
            break;
        case 'settings':
            $s = mcr_social_settings();
            $s['auto'] = !empty($in['auto']);
            $s['time'] = preg_match('/^\d{2}:\d{2}$/', (string) ($in['time'] ?? '')) ? $in['time'] : '11:30';
            $s['hashtags'] = sanitize_text_field($in['hashtags'] ?? '');
            $s['days_off'] = array_values(array_map('intval', (array) ($in['days_off'] ?? [])));
            $s['page_id'] = preg_replace('/[^0-9]/', '', (string) ($in['page_id'] ?? ''));
            $token = trim((string) ($in['token'] ?? ''));
            if ($token !== '') {
                update_option('mcr_social_fb_token', $token, false);
            }
            if (!empty($in['forget'])) {
                delete_option('mcr_social_fb_token');
                $s['page_name'] = '';
                $s['auto'] = false;
            }
            if ($s['page_id'] !== '' && mcr_social_token() !== '') {
                $name = mcr_social_check_page($s['page_id'], mcr_social_token());
                if (is_wp_error($name)) {
                    $s['page_name'] = '';
                    $s['auto'] = false;
                    $msg = ['error', 'Connexion Facebook refusée : ' . $name->get_error_message()];
                } else {
                    $s['page_name'] = $name;
                    $msg = ['success', 'Page « ' . $name . ' » connectée.'];
                }
            }
            update_option('mcr_social_settings', $s, false);
            break;
    }
    if (is_wp_error($res)) {
        $msg = ['error', $res->get_error_message()];
    }
    set_transient('mcr_social_notice', $msg, 120);
    wp_safe_redirect(mcr_social_admin_url(['d' => $date]));
    exit;
});

function mcr_social_form(string $do, string $date, string $label, string $class = 'button', array $extra = []): string
{
    $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field('mcr_social', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="mcr_social"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="date" value="' . esc_attr($date) . '">';
    foreach ($extra as $k => $v) {
        $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
    return $h . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
}

function mcr_render_social_page(): void
{
    $s = mcr_social_settings();
    $notice = get_transient('mcr_social_notice');
    delete_transient('mcr_social_notice');
    $tz = mcr_store_tz();
    $today = mcr_social_today();
    $date = mcr_social_admin_date(sanitize_text_field(wp_unslash($_GET['d'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification
    $posts = mcr_social_posts();
    $post = $posts[$date] ?? null;
    $connected = $s['page_id'] !== '' && mcr_social_token() !== '' && $s['page_name'] !== '';
    $themes = mcr_social_themes();
    $status = ['ready' => ['Prête', '#8a5a00', '#fdf0c8'], 'posted' => ['Publiée (à la main)', '#13693a', '#d9f7e3'], 'published' => ['Publiée sur Facebook', '#13693a', '#d9f7e3'], 'error' => ['Erreur', '#b32d2e', '#fde2e1']];
    ?>
<div class="wrap mcr-social">
  <h1>Publications Facebook</h1>
  <?php if (is_array($notice)) : ?><div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div><?php endif; ?>
  <p style="max-width:820px">Chaque matin à 6 h, une publication est préparée avec de vrais produits de la boutique : une image aux couleurs du marché et un texte avec les prix exacts et le lien vers la boutique.
    <?php echo $s['auto'] && $connected ? '<strong>Publication automatique activée à ' . esc_html(str_replace(':', ' h ', $s['time'])) . '.</strong>' : 'Copiez le texte, téléchargez l’image et publiez-la sur votre page, ou connectez la page pour publier automatiquement (en bas).'; ?></p>

  <nav class="mcr-social__days">
    <?php for ($i = 0; $i < 7; $i++) :
        $d = wp_date('Y-m-d', strtotime("+$i day", (new DateTimeImmutable($today, $tz))->getTimestamp()), $tz);
        $th = $themes[(int) wp_date('N', (new DateTimeImmutable($d, $tz))->getTimestamp(), $tz)];
        $pp = $posts[$d] ?? null;
        ?>
      <a href="<?php echo esc_url(mcr_social_admin_url(['d' => $d])); ?>" class="<?php echo $d === $date ? 'is-on' : ''; ?>">
        <b><?php echo esc_html($i === 0 ? 'Aujourd’hui' : ($i === 1 ? 'Demain' : ucfirst(wp_date('l j', (new DateTimeImmutable($d, $tz))->getTimestamp(), $tz)))); ?></b>
        <span><?php echo esc_html($pp ? $pp['title'] : $th[1]); ?></span>
        <em><?php echo $pp ? esc_html($status[$pp['status']][0] ?? '') : 'À préparer'; ?></em>
      </a>
    <?php endfor; ?>
  </nav>

  <?php if (!$post) : ?>
    <div class="mcr-social__empty">
      <p><strong><?php echo esc_html(ucfirst(wp_date('l j F', (new DateTimeImmutable($date, $tz))->getTimestamp(), $tz))); ?></strong> : <?php echo esc_html($themes[(int) wp_date('N', (new DateTimeImmutable($date, $tz))->getTimestamp(), $tz)][1]); ?> (ou la promotion à venir s’il y en a une).</p>
      <?php echo mcr_social_form('make', $date, 'Préparer la publication', 'button button-primary button-hero'); // phpcs:ignore ?>
      <p class="description">Quelques secondes : choix des produits, image, texte.</p>
    </div>
  <?php else : $st = $status[$post['status']] ?? $status['ready']; ?>
    <div class="mcr-social__post">
      <div class="mcr-social__img">
        <img src="<?php echo esc_url($post['image_url']); ?>" alt="">
        <p>
          <a class="button button-primary" href="<?php echo esc_url($post['image_url']); ?>" download="marche-chemin-du-roy-<?php echo esc_attr($date); ?>.jpg">Télécharger l’image</a>
          <button class="button" type="button" data-share>Partager…</button>
        </p>
      </div>
      <div class="mcr-social__text">
        <p><span class="mcr-pill" style="background:<?php echo esc_attr($st[2]); ?>;color:<?php echo esc_attr($st[1]); ?>"><?php echo esc_html($st[0]); ?></span>
          <strong><?php echo esc_html($post['title']); ?></strong> · texte : <?php echo esc_html($post['caption_by']); ?>
          <?php if (!empty($post['fb_id'])) : ?> · <a href="https://www.facebook.com/<?php echo esc_attr($post['fb_id']); ?>" target="_blank" rel="noopener">voir sur Facebook</a><?php endif; ?></p>
        <?php if (!empty($post['error'])) : ?><p style="color:#b32d2e">Facebook : <?php echo esc_html($post['error']); ?></p><?php endif; ?>
        <?php if (!empty($post['note']) && $post['caption_by'] === 'modèle') : ?><p class="description"><?php echo esc_html($post['note']); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('mcr_social'); ?><input type="hidden" name="action" value="mcr_social"><input type="hidden" name="do" value="caption"><input type="hidden" name="date" value="<?php echo esc_attr($date); ?>">
          <textarea name="caption" rows="14" class="large-text" data-caption><?php echo esc_textarea($post['caption']); ?></textarea>
          <p><button class="button button-primary" type="button" data-copy>Copier le texte</button> <button class="button">Enregistrer le texte</button></p>
        </form>
        <p class="mcr-social__acts">
          <?php echo mcr_social_form('rewrite', $date, 'Réécrire le texte (Claude)'); // phpcs:ignore ?>
          <?php echo mcr_social_form('other', $date, 'Autres produits'); // phpcs:ignore ?>
          <?php if ($post['status'] !== 'published') : ?>
            <?php if ($connected) : ?><?php echo mcr_social_form('publish', $date, 'Publier maintenant sur Facebook', 'button button-primary'); // phpcs:ignore ?><?php endif; ?>
            <?php echo mcr_social_form('done', $date, 'Marquer comme publiée'); // phpcs:ignore ?>
          <?php endif; ?>
        </p>
        <details>
          <summary>Choisir les produits moi-même</summary>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
            <?php wp_nonce_field('mcr_social'); ?><input type="hidden" name="action" value="mcr_social"><input type="hidden" name="do" value="products"><input type="hidden" name="date" value="<?php echo esc_attr($date); ?>">
            <select class="wc-product-search" multiple name="products[]" style="width:100%" data-placeholder="1 produit (grande photo) ou jusqu’à 4 produits" data-action="woocommerce_json_search_products"><?php echo function_exists('mcr_promo_product_options') ? mcr_promo_product_options((array) $post['products']) : ''; // phpcs:ignore ?></select>
            <p><button class="button">Refaire l’image</button></p>
          </form>
        </details>
      </div>
    </div>
  <?php endif; ?>

  <?php $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0]); ?>
  <h2 style="margin-top:34px">Créer une autre sorte de publication</h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcr-social__type" style="max-width:820px">
    <?php wp_nonce_field('mcr_social'); ?><input type="hidden" name="action" value="mcr_social"><input type="hidden" name="do" value="type"><input type="hidden" name="date" value="<?php echo esc_attr($date); ?>">
    <p><label><input type="radio" name="type" value="related" checked> <strong>Souvent achetés ensemble</strong> : un produit et ceux que les clients achètent avec lui (vraies commandes)</label></p>
    <p style="margin-left:24px"><select class="wc-product-search" name="hero" style="width:420px" data-placeholder="Produit vedette (vide : choisi automatiquement)" data-action="woocommerce_json_search_products" data-allow_clear="true"></select></p>
    <p><label><input type="radio" name="type" value="category"> <strong>Un rayon</strong> : 4 produits d’une même catégorie</label>
      <select name="cat" style="margin-left:8px"><?php foreach ((array) $cats as $t) : if (in_array($t->slug, ['tout', 'uncategorized', 'non-classe'], true)) { continue; } ?><option value="<?php echo esc_attr($t->slug); ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; ?></select></p>
    <p><label><input type="radio" name="type" value="theme"> <strong>Thème du jour</strong> (selon le jour de la semaine)</label></p>
    <p><button class="button button-primary"><?php echo $post ? 'Remplacer la publication de ce jour' : 'Créer la publication'; ?></button></p>
  </form>

  <h2 style="margin-top:34px">Thèmes de la semaine</h2>
  <p class="description">Si une promotion avec bannière commence dans les 3 jours (ou se termine dans les 2 jours), elle remplace le thème du jour.</p>
  <table class="widefat striped" style="max-width:820px"><tbody>
    <?php foreach (['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'] as $i => $day) : $th = $themes[$i + 1]; ?>
      <tr><td style="width:110px"><strong><?php echo esc_html($day); ?></strong></td><td><?php echo esc_html($th[1]); ?></td><td><?php echo esc_html($th[3] === 'single' ? '1 produit en vedette' : '4 produits'); ?></td><td><?php echo in_array($i + 1, array_map('intval', (array) $s['days_off']), true) ? '<em>pas de publication</em>' : ''; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>

  <?php $hist = array_filter($posts, fn($p, $d) => $d < $today, ARRAY_FILTER_USE_BOTH); if ($hist) : ?>
    <h2 style="margin-top:34px">Historique</h2>
    <table class="widefat striped" style="max-width:820px"><thead><tr><th>Jour</th><th>Thème</th><th>Produits</th><th>État</th></tr></thead><tbody>
      <?php foreach (array_slice($hist, 0, 14, true) as $d => $p) : ?>
        <tr><td><a href="<?php echo esc_url(mcr_social_admin_url(['d' => $d])); ?>"><?php echo esc_html(wp_date('j M', (new DateTimeImmutable($d, $tz))->getTimestamp(), $tz)); ?></a></td><td><?php echo esc_html($p['title']); ?></td><td><?php echo esc_html(implode(', ', array_map(fn($id) => ($x = wc_get_product($id)) ? $x->get_name() : '', array_slice((array) $p['products'], 0, 4)))); ?></td><td><?php echo esc_html($status[$p['status']][0] ?? ''); ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <h2 style="margin-top:34px">Réglages</h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:820px">
    <?php wp_nonce_field('mcr_social'); ?><input type="hidden" name="action" value="mcr_social"><input type="hidden" name="do" value="settings"><input type="hidden" name="date" value="<?php echo esc_attr($date); ?>">
    <table class="form-table" role="presentation">
      <tr><th>Mots-clics</th><td><input class="large-text" name="hashtags" value="<?php echo esc_attr($s['hashtags']); ?>"><p class="description">Ajoutés à la fin de chaque texte.</p></td></tr>
      <tr><th>Jours sans publication</th><td><?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $i => $l) : ?><label style="margin-right:12px"><input type="checkbox" name="days_off[]" value="<?php echo $i + 1; ?>" <?php checked(in_array($i + 1, array_map('intval', (array) $s['days_off']), true)); ?>> <?php echo esc_html($l); ?></label><?php endforeach; ?></td></tr>
      <tr><th>Page Facebook</th><td>
        <?php if ($connected) : ?><p><span class="mcr-pill" style="background:#d9f7e3;color:#13693a">Connectée</span> <strong><?php echo esc_html($s['page_name']); ?></strong> (ID <?php echo esc_html($s['page_id']); ?>)</p><?php endif; ?>
        <p><label>ID de la page<br><input name="page_id" value="<?php echo esc_attr($s['page_id']); ?>" inputmode="numeric" class="regular-text" placeholder="ex. 1234567890"></label></p>
        <p><label>Jeton d’accès de la page <?php echo mcr_social_token() !== '' ? '<em>(enregistré : laissez vide pour le garder)</em>' : ''; ?><br><input type="password" name="token" value="" class="large-text" autocomplete="off" placeholder="EAAB…"></label></p>
        <?php if (mcr_social_token() !== '') : ?><p><label><input type="checkbox" name="forget" value="1"> Déconnecter la page (effacer le jeton)</label></p><?php endif; ?>
        <p class="description">À faire une fois, par l’administrateur de la page : créer une application sur developers.facebook.com, puis générer un <em>jeton de page longue durée</em> avec les autorisations <code>pages_manage_posts</code> et <code>pages_read_engagement</code>. Le jeton reste sur votre site et n’est jamais affiché.</p>
      </td></tr>
      <tr><th>Publication automatique</th><td><label><input type="checkbox" name="auto" value="1" <?php checked($s['auto'] && $connected); ?> <?php disabled(!$connected); ?>> Publier la publication du jour sur la page à</label> <input type="time" name="time" value="<?php echo esc_attr($s['time']); ?>">
        <?php if (!$connected) : ?><p class="description">Connectez d’abord la page Facebook.</p><?php endif; ?></td></tr>
    </table>
    <?php submit_button('Enregistrer les réglages'); ?>
  </form>
</div>
<style>
.mcr-social__days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;max-width:1100px;margin:18px 0}
.mcr-social__days a{display:grid;gap:2px;padding:10px 12px;border:1px solid #dcdcde;border-radius:10px;background:#fff;text-decoration:none;color:#1d2327}
.mcr-social__days a.is-on{border-color:#174b35;box-shadow:0 0 0 2px #174b35}
.mcr-social__days span{font-size:12px;color:#50575e}.mcr-social__days em{font-size:11px;color:#8a5a00;font-style:normal}
.mcr-social__post{display:grid;grid-template-columns:minmax(260px,420px) minmax(0,1fr);gap:28px;max-width:1100px;align-items:start}
.mcr-social__img img{width:100%;border-radius:12px;border:1px solid #dcdcde;display:block}
.mcr-social__acts form{margin:0 6px 6px 0}
.mcr-social__empty{max-width:820px;padding:24px;border:1px dashed #c3c4c7;border-radius:12px;background:#fff}
.mcr-pill{display:inline-block;padding:2px 10px;border-radius:99px;font-weight:600;font-size:12px;margin-right:6px}
@media (max-width:900px){.mcr-social__post{grid-template-columns:1fr}.mcr-social__days{grid-template-columns:repeat(4,minmax(0,1fr))}}
</style>
<script>
jQuery(function ($) {
  $(document.body).trigger('wc-enhanced-select-init');
  $('[data-copy]').on('click', function () {
    var t = $('[data-caption]').val(), b = $(this);
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { b.text('Texte copié ✓'); setTimeout(function () { b.text('Copier le texte'); }, 2000); }, function () { $('[data-caption]').trigger('select'); document.execCommand('copy'); b.text('Texte copié ✓'); });
  });
  $('[data-share]').on('click', function () {
    var src = $('.mcr-social__img img').attr('src'), text = $('[data-caption]').val();
    if (!navigator.share) { alert('Sur téléphone, ce bouton ouvre le partage (Facebook, Messenger…). Sur ordinateur : téléchargez l’image puis collez le texte.'); return; }
    fetch(src).then(function (r) { return r.blob(); }).then(function (blob) {
      var f = new File([blob], 'publication.jpg', { type: 'image/jpeg' });
      var data = navigator.canShare && navigator.canShare({ files: [f] }) ? { files: [f], text: text } : { text: text, url: src };
      if (navigator.clipboard) navigator.clipboard.writeText(text).catch(function () {});
      return navigator.share(data);
    }).catch(function () {});
  });
});
</script>
    <?php
}
