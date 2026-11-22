<?php
/**
 * "Rejoignez notre groupe WhatsApp": a small window inviting visitors to receive news about new
 * arrivals. It appears on any page, at a random moment, only after the homepage promotions
 * pop-up (or after a few pages if the visitor never saw it). Visitors are told plainly that
 * messages come only once in a while.
 *
 * Once a number has been given, the visitor is never asked again (cookie + browser storage,
 * and an account flag for logged-in customers). "Plus tard" hides it for 3 days.
 * Numbers are kept in {prefix}mcr_wa_group with the date of consent; list, export and the group
 * link are in MCR Gestion > Groupe WhatsApp.
 */
defined('ABSPATH') || exit;

const MCR_WAG_DB = 1;
const MCR_WAG_COOKIE = 'mcr_wag';

function mcr_wag_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mcr_wa_group';
}

add_action('init', function () {
    if ((int) get_option('mcr_wag_db') >= MCR_WAG_DB) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . mcr_wag_table() . ' (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  phone varchar(20) NOT NULL,
  name varchar(120) NOT NULL DEFAULT \'\',
  lang varchar(5) NOT NULL DEFAULT \'fr\',
  user_id bigint unsigned NOT NULL DEFAULT 0,
  page varchar(255) NOT NULL DEFAULT \'\',
  consent_at datetime NOT NULL,
  added_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY phone (phone)
) ' . $wpdb->get_charset_collate() . ';');
    update_option('mcr_wag_db', MCR_WAG_DB);
});

function mcr_wag_settings(): array
{
    return wp_parse_args((array) get_option('mcr_wag_settings', []), ['on' => true, 'link' => '']);
}

/** Has this visitor already given a number? */
function mcr_wag_joined(): bool
{
    if (!empty($_COOKIE[MCR_WAG_COOKIE])) {
        return true;
    }
    return is_user_logged_in() && get_user_meta(get_current_user_id(), '_mcr_wa_group', true);
}

/** Digits only, with the country code; North American 10-digit numbers get "1". Empty if invalid. */
function mcr_wag_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (str_starts_with(trim($raw), '00')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10) {
        $d = '1' . $d;
    }
    return strlen($d) >= 11 && strlen($d) <= 15 ? $d : '';
}

function mcr_wag_pretty(string $d): string
{
    return strlen($d) === 11 && $d[0] === '1' ? sprintf('+1 %s-%s-%s', substr($d, 1, 3), substr($d, 4, 3), substr($d, 7)) : '+' . $d;
}

// ---------------------------------------------------------------- Saving a number

add_action('wc_ajax_mcr_wa_join', function () {
    // phpcs:disable WordPress.Security.NonceVerification -- public form: honeypot, consent box and rate limit.
    $en = ($_POST['lang'] ?? '') === 'en';
    if (!mcr_wag_settings()['on'] || !empty($_POST['website'])) {
        wp_send_json_error(['message' => 'off'], 400);
    }
    $ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $key = 'mcr_wag_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= 6) {
        wp_send_json_error(['message' => $en ? 'Too many attempts, please try again later.' : 'Trop d’essais, réessayez plus tard.'], 429);
    }
    set_transient($key, $n + 1, 30 * MINUTE_IN_SECONDS);
    $phone = mcr_wag_phone((string) wp_unslash($_POST['phone'] ?? ''));
    if ($phone === '') {
        wp_send_json_error(['message' => $en ? 'Please enter a valid WhatsApp number, e.g. 819 555-1234.' : 'Entrez un numéro WhatsApp valide, par ex. 819 555-1234.'], 400);
    }
    if (empty($_POST['consent'])) {
        wp_send_json_error(['message' => $en ? 'Please tick the box to agree to receive our messages.' : 'Cochez la case pour accepter de recevoir nos messages.'], 400);
    }
    global $wpdb;
    $name = mb_substr(sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 0, 120);
    $page = mb_substr(esc_url_raw(wp_unslash($_POST['page'] ?? '')), 0, 255);
    $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        'INSERT INTO ' . mcr_wag_table() . ' (phone, name, lang, user_id, page, consent_at) VALUES (%s, %s, %s, %d, %s, %s)
         ON DUPLICATE KEY UPDATE name = IF(VALUES(name) <> \'\', VALUES(name), name), consent_at = VALUES(consent_at)',
        $phone, $name, $en ? 'en' : 'fr', get_current_user_id(), $page, gmdate('Y-m-d H:i:s')
    ));
    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), '_mcr_wa_group', $phone);
    }
    setcookie(MCR_WAG_COOKIE, '1', ['expires' => time() + 2 * YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax']);
    $link = (string) mcr_wag_settings()['link'];
    wp_send_json_success(['link' => $link]);
    // phpcs:enable
});

