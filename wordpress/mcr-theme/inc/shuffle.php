<?php
/**
 * "Something new every visit": products are shown in a different order on each visit.
 *
 *  - Each visitor gets a random number (cookie mcr_seed) that lasts while they browse and
 *    changes after 30 minutes without visiting: a new visit = a new order.
 *  - Shop, category and tag pages: the default order becomes "Découvertes" (random with that
 *    number, products in stock always first). Because the number stays the same during the
 *    visit, page 2 never repeats page 1. Choosing a sort (price, popularity…) still works.
 *  - Homepage sections take a larger pool (best sellers, newest, on sale…) and show a different
 *    selection from it. The homepage cache keeps 8 shuffled versions; each visit gets one.
 */
defined('ABSPATH') || exit;

const MCRT_SEED_COOKIE = 'mcr_seed';
const MCRT_SEED_TTL = 30 * MINUTE_IN_SECONDS;
const MCRT_HOME_VARIANTS = 8;

/** This visit's random number (1 – 999 999). */
function mcrt_seed(): int
{
    static $seed = null;
    if ($seed === null) {
        $c = isset($_COOKIE[MCRT_SEED_COOKIE]) ? (int) $_COOKIE[MCRT_SEED_COOKIE] : 0;
        $seed = $c > 0 && $c < 1000000 ? $c : random_int(1, 999999);
    }
    return $seed;
}

// Give every visitor a number, and keep it while they browse.
add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax() || headers_sent()) {
        return;
    }
    $seed = mcrt_seed();
    setcookie(MCRT_SEED_COOKIE, (string) $seed, ['expires' => time() + MCRT_SEED_TTL, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[MCRT_SEED_COOKIE] = (string) $seed;
}, 0);

/** Which of the cached homepage versions this visit sees. */
function mcrt_home_variant(): int
{
    return mcrt_seed() % MCRT_HOME_VARIANTS;
}

/**
 * Pick $n products from a pool in this visit's order (same visit = same choice, so the page is stable
 * while browsing). $salt keeps sections independent of each other.
 */
function mcrt_pick(array $pool, int $n, string $salt = ''): array
{
    if (count($pool) <= 1) {
        return array_slice($pool, 0, $n);
    }
    $key = function_exists('mcrt_home_variant') && is_front_page() ? mcrt_home_variant() : mcrt_seed();
    mt_srand(crc32($salt . '|' . $key));
    for ($i = count($pool) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
    }
    mt_srand();
    return array_slice(array_values($pool), 0, $n);
}

// ---------------------------------------------------------------- Shop and category pages

function mcrt_random_applies(): bool
{
    return !is_admin() && !is_search() && empty($_GET['promotions']); // phpcs:ignore WordPress.Security.NonceVerification
}

add_filter('woocommerce_default_catalog_orderby', function ($by) {
    return mcrt_random_applies() ? 'discover' : $by;
}, 20);

add_filter('woocommerce_catalog_orderby', function ($opts) {
    if (!mcrt_random_applies()) {
        return $opts;
    }
    unset($opts['menu_order']);
    return ['discover' => mcr_is_en() ? 'Discover (new picks every visit)' : 'Découvertes (renouvelées à chaque visite)'] + $opts;
}, 20);

add_filter('woocommerce_get_catalog_ordering_args', function ($args, $orderby) {
    if ($orderby === 'discover') {
        $GLOBALS['mcrt_random_order'] = true;
        return ['orderby' => 'RAND(' . mcrt_seed() . ')', 'order' => '', 'meta_key' => ''];
    }
    return $args;
}, 20, 2);

// In-stock products first, then the visit's random order.
add_filter('posts_clauses', function ($clauses, WP_Query $q) {
    if (empty($GLOBALS['mcrt_random_order']) || !$q->is_main_query() || is_admin()) {
        return $clauses;
    }
    global $wpdb;
    $clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} mcr_lk ON mcr_lk.product_id = {$wpdb->posts}.ID";
    $clauses['orderby'] = "(mcr_lk.stock_status = 'outofstock') ASC, " . ($clauses['orderby'] ?: 'RAND(' . mcrt_seed() . ')');
    return $clauses;
}, 20, 2);
