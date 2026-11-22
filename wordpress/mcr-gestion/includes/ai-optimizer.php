<?php
/**
 * MCR Gestion > Optimiseur IA: Claude suggests better product texts, you approve.
 *
 * For each product it proposes: a clean French title, an English title, a short description in
 * French and English, an image description (alt text) and, from a list of real in-stock products,
 * up to 3 "pour compléter" products (saved as WooCommerce cross-sells). Only facts present in the
 * product data are used. Nothing changes on the site until you tick and apply a suggestion.
 *
 * Calls the Claude Messages API (model claude-opus-5-5) through WordPress's HTTP functions, with
 * structured output (a JSON schema) and server-side refusal fallback.
 * The API key: MCR_ANTHROPIC_API_KEY in wp-config.php, or the field on the page.
 */
defined('ABSPATH') || exit;

const MCR_AI_MODEL = 'claude-opus-5-5';
const MCR_AI_URL = 'https://api.anthropic.com/v1/messages';

function mcr_ai_key(): string
{
    if (defined('MCR_ANTHROPIC_API_KEY') && MCR_ANTHROPIC_API_KEY) {
        return (string) MCR_ANTHROPIC_API_KEY;
    }
    return (string) get_option('mcr_ai_key', '');
}

// ---------------------------------------------------------------- What to send

/** Problems worth fixing on a product, as short labels. */
function mcr_ai_issues(WC_Product $p): array
{
    $issues = [];
    $name = $p->get_name();
    if (preg_match('/\s{2,}|\(\s|\s\)|\s-\S|\S-\s|^\p{Ll}/u', $name) || ($name === mb_strtoupper($name) && mb_strlen($name) > 8)) {
        $issues[] = 'titre';
    }
    if (mb_strlen(trim(wp_strip_all_tags($p->get_short_description()))) < 60) {
        $issues[] = 'description';
    }
    if (trim((string) get_post_meta($p->get_id(), '_mcr_title_en', true)) === '' || trim((string) get_post_meta($p->get_id(), '_mcr_short_description_en', true)) === '') {
        $issues[] = 'anglais';
    }
    $img = $p->get_image_id();
    if ($img && trim((string) get_post_meta($img, '_wp_attachment_image_alt', true)) === '') {
        $issues[] = 'image';
    }
    if (!$p->get_cross_sell_ids()) {
        $issues[] = 'compléments';
    }
    return $issues;
}

/** Real in-stock products that could go with this one (other aisles of the same department). */
function mcr_ai_candidates(WC_Product $p, int $limit = 40): array
{
    $dept = function_exists('mcr_product_department') ? mcr_product_department($p->get_id()) : null;
    $args = ['status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple', 'limit' => $limit * 2, 'orderby' => 'popularity', 'exclude' => [$p->get_id()], 'return' => 'objects'];
    if ($dept) {
        $args['tax_query'] = [['taxonomy' => 'mcr_department', 'field' => 'slug', 'terms' => [$dept]]];
    }
    $own = $p->get_category_ids();
    $out = [];
    foreach (wc_get_products($args) as $c) {
        if (array_intersect($own, $c->get_category_ids()) && count($out) > $limit / 2) {
            continue; // prefer other aisles
        }
        $out[$c->get_id()] = $c->get_name();
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function mcr_ai_product_facts(WC_Product $p): array
{
    $cats = array_map(fn($id) => get_term($id)->name ?? '', $p->get_category_ids());
    $attrs = [];
    foreach ($p->get_attributes() as $a) {
        $attrs[] = wc_attribute_label($a->get_name()) . ': ' . implode(', ', $a->is_taxonomy() ? wc_get_product_terms($p->get_id(), $a->get_name(), ['fields' => 'names']) : $a->get_options());
    }
    return [
        'id' => $p->get_id(),
        'title' => $p->get_name(),
        'short_description' => trim(wp_strip_all_tags($p->get_short_description())),
        'description' => mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($p->get_description()))), 0, 1500),
        'categories' => array_values(array_filter($cats)),
        'tags' => wp_get_post_terms($p->get_id(), 'product_tag', ['fields' => 'names']),
        'unit' => (string) get_post_meta($p->get_id(), '_mcr_unit_label', true),
        'attributes' => $attrs,
        'price_cad' => (float) $p->get_price(),
        'english_title_now' => (string) get_post_meta($p->get_id(), '_mcr_title_en', true),
        'english_short_description_now' => (string) get_post_meta($p->get_id(), '_mcr_short_description_en', true),
    ];
}

