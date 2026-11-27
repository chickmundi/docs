<?php
/**
 * Marché Chemin-du-Roy: light WooCommerce theme (no page builder).
 */
defined('ABSPATH') || exit;

define('MCRT_VERSION', '1.6.4');
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
require_once MCRT_DIR . '/inc/speed.php';
require_once MCRT_DIR . '/inc/shuffle.php';

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
    // The form itself (name, phone, delivery or pickup) comes from MCR Gestion.
    printf(
        '<div class="buy__wa-row"><button type="button" class="btn btn--wa buy__wa" data-wa-sheet="product" data-product-id="%d" data-name="%s" data-price="%s">%s %s</button>%s</div>',
        $product->get_id(),
        esc_attr($product->get_name()),
        esc_attr((string) wc_get_price_to_display($product)),
        mcr_icon('whatsapp', 20),
        esc_html(mcr_t('Commander via WhatsApp')),
        mcrt_wa_share_button($product) // phpcs:ignore
    );
}, 5);

/** "Share on WhatsApp": a friendly message in the visitor's language, with the product, its price and link. */
function mcrt_wa_share_text(WC_Product $p): string
{
    $en = mcr_is_en();
    $store = (string) mcrt_store('name', 'Marché Chemin-du-Roy');
    $money = fn($v) => html_entity_decode(wp_strip_all_tags(wc_price((float) $v)));
    if ($p->is_type('variable')) {
        $min = (float) $p->get_variation_price('min', true);
        $max = (float) $p->get_variation_price('max', true);
        $price = $min !== $max ? ($en ? 'from ' : 'dès ') . $money($min) : $money($min);
    } else {
        $price = $money(wc_get_price_to_display($p));
    }
    $line = $p->get_name() . ($p->get_price() !== '' ? ' — ' . $price : '');
    if ($p->is_on_sale() && ($pct = mcrt_discount($p)) > 0) {
        $line .= ' (' . ($en ? 'on sale ' : 'en promo ') . '-' . $pct . ' %)';
    }
    return implode("\n", [
        sprintf($en ? 'Hi! 👋 Check out this product from %s:' : 'Salut ! 👋 Regarde ce produit du %s :', $store),
        '',
        '🛒 ' . $line,
        $p->get_permalink(),
    ]);
}

function mcrt_wa_share_button(WC_Product $p): string
{
    $en = mcr_is_en();
    return sprintf(
        '<a class="btn buy__share" href="%s" target="_blank" rel="noopener" aria-label="%s">%s <span>%s</span></a>',
        esc_attr('https://wa.me/?text=' . rawurlencode(mcrt_wa_share_text($p))), // esc_url() would drop the line breaks; the text is fully encoded
        esc_attr($en ? 'Share this product on WhatsApp' : 'Partager ce produit sur WhatsApp'),
        mcr_icon('whatsapp', 20),
        esc_html($en ? 'Share' : 'Partager')
    );
}

// Out of stock (no add-to-cart form): the share button alone.
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if ($product instanceof WC_Product && !$product->is_in_stock()) {
        echo '<div class="buy__wa-row buy__wa-row--solo">' . mcrt_wa_share_button($product) . '</div>'; // phpcs:ignore
    }
}, 31);