// ---------------------------------------------------------------- The window (every page except cart, checkout, account)

add_action('wp_footer', function () {
    if (is_admin() || !mcr_wag_settings()['on'] || mcr_wag_joined() || (function_exists('mcr_rw_wheel_on') && mcr_rw_wheel_on()) // the welcome wheel asks for the number instead
        || (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page()))) {
        return;
    }
    $en = mcr_is_en();
    $ic = fn($n, $s = 20) => function_exists('mcr_icon') ? mcr_icon($n, $s) : '';
    ?>
<dialog class="wag" id="wa-group" aria-labelledby="wag-h" data-wa-group data-lang="<?php echo $en ? 'en' : 'fr'; ?>" data-ajax="<?php echo esc_url(WC_AJAX::get_endpoint('mcr_wa_join')); ?>">
  <button type="button" class="wag__x" data-wag-later aria-label="<?php echo esc_attr($en ? 'Close' : 'Fermer'); ?>">×</button>
  <div class="wag__top" aria-hidden="true">
    <span class="wag__ring"></span><span class="wag__ring wag__ring--2"></span>
    <span class="wag__logo"><?php echo $ic('whatsapp', 34); // phpcs:ignore ?></span>
    <span class="wag__bubble wag__bubble--a"><?php echo esc_html($en ? 'New arrivals!' : 'Nouveaux arrivages !'); ?></span>
    <span class="wag__bubble wag__bubble--b"><?php echo esc_html($en ? 'Fresh stock in store' : 'Du frais en magasin'); ?></span>
  </div>
  <div class="wag__body" data-wag-step="form">
    <h2 id="wag-h" tabindex="-1" autofocus><?php echo esc_html($en ? 'Join our WhatsApp group' : 'Rejoignez notre groupe WhatsApp'); ?></h2>
    <p class="wag__lead"><?php echo esc_html($en ? 'Be the first to know when new products arrive in store, plus our best deals.' : 'Soyez les premiers informés de l’arrivée de nouveaux produits en magasin, et de nos meilleures promotions.'); ?></p>
    <p class="wag__promise"><?php echo $ic('circle-check', 18); // phpcs:ignore ?> <span><?php echo esc_html($en ? 'No daily messages: we only write once in a while, when there is something new. You can leave the group at any time.' : 'Pas de messages tous les jours : nous écrivons seulement de temps en temps, quand il y a du nouveau. Vous pouvez quitter le groupe à tout moment.'); ?></span></p>
    <form class="wag__form" novalidate>
      <div class="wag__row">
      <label class="wag__field"><span><?php echo esc_html($en ? 'WhatsApp number' : 'Numéro WhatsApp'); ?></span>
        <input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="819 555-1234" required></label>
      <label class="wag__field"><span><?php echo esc_html($en ? 'First name' : 'Prénom'); ?></span>
        <input type="text" name="name" autocomplete="given-name" maxlength="120"></label>
      </div>
      <label class="wag__check"><input type="checkbox" name="consent" value="1" required> <span><?php echo esc_html($en ? 'I agree to receive WhatsApp messages from Marché Chemin du Roy.' : 'J’accepte de recevoir des messages WhatsApp de Marché Chemin du Roy.'); ?></span></label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" class="wag__hp" aria-hidden="true">
      <p class="wag__err" role="alert" hidden></p>
      <button type="submit" class="btn btn--wa btn--block"><?php echo $ic('whatsapp', 18); // phpcs:ignore ?> <?php echo esc_html($en ? 'Join the group' : 'Rejoindre le groupe'); ?></button>
      <button type="button" class="wag__later" data-wag-later><?php echo esc_html($en ? 'Maybe later' : 'Plus tard'); ?></button>
    </form>
  </div>
  <div class="wag__body wag__done" data-wag-step="done" hidden>
    <span class="wag__tick" aria-hidden="true"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none" stroke="currentColor" stroke-width="3"/><path d="M15 27l7 7 15-16" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    <h2><?php echo esc_html($en ? 'Thank you!' : 'Merci !'); ?></h2>
    <p data-wag-msg-link hidden><?php echo esc_html($en ? 'Tap below to open the group in WhatsApp.' : 'Touchez le bouton ci-dessous pour ouvrir le groupe dans WhatsApp.'); ?></p>
    <p data-wag-msg-wait hidden><?php echo esc_html($en ? 'We will add you to the group very soon. See you on WhatsApp!' : 'Nous vous ajouterons au groupe très bientôt. À bientôt sur WhatsApp !'); ?></p>
    <a class="btn btn--wa btn--block" data-wag-link href="#" target="_blank" rel="noopener" hidden><?php echo $ic('whatsapp', 18); // phpcs:ignore ?> <?php echo esc_html($en ? 'Open the WhatsApp group' : 'Ouvrir le groupe WhatsApp'); ?></a>
    <button type="button" class="wag__later" data-wag-close><?php echo esc_html($en ? 'Close' : 'Fermer'); ?></button>
  </div>
</dialog>
<script id="mcr-wa-group-js">
(function () {
  var d = document.getElementById('wa-group');
  if (!d || typeof d.showModal !== 'function') return;
  var now = Math.floor(Date.now() / 1000);
  var ck = function (n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; };
  var setCk = function (n, v, s) { document.cookie = n + '=' + v + '; path=/; max-age=' + s + '; SameSite=Lax'; };
  var ls = function (k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
  if (ck('mcr_wag') || ls('mcr_wag') || ck('mcr_wag_later')) return; // joined, or "later" in the last 3 days
  // Pages seen in this visit
  var pages = 0; try { pages = +(sessionStorage.getItem('mcr_pages') || 0) + 1; sessionStorage.setItem('mcr_pages', pages); } catch (e) {}
  // Only after the promotions pop-up was shown (at least a minute ago), or after a few pages without it.
  var promoAt = +ck('mcr_pp_seen') || 0;
  var eligible = (promoAt && now - promoAt > 60) || pages >= 3;
  if (!eligible || Math.random() > 0.45) return; // at random: not on every page
  var later = function () { setCk('mcr_wag_later', '1', 3 * 86400); if (d.open) d.close(); };
  d.addEventListener('click', function (e) {
    if (e.target === d || e.target.closest('[data-wag-later]')) { e.preventDefault(); later(); }
    if (e.target.closest('[data-wag-close]')) d.close();
  });
  d.addEventListener('cancel', function () { setCk('mcr_wag_later', '1', 3 * 86400); });
  var show = function (tries) {
    if (document.querySelector('dialog[open]') || document.hidden) { if (tries < 4) setTimeout(function () { show(tries + 1); }, 15000); return; }
    d.showModal();
  };
  setTimeout(function () { show(0); }, (12 + Math.floor(Math.random() * 25)) * 1000);
  var f = d.querySelector('form'), err = d.querySelector('.wag__err'), btn = f.querySelector('[type=submit]');
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    err.hidden = true; btn.disabled = true;
    var body = new URLSearchParams(new FormData(f));
    body.append('lang', d.getAttribute('data-lang'));
    body.append('page', location.href);
    if (!f.consent.checked) body.delete('consent');
    fetch(d.getAttribute('data-ajax'), { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        btn.disabled = false;
        if (!r || !r.success) { err.textContent = (r && r.data && r.data.message) || 'Erreur'; err.hidden = false; return; }
        setCk('mcr_wag', '1', 2 * 365 * 86400); ls('mcr_wag', '1');
        d.querySelector('[data-wag-step=form]').hidden = true;
        d.querySelector('[data-wag-step=done]').hidden = false;
        var a = d.querySelector('[data-wag-link]');
        if (r.data.link) { a.href = r.data.link; a.hidden = false; d.querySelector('[data-wag-msg-link]').hidden = false; }
        else d.querySelector('[data-wag-msg-wait]').hidden = false;
      })
      .catch(function () { btn.disabled = false; err.textContent = (d.getAttribute('data-lang') === 'en' ? 'Network error, please try again.' : 'Erreur réseau, réessayez.'); err.hidden = false; });
  });
})();
</script>
    <?php
}, 0); // before the theme's icon sprite (wp_footer priority 1)

