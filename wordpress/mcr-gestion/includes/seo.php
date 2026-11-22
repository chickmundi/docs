<?php
/**
 * Being found by Google and by AI assistants (ChatGPT, Perplexity, Gemini, Claude):
 *  - the store as a "GroceryStore" (address, map position, hours, phone, delivery area) and
 *    the site search, as schema.org JSON-LD;
 *  - meta descriptions and link previews (Open Graph) for the home page, categories and pages;
 *  - the seller on WooCommerce's own product data;
 *  - /llms.txt: a plain summary of the store for AI assistants.
 * Everything comes from MCR Gestion settings and the real catalogue. Skipped when an SEO
 * plugin (Yoast, Rank Math, All in One SEO) already writes descriptions.
 */
defined('ABSPATH') || exit;

function mcr_seo_plugin_active(): bool
{
    return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION');
}

function mcr_seo_clip(string $text, int $max = 158): string
{
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES))));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > 80 ? mb_substr($cut, 0, $space) : $cut, " ,;:.-") . '…';
}

/** Opening hours as schema.org OpeningHoursSpecification entries. */
function mcr_seo_hours(): array
{
    $names = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $out = [];
    foreach ((array) mcr_setting('hours') as $d => $h) {
        if (!isset($names[(int) $d]) || !empty($h['closed']) || empty($h['open']) || empty($h['close'])) {
            continue;
        }
        $out[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/' . $names[(int) $d], 'opens' => $h['open'], 'closes' => $h['close']];
    }
    return $out;
}

function mcr_seo_store_schema(): array
{
    $s = mcr_setting('store');
    $home = home_url('/');
    $logo = function_exists('get_theme_file_uri') ? get_theme_file_uri('assets/MRC-logo.png') : '';
    $logo = $logo && file_exists(get_theme_file_path('assets/MRC-logo.png')) ? $logo : (get_site_icon_url(512) ?: '');
    $d = mcr_setting('delivery');
    $store = [
        '@type' => 'GroceryStore',
        '@id' => untrailingslashit((string) get_option('home')) . '/#store',
        'name' => $s['name'],
        'description' => mcr_is_en()
            ? 'African and local grocery in Trois-Rivières: African groceries, frozen meat and fish, wigs, hair extensions and hair care. Delivery 7 days a week and free in-store pickup.'
            : 'Épicerie africaine et d’ici à Trois-Rivières : épicerie africaine, viandes et poissons surgelés, perruques, mèches et soins capillaires. Livraison 7 jours sur 7 et cueillette gratuite en magasin.',
        'url' => $home,
        'telephone' => $s['phone'],
        'email' => $s['email'],
        'priceRange' => '$$',
        'currenciesAccepted' => 'CAD',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $s['street'], 'addressLocality' => $s['city'], 'addressRegion' => $s['region'], 'postalCode' => $s['postal_code'], 'addressCountry' => 'CA'],
        'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float) $s['lat'], 'longitude' => (float) $s['lon']],
        'openingHoursSpecification' => mcr_seo_hours(),
        'sameAs' => array_values(array_filter([$s['facebook'] ?? '', $s['instagram'] ?? ''])),
        'knowsLanguage' => ['fr-CA', 'en-CA'],
    ];
    if ($logo) {
        $store['logo'] = $logo;
        $store['image'] = $logo;
    }
    if (!empty($d['enabled'])) {
        $store['areaServed'] = ['@type' => 'GeoCircle', 'geoMidpoint' => $store['geo'], 'geoRadius' => (int) round((float) $d['max_km'] * 1000)];
    }
    return $store;
}

// ---------------------------------------------------------------- <head>