// Link previews (WhatsApp, Facebook, Messenger): product photo, name, price.
add_action('wp_head', function () {
    if (!is_product()) {
        return;
    }
    $p = wc_get_product(get_queried_object_id());
    if (!$p) {
        return;
    }
    $img = wp_get_attachment_image_src($p->get_image_id(), 'woocommerce_single');
    $desc = wp_strip_all_tags($p->get_short_description() ?: $p->get_description());
    $desc = mb_substr(trim(preg_replace('/\s+/', ' ', $desc)), 0, 180);
    $title = (mcr_is_en() && function_exists('mcr_en_meta') && mcr_en_meta($p->get_id(), 'title') !== '' ? mcr_en_meta($p->get_id(), 'title') : $p->get_name()) . ' — ' . html_entity_decode(wp_strip_all_tags(wc_price(wc_get_price_to_display($p))));
    echo "\n" . '<meta property="og:type" content="product">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr((string) mcrt_store('name', get_bloginfo('name'))) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($p->get_permalink()) . '">' . "\n";
    echo '<meta property="og:locale" content="' . (mcr_is_en() ? 'en_CA' : 'fr_CA') . '">' . "\n";
    if ($desc) {
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    }
    if ($img) {
        echo '<meta property="og:image" content="' . esc_url($img[0]) . '">' . "\n" . '<meta property="og:image:width" content="' . (int) $img[1] . '">' . "\n" . '<meta property="og:image:height" content="' . (int) $img[2] . '">' . "\n";
    }
    echo '<meta property="product:price:amount" content="' . esc_attr(wc_format_decimal(wc_get_price_to_display($p), 2)) . '">' . "\n" . '<meta property="product:price:currency" content="' . esc_attr(get_woocommerce_currency()) . '">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}, 5);

/** Whole-cart order on WhatsApp: opens the MCR Gestion form (name, phone, delivery or pickup). */
add_action('woocommerce_proceed_to_checkout', function () {
    if (!mcrt_has_whatsapp() || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    $en = function_exists('mcr_is_en') && mcr_is_en();
    $n = WC()->cart->get_cart_contents_count();
    $summary = sprintf($en ? '%d item%s · %s' : '%d article%s · %s', $n, $n > 1 ? 's' : '', html_entity_decode(wp_strip_all_tags(WC()->cart->get_cart_subtotal())));
    printf(
        '<button type="button" class="btn btn--wa cart__wa" data-wa-sheet="cart" data-summary="%s">%s %s</button><p class="cart__wa-note">%s</p>',
        esc_attr($summary),
        mcr_icon('whatsapp', 20),
        esc_html(mcr_t('Commander via WhatsApp')),
        esc_html($en ? 'Your order with prices and total, sent in one tap. We confirm it with you on WhatsApp.' : 'Votre commande avec prix et total, envoyée en un geste. Nous la confirmons avec vous sur WhatsApp.')
    );
}, 30);

function mcrt_has_whatsapp(): bool
{
    return (string) mcrt_store('whatsapp', '') !== '';
}

// Delivery price callout, right after the add-to-cart box and the WhatsApp button.
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
}, 35);

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
add_filter('woocommerce_output_related_products_args', fn($a) => array_merge($a, ['posts_per_page' => 10, 'columns' => 5])); // shown as a slider

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

// ---------- Product page: savings, real end date with countdown, deals ----------
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product instanceof WC_Product) {
        return;
    }
    $en = mcr_is_en();
    $save = mcrt_saving($product);
    $end = mcrt_sale_end($product);
    $deals = function_exists('mcr_product_deals') ? mcr_product_deals($product) : [];
    if ($save <= 0 && !$deals) {
        return;
    }
    echo '<div class="pp-promo">';
    if ($save > 0) {
        echo '<p class="pp-promo__save">' . mcr_icon('badge-percent', 18) . ' ' . wp_kses_post(sprintf($product->is_type('variable') ? ($en ? 'Save up to <strong>%s</strong>' : 'Économisez jusqu’à <strong>%s</strong>') : ($en ? 'You save <strong>%s</strong>' : 'Vous économisez <strong>%s</strong>'), wc_price($save))) . '</p>';
    }
    if ($end > time()) {
        echo '<p class="pp-promo__ends">' . mcr_icon('clock', 16) . ' ' . esc_html($en ? 'Sale ends in' : 'Fin de la promo dans') . ' <strong data-countdown="' . (int) $end . '">' . esc_html(mcrt_end_label($end)) . '</strong> <small>(' . esc_html(wp_date($en ? 'F j' : 'j F', $end, mcrt_tz())) . ')</small></p>';
    }
    $where = $save > 0 && function_exists('mcr_product_sale_where') ? mcr_product_sale_where($product) : '';
    if ($where === 'online') {
        echo '<p class="pp-promo__where">' . mcr_icon('shopping-cart', 16) . ' ' . esc_html($en ? 'Sale price for online orders only, not in store.' : 'Prix promo valable pour les commandes en ligne seulement, pas en magasin.') . '</p>';
    } elseif ($where === 'both') {
        echo '<p class="pp-promo__where">' . mcr_icon('store', 16) . ' ' . esc_html($en ? 'Same sale price online and in store.' : 'Même prix promo en ligne et en magasin.') . '</p>';
    }
    foreach ($deals as $d) {
        $kit = $d['type'] === 'bundle';
        echo '<p class="pp-promo__deal"><span class="badge badge--deal">' . esc_html(mcr_promo_label($d)) . '</span> ' . esc_html($kit ? (mcr_promo_title($d) . ($en ? ': this product is part of a kit.' : ' : ce produit fait partie d’un kit.')) : ($en ? 'Price applied automatically in the cart.' : 'Prix appliqué automatiquement au panier.')) . ' <small class="pp-promo__dwhere">' . esc_html(mcr_promo_where($d) === 'both' ? ($en ? 'Online and in store.' : 'En ligne et en magasin.') : ($en ? 'Online orders only.' : 'Commandes en ligne seulement.')) . '</small>';
        if ($kit) {
            echo ' <a href="' . esc_url(mcrt_promos_url()) . '#h-bundles">' . esc_html($en ? 'See the kit' : 'Voir le kit') . '</a>';
        }
        echo '</p>';
    }
    echo '</div>';
}, 11);

