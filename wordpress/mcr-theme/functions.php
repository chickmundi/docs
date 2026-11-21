<?php
/**
 * Marché Chemin-du-Roy: light WooCommerce theme (no page builder).
 */
defined('ABSPATH') || exit;

define('MCRT_VERSION', '1.2.1');
define('MCRT_DIR', get_template_directory());
define('MCRT_URL', get_template_directory_uri());

// Works without the MCR Gestion plugin too (French only, default settings).
if (!function_exists('mcr_t')) {
    function mcr_t(string $fr): string { return $fr; }
    function mcr_e(string $fr): void { echo esc_html($fr); }
    function mcr_lang(): string { return 'fr'; }
    function mcr_is_en(): bool { return false; }
    function mcr_switch_url(string $lang): string { return home_url('/'); }
    function mcr_setting(string $group, ?string $key = null) { return $key ? null : []; }
    function mcr_l(array $v, string $k): string { return (string) ($v["{$k}_fr"] ?? ''); }
}

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('woocommerce', ['thumbnail_image_width' => 400, 'single_image_width' => 800, 'product_grid' => ['default_columns' => 4, 'default_rows' => 12]]);
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    register_nav_menus(['footer' => 'Pied de page : informations']);
});

/**
 * Icon from the Lucide set in /icons. Each icon is drawn once per page (sprite printed in the
 * footer) and referenced with <use>, which keeps pages with many product cards light.
 */
function mcr_icon(string $name, int $size = 20, string $class = ''): string
{
    $name = sanitize_file_name($name);
    if (!is_readable(MCRT_DIR . "/icons/{$name}.svg")) {
        return '';
    }
    $GLOBALS['mcr_icons_used'][$name] = true;
    return sprintf('<svg width="%1$d" height="%1$d" class="icon %2$s" aria-hidden="true" focusable="false"><use href="#i-%3$s"/></svg>', $size, esc_attr($class), esc_attr($name));
}

add_action('wp_footer', function () {
    $used = array_keys($GLOBALS['mcr_icons_used'] ?? []);
    if (!$used) {
        return;
    }
    echo '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
    foreach ($used as $name) {
        $svg = (string) file_get_contents(MCRT_DIR . "/icons/{$name}.svg");
        preg_match('/<svg([^>]*)>(.*)<\/svg>/s', $svg, $m);
        $attrs = '';
        foreach (['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'] as $a) {
            if (preg_match('/\s' . $a . '="([^"]*)"/', $m[1] ?? '', $mm)) {
                $attrs .= " {$a}=\"{$mm[1]}\"";
            }
        }
        printf('<symbol id="i-%s" viewBox="0 0 24 24"%s>%s</symbol>', esc_attr($name), $attrs, trim(preg_replace('/<!--.*?-->/s', '', $m[2] ?? '')));
    }
    echo '</svg>';
}, 1);

/** Icon of a product category (term meta "mcr_icon", set by the importer or in the admin). */
function mcr_category_icon(WP_Term $term): string
{
    $icon = get_term_meta($term->term_id, 'mcr_icon', true);
    if ($icon) {
        return $icon;
    }
    $defaults = [
        'epicerie' => 'shopping-basket', 'petit-dejeuner' => 'coffee', 'epices-et-condiments' => 'flame', 'surgeles' => 'snowflake',
        'viandes' => 'beef', 'poissons-et-fruits-de-mer' => 'fish', 'fruits-et-legumes' => 'carrot', 'perruques' => 'sparkles',
        'extensions-capillaires' => 'scissors', 'soins-capillaires' => 'droplet', 'soins-corporels' => 'flower-2', 'conserves' => 'soup',
        'farines' => 'wheat', 'garde-manger' => 'package', 'autres' => 'ellipsis', 'naturel' => 'leaf', 'semi-naturel' => 'sparkles',
        'synthetiques' => 'sparkles', 'accessoires-de-cheveux' => 'brush', 'produits-capillaires' => 'spray-can',
    ];
    return $defaults[$term->slug] ?? 'tag';
}

