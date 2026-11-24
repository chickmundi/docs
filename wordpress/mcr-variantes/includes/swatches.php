<?php
/**
 * Variant swatches: each option of a variable product (colour, length…) is shown as a picture,
 * a colour dot or a text button instead of a drop-down list. WooCommerce's own select stays in
 * the page (hidden) so prices, stock and the variant photo keep working as usual.
 *
 * Pictures are stored per product (colour charts differ between brands), with an optional
 * store-wide picture or colour on each attribute term as a fallback:
 *   product meta _mcr_swatches = [attribute_key => [value_key => ['id' => attachment, 'color' => '#hex']]]
 *   term meta    mcr_swatch_image (attachment id), mcr_swatch_color (#hex)
 */
defined('ABSPATH') || exit;

/** "pa_couleur" stays as is; a product's own attribute "Couleur" becomes "couleur". */
function mcrv_attr_key(string $attr): string
{
    $attr = preg_replace('/^attribute_/', '', $attr);
    return taxonomy_exists($attr) ? $attr : sanitize_title($attr);
}

function mcrv_value_key(string $value): string
{
    return sanitize_title($value);
}

function mcrv_product_swatches(int $product_id): array
{
    $m = get_post_meta($product_id, '_mcr_swatches', true);
    return is_array($m) ? $m : [];
}

/** ['img' => url, 'img_full' => url, 'color' => '#hex'] or null. */
function mcrv_swatch_for(int $product_id, string $attr, string $value): ?array
{
    $key = mcrv_attr_key($attr);
    $map = mcrv_product_swatches($product_id);
    // Pictures copied from a store-wide attribute ("pa_couleur") also serve the product's own "couleur".
    $s = $map[$key][mcrv_value_key($value)] ?? ($map['pa_' . $key][mcrv_value_key($value)] ?? null);
    if (!$s) {
        // Same colour written differently ("T1B30" and "T1b-30").
        $alnum = fn($x) => preg_replace('/[^a-z0-9]/', '', strtolower(remove_accents((string) $x)));
        foreach ([$key, 'pa_' . $key] as $k) {
            foreach ((array) ($map[$k] ?? []) as $vk => $data) {
                if ($alnum($vk) === $alnum($value) && $alnum($value) !== '') {
                    $s = $data;
                    break 2;
                }
            }
        }
    }
    $id = (int) ($s['id'] ?? 0);
    $color = (string) ($s['color'] ?? '');
    if (!$id && !$color && taxonomy_exists($key)) {
        $term = get_term_by('slug', $value, $key);
        if ($term) {
            $id = (int) get_term_meta($term->term_id, 'mcr_swatch_image', true);
            $color = (string) get_term_meta($term->term_id, 'mcr_swatch_color', true);
        }
    }
    if ($id) {
        $img = wp_get_attachment_image_url($id, 'thumbnail');
        if ($img) {
            return ['img' => $img, 'img_full' => (string) wp_get_attachment_image_url($id, 'woocommerce_single'), 'color' => ''];
        }
    }
    return $color ? ['img' => '', 'img_full' => '', 'color' => sanitize_hex_color($color) ?: ''] : null;
}

// ---------------------------------------------------------------- Product page

add_filter('woocommerce_dropdown_variation_attribute_options_html', function ($html, $args) {
    $product = $args['product'] ?? null;
    $attr = (string) ($args['attribute'] ?? '');
    $options = (array) ($args['options'] ?? []);
    if (!$product instanceof WC_Product || !$attr || !$options || is_admin()) {
        return $html;
    }
    $name = $args['name'] ?: 'attribute_' . sanitize_title($attr);
    $pid = $product->get_id();
    $items = [];
    if (taxonomy_exists($attr)) {
        foreach (wc_get_product_terms($pid, $attr, ['fields' => 'all']) as $t) {
            if (in_array($t->slug, $options, true)) {
                $items[] = [$t->slug, $t->name];
            }
        }
    } else {
        foreach ($options as $o) {
            $items[] = [$o, $o];
        }
    }
    $buttons = '';
    $pictures = 0;
    foreach ($items as [$value, $label]) {
        $sw = mcrv_swatch_for($pid, $attr, (string) $value);
        $inner = '<span class="mcrv-opt__t">' . esc_html($label) . '</span>';
        $cls = 'mcrv-opt';
        if ($sw && $sw['img']) {
            $inner = '<img src="' . esc_url($sw['img']) . '" alt="" loading="lazy" width="56" height="56">';
            $cls .= ' mcrv-opt--img';
            $pictures++;
        } elseif ($sw && $sw['color']) {
            $inner = '<span class="mcrv-opt__dot" style="background:' . esc_attr($sw['color']) . '"></span><span class="mcrv-opt__t">' . esc_html($label) . '</span>';
            $cls .= ' mcrv-opt--color';
        }
        $buttons .= '<button type="button" class="' . $cls . '" data-value="' . esc_attr($value) . '" data-label="' . esc_attr($label) . '" aria-pressed="false" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . ($sw && $sw['img_full'] ? ' data-full="' . esc_url($sw['img_full']) . '"' : '') . '>' . $inner . '</button>';
    }
    $label = wc_attribute_label($attr, $product);
    return '<div class="mcrv-wrap' . ($pictures ? ' mcrv-wrap--img' : '') . '">' . $html .
        '<div class="mcrv" role="group" aria-label="' . esc_attr($label) . '" data-for="' . esc_attr($name) . '">' . $buttons . '</div></div>';
}, 20, 2);