// ---------- Product page: upcoming promotion (preview of the future price, with countdown) ----------
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product instanceof WC_Product || !function_exists('mcr_product_upcoming') || $product->is_on_sale()) {
        return;
    }
    $u = mcr_product_upcoming($product);
    if (!$u) {
        return;
    }
    $en = mcr_is_en();
    $e = $u['event'];
    echo '<div class="pp-soon"><p class="pp-soon__head">' . mcr_icon('clock', 18) . ' <strong>' . esc_html($e['title']) . '</strong> · -' . (int) $u['pct'] . ' %</p>'
        . '<p class="pp-soon__price">' . esc_html($en ? 'Promo price: ' : 'Prix promo : ') . '<b>' . wp_kses_post(($product->is_type('variable') ? ($en ? 'from ' : 'dès ') : '') . wc_price($u['price'])) . '</b> · ' . esc_html(mcrt_event_when($e)) . ($e['where_label'] ? ' (' . esc_html(mb_strtolower($e['where_label'])) . ')' : '') . '</p>'
        . '<p class="pp-soon__count">' . esc_html($en ? 'Starts in' : 'Commence dans') . ' <b data-countdown="' . (int) $e['starts'] . '" data-countdown-reload>' . esc_html(mcrt_end_label($e['starts'])) . '</b>. ' . esc_html($en ? 'Until then, the regular price applies.' : 'D’ici là, le prix régulier s’applique.') . '</p></div>';
}, 11);

// ---------- Product page: promotions valid in the physical store only ----------
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product instanceof WC_Product || !function_exists('mcr_product_store_deals')) {
        return;
    }
    $deals = mcr_product_store_deals($product);
    if (!$deals) {
        return;
    }
    $en = mcr_is_en();
    $street = function_exists('mcr_setting') ? (string) mcr_setting('store', 'street') : '';
    echo '<div class="pp-store">';
    foreach ($deals as $d) {
        [, $to] = mcr_promo_range($d);
        $what = mcr_promo_label($d);
        if ($d['type'] === 'percent' && ($price = mcr_promo_percent_price($product, $d)) > 0) {
            $what .= ' · ' . html_entity_decode(wp_strip_all_tags(wc_price($price))) . ($product->is_type('variable') ? ($en ? ' and up' : ' et plus') : '');
        } elseif ($d['type'] === 'bundle') {
            $what = mcr_promo_title($d) . ' · ' . $what;
        }
        echo '<p class="pp-store__deal">' . mcr_icon('store', 18) . ' <span><strong class="pp-store__tag">' . esc_html($en ? 'In store only' : 'En magasin seulement') . '</strong> ' . esc_html($what);
        if ($to) {
            echo ' <small>' . esc_html(($en ? 'until ' : 'jusqu’au ') . wp_date($en ? 'F j' : 'j F', $to, mcrt_tz())) . '</small>';
        }
        echo '<small class="pp-store__where">' . esc_html(($en ? 'At the counter' : 'À la caisse') . ($street ? ', ' . $street : '') . ($en ? '. Not applied to online orders.' : '. Ne s’applique pas aux commandes en ligne.')) . '</small></span></p>';
    }
    echo '</div>';
}, 12);

// ---------- Homepage promotions pop-up (before the icon sprite, which prints at wp_footer priority 1) ----------
add_action('wp_footer', function () {
    if (is_front_page() && !is_customize_preview() && function_exists('mcr_promo_banners')) { // Promotions module on
        get_template_part('template-parts/promo-popup');
    }
}, 0);