// ---------------------------------------------------------------- Admin: MCR Gestion > Groupe WhatsApp

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Groupe WhatsApp', 'Groupe WhatsApp', 'manage_woocommerce', 'mcr-wa-group', 'mcr_render_wag_page');
}, 30);

add_action('admin_post_mcr_wag', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('', 403);
    }
    check_admin_referer('mcr_wag');
    global $wpdb;
    $do = sanitize_key($_POST['do'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
    if ($do === 'settings') {
        $link = esc_url_raw(trim((string) wp_unslash($_POST['link'] ?? '')));
        if ($link !== '' && !preg_match('#^https://(chat\.whatsapp\.com|wa\.me|whatsapp\.com)/#', $link)) {
            $link = '';
        }
        update_option('mcr_wag_settings', ['on' => !empty($_POST['on']), 'link' => $link], false);
    } elseif ($do === 'added' || $do === 'delete') {
        $id = absint($_POST['id'] ?? 0);
        if ($do === 'added') {
            $wpdb->update(mcr_wag_table(), ['added_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]); // phpcs:ignore
        } else {
            $wpdb->delete(mcr_wag_table(), ['id' => $id]); // phpcs:ignore
        }
    } elseif ($do === 'csv') {
        $rows = $wpdb->get_results('SELECT phone, name, lang, consent_at, added_at FROM ' . mcr_wag_table() . ' ORDER BY id DESC', ARRAY_A); // phpcs:ignore
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=groupe-whatsapp-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Numéro', 'Prénom', 'Langue', 'Consentement (UTC)', 'Ajouté au groupe (UTC)']);
        foreach ($rows as $r) {
            fputcsv($out, ['+' . $r['phone'], $r['name'], $r['lang'], $r['consent_at'], (string) $r['added_at']]);
        }
        exit;
    }
    wp_safe_redirect(admin_url('admin.php?page=mcr-wa-group&saved=1'));
    exit;
});

function mcr_render_wag_page(): void
{
    global $wpdb;
    $s = mcr_wag_settings();
    $rows = $wpdb->get_results('SELECT * FROM ' . mcr_wag_table() . ' ORDER BY id DESC LIMIT 500'); // phpcs:ignore
    $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . mcr_wag_table()); // phpcs:ignore
    $waiting = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . mcr_wag_table() . ' WHERE added_at IS NULL'); // phpcs:ignore
    $form = function (string $do, array $fields = []) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('mcr_wag');
        echo '<input type="hidden" name="action" value="mcr_wag"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
    };
    ?>
