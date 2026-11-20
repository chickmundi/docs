<?php
/**
 * One-time catalog import for the TEST site (staging): recreates the live catalog with the
 * same product and variation IDs and the same addresses, from data/catalog.json.
 * Refuses to run on a site that already has products or orders, so it can't touch the live store.
 */
defined('ABSPATH') || exit;

const MCR_IMPORT_BATCH = 6;

function mcr_import_allowed(): array
{
    $counts = wp_count_posts('product');
    $existing = (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0) + (int) ($counts->private ?? 0);
    $progress = (int) get_option('mcr_import_offset', 0);
    if ($existing > 0 && $progress === 0 && !get_option('mcr_import_finished')) {
        return [false, sprintf('Ce site contient déjà %d produit(s) : l’import est réservé au site d’essai vide.', $existing)];
    }
    $orders = wc_get_orders(['limit' => 1, 'return' => 'ids']);
    if ($orders) {
        return [false, 'Ce site contient des commandes : l’import est désactivé pour protéger les données.'];
    }
    if (!file_exists(MCR_DIR . 'data/catalog.json')) {
        return [false, 'Fichier data/catalog.json absent.'];
    }
    return [true, ''];
}

add_action('admin_menu', function () {
    add_submenu_page('mcr', 'Importer le catalogue (site d’essai)', 'Import (essai)', 'manage_woocommerce', 'mcr-import', 'mcr_render_import_page');
}, 30);

function mcr_render_import_page(): void
{
    [$ok, $why] = mcr_import_allowed();
    $offset = (int) get_option('mcr_import_offset', 0);
    $total = $ok ? count(mcr_import_data()['products']) : 0;
    echo '<div class="wrap"><h1>Importer le catalogue (site d’essai)</h1>';
    echo '<p>Recrée les produits, catégories, variations et images de la boutique avec les mêmes numéros et adresses. À utiliser uniquement sur le site d’essai.</p>';
    if (!$ok) {
        echo '<div class="notice notice-warning"><p>' . esc_html($why) . '</p></div></div>';
        return;
    }
    printf('<p><strong id="mcr-import-status">%d / %d produits importés</strong></p>', $offset, $total);
    echo '<p><button class="button button-primary" id="mcr-import-start">' . ($offset ? 'Continuer l’import' : 'Lancer l’import') . '</button></p>';
    echo '<pre id="mcr-import-log" style="max-height:300px;overflow:auto;background:#fff;padding:8px"></pre>';
    $nonce = wp_create_nonce('mcr_import');
    ?>
    <script>
    (function () {
      const btn = document.getElementById('mcr-import-start');
      const status = document.getElementById('mcr-import-status');
      const log = document.getElementById('mcr-import-log');
      async function step() {
        const body = new URLSearchParams({ action: 'mcr_import_step', _ajax_nonce: '<?php echo esc_js($nonce); ?>' });
        const r = await fetch(ajaxurl, { method: 'POST', body, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) { log.textContent += 'Erreur : ' + (j.data || r.status) + '\n'; btn.disabled = false; return; }
        status.textContent = j.data.offset + ' / ' + j.data.total + ' produits importés';
        if (j.data.messages.length) log.textContent += j.data.messages.join('\n') + '\n';
        if (j.data.done) { status.textContent += ' : terminé.'; btn.textContent = 'Terminé'; return; }
        step();
      }
      btn.addEventListener('click', () => { btn.disabled = true; step(); });
    })();
    </script>
    <?php
    echo '</div>';
}

function mcr_import_data(): array
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(MCR_DIR . 'data/catalog.json'), true);
    return $data;
}

add_action('wp_ajax_mcr_import_step', function () {
    check_ajax_referer('mcr_import');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('accès refusé');
    }
    [$ok, $why] = mcr_import_allowed();
    if (!$ok) {
        wp_send_json_error($why);
    }
    @set_time_limit(120);
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $data = mcr_import_data();
    $offset = (int) get_option('mcr_import_offset', 0);
    $messages = [];
    if ($offset === 0 && !get_option('mcr_import_categories_done')) {
        $messages[] = mcr_import_categories($data['categories']);
        update_option('mcr_import_categories_done', 1, false);
    }
    $batch = array_slice($data['products'], $offset, MCR_IMPORT_BATCH);
    foreach ($batch as $p) {
        try {
            $messages[] = mcr_import_product($p);
        } catch (Throwable $e) {
            $messages[] = "#{$p['id']} ERREUR : " . $e->getMessage();
        }
    }
    $offset += count($batch);
    update_option('mcr_import_offset', $offset, false);
    $total = count($data['products']);
    if ($offset >= $total) {
        $messages[] = mcr_import_pages();
        delete_option('mcr_import_offset');
        update_option('mcr_import_finished', current_time('mysql'), false);
        wc_delete_product_transients();
    }
    wp_send_json_success(['offset' => min($offset, $total), 'total' => $total, 'done' => $offset >= $total, 'messages' => array_filter($messages)]);
});