function mcr_ai_system_prompt(): string
{
    return <<<TXT
You write product information for Marché Chemin-du-Roy, an African and Québec grocery and beauty store in Trois-Rivières, Québec. Customers are mostly French-speaking Quebecers and African families; many shop on their phone. You will receive one product's current data and a numbered list of other real products in stock.

Write, for this product:
- title_fr: a clean French (Québec) title, at most 70 characters. Keep the brand and the size or weight from the data. Fix spacing, capitals and punctuation; write units as "2 lb", "5 kg", "500 ml", "1 l". Keep proper names and product names that customers search for (gari, attiéké, fufu, egusi...). If the current title is already good, return it unchanged.
- title_en: the same title in natural Canadian English.
- short_fr: one or two sentences (140 to 230 characters) saying what the product is and how it is used, for a customer deciding to buy.
- short_en: the same in natural Canadian English.
- alt_fr: a short French description of the product photo for people who cannot see it (packaging, brand, size), at most 110 characters.
- cross_sells: the ids, from the list, of up to 3 products a customer would genuinely use together with this one (for example the ingredients of the same dish, or a hair product used with an extension). Return an empty array when none is a natural match.

Only use facts present in the data. Do not invent origin, ingredients, weight, certifications or health benefits, and make no medical claims. Do not mention prices or promotions. Plain text only, no emoji, no quotation marks around values.
TXT;
}

function mcr_ai_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'title_fr' => ['type' => 'string'],
            'title_en' => ['type' => 'string'],
            'short_fr' => ['type' => 'string'],
            'short_en' => ['type' => 'string'],
            'alt_fr' => ['type' => 'string'],
            'cross_sells' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
        'required' => ['title_fr', 'title_en', 'short_fr', 'short_en', 'alt_fr', 'cross_sells'],
        'additionalProperties' => false,
    ];
}

/** Body of the Messages API request for one product. */
function mcr_ai_request_body(array $facts, array $candidates): array
{
    $list = '';
    foreach ($candidates as $id => $name) {
        $list .= $id . ': ' . $name . "\n";
    }
    return [
        'model' => MCR_AI_MODEL,
        'max_tokens' => 4000,
        'system' => mcr_ai_system_prompt(),
        'messages' => [[
            'role' => 'user',
            'content' => "Product data (JSON):\n" . wp_json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\nOther products in stock (id: name):\n" . ($list ?: "(none)\n"),
        ]],
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => mcr_ai_schema()]],
        'fallbacks' => 'default',
    ];
}

/**
 * Read an API response: the suggestion array, or a WP_Error with a message for the admin.
 * $candidates limits cross_sells to real products that were offered.
 */
