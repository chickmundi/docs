<?php
/**
 * Copy the English texts collected from the current site's /en/ pages
 * (TranslatePress) into MCR Gestion's English fields. Safe on the live site:
 * it only fills English fields that are empty, unless "replace" is ticked.
 * Data: data/en.json (made by wordpress/collect-english.mjs).
 */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Traductions anglaises', 'Traductions anglaises', 'manage_woocommerce', 'mcr-english', 'mcr_render_english_page');
}, 25);

function mcr_english_data(): array
{
    $file = MCR_DIR . 'data/en.json';
    $d = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($d) ? $d + ['products' => [], 'categories' => [], 'pages' => []] : ['products' => [], 'categories' => [], 'pages' => []];
}

/** Current product ID for an ID from the old site (same number, or remapped by the test-site import). */
function mcr_current_product_id(int $old_id): int
{
    $remapped = get_posts(['post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_mcr_original_id', 'meta_value' => $old_id, 'fields' => 'ids', 'numberposts' => 1]);
    if ($remapped) {
        return (int) $remapped[0];
    }
    return get_post_type($old_id) === 'product' ? $old_id : 0;
}

function mcr_render_english_page(): void
{
    $d = mcr_english_data();
    echo '<div class="wrap"><h1>Traductions anglaises</h1>';
    if ($msg = get_transient('mcr_english_result')) {
        delete_transient('mcr_english_result');
        echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
    }
    printf(
        '<p>Textes anglais repris de l’ancien site (pages <code>/en/</code>) : <strong>%d produits, %d catégories, %d pages</strong>.</p>',
        count($d['products']),
        count($d['categories']),
        count($d['pages'])
    );
    echo '<p>Ils remplissent les champs « English » des produits, catégories et pages. Les champs anglais déjà remplis ne sont pas modifiés, sauf si vous cochez la case ci-dessous. Les produits sans traduction affichent le texte français en anglais, comme avant.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mcr_import_english">';
    wp_nonce_field('mcr_import_english');
    echo '<p><label><input type="checkbox" name="replace" value="1"> Remplacer aussi les textes anglais déjà saisis</label></p>';
    submit_button('Importer les traductions anglaises', 'primary', 'submit', false);
    echo '</form></div>';
}

add_action('admin_post_mcr_import_english', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Accès refusé.');
    }
    check_admin_referer('mcr_import_english');
    $replace = !empty($_POST['replace']);
    $d = mcr_english_data();
    $n = ['p' => 0, 'c' => 0, 'g' => 0, 'skip' => 0, 'missing' => 0];
    $set_post = function (int $id, string $field, string $value) use ($replace, &$n): bool {
        $key = "_mcr_{$field}_en";
        if (!$replace && trim((string) get_post_meta($id, $key, true)) !== '') {
            $n['skip']++;
            return false;
        }
        update_post_meta($id, $key, $field === 'title' ? sanitize_text_field($value) : wp_kses_post($value));
        return true;
    };
    foreach ($d['products'] as $old => $e) {
        $id = mcr_current_product_id((int) $old);
        if (!$id) {
            $n['missing']++;
            continue;
        }
        $changed = false;
        foreach (['title', 'short_description', 'description'] as $f) {
            if (!empty($e[$f])) {
                $changed = $set_post($id, $f, (string) $e[$f]) || $changed;
            }
        }
        $n['p'] += $changed ? 1 : 0;
    }
    foreach ($d['categories'] as $slug => $e) {
        $term = get_term_by('slug', $slug, 'product_cat');
        if (!$term) {
            continue;
        }
        foreach (['name' => 'mcr_name_en', 'description' => 'mcr_description_en'] as $f => $key) {
            if (!empty($e[$f]) && ($replace || trim((string) get_term_meta($term->term_id, $key, true)) === '')) {
                update_term_meta($term->term_id, $key, $f === 'name' ? sanitize_text_field($e[$f]) : wp_kses_post($e[$f]));
                $n['c']++;
            }
        }
    }
    foreach ($d['pages'] as $slug => $e) {
        $page = get_page_by_path($slug);
        if (!$page) {
            continue;
        }
        $changed = false;
        foreach (['title', 'content'] as $f) {
            if (!empty($e[$f])) {
                $changed = $set_post($page->ID, $f, (string) $e[$f]) || $changed;
            }
        }
        $n['g'] += $changed ? 1 : 0;
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
    set_transient('mcr_english_result', sprintf(
        'Terminé : %d produits, %d champs de catégories et %d pages mis à jour. %d champs déjà remplis gardés, %d produits introuvables.',
        $n['p'],
        $n['c'],
        $n['g'],
        $n['skip'],
        $n['missing']
    ), 120);
    wp_safe_redirect(admin_url('admin.php?page=mcr-english'));
    exit;
});