add_action('wp_head', function () {
    if (is_admin() || is_feed()) {
        return;
    }
    $graph = [mcr_seo_store_schema()];
    if (is_front_page()) {
        $graph[] = [
            '@type' => 'WebSite',
            '@id' => untrailingslashit((string) get_option('home')) . '/#website',
            'url' => home_url('/'),
            'name' => mcr_setting('store', 'name'),
            'inLanguage' => mcr_is_en() ? 'en-CA' : 'fr-CA',
            'publisher' => ['@id' => untrailingslashit((string) get_option('home')) . '/#store'],
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => add_query_arg(['post_type' => 'product'], home_url('/')) . '&s={search_term_string}'], 'query-input' => 'required name=search_term_string'],
        ];
    }
    echo "\n<script type=\"application/ld+json\">" . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";

    if (mcr_seo_plugin_active()) {
        return;
    }
    $en = mcr_is_en();
    $desc = '';
    $img = '';
    $type = 'website';
    if (is_front_page()) {
        $desc = $en
            ? 'African and local grocery in Trois-Rivières: 1,100+ products, frozen meat and fish, wigs and hair care. Delivery 7 days a week, free from $99, or free in-store pickup.'
            : 'Épicerie africaine et d’ici à Trois-Rivières : plus de 1 100 produits, viandes et poissons surgelés, perruques et soins capillaires. Livraison 7 jours sur 7, gratuite dès 99 $, ou cueillette gratuite.';
    } elseif (function_exists('is_product_category') && (is_product_category() || is_product_tag())) {
        $t = get_queried_object();
        if ($t instanceof WP_Term) {
            $n = function_exists('mcr_category_count') ? mcr_category_count($t) : (int) $t->count;
            $desc = trim((string) term_description($t)) !== '' ? (string) term_description($t) : sprintf($en ? '%1$s at %3$s in Trois-Rivières: %2$d products. Delivery 7 days a week or free in-store pickup.' : '%1$s au %3$s à Trois-Rivières : %2$d produits. Livraison 7 jours sur 7 ou cueillette gratuite en magasin.', $t->name, $n, mcr_setting('store', 'name'));
            $thumb = (int) get_term_meta($t->term_id, 'thumbnail_id', true);
            $img = $thumb ? (string) wp_get_attachment_image_url($thumb, 'large') : '';
        }
    } elseif (is_singular() && !(function_exists('is_product') && is_product())) {
        $post = get_queried_object();
        if ($post instanceof WP_Post) {
            $desc = has_excerpt($post) ? $post->post_excerpt : strip_shortcodes((string) $post->post_content);
            $type = 'article';
            $img = (string) get_the_post_thumbnail_url($post, 'large');
        }
    } elseif (function_exists('is_product') && is_product()) {
        $p = wc_get_product(get_queried_object_id());
        if ($p) {
            $desc = $p->get_short_description() ?: $p->get_description();
            if ($desc === '') {
                $desc = sprintf($en ? '%s at %s, Trois-Rivières. Delivery or in-store pickup.' : '%s au %s, Trois-Rivières. Livraison ou cueillette en magasin.', $p->get_name(), mcr_setting('store', 'name'));
            }
        }
    } elseif (function_exists('is_shop') && is_shop()) {
        $desc = $en ? 'Shop all our African and local groceries, frozen foods, wigs and hair care online. Delivery in Trois-Rivières or free in-store pickup.' : 'Tous nos produits en ligne : épicerie africaine et d’ici, surgelés, perruques et soins capillaires. Livraison à Trois-Rivières ou cueillette gratuite.';
    }
    $desc = mcr_seo_clip((string) $desc);
    if ($desc !== '') {
        echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    }
    // Product pages get their own link-preview tags from the theme.
    if ((function_exists('is_product') && is_product()) || $desc === '') {
        return;
    }
    $title = wp_get_document_title();
    $img = $img ?: (string) (get_site_icon_url(512) ?: '');
    echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr((string) mcr_setting('store', 'name')) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(mcr_switch_url(mcr_lang())) . '">' . "\n";
    echo '<meta property="og:locale" content="' . ($en ? 'en_CA' : 'fr_CA') . '">' . "\n";
    if ($img) {
        echo '<meta property="og:image" content="' . esc_url($img) . '">' . "\n";
    }
}, 4);

// The store as the seller on WooCommerce's own product data.
add_filter('woocommerce_structured_data_product', function ($markup) {
    if (!is_array($markup)) {
        return $markup;
    }
    if (isset($markup['offers']) && is_array($markup['offers'])) {
        foreach ($markup['offers'] as &$offer) {
            if (is_array($offer)) {
                $offer['seller'] = ['@id' => untrailingslashit((string) get_option('home')) . '/#store'];
            }
        }
        unset($offer);
    }
    return $markup;
});

// ---------------------------------------------------------------- /llms.txt

