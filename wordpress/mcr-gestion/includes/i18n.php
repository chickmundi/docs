<?php
/**
 * French / English without a translation plugin.
 *
 * - Addresses: /fr/... and /en/... (same as the old TranslatePress setup, so existing links keep working).
 *   The prefix is removed before WordPress reads the address, and added back to every front-end link.
 * - WordPress and WooCommerce texts switch to English on /en/ (locale en_US).
 * - Products, categories and pages get optional English fields; French is shown when English is empty.
 * - Theme texts go through mcr_t('Texte français').
 */
defined('ABSPATH') || exit;

const MCR_LANGS = ['fr', 'en'];
const MCR_DEFAULT_LANG = 'fr';

function mcr_lang(): string
{
    return $GLOBALS['mcr_lang'] ?? MCR_DEFAULT_LANG;
}

function mcr_is_en(): bool
{
    return mcr_lang() === 'en';
}

/** Path of the site inside the domain, e.g. '' or '/boutique'. */
function mcr_home_path(): string
{
    return rtrim((string) parse_url(get_option('home'), PHP_URL_PATH), '/');
}

/** Paths that must never get a language prefix. */
function mcr_is_technical_path(string $path): bool
{
    $p = '/' . ltrim(strtok($path, '?') ?: '', '/');
    return (bool) preg_match('#^/(wp-admin|wp-json|wp-content|wp-includes|wp-login\.php|xmlrpc\.php|wp-cron\.php|robots\.txt|wp-sitemap|sitemap[^/]*\.xml|favicon\.ico)#', $p);
}