// ---------- Assets: one stylesheet, one small script, nothing else ----------
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('mcr-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap', [], null);
    wp_enqueue_style('mcr', MCRT_URL . '/assets/theme.css', [], MCRT_VERSION);
    wp_enqueue_script('mcr', MCRT_URL . '/assets/theme.js', [], MCRT_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    wp_localize_script('mcr', 'MCR', [
        'ajax' => WC_AJAX::get_endpoint('%%endpoint%%'),
        'cartUrl' => wc_get_cart_url(),
        'lang' => mcr_lang(),
        'store' => rest_url('wc/store/v1/'),
        'searchUrl' => add_query_arg(['post_type' => 'product', 's' => ''], home_url('/')),
        'i18n' => [
            'searching' => mcr_t('Recherche…'),
            'noresult' => mcr_is_en() ? 'No products found for' : 'Aucun produit trouvé pour',
            'allresults' => mcr_is_en() ? 'See all results' : 'Voir tous les résultats',
            'out' => mcr_t('Épuisé'),
            'added' => mcr_t('ajouté au panier'),
            'add' => mcr_t('Ajouter à ma liste'),
            'remove' => mcr_t('Retirer de ma liste'),
        ],
    ]);
}, 20);

add_filter('wp_resource_hints', function ($urls, $type) {
    if ($type === 'preconnect') {
        $urls[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'];
        $urls[] = 'https://fonts.googleapis.com';
    }
    return $urls;
}, 10, 2);

// Remove what this design does not use.
add_filter('woocommerce_enqueue_styles', '__return_empty_array');
add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
    wp_dequeue_style('wc-blocks-style');
    if (!is_cart() && !is_checkout() && !is_account_page()) {
        wp_dequeue_script('wc-cart-fragments'); // the theme updates the cart badge itself
        if (!is_product()) {
            // Lists and pages: the theme adds to cart itself, no jQuery needed.
            foreach (['wc-add-to-cart', 'woocommerce', 'jquery-blockui', 'js-cookie', 'wc-order-attribution', 'sourcebuster-js'] as $h) {
                wp_dequeue_script($h);
            }
        }
    }
}, 100);
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
add_filter('wp_img_tag_add_auto_sizes', '__return_true');
// Product photos are shown at most ~600 px wide: don't list bigger sizes in srcset.
add_filter('max_srcset_image_width', fn() => 640);
// Block editor styles (this theme doesn't use blocks on the front end).
remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

// ---------- WooCommerce layout ----------
// Single product and other WooCommerce templates: the theme's container.
add_action('woocommerce_before_main_content', function () {
    if (!is_shop() && !is_product_taxonomy() && !is_search()) {
        echo '<div class="container wc-main">';
        woocommerce_breadcrumb();
    }
}, 5);
add_action('woocommerce_after_main_content', function () {
    if (!is_shop() && !is_product_taxonomy() && !is_search()) {
        echo '</div>';
    }
}, 50);
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
add_filter('loop_shop_per_page', fn() => 48);
add_filter('loop_shop_columns', fn() => 4);
add_filter('woocommerce_show_page_title', '__return_false');
add_filter('woocommerce_breadcrumb_defaults', function ($d) {
    $d['delimiter'] = ' ' . mcr_icon('chevron-right', 14) . ' ';
    $d['wrap_before'] = '<nav class="breadcrumbs" aria-label="' . esc_attr(mcr_t('Fil d’Ariane')) . '">';
    $d['wrap_after'] = '</nav>';
    $d['home'] = mcr_t('Accueil');
    return $d;
});
// "Add to cart" in lists: icon + short label; variable products go to their page.
add_filter('woocommerce_product_add_to_cart_text', function ($text, $product) {
    if (!$product->is_in_stock()) {
        return mcr_t('Voir le produit');
    }
    return $product->is_type('variable') ? mcr_t('Choisir les options') : mcr_t('Ajouter');
}, 10, 2);