function mcr_ai_parse_response(int $code, string $raw, array $candidates)
{
    $data = json_decode($raw, true);
    if ($code !== 200 || !is_array($data)) {
        $type = $data['error']['type'] ?? '';
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        $human = match ($type) {
            'authentication_error' => 'Clé API refusée : vérifiez la clé Claude.',
            'permission_error' => 'Cette clé n’a pas accès à ce modèle.',
            'billing_error' => 'Crédit insuffisant sur le compte Claude (facturation).',
            'rate_limit_error', 'overloaded_error' => 'Service occupé : réessayez dans une minute.',
            default => 'Erreur de l’API Claude : ' . $msg,
        };
        return new WP_Error('mcr_ai_' . ($type ?: 'http'), $human, ['retry' => in_array($type, ['rate_limit_error', 'overloaded_error'], true) || $code >= 500]);
    }
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return new WP_Error('mcr_ai_refusal', 'Claude n’a pas traité ce produit. Modifiez-le à la main.');
    }
    if (($data['stop_reason'] ?? '') === 'max_tokens') {
        return new WP_Error('mcr_ai_truncated', 'Réponse incomplète : réessayez.', ['retry' => true]);
    }
    $text = '';
    foreach ((array) ($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text = (string) $block['text'];
            break;
        }
    }
    $s = json_decode($text, true);
    if (!is_array($s) || !isset($s['title_fr'], $s['short_fr'])) {
        return new WP_Error('mcr_ai_format', 'Réponse illisible : réessayez.', ['retry' => true]);
    }
    $clean = fn($v, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $v))), 0, $max);
    return [
        'title_fr' => $clean($s['title_fr'], 90),
        'title_en' => $clean($s['title_en'] ?? '', 90),
        'short_fr' => $clean($s['short_fr'], 400),
        'short_en' => $clean($s['short_en'] ?? '', 400),
        'alt_fr' => $clean($s['alt_fr'] ?? '', 140),
        'cross_sells' => array_values(array_slice(array_filter(array_map('intval', (array) ($s['cross_sells'] ?? [])), fn($id) => isset($candidates[$id])), 0, 3)),
        'model' => (string) ($data['model'] ?? MCR_AI_MODEL),
        'usage' => ['in' => (int) ($data['usage']['input_tokens'] ?? 0), 'out' => (int) ($data['usage']['output_tokens'] ?? 0)],
        'at' => time(),
    ];
}

/** Ask Claude for one product; stores the suggestion on the product. */
function mcr_ai_suggest(WC_Product $p)
{
    $key = mcr_ai_key();
    if ($key === '') {
        return new WP_Error('mcr_ai_nokey', 'Ajoutez d’abord votre clé API Claude.');
    }
    $candidates = mcr_ai_candidates($p);
    $res = wp_remote_post(MCR_AI_URL, [
        'timeout' => 90,
        'headers' => [
            'x-api-key' => $key,
            'anthropic-version' => '2026-10-21',
            'anthropic-beta' => 'server-side-fallback-2026-07-01',
            'content-type' => 'application/json',
        ],
        'body' => wp_json_encode(mcr_ai_request_body(mcr_ai_product_facts($p), $candidates)),
    ]);
    if (is_wp_error($res)) {
        return new WP_Error('mcr_ai_network', 'Connexion à Claude impossible : ' . $res->get_error_message(), ['retry' => true]);
    }
    $s = mcr_ai_parse_response((int) wp_remote_retrieve_response_code($res), (string) wp_remote_retrieve_body($res), $candidates);
    if (!is_wp_error($s)) {
        update_post_meta($p->get_id(), '_mcr_ai_suggestion', $s);
    }
    return $s;
}

/** Apply the ticked parts of a suggestion. */
function mcr_ai_apply(WC_Product $p, array $s, array $parts): array
{
    $done = [];
    if (in_array('title_fr', $parts, true) && $s['title_fr'] !== '') {
        $p->set_name($s['title_fr']); // the address (slug) does not change
        $done[] = 'titre';
    }
    if (in_array('short_fr', $parts, true) && $s['short_fr'] !== '') {
        $p->set_short_description($s['short_fr']);
        $done[] = 'description';
    }
    if (in_array('cross_sells', $parts, true) && $s['cross_sells']) {
        $p->set_cross_sell_ids(array_values(array_unique(array_merge($p->get_cross_sell_ids(), $s['cross_sells']))));
        $done[] = 'compléments';
    }
    $p->save();
    if (in_array('title_en', $parts, true) && $s['title_en'] !== '') {
        update_post_meta($p->get_id(), '_mcr_title_en', $s['title_en']);
        $done[] = 'titre anglais';
    }
    if (in_array('short_en', $parts, true) && $s['short_en'] !== '') {
        update_post_meta($p->get_id(), '_mcr_short_description_en', $s['short_en']);
        $done[] = 'description anglaise';
    }
    if (in_array('alt_fr', $parts, true) && $s['alt_fr'] !== '' && $p->get_image_id()) {
        update_post_meta($p->get_image_id(), '_wp_attachment_image_alt', $s['alt_fr']);
        $done[] = 'image';
    }
    $s['applied'] = time();
    update_post_meta($p->get_id(), '_mcr_ai_suggestion', $s);
    return $done;
}

