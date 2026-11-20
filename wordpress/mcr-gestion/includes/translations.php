<?php
/**
 * MCR Gestion > Traductions: the French text next to its English version,
 * for products, categories, pages and the site's own texts, with a
 * "to translate" filter. French stays the main language: English fields
 * left empty show the French text.
 */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Traductions', 'Traductions', 'manage_woocommerce', 'mcr-translate', 'mcr_render_translate_page');
    remove_submenu_page('mcr', 'mcr-english');
}, 26);

function mcr_translate_tabs(): array
{
    return ['products' => 'Produits', 'categories' => 'Catégories', 'pages' => 'Pages', 'strings' => 'Textes du site', 'import' => 'Importer de l’ancien site'];
}

function mcr_translate_url(array $args = []): string
{
    return add_query_arg(array_merge(['page' => 'mcr-translate'], $args), admin_url('admin.php'));
}

/** Translation progress: [translated, total] for products (any English text counts). */
function mcr_translate_progress(): array
{
    global $wpdb;
    $statuses = "'publish','draft','private','pending'";
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ($statuses)");
    $done = (int) $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key IN ('_mcr_title_en','_mcr_short_description_en','_mcr_description_en') AND m.meta_value<>'' WHERE p.post_type='product' AND p.post_status IN ($statuses)");
    return [$done, $total];
}

/** A product has English text when any of its English fields is filled. */
function mcr_has_english(int $id): bool
{
    foreach (['title', 'short_description', 'description'] as $f) {
        if (trim((string) get_post_meta($id, "_mcr_{$f}_en", true)) !== '') {
            return true;
        }
    }
    return false;
}