function mcr_import_categories(array $cats): string
{
    $n = 0;
    // Parents first.
    usort($cats, fn($a, $b) => (int) !empty($a['parent']) <=> (int) !empty($b['parent']));
    foreach ($cats as $c) {
        $parent = !empty($c['parent']) ? (get_term_by('slug', $c['parent'], 'product_cat')->term_id ?? 0) : 0;
        $existing = get_term_by('slug', $c['slug'], 'product_cat');
        $res = $existing ? ['term_id' => $existing->term_id] : wp_insert_term($c['name'], 'product_cat', ['slug' => $c['slug'], 'parent' => $parent, 'description' => $c['description'] ?? '']);
        if (is_wp_error($res)) {
            continue;
        }
        update_term_meta($res['term_id'], 'mcr_icon', $c['icon'] ?? '');
        update_term_meta($res['term_id'], 'order', $c['sort'] ?? 99);
        if (!empty($c['image']) && !get_term_meta($res['term_id'], 'thumbnail_id', true)) {
            $img = media_sideload_image($c['image'], 0, $c['name'], 'id');
            if (!is_wp_error($img)) {
                update_term_meta($res['term_id'], 'thumbnail_id', $img);
            }
        }
        $n++;
    }
    return "{$n} catégories";
}

function mcr_attach_image(string $url, int $post_id, string $alt): int
{
    // Reuse an image already imported from the same address.
    $found = get_posts(['post_type' => 'attachment', 'meta_key' => '_mcr_source', 'meta_value' => $url, 'fields' => 'ids', 'numberposts' => 1]);
    if ($found) {
        return (int) $found[0];
    }
    $id = media_sideload_image($url, $post_id, $alt, 'id');
    if (is_wp_error($id)) {
        return 0;
    }
    update_post_meta($id, '_mcr_source', $url);
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    return (int) $id;
}