// ---------------------------------------------------------------- Admin

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Optimiseur IA des produits', 'Optimiseur IA', 'manage_woocommerce', 'mcr-ai', 'mcr_render_ai_page');
}, 24);

add_action('admin_post_mcr_ai_key', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Accès refusé');
    }
    check_admin_referer('mcr_ai_key');
    $k = trim(sanitize_text_field(wp_unslash($_POST['key'] ?? '')));
    if (!empty($_POST['remove'])) {
        delete_option('mcr_ai_key');
    } elseif ($k !== '') {
        update_option('mcr_ai_key', $k, false);
    }
    wp_safe_redirect(admin_url('admin.php?page=mcr-ai'));
    exit;
});

// One product at a time from the page (so progress shows and no request times out).
add_action('wp_ajax_mcr_ai_suggest', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Accès refusé'], 403);
    }
    check_ajax_referer('mcr_ai');
    $p = wc_get_product(absint($_POST['id'] ?? 0));
    if (!$p) {
        wp_send_json_error(['message' => 'Produit introuvable']);
    }
    $s = mcr_ai_suggest($p);
    if (is_wp_error($s)) {
        wp_send_json_error(['message' => $s->get_error_message(), 'retry' => !empty($s->get_error_data()['retry'])]);
    }
    wp_send_json_success(['html' => mcr_ai_review_html($p, $s)]);
});

add_action('wp_ajax_mcr_ai_apply', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Accès refusé'], 403);
    }
    check_ajax_referer('mcr_ai');
    $p = wc_get_product(absint($_POST['id'] ?? 0));
    $s = $p ? get_post_meta($p->get_id(), '_mcr_ai_suggestion', true) : null;
    if (!$p || !is_array($s)) {
        wp_send_json_error(['message' => 'Suggestion introuvable']);
    }
    $parts = array_map('sanitize_key', (array) ($_POST['parts'] ?? []));
    $done = mcr_ai_apply($p, $s, $parts);
    wp_send_json_success(['message' => $done ? 'Appliqué : ' . implode(', ', $done) . '.' : 'Rien à appliquer.']);
});

function mcr_ai_review_html(WC_Product $p, array $s): string
{
    $row = function (string $key, string $label, string $now, string $new) {
        $same = trim($now) === trim($new);
        return '<tr><td style="width:150px"><label><input type="checkbox" name="parts[]" value="' . esc_attr($key) . '"' . ($same || $new === '' ? ' disabled' : ' checked') . '> <strong>' . esc_html($label) . '</strong></label></td><td style="color:#646970">' . ($now !== '' ? esc_html($now) : '<em>vide</em>') . '</td><td>' . ($same ? '<em>inchangé</em>' : esc_html($new)) . '</td></tr>';
    };
    $img = $p->get_image_id();
    $cs = array_map(fn($id) => ($c = wc_get_product($id)) ? $c->get_name() : '#' . $id, $s['cross_sells']);
    $nowCs = array_map(fn($id) => ($c = wc_get_product($id)) ? $c->get_name() : '#' . $id, $p->get_cross_sell_ids());
    $h = '<form class="mcr-ai-review" data-id="' . (int) $p->get_id() . '"><table class="widefat" style="margin:8px 0"><thead><tr><th></th><th>Actuel</th><th>Suggestion</th></tr></thead><tbody>';
    $h .= $row('title_fr', 'Titre', $p->get_name(), $s['title_fr']);
    $h .= $row('short_fr', 'Description courte', trim(wp_strip_all_tags($p->get_short_description())), $s['short_fr']);
    $h .= $row('title_en', 'Titre anglais', (string) get_post_meta($p->get_id(), '_mcr_title_en', true), $s['title_en']);
    $h .= $row('short_en', 'Description anglaise', (string) get_post_meta($p->get_id(), '_mcr_short_description_en', true), $s['short_en']);
    if ($img) {
        $h .= $row('alt_fr', 'Description de l’image', (string) get_post_meta($img, '_wp_attachment_image_alt', true), $s['alt_fr']);
    }
    $h .= $row('cross_sells', 'Pour compléter', implode(', ', $nowCs), implode(', ', $cs));
    $h .= '</tbody></table><button type="submit" class="button button-primary">Appliquer la sélection</button> <span class="mcr-ai-msg"></span></form>';
    return $h;
}

