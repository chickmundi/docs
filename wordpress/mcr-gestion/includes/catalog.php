<?php
/**
 * Departments, grocery and hair attributes, lots and expiry dates.
 */
defined('ABSPATH') || exit;

const MCR_DEPARTMENTS = [
    'epicerie' => ['Épicerie', 'Grocery'],
    'beaute-cheveux' => ['Beauté et cheveux', 'Beauty and hair'],
    'soins-corporels' => ['Soins corporels', 'Body care'],
];

const MCR_GROCERY_FIELDS = [
    'weight_value' => ['Poids / volume (nombre)', 'number'],
    'measure_unit' => ['Unité de mesure', ['' => '—', 'g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'L' => 'L', 'units' => 'unités']],
    'net_quantity' => ['Quantité nette (ex. 6 × 355 ml)', 'text'],
    'packaging' => ['Emballage', 'text'],
    'country_of_origin' => ['Pays d’origine', 'text'],
    'cold_chain' => ['Conservation', ['' => '—', 'ambient' => 'Température ambiante', 'refrigerated' => 'Réfrigéré', 'frozen' => 'Surgelé']],
    'ingredients' => ['Ingrédients', 'textarea'],
    'allergens' => ['Allergènes', 'textarea'],
    'storage_instructions' => ['Instructions de conservation', 'textarea'],
];

const MCR_HAIR_FIELDS = [
    'hair_type' => ['Type de cheveux (naturel, semi-naturel, synthétique)', 'text'],
    'hair_length' => ['Longueur', 'text'],
    'hair_color' => ['Couleur', 'text'],
    'hair_texture' => ['Texture (lisse, bouclé, crépu…)', 'text'],
    'material' => ['Matière', 'text'],
    'style' => ['Style', 'text'],
    'product_size' => ['Format / taille du produit', 'text'],
];

// Public labels (French / English) for the attributes shown on product pages.
const MCR_ATTRIBUTE_LABELS = [
    'net_quantity' => ['Quantité nette', 'Net quantity'],
    'packaging' => ['Emballage', 'Packaging'],
    'country_of_origin' => ['Origine', 'Origin'],
    'cold_chain' => ['Conservation', 'Storage'],
    'ingredients' => ['Ingrédients', 'Ingredients'],
    'allergens' => ['Allergènes', 'Allergens'],
    'storage_instructions' => ['Conservation', 'Storage instructions'],
    'hair_type' => ['Type de cheveux', 'Hair type'],
    'hair_length' => ['Longueur', 'Length'],
    'hair_color' => ['Couleur', 'Color'],
    'hair_texture' => ['Texture', 'Texture'],
    'material' => ['Matière', 'Material'],
    'style' => ['Style', 'Style'],
    'product_size' => ['Format', 'Size'],
];

// ---------- Departments ----------
function mcr_register_department_taxonomy(): void
{
    register_taxonomy('mcr_department', ['product'], [
        'labels' => [
            'name' => 'Départements',
            'singular_name' => 'Département',
            'menu_name' => 'Départements',
            'all_items' => 'Tous les départements',
            'edit_item' => 'Modifier le département',
            'add_new_item' => 'Ajouter un département',
        ],
        'hierarchical' => true, // checkbox list in the editor
        'public' => false,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => false,
    ]);
}
add_action('init', 'mcr_register_department_taxonomy', 5);

function mcr_ensure_departments(): void
{
    foreach (MCR_DEPARTMENTS as $slug => [$fr, $en]) {
        if (!term_exists($slug, 'mcr_department')) {
            $t = wp_insert_term($fr, 'mcr_department', ['slug' => $slug]);
            if (!is_wp_error($t)) {
                update_term_meta($t['term_id'], 'mcr_name_en', $en);
            }
        }
    }
}

/** Department slug of a product (or null). */
function mcr_product_department(int $product_id): ?string
{
    $terms = get_the_terms($product_id, 'mcr_department');
    return $terms && !is_wp_error($terms) ? $terms[0]->slug : null;
}

// ---------- Attributes tab in the product editor ----------
add_filter('woocommerce_product_data_tabs', function ($tabs) {
    $tabs['mcr_details'] = ['label' => 'Détails MCR', 'target' => 'mcr_details_panel', 'priority' => 65];
    return $tabs;
});

add_action('woocommerce_product_data_panels', function () {
    global $post;
    $dept = mcr_product_department($post->ID);
    echo '<div id="mcr_details_panel" class="panel woocommerce_options_panel">';
    wp_nonce_field('mcr_details_save', 'mcr_details_nonce');
    if (!$dept) {
        echo '<p class="form-field"><em>Choisissez le département du produit (boîte « Départements » à droite) pour voir les champs adaptés. Tous les champs sont affichés en attendant.</em></p>';
    }
    if (!$dept || $dept === 'epicerie' || $dept === 'soins-corporels') {
        echo '<h4 style="padding-left:12px">Épicerie</h4>';
        foreach (MCR_GROCERY_FIELDS as $key => [$label, $type]) {
            mcr_render_field($post->ID, $key, $label, $type);
        }
    }
    if (!$dept || $dept === 'beaute-cheveux' || $dept === 'soins-corporels') {
        echo '<h4 style="padding-left:12px">Beauté et cheveux</h4>';
        foreach (MCR_HAIR_FIELDS as $key => [$label, $type]) {
            mcr_render_field($post->ID, $key, $label, $type);
        }
        echo '<p class="form-field"><em>Pour une perruque en plusieurs couleurs ou longueurs, utilisez « Produit variable » : chaque variation a son UGS (SKU), son prix, son stock et son image.</em></p>';
    }
    $legacy = get_post_meta($post->ID, '_mcr_legacy_sku', true);
    if ($legacy) {
        echo '<p class="form-field"><strong>Ancien code partagé (WooCommerce) :</strong> ' . esc_html($legacy) . ' — entrez une vraie UGS dans l’onglet Inventaire.</p>';
    }
    echo '</div>';
});

function mcr_render_field(int $post_id, string $key, string $label, $type): void
{
    $value = get_post_meta($post_id, "_mcr_{$key}", true);
    $args = ['id' => "_mcr_{$key}", 'label' => $label, 'value' => $value];
    if (is_array($type)) {
        woocommerce_wp_select($args + ['options' => $type]);
    } elseif ($type === 'textarea') {
        woocommerce_wp_textarea_input($args);
    } else {
        woocommerce_wp_text_input($args + ($type === 'number' ? ['type' => 'number', 'custom_attributes' => ['step' => 'any', 'min' => '0']] : []));
    }
}

add_action('woocommerce_process_product_meta', function ($post_id) {
    if (!isset($_POST['mcr_details_nonce']) || !wp_verify_nonce($_POST['mcr_details_nonce'], 'mcr_details_save')) {
        return;
    }
    foreach (MCR_GROCERY_FIELDS + MCR_HAIR_FIELDS as $key => [, $type]) {
        if (!isset($_POST["_mcr_{$key}"])) {
            continue;
        }
        $raw = wp_unslash($_POST["_mcr_{$key}"]);
        $value = $type === 'textarea' ? sanitize_textarea_field($raw) : sanitize_text_field($raw);
        $value === '' ? delete_post_meta($post_id, "_mcr_{$key}") : update_post_meta($post_id, "_mcr_{$key}", $value);
    }
});

/** Attributes to show on the product page, in the current language. */
function mcr_public_attributes(int $product_id): array
{
    $out = [];
    $cold = ['ambient' => ['Température ambiante', 'Room temperature'], 'refrigerated' => ['Réfrigéré', 'Refrigerated'], 'frozen' => ['Surgelé', 'Frozen']];
    foreach (MCR_ATTRIBUTE_LABELS as $key => [$fr, $en]) {
        $v = trim((string) get_post_meta($product_id, "_mcr_{$key}", true));
        if ($v === '') {
            continue;
        }
        if ($key === 'cold_chain') {
            $v = $cold[$v][mcr_is_en() ? 1 : 0] ?? $v;
        }
        $out[mcr_is_en() ? $en : $fr] = $v;
    }
    $w = get_post_meta($product_id, '_mcr_weight_value', true);
    if ($w !== '' && $w !== false) {
        $unit = get_post_meta($product_id, '_mcr_measure_unit', true);
        $out[mcr_is_en() ? 'Weight / volume' : 'Poids / volume'] = rtrim(rtrim(number_format_i18n((float) $w, 3), '0'), ',.') . ' ' . ($unit === 'units' ? (mcr_is_en() ? 'units' : 'unités') : $unit);
    }
    return $out;
}

// ---------- Lots and expiry dates ----------
add_action('add_meta_boxes', function () {
    add_meta_box('mcr_lots', 'Lots et dates de péremption', 'mcr_render_lots_box', 'product', 'normal', 'default');
});

function mcr_get_lots(int $product_id): array
{
    $lots = get_post_meta($product_id, '_mcr_lots', true);
    return is_array($lots) ? $lots : [];
}

function mcr_render_lots_box(WP_Post $post): void
{
    $track = get_post_meta($post->ID, '_mcr_track_expiry', true) === 'yes';
    $lots = mcr_get_lots($post->ID);
    wp_nonce_field('mcr_lots_save', 'mcr_lots_nonce');
    echo '<p><label><input type="checkbox" name="mcr_track_expiry" value="yes" ' . checked($track, true, false) . '> Produit périssable : suivre les lots et les dates de péremption</label></p>';
    echo '<table class="widefat striped mcr-lots"><thead><tr><th>N° de lot</th><th>Péremption</th><th>Quantité</th><th>Reçu le</th><th>Note</th><th></th></tr></thead><tbody>';
    $rows = $lots ?: [];
    $rows[] = ['lot' => '', 'expiry' => '', 'qty' => '', 'received' => '', 'note' => '']; // empty row to add one
    foreach ($rows as $i => $l) {
        printf(
            '<tr><td><input type="text" name="mcr_lots[%1$d][lot]" value="%2$s"></td><td><input type="date" name="mcr_lots[%1$d][expiry]" value="%3$s"></td><td><input type="number" step="any" min="0" name="mcr_lots[%1$d][qty]" value="%4$s" style="width:90px"></td><td><input type="date" name="mcr_lots[%1$d][received]" value="%5$s"></td><td><input type="text" name="mcr_lots[%1$d][note]" value="%6$s"></td><td>%7$s</td></tr>',
            $i,
            esc_attr($l['lot'] ?? ''),
            esc_attr($l['expiry'] ?? ''),
            esc_attr($l['qty'] ?? ''),
            esc_attr($l['received'] ?? ''),
            esc_attr($l['note'] ?? ''),
            $i < count($lots) ? '<label><input type="checkbox" name="mcr_lots[' . $i . '][remove]" value="1"> retirer</label>' : '<em>nouveau</em>'
        );
    }
    echo '</tbody></table><p class="description">Une ligne vide permet d’ajouter un lot ; enregistrez le produit pour en ajouter d’autres. Les lots qui expirent dans 14 jours apparaissent dans <a href="' . esc_url(admin_url('admin.php?page=mcr-expiry')) . '">Péremption</a>.</p>';
}

add_action('save_post_product', function ($post_id) {
    if (!isset($_POST['mcr_lots_nonce']) || !wp_verify_nonce($_POST['mcr_lots_nonce'], 'mcr_lots_save') || !current_user_can('edit_product', $post_id)) {
        return;
    }
    update_post_meta($post_id, '_mcr_track_expiry', isset($_POST['mcr_track_expiry']) ? 'yes' : 'no');
    $lots = [];
    foreach ((array) ($_POST['mcr_lots'] ?? []) as $l) {
        $l = wp_unslash($l);
        if (!empty($l['remove']) || (empty($l['lot']) && empty($l['expiry']) && ($l['qty'] ?? '') === '')) {
            continue;
        }
        $lots[] = [
            'lot' => sanitize_text_field($l['lot'] ?? ''),
            'expiry' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $l['expiry'] ?? '') ? $l['expiry'] : '',
            'qty' => (float) str_replace(',', '.', (string) ($l['qty'] ?? 0)),
            'received' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $l['received'] ?? '') ? $l['received'] : '',
            'note' => sanitize_text_field($l['note'] ?? ''),
        ];
    }
    usort($lots, fn($a, $b) => strcmp($a['expiry'] ?: '9999', $b['expiry'] ?: '9999'));
    update_post_meta($post_id, '_mcr_lots', $lots);
    // Earliest expiry of a lot still in stock, for fast lists.
    $next = '';
    foreach ($lots as $l) {
        if ($l['expiry'] && $l['qty'] > 0) {
            $next = $l['expiry'];
            break;
        }
    }
    $next ? update_post_meta($post_id, '_mcr_next_expiry', $next) : delete_post_meta($post_id, '_mcr_next_expiry');
});