function mcr_import_product(array $p): string
{
    $taken = get_post($p['id']);
    if ($taken && $taken->post_type === 'product') {
        return '';
    }
    if (!$taken) {
        // Imported earlier under a new ID?
        $prev = get_posts(['post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_mcr_original_id', 'meta_value' => $p['id'], 'fields' => 'ids', 'numberposts' => 1]);
        if ($prev) {
            return '';
        }
    }
    $variable = $p['type'] === 'variable' && !empty($p['variations']);
    // Reserve the original ID and address.
    $id = wp_insert_post([
        'import_id' => $taken ? 0 : $p['id'], // ID already used by another page on this site: take a new one
        'post_type' => 'product',
        'post_status' => 'publish',
        'post_title' => $p['name'],
        'post_name' => $p['slug'],
        'post_content' => $p['description'] ?? '',
        'post_excerpt' => $p['short_description'] ?? '',
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    if ($taken) {
        update_post_meta($id, '_mcr_original_id', $p['id']);
    }
    wp_set_object_terms($id, $variable ? 'variable' : ($p['type'] === 'external' ? 'external' : 'simple'), 'product_type');
    $product = $variable ? new WC_Product_Variable($id) : ($p['type'] === 'external' ? new WC_Product_External($id) : new WC_Product_Simple($id));

    $cat_ids = [];
    foreach ($p['categories'] as $slug) {
        $t = get_term_by('slug', $slug, 'product_cat');
        if ($t) {
            $cat_ids[] = $t->term_id;
        }
    }
    $product->set_category_ids($cat_ids);
    if (!empty($p['tags'])) {
        wp_set_object_terms($id, $p['tags'], 'product_tag');
    }
    if (!empty($p['department'])) {
        wp_set_object_terms($id, $p['department'], 'mcr_department');
    }
    if (!$variable) {
        $product->set_regular_price($p['regular_price'] ?? $p['price']);
        if (isset($p['regular_price'], $p['price']) && (float) $p['price'] < (float) $p['regular_price']) {
            $product->set_sale_price($p['price']);
        }
    }
    if (!empty($p['sku'])) {
        try {
            $product->set_sku($p['sku']);
        } catch (Throwable $e) {
        }
    }
    if (!empty($p['legacy_sku'])) {
        update_post_meta($id, '_mcr_legacy_sku', $p['legacy_sku']);
    }
    if ($p['low_stock'] !== null) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($p['low_stock']);
    } else {
        $product->set_stock_status($p['in_stock'] ? 'instock' : 'outofstock');
    }
    if (!empty($p['unit'])) {
        update_post_meta($id, '_mcr_unit_label', $p['unit']);
    }
    if ($p['type'] === 'external' && !empty($p['external_url'])) {
        $product->set_product_url($p['external_url']);
    }

    // Attributes: options used by variations, plus plain information.
    $attrs = [];
    foreach ($p['attributes'] as $i => $a) {
        $attr = new WC_Product_Attribute();
        $attr->set_name($a['name']);
        $attr->set_options($a['values']);
        $attr->set_position($i);
        $attr->set_visible(true);
        $attr->set_variation((bool) $a['variation']);
        $attrs[] = $attr;
    }
    $product->set_attributes($attrs);

    // Images.
    $image_ids = [];
    foreach ($p['images'] as $img) {
        $aid = mcr_attach_image($img['src'], $id, $img['alt'] ?: $p['name']);
        if ($aid) {
            $image_ids[] = $aid;
        }
    }
    if ($image_ids) {
        $product->set_image_id(array_shift($image_ids));
        $product->set_gallery_image_ids($image_ids);
    }
    $product->save();

    if ($variable) {
        foreach ($p['variations'] as $i => $v) {
            $vid = wp_insert_post(['import_id' => $v['id'], 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $id, 'menu_order' => $i, 'post_title' => $p['name']], true);
            if (is_wp_error($vid)) {
                continue;
            }
            $variation = new WC_Product_Variation($vid);
            $variation->set_parent_id($id);
            $attributes = [];
            foreach ($v['attributes'] as $name => $value) {
                $attributes[sanitize_title($name)] = (string) ($value ?? ''); // empty = "any"
            }
            $variation->set_attributes($attributes);
            $variation->set_regular_price($v['regular_price'] ?? $v['price']);
            if (isset($v['regular_price'], $v['price']) && (float) $v['price'] < (float) $v['regular_price']) {
                $variation->set_sale_price($v['price']);
            }
            $variation->set_stock_status($v['in_stock'] ? 'instock' : 'outofstock');
            $variation->save();
            // "Any value" options must be stored as an empty string (WooCommerce skips them otherwise).
            foreach ($attributes as $slug => $value) {
                $stored = get_post_meta($vid, 'attribute_' . $slug);
                if ($value === '' && (!$stored || $stored[0] === null)) {
                    update_post_meta($vid, 'attribute_' . $slug, '');
                }
            }
        }
        WC_Product_Variable::sync($id);
    }
    return '';
}


/** Test site: same page addresses as the live store, plus the information pages. */
function mcr_import_pages(): string
{
    $wc = [
        'shop' => ['boutique', 'Boutique', null],
        'cart' => ['panier', 'Panier', '[woocommerce_cart]'],
        'checkout' => ['caisse', 'Caisse', '[woocommerce_checkout]'],
        'myaccount' => ['mon-compte', 'Mon compte', '[woocommerce_my_account]'],
    ];
    foreach ($wc as $key => [$slug, $title, $content]) {
        $id = wc_get_page_id($key);
        if ($id > 0) {
            $update = ['ID' => $id, 'post_name' => $slug, 'post_title' => $title];
            if ($content) {
                $update['post_content'] = $content; // classic cart/checkout (needed for distance-based delivery)
            }
            wp_update_post($update);
        }
    }
    $file = MCR_DIR . 'data/pages.json';
    $n = 0;
    if (file_exists($file)) {
        foreach (json_decode((string) file_get_contents($file), true) as $slug => $page) {
            if (get_page_by_path($slug)) {
                continue;
            }
            wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $page['title'], 'post_content' => $page['html']]);
            $n++;
        }
    }
    // Home page = theme front page; products page = shop.
    update_option('show_on_front', 'posts');
    flush_rewrite_rules();
    return "pages : {$n} créées, pages WooCommerce renommées";
}