add_action('wp_enqueue_scripts', function () {
    if (!is_product()) {
        return;
    }
    wp_enqueue_style('mcrv', MCRV_URL . 'assets/front.css', [], MCRV_VERSION);
    wp_enqueue_script('mcrv', MCRV_URL . 'assets/front.js', ['jquery'], MCRV_VERSION, true);
    wp_localize_script('mcrv', 'MCRV', ['en' => mcrv_en(), 'currency' => get_woocommerce_currency_symbol(), 'decimal' => wc_get_price_decimal_separator(), 'pos' => get_option('woocommerce_currency_pos')]);
});

// ---------------------------------------------------------------- Admin: store-wide picture or colour per attribute term

add_action('admin_init', function () {
    foreach (wc_get_attribute_taxonomy_names() as $tax) {
        add_action("{$tax}_add_form_fields", 'mcrv_term_fields_add');
        add_action("{$tax}_edit_form_fields", 'mcrv_term_fields_edit');
        add_action("created_{$tax}", 'mcrv_term_save');
        add_action("edited_{$tax}", 'mcrv_term_save');
        add_filter("manage_edit-{$tax}_columns", fn($c) => array_slice($c, 0, 1, true) + ['mcrv' => 'Échantillon'] + array_slice($c, 1, null, true));
        add_filter("manage_{$tax}_custom_column", function ($out, $col, $term_id) {
            if ($col !== 'mcrv') {
                return $out;
            }
            $id = (int) get_term_meta($term_id, 'mcr_swatch_image', true);
            $color = (string) get_term_meta($term_id, 'mcr_swatch_color', true);
            return $id ? wp_get_attachment_image($id, [36, 36]) : ($color ? '<span style="display:inline-block;width:28px;height:28px;border-radius:50%;background:' . esc_attr($color) . ';border:1px solid #ccc"></span>' : '');
        }, 10, 3);
    }
});

function mcrv_picker(string $name, int $id): string
{
    $src = $id ? wp_get_attachment_image_url($id, 'thumbnail') : '';
    return '<span class="mcrv-pick"><img src="' . esc_url($src) . '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #ddd;' . ($src ? '' : 'display:none') . '"> '
        . '<input type="hidden" name="' . esc_attr($name) . '" value="' . ($id ?: '') . '"> '
        . '<button type="button" class="button mcrv-pick__choose">Choisir une image</button> '
        . '<button type="button" class="button-link mcrv-pick__remove"' . ($id ? '' : ' style="display:none"') . '>Retirer</button></span>';
}

function mcrv_term_fields_add(): void
{
    wp_enqueue_media();
    echo '<div class="form-field"><label>Échantillon (image)</label>' . mcrv_picker('mcr_swatch_image', 0) . '<p>Image affichée pour cette option sur les fiches produits, sauf si le produit a sa propre image.</p></div>';
    echo '<div class="form-field"><label for="mcr_swatch_color">Ou une couleur</label><input type="color" id="mcr_swatch_color" name="mcr_swatch_color" value="#000000"><label><input type="checkbox" name="mcr_swatch_color_on" value="1"> Utiliser cette couleur</label></div>';
}