// Unit label after the price ("par kg").
add_filter('woocommerce_get_price_suffix', function ($suffix, $product) {
    $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $unit = get_post_meta($id, '_mcr_unit_label', true);
    return $unit ? $suffix . ' <small class="price-unit">' . esc_html(mcr_is_en() ? str_replace('par ', 'per ', $unit) : $unit) . '</small>' : $suffix;
}, 10, 2);

// Cart count for the header badge, refreshed after AJAX add-to-cart.
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $n = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $fragments['.count[data-cart-count]'] = sprintf('<span class="count" data-cart-count%s>%d</span>', $n ? '' : ' hidden', $n);
    return $fragments;
});

/** Cart count for templates. */
function mcr_cart_count(): int
{
    return function_exists('WC') && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
}

/** Shop URLs in the current language. */
function mcr_shop_url(): string
{
    return get_permalink(wc_get_page_id('shop')) ?: home_url('/');
}

/** Top-level product categories in display order (hides "Tout" / "Uncategorized"). */
function mcr_top_categories(): array
{
    $terms = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'orderby' => 'meta_value_num', 'meta_key' => 'order', 'order' => 'ASC']);
    return array_values(array_filter(is_array($terms) ? $terms : [], fn($t) => !in_array($t->slug, ['tout', 'uncategorized', 'non-classe'], true)));
}

