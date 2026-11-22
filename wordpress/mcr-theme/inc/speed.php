<?php
/**
 * Speed: self-hosted fonts, minified files, scripts out of the way, HTML fragment cache.
 */
defined('ABSPATH') || exit;

// ---------- Fragment cache (homepage sections, category menus) ----------

/** Cache "version": changes whenever products, prices, stock, promotions or settings change. */
function mcrt_cache_ver(): string
{
    return (string) get_option('mcrt_cache_ver', '1');
}

function mcrt_cache_flush(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    update_option('mcrt_cache_ver', (string) time(), true);
}

foreach (['save_post_product', 'woocommerce_update_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock',
    'woocommerce_product_set_stock_status', 'woocommerce_scheduled_sales', 'edited_product_cat', 'created_product_cat',
    'delete_product_cat', 'mcr_promo_switch', 'trashed_post', 'switch_theme'] as $hook) {
    add_action($hook, 'mcrt_cache_flush');
}
add_action('updated_option', function ($name) {
    if (str_starts_with($name, 'mcr_') && !preg_match('/^mcr_(fbt_|llms|push_|report_|content_|rem_|ai_|vapid|tax_)/', $name) || in_array($name, ['woocommerce_currency', 'woocommerce_price_num_decimals'], true)) {
        mcrt_cache_flush();
    }
});

/**
 * Print a piece of HTML from the cache, or build it with $render and keep it 15 minutes.
 * Only for visitors who are not logged in (what they see is the same for everyone).
 * Icons used inside are remembered too, so the icon sprite still includes them.
 */
function mcrt_cached(string $name, callable $render, int $ttl = 15 * MINUTE_IN_SECONDS): void
{
    if (is_user_logged_in() || is_customize_preview() || isset($_GET['nocache'])) { // phpcs:ignore WordPress.Security.NonceVerification
        $render();
        return;
    }
    $wa = function_exists('mcr_wa_allowed') && mcr_wa_allowed() ? 'wa' : 'nowa'; // WhatsApp links only for visitors in Canada
    $key = 'mcrt_frag_' . md5($name . '|' . mcr_lang() . '|' . mcrt_cache_ver() . '|' . MCRT_VERSION . '|' . $wa);
    $hit = get_transient($key);
    if (is_array($hit) && isset($hit['html'])) {
        echo '<!-- mcr-cache: hit -->';
        foreach ((array) $hit['icons'] as $i) {
            $GLOBALS['mcr_icons_used'][$i] = true;
        }
        echo $hit['html']; // phpcs:ignore WordPress.Security.EscapeOutput
        return;
    }
    $before = array_keys($GLOBALS['mcr_icons_used'] ?? []);
    $GLOBALS['mcr_icons_used'] = [];
    ob_start();
    $render();
    $html = (string) ob_get_clean();
    $icons = array_keys($GLOBALS['mcr_icons_used']);
    foreach ($before as $i) {
        $GLOBALS['mcr_icons_used'][$i] = true;
    }
    set_transient($key, ['html' => $html, 'icons' => $icons], $ttl);
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
}

// ---------- Fonts: served from the site, preloaded, no Google request ----------

add_action('wp_head', function () {
    $css = (string) @file_get_contents(MCRT_DIR . '/assets/fonts.css'); // phpcs:ignore
    if ($css === '') {
        return;
    }
    $base = MCRT_URL . '/assets/';
    foreach (['plus-jakarta-sans-normal-latin-', 'fraunces-normal-latin-'] as $prefix) { // latin only: the -ext files load if needed
        foreach ((array) glob(MCRT_DIR . '/assets/fonts/' . $prefix . '[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f].woff2') as $f) {
            printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url($base . 'fonts/' . basename($f)));
        }
    }
    echo '<style id="mcr-fonts">' . str_replace("url(fonts/", 'url(' . esc_url($base) . 'fonts/', preg_replace('/\s+/', ' ', $css)) . "</style>\n"; // phpcs:ignore
}, 2);

// ---------- Minified files when present (built by build.sh) ----------

add_filter('style_loader_src', fn($src, $handle) => mcrt_min_src($src, $handle), 10, 2);
add_filter('script_loader_src', fn($src, $handle) => mcrt_min_src($src, $handle), 10, 2);
function mcrt_min_src($src, $handle)
{
    if ($handle !== 'mcr' || !is_string($src)) {
        return $src;
    }
    static $map = null;
    if ($map === null) {
        // build.sh records the source each minified file was made from; a stale one is never used.
        $map = (array) json_decode((string) @file_get_contents(MCRT_DIR . '/assets/min.json'), true); // phpcs:ignore
    }
    foreach (['theme.css' => 'theme.min.css', 'theme.js' => 'theme.min.js'] as $from => $to) {
        if (str_contains($src, '/assets/' . $from) && isset($map[$from]) && is_readable(MCRT_DIR . '/assets/' . $to)
            && $map[$from] === md5_file(MCRT_DIR . '/assets/' . $from)) {
            return str_replace('/assets/' . $from, '/assets/' . $to, $src);
        }
    }
    return $src;
}

// ---------- jQuery: at the end of the page, without jquery-migrate (front end only) ----------

add_action('wp_enqueue_scripts', function () {
    $s = wp_scripts();
    if (isset($s->registered['jquery'])) {
        $s->registered['jquery']->deps = array_values(array_diff($s->registered['jquery']->deps, ['jquery-migrate']));
    }
}, 1);

// Every script that needs jQuery (WooCommerce's gallery, add to cart…) goes to the end of the page
// with it, so nothing blocks the first paint. Inline jQuery code is printed after them, as before.
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }
    $s = wp_scripts();
    $needs = function (string $h, array $seen = []) use (&$needs, $s): bool {
        if (in_array($h, ['jquery', 'jquery-core'], true)) {
            return true;
        }
        if (isset($seen[$h]) || !isset($s->registered[$h])) {
            return false;
        }
        $seen[$h] = true;
        foreach ($s->registered[$h]->deps as $d) {
            if ($needs($d, $seen)) {
                return true;
            }
        }
        return false;
    };
    $all = ['jquery', 'jquery-core'];
    foreach ($s->queue as $h) {
        $stack = [$h];
        while ($stack) {
            $x = array_pop($stack);
            if (in_array($x, $all, true) || !isset($s->registered[$x])) {
                continue;
            }
            if ($needs($x)) {
                $all[] = $x;
            }
            $stack = array_merge($stack, $s->registered[$x]->deps);
        }
    }
    foreach ($all as $h) {
        $s->add_data($h, 'group', 1);
    }
}, 9999);

// ---------- Product page: the main photo is the largest element, load it first ----------

add_filter('woocommerce_gallery_image_html_attachment_image_params', function ($params, $id, $size, $main) {
    if ($main) {
        $params['fetchpriority'] = 'high';
        $params['loading'] = 'eager';
        $params['decoding'] = 'async';
    }
    return $params;
}, 10, 4);