function mcrv_term_fields_edit($term): void
{
    wp_enqueue_media();
    $id = (int) get_term_meta($term->term_id, 'mcr_swatch_image', true);
    $color = (string) get_term_meta($term->term_id, 'mcr_swatch_color', true);
    echo '<tr class="form-field"><th>Échantillon (image)</th><td>' . mcrv_picker('mcr_swatch_image', $id) . '<p class="description">Image affichée pour cette option sur les fiches produits, sauf si le produit a sa propre image.</p></td></tr>';
    echo '<tr class="form-field"><th><label for="mcr_swatch_color">Ou une couleur</label></th><td><input type="color" id="mcr_swatch_color" name="mcr_swatch_color" value="' . esc_attr($color ?: '#000000') . '"> <label><input type="checkbox" name="mcr_swatch_color_on" value="1"' . checked((bool) $color, true, false) . '> Utiliser cette couleur</label></td></tr>';
}

function mcrv_term_save(int $term_id): void
{
    if (!current_user_can('manage_product_terms') || !isset($_POST['mcr_swatch_image'])) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }
    update_term_meta($term_id, 'mcr_swatch_image', absint($_POST['mcr_swatch_image'])); // phpcs:ignore
    $color = !empty($_POST['mcr_swatch_color_on']) ? sanitize_hex_color(wp_unslash($_POST['mcr_swatch_color'] ?? '')) : ''; // phpcs:ignore
    update_term_meta($term_id, 'mcr_swatch_color', (string) $color);
}

// ---------------------------------------------------------------- Admin: this product's own pictures

add_action('add_meta_boxes', function () {
    add_meta_box('mcrv-swatches', 'Images des options (échantillons)', function ($post) {
        $p = wc_get_product($post->ID);
        if (!$p || !$p->is_type('variable')) {
            echo '<p>Pour les produits à variantes : enregistrez d’abord le produit comme « Produit variable » avec ses attributs.</p>';
            return;
        }
        wp_enqueue_media();
        wp_nonce_field('mcrv_swatches', 'mcrv_nonce');
        $map = mcrv_product_swatches($p->get_id());
        echo '<p class="description">Image montrée pour chaque option sur la fiche du produit. Sans image, l’option s’affiche en bouton texte (ou avec l’image commune de l’attribut).</p>';
        foreach ($p->get_variation_attributes() as $attr => $values) {
            $key = mcrv_attr_key($attr);
            echo '<h4 style="margin:14px 0 6px">' . esc_html(wc_attribute_label($attr, $p)) . '</h4><div style="display:flex;flex-wrap:wrap;gap:14px">';
            foreach ($values as $value) {
                $label = taxonomy_exists($attr) && ($t = get_term_by('slug', $value, $attr)) ? $t->name : $value;
                $id = (int) ($map[$key][mcrv_value_key($value)]['id'] ?? 0);
                echo '<div style="min-width:150px"><strong style="display:block;margin-bottom:4px">' . esc_html($label) . '</strong>' . mcrv_picker('mcrv_sw[' . esc_attr($key) . '][' . esc_attr(mcrv_value_key($value)) . ']', $id) . '</div>';
            }
            echo '</div>';
        }
    }, 'product', 'normal', 'default');
});

add_action('woocommerce_admin_process_product_object', function (WC_Product $p) {
    if (!isset($_POST['mcrv_nonce']) || !wp_verify_nonce(sanitize_key($_POST['mcrv_nonce']), 'mcrv_swatches')) {
        return;
    }
    $map = mcrv_product_swatches($p->get_id());
    foreach ((array) ($_POST['mcrv_sw'] ?? []) as $attr => $values) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $attr = sanitize_key($attr);
        foreach ((array) $values as $value => $id) {
            $value = sanitize_title($value);
            $id = absint($id);
            if ($id) {
                $map[$attr][$value] = ['id' => $id, 'color' => (string) ($map[$attr][$value]['color'] ?? '')];
            } else {
                unset($map[$attr][$value]['id']);
                if (empty($map[$attr][$value]['color'])) {
                    unset($map[$attr][$value]);
                }
            }
        }
    }
    $p->update_meta_data('_mcr_swatches', $map);
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (in_array($hook, ['post.php', 'post-new.php', 'edit-tags.php', 'term.php'], true)) {
        wp_enqueue_script('mcrv-admin', MCRV_URL . 'assets/admin.js', ['jquery'], MCRV_VERSION, true);
    }
});