function mcr_child_categories(int $parent): array
{
    $terms = get_terms(['taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false, 'orderby' => 'name']);
    return is_array($terms) ? $terms : [];
}

/** Number of distinct products in a category including its sub-categories. */
function mcr_category_count(WP_Term $term): int
{
    return function_exists('mcrt_distinct_count') ? mcrt_distinct_count($term) : (int) $term->count;
}

// Product changes: recount the categories.
add_action('woocommerce_update_product', function () {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mcr\\_cnt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mcr\\_cnt\\_%'"); // phpcs:ignore
});

require_once MCRT_DIR . '/inc/template-tags.php';

// ---------- Shop filters ----------
// ?promotions=1 : only products on sale.
add_action('pre_get_posts', function (WP_Query $q) {
    if (is_admin() || !$q->is_main_query()) {
        return;
    }
    if (!empty($_GET['promotions']) && (is_shop() || $q->get('post_type') === 'product')) {
        $q->set('post__in', array_merge([0], wc_get_product_ids_on_sale()));
    }
    // ?stock=instock : hide sold-out products (WooCommerce's own visibility term).
    if (!empty($_GET['stock']) && ($q->is_post_type_archive('product') || $q->is_tax(get_object_taxonomies('product')) || $q->is_search())) {
        $tax = (array) $q->get('tax_query');
        $tax[] = ['taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => ['outofstock'], 'operator' => 'NOT IN'];
        $q->set('tax_query', $tax);
    }
    // Search: products only.
    if ($q->is_search() && !$q->get('post_type')) {
        $q->set('post_type', 'product');
    }
});

// ---------- Product page ----------
add_action('woocommerce_after_add_to_cart_button', function () {
    global $product;
    printf('<button class="buy__wish" type="button" aria-pressed="false" aria-label="%s" data-wish="%s">%s</button>', esc_attr(mcr_t('Ajouter à ma liste')), esc_attr(mcrt_wish_data($product)), mcr_icon('heart', 22));
});

add_action('woocommerce_after_add_to_cart_form', function () {
    global $product;
    if (!$product->is_in_stock() || !mcrt_has_whatsapp()) {
        return;
    }
    printf(
        '<button type="button" class="btn btn--wa buy__wa" data-wa-order data-wa-number="%s" data-name="%s" data-url="%s" data-price="%s" data-unit="%s">%s %s</button>',
        esc_attr((string) mcrt_store('whatsapp')),
        esc_attr($product->get_name()),
        esc_attr($product->get_permalink()),
        esc_attr($product->get_price()),
        esc_attr((string) get_post_meta($product->get_id(), '_mcr_unit_label', true)),
        mcr_icon('whatsapp', 20),
        esc_html(mcr_t('Commander via WhatsApp'))
    );
}, 5);

/** Whole-cart order on WhatsApp: the message lists every item, the subtotal and asks for delivery or pickup. */
add_action('woocommerce_proceed_to_checkout', function () {
    if (!mcrt_has_whatsapp() || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    $en = function_exists('mcr_is_en') && mcr_is_en();
    $lines = [$en ? 'Hello, I would like to order:' : 'Bonjour, je souhaite commander :', ''];
    foreach (WC()->cart->get_cart() as $item) {
        $p = $item['data'];
        $opts = [];
        foreach ((array) ($item['variation'] ?? []) as $k => $v) {
            if ($v !== '') {
                $opts[] = wc_attribute_label(str_replace('attribute_', '', $k), $p) . ' : ' . $v;
            }
        }
        $total = wp_strip_all_tags(WC()->cart->get_product_subtotal($p, $item['quantity']));
        $lines[] = sprintf('%d × %s%s : %s', $item['quantity'], $p->get_name(), $opts ? ' (' . implode(', ', $opts) . ')' : '', html_entity_decode($total));
    }
    $lines[] = '';
    $lines[] = ($en ? 'Subtotal: ' : 'Sous-total : ') . html_entity_decode(wp_strip_all_tags(WC()->cart->get_cart_subtotal()));
    $lines[] = '';
    $lines[] = $en ? 'Delivery or in-store pickup: ' : 'Livraison ou retrait en magasin : ';
    $lines[] = $en ? 'Name: ' : 'Nom : ';
    $lines[] = $en ? 'Address (for delivery): ' : 'Adresse (pour la livraison) : ';
    $url = mcrt_whatsapp_link(implode("\n", $lines));
    printf(
        '<a class="btn btn--wa cart__wa" href="%s" target="_blank" rel="noopener">%s %s</a><p class="cart__wa-note">%s</p>',
        esc_url($url),
        mcr_icon('whatsapp', 20),
        esc_html(mcr_t('Commander via WhatsApp')),
        esc_html($en ? 'We confirm the total and delivery fee with you on WhatsApp.' : 'Nous confirmons avec vous le total et les frais de livraison sur WhatsApp.')
    );
}, 30);

function mcrt_has_whatsapp(): bool
{
    return (string) mcrt_store('whatsapp', '') !== '';
}

// Delivery price callout, right above the add-to-cart box.
add_action('woocommerce_single_product_summary', function () {
    $d = function_exists('mcr_setting') ? mcr_setting('delivery') : [];
    $fees = array_column($d['tiers'] ?? [], 'fee');
    if (empty($d['enabled'])) {
        return;
    }
    if ($fees) {
        $en = mcr_is_en();
        printf(
            '<div class="pp-delivery"><span class="pp-delivery__ic">%s</span><p><strong>%s</strong><span>%s</span></p><a class="pp-delivery__link" href="%s">%s %s</a></div>',
            mcr_icon('truck', 22),
            esc_html($en
                ? sprintf('Delivery from %s to %s', mcrt_money(min($fees)), mcrt_money(max($fees)))
                : sprintf('Livraison de %s à %s', mcrt_money(min($fees)), mcrt_money(max($fees)))),
            esc_html($en
                ? sprintf('Depending on distance. Free from %s (within %s km).', mcrt_money((float) $d['free_min_subtotal']), $d['free_radius_km'])
                : sprintf('Selon la distance. Gratuite dès %s (jusqu’à %s km).', mcrt_money((float) $d['free_min_subtotal']), $d['free_radius_km'])),
            esc_url(home_url('/livraison/')),
            esc_html($en ? 'Rates' : 'Tarifs'),
            mcr_icon('arrow-right', 15)
        );
    }
}, 25);

add_action('woocommerce_single_product_summary', function () {
    $d = function_exists('mcr_setting') ? mcr_setting('delivery') : [];
    $tiers = $d['tiers'] ?? [];
    $fees = array_column($tiers, 'fee');
    echo '<ul class="perks">';
    printf('<li><span class="perk-ic">%s</span><span>%s</span></li>', mcr_icon('store', 18), esc_html((mcr_is_en() ? 'Free in-store pickup at ' : 'Retrait gratuit en magasin au ') . mcrt_store('street')));
    printf('<li><span class="perk-ic">%s</span><span>%s <a href="%s">%s</a></span></li>', mcr_icon('phone', 18), esc_html(mcr_is_en() ? 'Questions?' : 'Une question ?'), esc_url(mcrt_phone_href()), esc_html((string) mcrt_store('phone')));
    echo '</ul>';
}, 45);

// "Details" tab with grocery / hair attributes.
add_filter('woocommerce_product_tabs', function ($tabs) {
    global $product;
    if (function_exists('mcr_public_attributes') && mcr_public_attributes($product->get_id())) {
        $tabs['mcr_details'] = [
            'title' => mcr_is_en() ? 'Details' : 'Détails',
            'priority' => 15,
            'callback' => function () use ($product) {
                echo '<table class="attrs">';
                foreach (mcr_public_attributes($product->get_id()) as $k => $v) {
                    printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($k), nl2br(esc_html($v)));
                }
                echo '</table>';
            },
        ];
    }
    unset($tabs['reviews']);
    return $tabs;
});
add_filter('woocommerce_output_related_products_args', fn($a) => array_merge($a, ['posts_per_page' => 5, 'columns' => 5]));

// Product page: aisle above the title, availability under the price.
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if ($cat = mcrt_primary_cat($product)) {
        printf('<a class="pp-cat" href="%s">%s</a>', esc_url(get_term_link($cat)), esc_html($cat->name));
    }
}, 4);
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if ($product->managing_stock() || $product->is_type('variable')) {
        return; // WooCommerce shows the stock itself
    }
    $in = $product->is_in_stock();
    printf('<p class="pp-stock %s">%s %s</p>', $in ? 'is-in' : 'is-out', mcr_icon($in ? 'circle-check' : 'circle-x', 16), esc_html($in ? (mcr_is_en() ? 'In stock' : 'En stock') : mcr_t('Épuisé')));
}, 11);
add_filter('woocommerce_sale_flash', function ($html, $post, $product) {
    $pct = mcrt_discount($product);
    return $pct > 0 ? '<span class="onsale">-' . $pct . ' %</span>' : '';
}, 10, 3);
// Hide "SKU: N/A" on products without a code.
add_filter('wc_product_sku_enabled', function ($on) {
    if (is_admin() || !is_product()) {
        return $on;
    }
    global $product;
    return $product instanceof WC_Product && $product->get_sku() !== '' ? $on : false;
});
add_filter('woocommerce_product_related_products_heading', fn() => mcr_is_en() ? 'You may also like' : 'Vous aimerez aussi');

// ---------- Cart and checkout ----------
remove_action('woocommerce_cart_is_empty', 'wc_empty_cart_message', 10);
remove_action('woocommerce_cart_collaterals', 'woocommerce_cross_sell_display');

// Icons that appear after a cart or checkout refresh must already be on the page.
add_action('wp_footer', function () {
    if (function_exists('is_cart') && (is_cart() || is_checkout())) {
        foreach (['circle-check', 'truck', 'store', 'lock', 'arrow-right', 'trash-2', 'minus', 'plus', 'whatsapp', 'shopping-basket', 'badge-percent', 'map-pin'] as $i) {
            mcr_icon($i);
        }
    }
}, 1);

add_filter('body_class', function ($classes) {
    if (is_front_page()) {
        $classes[] = 'is-home';
    }
    if (function_exists('is_cart') && is_cart() && WC()->cart && !WC()->cart->is_empty()) {
        $classes[] = 'has-cart-bar';
    }
    return $classes;
});