function mcr_llms_txt(): string
{
    $s = mcr_setting('store');
    $d = mcr_setting('delivery');
    $home = untrailingslashit((string) get_option('home'));
    $days = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $hours = [];
    $clock = function (string $t): string {
        [$h, $m] = array_map('intval', explode(':', $t . ':0'));
        return $m ? sprintf('%d h %02d', $h, $m) : sprintf('%d h', $h);
    };
    foreach ((array) mcr_setting('hours') as $i => $h) {
        $hours[] = '- ' . ucfirst($days[(int) $i] ?? (string) $i) . ' : ' . (!empty($h['closed']) ? 'fermé' : $clock((string) $h['open']) . ' – ' . $clock((string) $h['close']));
    }
    $money = fn($v) => html_entity_decode(wp_strip_all_tags(wc_price((float) $v)));
    $tiers = [];
    foreach ((array) ($d['tiers'] ?? []) as $t) {
        $tiers[] = sprintf('- De %s à %s km : %s', rtrim(rtrim(number_format((float) $t['from_km'], 1, ',', ''), '0'), ','), rtrim(rtrim(number_format((float) $t['to_km'], 1, ',', ''), '0'), ','), $money($t['fee']));
    }
    $cats = [];
    foreach (function_exists('mcr_top_categories') ? mcr_top_categories() : [] as $c) {
        $n = function_exists('mcr_category_count') ? mcr_category_count($c) : (int) $c->count;
        if ($n > 0 && !in_array($c->slug, ['autres', 'tout'], true)) {
            $cats[] = sprintf('- [%s](%s) : %d produits', $c->name, $home . '/fr/product-category/' . $c->slug . '/', $n);
        }
    }
    $page = fn(string $slug) => $home . '/fr/' . $slug . '/';
    $L = [
        '# ' . $s['name'],
        '',
        '> Épicerie africaine et d’ici à Trois-Rivières (Québec, Canada) : épicerie africaine, viandes et poissons surgelés, perruques, mèches et soins capillaires. Plus de 1 100 produits en ligne, livraison 7 jours sur 7 et cueillette gratuite en magasin. Site en français et en anglais (/fr/, /en/).',
        '',
        '## Magasin',
        '',
        '- Adresse : ' . $s['street'] . ', ' . $s['city'] . ', ' . $s['region'] . ' ' . $s['postal_code'] . ', Canada',
        '- Téléphone : ' . $s['phone'],
        '- WhatsApp : ' . ($s['whatsapp_display'] ?? ''),
        '- Courriel : ' . $s['email'],
        '- Services : transfert d’argent Ria en magasin',
        '',
        '## Heures d’ouverture',
        '',
        ...$hours,
        '',
        '## Livraison et cueillette',
        '',
        !empty($d['enabled']) ? sprintf('Livraison jusqu’à %s km du magasin, prix selon la distance par la route :', rtrim(rtrim(number_format((float) $d['max_km'], 1, ',', ''), '0'), ',')) : 'Livraison : non disponible pour le moment.',
        ...$tiers,
        !empty($d['enabled']) ? sprintf('- Livraison gratuite dès %s d’achats (jusqu’à %s km)', $money($d['free_min_subtotal']), $d['free_radius_km']) : '',
        '- Cueillette gratuite en magasin, à une heure choisie selon les heures d’ouverture',
        '- Paiement à la livraison ou au retrait possible ; commande possible aussi par WhatsApp',
        '',
        '## Rayons',
        '',
        ...$cats,
        '',
        '## Pages utiles',
        '',
        '- [Boutique](' . $page('boutique') . ') : tous les produits',
        '- [Promotions](' . $page(get_option('mcr_promotions_page') ? (string) get_post_field('post_name', (int) get_option('mcr_promotions_page')) : 'promotions') . ') : produits en promotion et offres en cours',
        '- [Livraison et cueillette](' . $page('livraison') . ') : zones, prix et vérification d’adresse',
        '- [Contact](' . $page('contact') . ')',
        '- [À propos](' . $page('a-propos') . ')',
        '- [English version](' . $home . '/en/)',
        '',
    ];
    return implode("\n", array_filter($L, fn($l) => $l !== null)) . "\n";
}

add_action('init', function () {
    $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (untrailingslashit($path) !== mcr_home_path() . '/llms.txt' || !function_exists('wc_price')) {
        return;
    }
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');
    echo mcr_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text
    exit;
}, 0);

// Hostinger Tools writes a static /llms.txt (only blog posts). Write ours in its place, and keep
// it current (hourly, and after settings or products change); the request handler above serves
// it on hosts without a static file.
function mcr_llms_write(): void
{
    $file = ABSPATH . 'llms.txt';
    $content = mcr_llms_txt();
    if ((!file_exists($file) && !is_writable(ABSPATH)) || (file_exists($file) && !is_writable($file))) {
        return;
    }
    if (!file_exists($file) || md5_file($file) !== md5($content)) {
        file_put_contents($file, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
}
add_action('mcr_llms_refresh', 'mcr_llms_write');
add_action('init', function () {
    if (!wp_next_scheduled('mcr_llms_refresh')) {
        wp_schedule_event(time() + 60, 'hourly', 'mcr_llms_refresh');
    }
});
add_action('admin_init', function () {
    if (!get_transient('mcr_llms_fresh') && current_user_can('manage_woocommerce')) {
        set_transient('mcr_llms_fresh', 1, HOUR_IN_SECONDS);
        mcr_llms_write();
    }
});
add_action('update_option_mcr_store', 'mcr_llms_write');
add_action('update_option_mcr_hours', 'mcr_llms_write');
add_action('update_option_mcr_delivery', 'mcr_llms_write');

// English image descriptions on /en/ pages.
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment) {
    if (function_exists('mcr_is_en') && mcr_is_en() && $attachment instanceof WP_Post) {
        $en = trim((string) get_post_meta($attachment->ID, '_mcr_alt_en', true));
        if ($en !== '' && (!isset($attr['alt']) || $attr['alt'] !== '')) {
            $attr['alt'] = $en;
        }
    }
    return $attr;
}, 20, 2);

// English product name in the page title (browser tab, Google) on /en/.
add_filter('document_title_parts', function ($parts) {
    if (function_exists('mcr_is_en') && mcr_is_en() && function_exists('is_product') && is_product()) {
        $en = function_exists('mcr_en_meta') ? mcr_en_meta((int) get_queried_object_id(), 'title') : '';
        if ($en !== '') {
            $parts['title'] = $en;
        }
    }
    return $parts;
});