<div class="wrap">
  <h1>Groupe WhatsApp « Nouveaux arrivages »</h1>
  <?php if (isset($_GET['saved'])) : // phpcs:ignore ?><div class="notice notice-success is-dismissible"><p>Enregistré.</p></div><?php endif; ?>
  <p>Les visiteurs qui donnent leur numéro (avec leur accord) apparaissent ici. La fenêtre s’affiche au hasard sur le site, après la fenêtre des promotions, et n’est plus jamais proposée à quelqu’un qui a déjà donné son numéro.</p>
  <p><strong><?php echo (int) $total; ?></strong> numéro(s), dont <strong><?php echo (int) $waiting; ?></strong> à ajouter au groupe.</p>

  <h2>Réglages</h2>
  <?php $form('settings'); ?>
    <table class="form-table" role="presentation">
      <tr><th scope="row">Afficher la fenêtre</th><td><label><input type="checkbox" name="on" value="1" <?php checked($s['on']); ?>> Oui, inviter les visiteurs à rejoindre le groupe</label></td></tr>
      <tr><th scope="row"><label for="wag-link">Lien d’invitation du groupe</label></th><td>
        <input id="wag-link" type="url" name="link" class="regular-text" value="<?php echo esc_attr($s['link']); ?>" placeholder="https://chat.whatsapp.com/…">
        <p class="description">Dans WhatsApp : ouvrez le groupe, touchez son nom, puis « Inviter via un lien », et copiez le lien ici.<br>Avec un lien, le client rejoint le groupe lui-même. Sans lien, le numéro est enregistré ici et vous l’ajoutez vous-même.<br>Astuce : réglez le groupe pour que seuls les administrateurs puissent écrire (« Paramètres du groupe »), pour que les membres ne reçoivent que vos annonces.</p>
      </td></tr>
    </table>
    <?php submit_button('Enregistrer'); ?>
  </form>

  <h2>Numéros reçus <?php $form('csv'); ?><button class="button">Exporter (CSV)</button></form></h2>
  <table class="widefat striped">
    <thead><tr><th>Numéro</th><th>Prénom</th><th>Langue</th><th>Date</th><th>Groupe</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows) : ?><tr><td colspan="6">Aucun numéro pour l’instant.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r) : ?>
      <tr>
        <td><a href="<?php echo esc_url('https://wa.me/' . $r->phone); ?>" target="_blank" rel="noopener"><?php echo esc_html(mcr_wag_pretty($r->phone)); ?></a></td>
        <td><?php echo esc_html($r->name); ?></td>
        <td><?php echo esc_html(strtoupper($r->lang)); ?></td>
        <td><?php echo esc_html(wp_date('j M Y H:i', strtotime($r->consent_at . ' UTC'), function_exists('mcr_store_tz') ? mcr_store_tz() : null)); ?></td>
        <td><?php if ($r->added_at) : ?>Ajouté<?php else : $form('added', ['id' => $r->id]); ?><button class="button button-small">Marquer comme ajouté</button></form><?php endif; ?></td>
        <td><?php $form('delete', ['id' => $r->id]); ?><button class="button-link button-link-delete" onclick="return confirm('Supprimer ce numéro ?')">Supprimer</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
    <?php
}