function mcr_render_ai_page(): void
{
    $key = mcr_ai_key();
    $filter = sanitize_key($_GET['f'] ?? 'all'); // phpcs:ignore WordPress.Security.NonceVerification
    $paged = max(1, (int) ($_GET['pg'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
    echo '<div class="wrap"><h1>Optimiseur IA des produits</h1>';
    echo '<p style="max-width:860px">Claude (l’IA d’Anthropic) propose pour chaque produit un titre propre, une description courte en français et en anglais, une description de l’image pour Google et les lecteurs d’écran, et jusqu’à 3 produits « pour compléter » choisis parmi vos produits en stock. <strong>Rien ne change sur le site tant que vous n’avez pas coché et appliqué une suggestion.</strong> Claude n’utilise que les informations déjà présentes sur le produit.</p>';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;max-width:860px;margin:12px 0">';
    if (defined('MCR_ANTHROPIC_API_KEY') && MCR_ANTHROPIC_API_KEY) {
        echo '<p style="margin:0">✅ Clé API Claude définie dans <code>wp-config.php</code>. Modèle : <code>' . esc_html(MCR_AI_MODEL) . '</code>.</p>';
    } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        wp_nonce_field('mcr_ai_key');
        echo '<input type="hidden" name="action" value="mcr_ai_key"><strong>Clé API Claude :</strong> ';
        echo $key ? '<code>…' . esc_html(substr($key, -4)) . '</code> <button class="button" name="remove" value="1">Retirer</button> <input type="password" name="key" class="regular-text" placeholder="Remplacer par une nouvelle clé" autocomplete="off">' : '<input type="password" name="key" class="regular-text" placeholder="sk-ant-…" autocomplete="off">';
        submit_button('Enregistrer', 'secondary', 'submit', false);
        echo '</form><p class="description" style="margin:6px 0 0">Créez une clé sur <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">platform.claude.com</a>. Coût indicatif : environ 1 à 2 ¢ par produit (modèle ' . esc_html(MCR_AI_MODEL) . ').</p>';
    }
    echo '</div>';
    $filters = ['all' => 'Tous à améliorer', 'titre' => 'Titre', 'description' => 'Description', 'anglais' => 'Anglais', 'image' => 'Image', 'compléments' => 'Pour compléter', 'suggested' => 'Suggestions à revoir'];
    echo '<ul class="subsubsub">';
    $links = [];
    foreach ($filters as $k => $l) {
        $links[] = '<li><a href="' . esc_url(admin_url('admin.php?page=mcr-ai&f=' . rawurlencode($k))) . '"' . ($filter === sanitize_key($k) ? ' class="current"' : '') . '>' . esc_html($l) . '</a></li>';
    }
    echo implode(' | ', $links) . '</ul><br class="clear">';

    $ids = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids', 'orderby' => 'popularity']);
    $rows = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if (!$p) {
            continue;
        }
        $s = get_post_meta($id, '_mcr_ai_suggestion', true);
        $pending = is_array($s) && empty($s['applied']);
        $issues = mcr_ai_issues($p);
        if ($filter === 'suggested' ? !$pending : (!$issues || ($filter !== 'all' && !in_array($filter, array_map('sanitize_key', $issues), true)))) {
            continue;
        }
        $rows[] = [$p, $issues, $pending ? $s : null];
    }
    $per = 50;
    $total = count($rows);
    $rows = array_slice($rows, ($paged - 1) * $per, $per);
    echo '<p>' . (int) $total . ' produit(s). Les plus vendus d’abord. Cochez des produits puis « Générer les suggestions » (10 à la fois au plus).</p>';
    echo '<p><button type="button" class="button button-primary" id="mcr-ai-go"' . ($key ? '' : ' disabled') . '>Générer les suggestions</button> <span id="mcr-ai-progress"></span></p>';
    echo '<table class="widefat striped" id="mcr-ai-table"><thead><tr><td class="check-column"><input type="checkbox" id="mcr-ai-all"></td><th>Produit</th><th>À améliorer</th></tr></thead><tbody>';
    foreach ($rows as [$p, $issues, $s]) {
        printf(
            '<tr data-id="%d"><th class="check-column"><input type="checkbox" class="mcr-ai-pick" value="%d"></th><td><a href="%s" target="_blank"><strong>%s</strong></a><div class="mcr-ai-out">%s</div></td><td>%s</td></tr>',
            $p->get_id(), $p->get_id(), esc_url(get_edit_post_link($p->get_id())), esc_html($p->get_name()),
            $s ? mcr_ai_review_html($p, $s) : '', // phpcs:ignore
            implode(' ', array_map(fn($i) => '<span style="display:inline-block;background:#f0f0f1;border-radius:99px;padding:1px 8px;margin:1px;font-size:12px">' . esc_html($i) . '</span>', $issues))
        );
    }
    echo '</tbody></table>';
    if ($total > $per) {
        echo '<p>';
        for ($i = 1; $i <= ceil($total / $per); $i++) {
            echo $i === $paged ? ' <strong>' . (int) $i . '</strong>' : ' <a href="' . esc_url(admin_url('admin.php?page=mcr-ai&f=' . rawurlencode($filter) . '&pg=' . $i)) . '">' . (int) $i . '</a>';
        }
        echo '</p>';
    }
    ?>
<script>
jQuery(function ($) {
  var nonce = <?php echo wp_json_encode(wp_create_nonce('mcr_ai')); ?>;
  $('#mcr-ai-all').on('change', function () { $('.mcr-ai-pick').prop('checked', this.checked); });
  $('#mcr-ai-go').on('click', function () {
    var ids = $('.mcr-ai-pick:checked').map(function () { return this.value; }).get().slice(0, 10);
    if (!ids.length) { $('#mcr-ai-progress').text('Cochez au moins un produit.'); return; }
    var btn = $(this).prop('disabled', true), done = 0;
    (function next() {
      if (!ids.length) { btn.prop('disabled', false); $('#mcr-ai-progress').text('Terminé : ' + done + ' suggestion(s). Vérifiez et appliquez ci-dessous.'); return; }
      var id = ids.shift(), row = $('tr[data-id="' + id + '"]');
      $('#mcr-ai-progress').text('Claude prépare le produit ' + (done + 1) + '…');
      row.find('.mcr-ai-out').html('<em>En cours…</em>');
      $.post(ajaxurl, { action: 'mcr_ai_suggest', _ajax_nonce: nonce, id: id }).done(function (r) {
        if (r.success) { row.find('.mcr-ai-out').html(r.data.html); done++; }
        else { row.find('.mcr-ai-out').html('<span style="color:#b32d2e">' + $('<div>').text(r.data.message).html() + '</span>'); if (/clé|crédit|accès/i.test(r.data.message)) ids = []; }
      }).fail(function () { row.find('.mcr-ai-out').html('<span style="color:#b32d2e">Erreur réseau.</span>'); }).always(next);
    })();
  });
  $(document).on('submit', '.mcr-ai-review', function (e) {
    e.preventDefault();
    var f = $(this), parts = f.find('input[name="parts[]"]:checked').map(function () { return this.value; }).get();
    f.find('button').prop('disabled', true);
    $.post(ajaxurl, { action: 'mcr_ai_apply', _ajax_nonce: nonce, id: f.data('id'), parts: parts }).done(function (r) {
      f.find('.mcr-ai-msg').text(r.success ? '✅ ' + r.data.message : r.data.message);
    }).always(function () { f.find('button').prop('disabled', false); });
  });
});
</script>
    <?php
    echo '</div>';
}
