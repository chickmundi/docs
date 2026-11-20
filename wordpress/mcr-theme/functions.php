<?php
/**
 * Marché Chemin-du-Roy: light WooCommerce theme (no page builder).
 */
defined('ABSPATH') || exit;

define('MCRT_VERSION', '0.1.0');
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
    wp_enqueue_style('mcr-fonts', 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;500;600;700;800&display=swap', [], null);
    wp_enqueue_style('mcr', MCRT_URL . '/assets/theme.css', [], MCRT_VERSION);
    wp_enqueue_script('mcr', MCRT_URL . '/assets/theme.js', [], MCRT_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    wp_localize_script('mcr', 'MCR', [
        'ajax' => WC_AJAX::get_endpoint('%%endpoint%%'),
        'cartUrl' => wc_get_cart_url(),
        'lang' => mcr_lang(),
        'i18n' => [
            'added' => mcr_t('ajouté au panier'),
            'add' => mcr_t('Ajouter à ma liste'),
            'remove' => mcr_t('Retirer de ma liste'),
        ],
    ]);
}, 20);

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

/** Number of products in a category including its sub-categories. */
function mcr_category_count(WP_Term $term): int
{
    $n = (int) $term->count;
    foreach (mcr_child_categories($term->term_id) as $c) {
        $n += (int) $c->count;
    }
    return $n;
}

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

function mcrt_has_whatsapp(): bool
{
    return (string) mcrt_store('whatsapp', '') !== '';
}

add_action('woocommerce_single_product_summary', function () {
    $d = function_exists('mcr_setting') ? mcr_setting('delivery') : [];
    $tiers = $d['tiers'] ?? [];
    $fees = array_column($tiers, 'fee');
    echo '<ul class="perks">';
    if ($fees) {
        printf(
            '<li><span class="perk-ic">%s</span><span>%s <a href="%s">%s</a></span></li>',
            mcr_icon('truck', 18),
            esc_html(mcr_is_en()
                ? sprintf('Delivery from %s to %s depending on distance, free from %s (within %s km) ·', mcrt_money(min($fees)), mcrt_money(max($fees)), mcrt_money((float) $d['free_min_subtotal']), $d['free_radius_km'])
                : sprintf('Livraison de %s à %s selon la distance, gratuite dès %s (jusqu’à %s km) ·', mcrt_money(min($fees)), mcrt_money(max($fees)), mcrt_money((float) $d['free_min_subtotal']), $d['free_radius_km'])),
            esc_url(home_url('/livraison/')),
            esc_html(mcr_is_en() ? 'Rates' : 'Tarifs')
        );
    }
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
add_filter('woocommerce_output_related_products_args', fn($a) => array_merge($a, ['posts_per_page' => 8, 'columns' => 4]));