// 1. Read the language from the address, as early as possible (before WordPress parses the request).
(function () {
    if (defined('WP_CLI') || is_admin()) {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $home = preg_quote(mcr_home_path(), '#');
    if (preg_match("#^{$home}/(fr|en)(/|\?|$)#", $uri, $m)) {
        $GLOBALS['mcr_lang'] = $m[1];
        $stripped = preg_replace("#^({$home})/(fr|en)(?=/|\?|$)#", '$1', $uri, 1);
        $_SERVER['REQUEST_URI'] = ($stripped === '' || $stripped[0] === '?') ? '/' . $stripped : $stripped;
        $GLOBALS['mcr_lang_in_url'] = true;
    }
})();

// 2. WordPress and WooCommerce texts in English on /en/.
add_filter('locale', function ($locale) {
    if (is_admin()) {
        return $locale;
    }
    return mcr_is_en() ? 'en_US' : 'fr_CA';
});

// 3. Add the language prefix to front-end links.
function mcr_prefix_url(string $url, ?string $lang = null): string
{
    $lang = $lang ?? mcr_lang();
    $home = untrailingslashit(get_option('home'));
    $home_alt = set_url_scheme($home, str_starts_with($home, 'https') ? 'http' : 'https');
    foreach ([$home, $home_alt] as $h) {
        if (str_starts_with($url, $h)) {
            $rest = substr($url, strlen($h));
            if (preg_match('#^/(fr|en)(/|\?|$)#', $rest)) {
                $rest = preg_replace('#^/(fr|en)(?=/|\?|$)#', '', $rest, 1);
            }
            if (mcr_is_technical_path($rest) || str_contains($rest, 'wc-api')) {
                return $url;
            }
            return $h . '/' . $lang . ($rest === '' ? '/' : $rest);
        }
    }
    return $url;
}

add_filter('home_url', function ($url, $path) {
    // Only on real front-end pages: never in the admin (or admin-ajax), REST, cron or CLI,
    // so WordPress always writes its rewrite rules with the true site address.
    if (is_admin() || defined('REST_REQUEST') || wp_doing_cron() || defined('WP_CLI') || empty($GLOBALS['mcr_lang_in_url']) || mcr_is_technical_path((string) $path)) {
        return $url;
    }
    return mcr_prefix_url($url);
}, 10, 2);

// 4. Addresses without a language go to /fr/ (GET only), and the choice is remembered for AJAX calls.
add_action('template_redirect', function () {
    if (empty($GLOBALS['mcr_lang_in_url']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !is_feed() && !is_robots() && !wp_doing_ajax() && empty($_GET['wc-ajax'])) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        wp_safe_redirect(untrailingslashit(get_option('home')) . '/' . MCR_DEFAULT_LANG . substr($uri, strlen(mcr_home_path())), 301);
        exit;
    }
    if (!headers_sent() && (($_COOKIE['mcr_lang'] ?? '') !== mcr_lang())) {
        setcookie('mcr_lang', mcr_lang(), ['expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'samesite' => 'Lax', 'secure' => is_ssl()]);
    }
}, 1);

/** Same page in the other language. */
function mcr_switch_url(string $lang): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = substr($uri, strlen(mcr_home_path()));
    return untrailingslashit(get_option('home')) . '/' . $lang . ($path === '' ? '/' : $path);
}

// 5. Tell search engines about both versions.
add_action('wp_head', function () {
    foreach (MCR_LANGS as $l) {
        printf('<link rel="alternate" hreflang="%s" href="%s" />' . "\n", $l === 'fr' ? 'fr-CA' : 'en-CA', esc_url(mcr_switch_url($l)));
    }
    printf('<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url(mcr_switch_url(MCR_DEFAULT_LANG)));
}, 2);

add_filter('language_attributes', function ($attr) {
    return preg_replace('/lang="[^"]*"/', 'lang="' . (mcr_is_en() ? 'en-CA' : 'fr-CA') . '"', $attr);
});

// 6. English content for products, pages and categories (falls back to French).
function mcr_en_meta(int $post_id, string $field): string
{
    return trim((string) get_post_meta($post_id, "_mcr_{$field}_en", true));
}

add_filter('the_title', function ($title, $id = 0) {
    if (!mcr_is_en() || is_admin() || !$id) {
        return $title;
    }
    $en = mcr_en_meta((int) $id, 'title');
    return $en !== '' ? $en : $title;
}, 10, 2);

add_filter('woocommerce_product_get_name', function ($name, $product) {
    if (!mcr_is_en() || is_admin()) {
        return $name;
    }
    $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $en = mcr_en_meta($id, 'title');
    return $en !== '' ? $en : $name;
}, 10, 2);

add_filter('woocommerce_product_variation_get_name', function ($name, $product) {
    if (!mcr_is_en() || is_admin()) {
        return $name;
    }
    $en = mcr_en_meta($product->get_parent_id(), 'title');
    return $en !== '' ? preg_replace('/^[^–-]+/u', $en . ' ', $name, 1) : $name;
}, 10, 2);

foreach (['short_description' => 'woocommerce_product_get_short_description', 'description' => 'woocommerce_product_get_description'] as $field => $hook) {
    add_filter($hook, function ($value, $product) use ($field) {
        if (!mcr_is_en() || is_admin()) {
            return $value;
        }
        $en = mcr_en_meta($product->get_id(), $field);
        return $en !== '' ? $en : $value;
    }, 10, 2);
}

add_filter('the_content', function ($content) {
    if (!mcr_is_en() || is_admin() || !in_the_loop() || get_post_type() === 'product') {
        return $content;
    }
    $en = mcr_en_meta(get_the_ID(), 'content');
    return $en !== '' ? wpautop($en) : $content;
}, 5);

add_filter('get_term', function ($term) {
    if (!mcr_is_en() || is_admin() || !($term instanceof WP_Term) || !in_array($term->taxonomy, ['product_cat', 'mcr_department', 'product_tag'], true)) {
        return $term;
    }
    $name = trim((string) get_term_meta($term->term_id, 'mcr_name_en', true));
    $desc = trim((string) get_term_meta($term->term_id, 'mcr_description_en', true));
    if ($name !== '') {
        $term->name = $name;
    }
    if ($desc !== '') {
        $term->description = $desc;
    }
    return $term;
});

// Prices: "167,64 $" in French, "$167.64" in English.
add_filter('wc_price_args', function ($args) {
    if (is_admin() && !wp_doing_ajax()) {
        return $args;
    }
    if (mcr_is_en()) {
        return array_merge($args, ['decimal_separator' => '.', 'thousand_separator' => ',', 'price_format' => '%1$s%2$s']);
    }
    return array_merge($args, ['decimal_separator' => ',', 'thousand_separator' => ' ', 'price_format' => '%2$s&nbsp;%1$s']);
});
add_filter('woocommerce_get_price_decimal_separator', fn($sep) => is_admin() ? $sep : (mcr_is_en() ? '.' : ','));
add_filter('woocommerce_get_price_thousand_separator', fn($sep) => is_admin() ? $sep : (mcr_is_en() ? ',' : ' '));
add_filter('woocommerce_price_format', fn($f) => is_admin() ? $f : (mcr_is_en() ? '%1$s%2$s' : '%2$s&nbsp;%1$s'));

// Keep the shopper's language on orders (for emails and the back office).
add_action('woocommerce_checkout_create_order', function ($order) {
    $order->update_meta_data('_mcr_lang', mcr_lang());
});

// 7. Theme texts.
function mcr_t(string $fr): string
{
    if (!mcr_is_en()) {
        return $fr;
    }
    static $dict = null;
    $dict ??= require MCR_DIR . 'includes/strings-en.php';
    return $dict[$fr] ?? $fr;
}

function mcr_e(string $fr): void
{
    echo esc_html(mcr_t($fr));
}

/** A setting that exists in both languages, e.g. mcr_l(mcr_setting('delivery'), 'eta'). */
function mcr_l(array $values, string $key): string
{
    return (string) ($values["{$key}_" . mcr_lang()] ?? $values["{$key}_fr"] ?? '');
}

// 8. Back office: English fields on products, pages and categories.
add_action('add_meta_boxes', function () {
    foreach (['product', 'page'] as $type) {
        add_meta_box('mcr_en', 'Version anglaise (English)', 'mcr_render_en_box', $type, 'normal', 'default');
    }
});

function mcr_render_en_box(WP_Post $post): void
{
    wp_nonce_field('mcr_en_save', 'mcr_en_nonce');
    $fields = $post->post_type === 'product'
        ? ['title' => 'Nom en anglais', 'short_description' => 'Résumé en anglais', 'description' => 'Description en anglais']
        : ['title' => 'Titre en anglais', 'content' => 'Contenu en anglais'];
    echo '<p class="description">Laissez vide pour afficher le texte français sur la version anglaise du site.</p>';
    foreach ($fields as $key => $label) {
        $value = get_post_meta($post->ID, "_mcr_{$key}_en", true);
        echo '<p><label for="mcr_en_' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        if ($key === 'title') {
            echo '<input type="text" class="widefat" id="mcr_en_title" name="mcr_en[title]" value="' . esc_attr($value) . '"></p>';
        } else {
            echo '<textarea class="widefat" rows="' . ($key === 'short_description' ? 3 : 8) . '" id="mcr_en_' . esc_attr($key) . '" name="mcr_en[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea></p>';
        }
    }
}

add_action('save_post', function ($post_id) {
    if (!isset($_POST['mcr_en_nonce']) || !wp_verify_nonce($_POST['mcr_en_nonce'], 'mcr_en_save') || !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    foreach ((array) ($_POST['mcr_en'] ?? []) as $key => $value) {
        if (!in_array($key, ['title', 'short_description', 'description', 'content'], true)) {
            continue;
        }
        $clean = $key === 'title' ? sanitize_text_field(wp_unslash($value)) : wp_kses_post(wp_unslash($value));
        $clean === '' ? delete_post_meta($post_id, "_mcr_{$key}_en") : update_post_meta($post_id, "_mcr_{$key}_en", $clean);
    }
});

foreach (['product_cat', 'mcr_department'] as $tax) {
    add_action("{$tax}_edit_form_fields", function ($term) {
        $name = get_term_meta($term->term_id, 'mcr_name_en', true);
        $desc = get_term_meta($term->term_id, 'mcr_description_en', true);
        wp_nonce_field('mcr_term_en', 'mcr_term_en_nonce');
        echo '<tr class="form-field"><th><label for="mcr_name_en">Nom en anglais</label></th><td><input type="text" id="mcr_name_en" name="mcr_name_en" value="' . esc_attr($name) . '"></td></tr>';
        echo '<tr class="form-field"><th><label for="mcr_description_en">Description en anglais</label></th><td><textarea id="mcr_description_en" name="mcr_description_en" rows="3">' . esc_textarea($desc) . '</textarea></td></tr>';
    });
    add_action("{$tax}_add_form_fields", function () {
        wp_nonce_field('mcr_term_en', 'mcr_term_en_nonce');
        echo '<div class="form-field"><label for="mcr_name_en">Nom en anglais</label><input type="text" id="mcr_name_en" name="mcr_name_en"></div>';
    });
    foreach (["created_{$tax}", "edited_{$tax}"] as $hook) {
        add_action($hook, function ($term_id) {
            if (!isset($_POST['mcr_term_en_nonce']) || !wp_verify_nonce($_POST['mcr_term_en_nonce'], 'mcr_term_en') || !current_user_can('manage_product_terms')) {
                return;
            }
            foreach (['mcr_name_en' => 'sanitize_text_field', 'mcr_description_en' => 'sanitize_textarea_field'] as $key => $fn) {
                if (isset($_POST[$key])) {
                    $v = $fn(wp_unslash($_POST[$key]));
                    $v === '' ? delete_term_meta($term_id, $key) : update_term_meta($term_id, $key, $v);
                }
            }
        });
    }
}

// English title for the "pay on delivery or at pickup" option.
add_filter('woocommerce_gateway_title', function ($title, $id) {
    if ($id === 'cod' && !is_admin() && mcr_is_en()) {
        return 'Pay on delivery or at pickup';
    }
    return $title;
}, 10, 2);
add_filter('woocommerce_gateway_description', function ($desc, $id) {
    if ($id === 'cod' && !is_admin() && mcr_is_en()) {
        return 'Pay by debit, credit or cash when you receive your order.';
    }
    return $desc;
}, 10, 2);