function mcr_render_translate_page(): void
{
    $tabs = mcr_translate_tabs();
    $tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? sanitize_key($_GET['tab']) : 'products';
    echo '<div class="wrap mcr-tr"><h1>Traductions <small style="font-size:13px;font-weight:400;color:#646970">français → anglais</small></h1>';
    echo '<style>
.mcr-tr .tr-row{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:0 0 10px}
.mcr-tr .tr-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}
.mcr-tr .tr-fr{color:#3c434a;background:#f6f7f7;border-radius:6px;padding:8px 10px;max-height:160px;overflow:auto;font-size:13px}
.mcr-tr .tr-fr p{margin:0 0 .5em}
.mcr-tr .tr-label{font-weight:600;margin:10px 0 4px;display:block}
.mcr-tr input.tr-in,.mcr-tr textarea{width:100%}
.mcr-tr .badge{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;margin-left:6px;vertical-align:middle}
.mcr-tr .badge--todo{background:#fcf0e3;color:#8a4b00}.mcr-tr .badge--ok{background:#e7f5ea;color:#1d5c2a}
.mcr-tr .tr-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.mcr-tr .tr-head a.name{font-size:14px;font-weight:600;text-decoration:none}
.mcr-tr .bar{height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;width:240px;display:inline-block;vertical-align:middle;margin:0 8px}
.mcr-tr .bar i{display:block;height:100%;background:#4f3be6}
.mcr-tr .tr-save{position:sticky;bottom:0;background:#f0f0f1;padding:10px 0;border-top:1px solid #dcdcde;z-index:5}
@media (max-width:900px){.mcr-tr .tr-grid{grid-template-columns:1fr}}
</style>';
    if ($msg = get_transient('mcr_translate_msg')) {
        delete_transient('mcr_translate_msg');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
    [$done, $total] = mcr_translate_progress();
    $pct = $total ? round($done / $total * 100) : 0;
    printf('<p>Produits traduits : <strong>%d / %d</strong><span class="bar"><i style="width:%d%%"></i></span>%d %%. Un champ anglais vide affiche le texte français.</p>', $done, $total, $pct, $pct);
    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $k => $label) {
        printf('<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url(mcr_translate_url(['tab' => $k])), esc_html($label));
    }
    echo '</nav>';
    switch ($tab) {
        case 'products':
            mcr_translate_products();
            break;
        case 'categories':
            mcr_translate_categories();
            break;
        case 'pages':
            mcr_translate_pages();
            break;
        case 'strings':
            mcr_translate_strings();
            break;
        case 'import':
            if (function_exists('mcr_render_english_page')) {
                mcr_render_english_page();
            }
            break;
    }
    echo '</div>';
}

function mcr_tr_form_open(string $tab): void
{
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mcr_save_translations"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
    echo '<input type="hidden" name="back" value="' . esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) . '">';
    wp_nonce_field('mcr_save_translations');
}

function mcr_tr_form_close(): void
{
    echo '<div class="tr-save"><button class="button button-primary button-large">Enregistrer les traductions</button></div></form>';
}

function mcr_tr_fr_box(string $html): string
{
    $html = trim($html);
    return '<div class="tr-fr">' . ($html !== '' ? wp_kses_post(wpautop($html)) : '<em>(vide)</em>') . '</div>';
}

// ---------------------------------------------------------------- Products

function mcr_translate_products(): void
{
    $filter = ($_GET['filter'] ?? 'todo') === 'all' ? 'all' : 'todo';
    $s = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $args = [
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 20,
        'paged' => $paged,
        'orderby' => 'title',
        'order' => 'ASC',
        's' => $s,
    ];
    if ($filter === 'todo') {
        $args['meta_query'] = [
            'relation' => 'AND',
            ['relation' => 'OR', ['key' => '_mcr_title_en', 'compare' => 'NOT EXISTS'], ['key' => '_mcr_title_en', 'value' => '']],
            ['relation' => 'OR', ['key' => '_mcr_short_description_en', 'compare' => 'NOT EXISTS'], ['key' => '_mcr_short_description_en', 'value' => '']],
            ['relation' => 'OR', ['key' => '_mcr_description_en', 'compare' => 'NOT EXISTS'], ['key' => '_mcr_description_en', 'value' => '']],
        ];
    }
    $q = new WP_Query($args);
    echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="mcr-translate"><input type="hidden" name="tab" value="products">';
    printf('<select name="filter"><option value="todo"%s>À traduire</option><option value="all"%s>Tous les produits</option></select> ', selected($filter, 'todo', false), selected($filter, 'all', false));
    printf('<input type="search" name="s" value="%s" placeholder="Nom du produit"> <button class="button">Filtrer</button>', esc_attr($s));
    printf(' <a class="button" href="%s">Ajouter un produit</a></form>', esc_url(admin_url('post-new.php?post_type=product')));
    if (!$q->have_posts()) {
        echo '<p>' . ($filter === 'todo' ? 'Tous les produits ont un texte anglais.' : 'Aucun produit.') . '</p>';
        return;
    }
    printf('<p>%d produit(s). Page %d sur %d.</p>', $q->found_posts, $paged, $q->max_num_pages);
    mcr_tr_form_open('products');
    foreach ($q->posts as $post) {
        $id = $post->ID;
        $en = fn(string $f) => (string) get_post_meta($id, "_mcr_{$f}_en", true);
        $ok = mcr_has_english($id);
        echo '<div class="tr-row"><div class="tr-head">';
        printf('<a class="name" href="%s">%s</a>', esc_url(get_edit_post_link($id)), esc_html($post->post_title));
        printf('<span class="badge %s">%s</span>', $ok ? 'badge--ok' : 'badge--todo', $ok ? 'traduit' : 'à traduire');
        echo '</div><div class="tr-grid"><div><span class="tr-label">Nom (français)</span><div class="tr-fr">' . esc_html($post->post_title) . '</div></div>';
        printf('<div><label class="tr-label" for="t%1$d">Nom (anglais)</label><input class="tr-in" id="t%1$d" type="text" name="p[%1$d][title]" value="%2$s" placeholder="%3$s"></div></div>', $id, esc_attr($en('title')), esc_attr($post->post_title . ' (même nom si vide)'));
        echo '<details><summary style="cursor:pointer;margin-top:8px">Descriptions</summary>';
        echo '<div class="tr-grid"><div><span class="tr-label">Description courte (français)</span>' . mcr_tr_fr_box($post->post_excerpt) . '</div>';
        printf('<div><span class="tr-label">Description courte (anglais)</span><textarea name="p[%d][short_description]" rows="4">%s</textarea></div></div>', $id, esc_textarea($en('short_description')));
        echo '<div class="tr-grid"><div><span class="tr-label">Description (français)</span>' . mcr_tr_fr_box($post->post_content) . '</div>';
        printf('<div><span class="tr-label">Description (anglais)</span><textarea name="p[%d][description]" rows="6">%s</textarea></div></div>', $id, esc_textarea($en('description')));
        echo '</details></div>';
    }
    mcr_tr_form_close();
    echo '<p>' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages]) . '</p>';
}

// ---------------------------------------------------------------- Categories

function mcr_translate_categories(): void
{
    $terms = get_terms(['taxonomy' => ['product_cat', 'mcr_department'], 'hide_empty' => false, 'orderby' => 'name']);
    printf('<p style="margin:12px 0"><a class="button" href="%s">Ajouter une catégorie</a></p>', esc_url(admin_url('edit-tags.php?taxonomy=product_cat&post_type=product')));
    mcr_tr_form_open('categories');
    foreach ($terms as $t) {
        $name = (string) get_term_meta($t->term_id, 'mcr_name_en', true);
        $desc = (string) get_term_meta($t->term_id, 'mcr_description_en', true);
        echo '<div class="tr-row"><div class="tr-head">';
        printf('<a class="name" href="%s">%s</a> <small>%s</small>', esc_url(get_edit_term_link($t)), esc_html($t->name), $t->taxonomy === 'mcr_department' ? 'département' : 'catégorie');
        printf('<span class="badge %s">%s</span>', $name !== '' ? 'badge--ok' : 'badge--todo', $name !== '' ? 'traduit' : 'à traduire');
        echo '</div><div class="tr-grid"><div><span class="tr-label">Nom (français)</span><div class="tr-fr">' . esc_html($t->name) . '</div></div>';
        printf('<div><span class="tr-label">Nom (anglais)</span><input class="tr-in" type="text" name="t[%d][name]" value="%s"></div></div>', $t->term_id, esc_attr($name));
        if (trim($t->description) !== '' || $desc !== '') {
            echo '<div class="tr-grid"><div><span class="tr-label">Description (français)</span>' . mcr_tr_fr_box($t->description) . '</div>';
            printf('<div><span class="tr-label">Description (anglais)</span><textarea name="t[%d][description]" rows="3">%s</textarea></div></div>', $t->term_id, esc_textarea($desc));
        }
        echo '</div>';
    }
    mcr_tr_form_close();
}

// ---------------------------------------------------------------- Pages

function mcr_translate_pages(): void
{
    $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'menu_order,post_title']);
    printf('<p style="margin:12px 0"><a class="button" href="%s">Ajouter une page</a> Écrivez la page en français, puis sa version anglaise ici ou dans l’encadré « English » de l’éditeur.</p>', esc_url(admin_url('post-new.php?post_type=page')));
    mcr_tr_form_open('pages');
    foreach ($pages as $p) {
        $title = (string) get_post_meta($p->ID, '_mcr_title_en', true);
        $content = (string) get_post_meta($p->ID, '_mcr_content_en', true);
        $is_wc = in_array($p->ID, array_map('intval', [wc_get_page_id('shop'), wc_get_page_id('cart'), wc_get_page_id('checkout'), wc_get_page_id('myaccount')]), true);
        $ok = $title !== '' && ($content !== '' || $is_wc || trim(wp_strip_all_tags($p->post_content)) === '');
        echo '<div class="tr-row"><div class="tr-head">';
        printf('<a class="name" href="%s">%s</a> <a href="%s" target="_blank">voir en anglais</a>', esc_url(get_edit_post_link($p->ID)), esc_html($p->post_title), esc_url(function_exists('mcr_prefix_url') ? mcr_prefix_url(get_permalink($p), 'en') : get_permalink($p)));
        printf('<span class="badge %s">%s</span>', $ok ? 'badge--ok' : 'badge--todo', $ok ? 'traduit' : 'à traduire');
        echo '</div><div class="tr-grid"><div><span class="tr-label">Titre (français)</span><div class="tr-fr">' . esc_html($p->post_title) . '</div></div>';
        printf('<div><span class="tr-label">Titre (anglais)</span><input class="tr-in" type="text" name="g[%d][title]" value="%s"></div></div>', $p->ID, esc_attr($title));
        if (!$is_wc && trim(wp_strip_all_tags($p->post_content)) !== '') {
            echo '<details' . ($content === '' ? ' open' : '') . '><summary style="cursor:pointer;margin-top:8px">Contenu</summary><div class="tr-grid"><div><span class="tr-label">Contenu (français)</span>' . str_replace('max-height:160px', 'max-height:420px', mcr_tr_fr_box($p->post_content)) . '</div>';
            printf('<div><span class="tr-label">Contenu (anglais), HTML simple accepté</span><textarea name="g[%d][content]" rows="14">%s</textarea></div></div></details>', $p->ID, esc_textarea($content));
        }
        echo '</div>';
    }
    mcr_tr_form_close();
}