// ---------- Product page: flash sale (countdown, units sold), low stock, ordered this month ----------
add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product instanceof WC_Product || !function_exists('mcr_flash_for_product')) {
        return;
    }
    $en = mcr_is_en();
    $d = mcr_flash_for_product($product->get_id());
    if ($d) {
        [, $end] = mcr_flash_window($d);
        $sold = (int) ($d['sold'] ?? 0);
        $qty = max(1, (int) $d['qty']);
        $per = (int) ($d['per_order'] ?? 0);
        echo '<div class="pp-flash"><p class="pp-flash__k">' . mcr_icon('zap', 18) . ' ' . esc_html($en ? 'Flash sale' : 'Vente éclair') . '</p>'
            . '<p class="pp-flash__time">' . esc_html($en ? 'Ends in' : 'Se termine dans') . ' <b data-countdown="' . (int) $end . '" data-countdown-reload>' . esc_html(mcrt_end_label($end)) . '</b></p>'
            . '<div class="fcard__bar"><span style="width:' . max(6, (int) round($sold / $qty * 100)) . '%"></span></div>'
            . '<p class="pp-flash__sold">' . esc_html(sprintf($en ? '%1$d sold out of %2$d' : '%1$d vendus sur %2$d', $sold, $qty)) . ($per ? ' · ' . esc_html(sprintf($en ? 'max %d per order' : 'max %d par commande', $per)) : '') . '</p></div>';
    }
    $low = mcr_low_stock_qty($product);
    $hot = mcr_sold30_label($product);
    if ($low || $hot) {
        echo '<p class="pp-proof">';
        if ($low) {
            echo '<span class="pp-proof__low">' . esc_html(sprintf($en ? 'Only %d left in stock' : 'Plus que %d en stock', $low)) . '</span>';
        }
        if ($hot) {
            echo '<span class="pp-proof__hot">' . mcr_icon('flame', 15) . ' ' . esc_html($hot) . '</span>';
        }
        echo '</p>';
    }
}, 10);

// ---------------------------------------------------------------- Customer sign-in / account creation page
add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_account_page') || !is_account_page() || is_user_logged_in()) {
        return;
    }
    wp_enqueue_style('mcr-auth', MCRT_URL . '/assets/auth.css', ['mcr'], MCRT_VERSION);
    wp_enqueue_script('mcr-auth', MCRT_URL . '/assets/auth.js', [], MCRT_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    wp_localize_script('mcr-auth', 'MCR_AUTH', [
        'en' => mcr_is_en(),
        'words' => mcr_is_en() ? ['Too short', 'Weak', 'Fair', 'Good', 'Strong'] : ['Trop court', 'Faible', 'Moyen', 'Bon', 'Excellent'],
        'required' => mcr_is_en() ? 'Fill in this field.' : 'Remplissez ce champ.',
        'email' => mcr_is_en() ? 'Enter a valid email address.' : 'Entrez un courriel valide.',
        'short' => mcr_is_en() ? 'At least 8 characters.' : 'Au moins 8 caractères.',
    ]);
    // Our own gentle strength indicator replaces WooCommerce's, which blocks the button until "strong".
    wp_dequeue_script('wc-password-strength-meter');
}, 30);

// Account creation: first name kept on the account and for the next checkout; passwords of 8+ characters.
add_filter('woocommerce_registration_errors', function ($errors, $username, $email) {
    $pw = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    if ('no' === get_option('woocommerce_registration_generate_password') && $pw !== '' && strlen($pw) < 8) {
        $errors->add('mcr_short_password', mcr_is_en() ? 'Choose a password of at least 8 characters.' : 'Choisissez un mot de passe d’au moins 8 caractères.');
    }
    return $errors;
}, 10, 3);
add_action('woocommerce_created_customer', function ($customer_id) {
    $first = isset($_POST['mcr_first_name']) ? sanitize_text_field(wp_unslash($_POST['mcr_first_name'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    if ($first !== '') {
        update_user_meta($customer_id, 'first_name', $first);
        update_user_meta($customer_id, 'billing_first_name', $first);
        wp_update_user(['ID' => $customer_id, 'display_name' => $first]);
    }
});

// Privacy notice under account creation, in the visitor's language.
add_filter('woocommerce_get_privacy_policy_text', function ($text, $type) {
    if ($type !== 'registration') {
        return $text;
    }
    return mcr_is_en()
        ? 'Your details are used to manage your account and your orders, as described in our [privacy_policy].'
        : 'Vos renseignements servent à gérer votre compte et vos commandes, comme décrit dans notre [privacy_policy].';
}, 10, 2);