// Test site only (the import ran here): open the store to visitors, keep it out of search engines.
add_action('admin_init', function () {
    if (!get_option('mcr_import_finished') || get_option('mcr_staging_configured')) {
        return;
    }
    update_option('woocommerce_coming_soon', 'no');
    update_option('woocommerce_store_pages_only', 'no');
    update_option('blog_public', '0');
    update_option('mcr_staging_configured', 1, false);
});


// Test site: repair variations imported before the "any value" fix (one time).
add_action('admin_init', function () {
    if (!get_option('mcr_import_finished') || get_option('mcr_fixed_any_attributes_v2') !== false) {
        return;
    }
    $fixed = 0;
    foreach (wc_get_products(['type' => 'variable', 'limit' => -1, 'return' => 'ids']) as $pid) {
        $parent = wc_get_product($pid);
        $slugs = [];
        foreach ($parent->get_attributes() as $attr) {
            if ($attr->get_variation()) {
                $slugs[] = sanitize_title($attr->get_name());
            }
        }
        foreach ($parent->get_children() as $vid) {
            foreach ($slugs as $slug) {
                $stored = get_post_meta($vid, 'attribute_' . $slug);
                if (!$stored || $stored[0] === null) {
                    update_post_meta($vid, 'attribute_' . $slug, '');
                    $fixed++;
                }
            }
        }
        wc_delete_product_transients($pid);
    }
    update_option('mcr_fixed_any_attributes_v2', $fixed, false);
});


/**
 * One time: install the French (Canada) language files for WordPress and its
 * plugins (WooCommerce's "Ajouter au panier", "Choisir une option"…).
 * English comes from the WooCommerce source strings.
 */
add_action('admin_init', function () {
    if (get_option('mcr_fr_languages_v1') || !current_user_can('install_languages')) {
        return;
    }
    update_option('mcr_fr_languages_v1', 'started', false);
    require_once ABSPATH . 'wp-admin/includes/translation-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $core = wp_download_language_pack('fr_CA');
    delete_site_transient('update_plugins');
    wp_update_plugins();
    $updates = wp_get_translation_updates();
    $done = 0;
    if ($updates) {
        $upgrader = new Language_Pack_Upgrader(new Automatic_Upgrader_Skin());
        $res = $upgrader->bulk_upgrade($updates);
        $done = is_array($res) ? count(array_filter($res)) : 0;
    }
    update_option('mcr_fr_languages_v1', ($core ?: 'core-failed') . ' / ' . $done . ' packs', false);
});

// Test site only: sell and deliver in Canada (the country field then shows "Canada" only).
add_action('admin_init', function () {
    if (!get_option('mcr_import_finished') || get_option('mcr_staging_countries_v1')) {
        return;
    }
    update_option('woocommerce_allowed_countries', 'specific');
    update_option('woocommerce_specific_allowed_countries', ['CA']);
    update_option('woocommerce_ship_to_countries', '');
    update_option('woocommerce_default_country', 'CA:QC');
    update_option('woocommerce_default_customer_address', 'base');
    update_option('mcr_staging_countries_v1', 1, false);
});


// Test site only: "pay on delivery or at pickup" so orders can be tested end to end.
add_action('admin_init', function () {
    if (!get_option('mcr_import_finished') || get_option('mcr_staging_cod_v1')) {
        return;
    }
    $cod = (array) get_option('woocommerce_cod_settings', []);
    $cod = array_merge($cod, [
        'enabled' => 'yes',
        'title' => 'Payer à la livraison ou au retrait',
        'description' => 'Payez par carte de débit, crédit ou comptant à la réception de votre commande.',
        'instructions' => 'Payez à la réception de votre commande, à la livraison ou en magasin.',
        'enable_for_methods' => [],
        'enable_for_virtual' => 'yes',
    ]);
    update_option('woocommerce_cod_settings', $cod);
    update_option('mcr_staging_cod_v1', 1, false);
});

// Test site only: Canadian French price format in the admin (front end is set by language).
add_action('admin_init', function () {
    if (!get_option('mcr_import_finished') || get_option('mcr_staging_price_format_v1')) {
        return;
    }
    update_option('woocommerce_currency', 'CAD');
    update_option('woocommerce_currency_pos', 'right_space');
    update_option('woocommerce_price_decimal_sep', ',');
    update_option('woocommerce_price_thousand_sep', ' ');
    update_option('mcr_staging_price_format_v1', 1, false);
});