// ---------------------------------------------------------------- Site texts

function mcr_translate_strings(): void
{
    $base = require MCR_DIR . 'includes/strings-en.php';
    $custom = (array) get_option('mcr_strings_en', []);
    echo '<p style="margin:12px 0">Menus, boutons et petits textes du site. Les textes des réglages (bandeau, livraison, retrait, page d’accueil) se traduisent dans <a href="' . esc_url(admin_url('admin.php?page=mcr')) . '">MCR Gestion > Paramètres</a>.</p>';
    mcr_tr_form_open('strings');
    echo '<table class="widefat striped"><thead><tr><th style="width:45%">Français</th><th>Anglais</th></tr></thead><tbody>';
    $i = 0;
    foreach ($base as $fr => $en) {
        printf(
            '<tr><td>%s<input type="hidden" name="s[%d][fr]" value="%s"></td><td><input class="tr-in" type="text" name="s[%d][en]" value="%s" placeholder="%s"></td></tr>',
            esc_html($fr),
            $i,
            esc_attr($fr),
            $i,
            esc_attr($custom[$fr] ?? $en),
            esc_attr($en)
        );
        $i++;
    }
    echo '</tbody></table>';
    mcr_tr_form_close();
}

// ---------------------------------------------------------------- Save

add_action('admin_post_mcr_save_translations', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Accès refusé.');
    }
    check_admin_referer('mcr_save_translations');
    $count = 0;
    $set_meta = function (int $id, string $key, string $value, bool $html) use (&$count) {
        $value = $html ? wp_kses_post(trim($value)) : sanitize_text_field($value);
        $old = (string) get_post_meta($id, $key, true);
        if ($value === $old) {
            return;
        }
        $value === '' ? delete_post_meta($id, $key) : update_post_meta($id, $key, $value);
        $count++;
    };
    foreach ((array) wp_unslash($_POST['p'] ?? []) as $id => $f) {
        $id = (int) $id;
        if (get_post_type($id) !== 'product' || !current_user_can('edit_post', $id)) {
            continue;
        }
        foreach (['title' => false, 'short_description' => true, 'description' => true] as $k => $html) {
            if (isset($f[$k])) {
                $set_meta($id, "_mcr_{$k}_en", (string) $f[$k], $html);
            }
        }
    }
    foreach ((array) wp_unslash($_POST['g'] ?? []) as $id => $f) {
        $id = (int) $id;
        if (get_post_type($id) !== 'page' || !current_user_can('edit_post', $id)) {
            continue;
        }
        foreach (['title' => false, 'content' => true] as $k => $html) {
            if (isset($f[$k])) {
                $set_meta($id, "_mcr_{$k}_en", (string) $f[$k], $html);
            }
        }
    }
    foreach ((array) wp_unslash($_POST['t'] ?? []) as $id => $f) {
        $id = (int) $id;
        foreach (['name' => 'mcr_name_en', 'description' => 'mcr_description_en'] as $k => $key) {
            if (!isset($f[$k])) {
                continue;
            }
            $v = $k === 'name' ? sanitize_text_field($f[$k]) : wp_kses_post(trim($f[$k]));
            if ($v !== (string) get_term_meta($id, $key, true)) {
                $v === '' ? delete_term_meta($id, $key) : update_term_meta($id, $key, $v);
                $count++;
            }
        }
    }
    if (isset($_POST['s'])) {
        $base = require MCR_DIR . 'includes/strings-en.php';
        $custom = [];
        foreach ((array) wp_unslash($_POST['s']) as $row) {
            $fr = (string) ($row['fr'] ?? '');
            $en = sanitize_text_field($row['en'] ?? '');
            if (isset($base[$fr]) && $en !== '' && $en !== $base[$fr]) {
                $custom[$fr] = $en;
            }
        }
        update_option('mcr_strings_en', $custom, false);
        $count++;
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
    set_transient('mcr_translate_msg', $count ? "Enregistré ({$count} changement(s))." : 'Aucun changement.', 60);
    $back = wp_validate_redirect((string) ($_POST['back'] ?? ''), mcr_translate_url());
    wp_safe_redirect($back);
    exit;
});