/** Lots expiring within $days days (or already expired), still in stock. */
function mcr_expiring_lots(int $days = 14): array
{
    $limit = gmdate('Y-m-d', time() + $days * DAY_IN_SECONDS);
    $ids = get_posts([
        'post_type' => 'product',
        'post_status' => 'any',
        'fields' => 'ids',
        'numberposts' => 500,
        'meta_query' => [['key' => '_mcr_next_expiry', 'value' => $limit, 'compare' => '<=', 'type' => 'DATE']],
    ]);
    $out = [];
    foreach ($ids as $id) {
        foreach (mcr_get_lots($id) as $l) {
            if ($l['expiry'] && $l['expiry'] <= $limit && $l['qty'] > 0) {
                $out[] = $l + ['product_id' => $id, 'name' => get_the_title($id), 'days_left' => (int) floor((strtotime($l['expiry']) - strtotime(gmdate('Y-m-d'))) / DAY_IN_SECONDS)];
            }
        }
    }
    usort($out, fn($a, $b) => strcmp($a['expiry'], $b['expiry']));
    return $out;
}

// AVIF photos (a few products use them): accepted like JPEG/WebP; every current browser shows them.
add_filter('upload_mimes', function ($m) {
    $m['avif'] = 'image/avif';
    return $m;
});
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename) {
    if (empty($data['type']) && preg_match('/\.avif$/i', (string) $filename)) {
        $data = ['ext' => 'avif', 'type' => 'image/avif', 'proper_filename' => $data['proper_filename'] ?? false];
    }
    return $data;
}, 10, 3);
