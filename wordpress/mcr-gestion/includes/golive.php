<?php
/**
 * Going live: when this site runs on the real address (marchecheminduroy.com) for the first time,
 * prepare it for Google once:
 *  - allow search engines (the test site is "noindex", and pushing it to live copies that setting);
 *  - Montréal time zone; "Hello world" sample post removed;
 *  - fresh address rules, caches and llms.txt with the real address.
 * The test site (staging.…) always stays hidden from search engines.
 */
defined('ABSPATH') || exit;

const MCR_LIVE_HOSTS = ['marchecheminduroy.com'];

function mcr_site_host(): string
{
    return strtolower((string) parse_url((string) get_option('home'), PHP_URL_HOST));
}

function mcr_is_live_host(): bool
{
    return in_array(preg_replace('/^www\./', '', mcr_site_host()), MCR_LIVE_HOSTS, true);
}

add_action('init', function () {
    $host = mcr_site_host();
    if (str_starts_with($host, 'staging.')) {
        if (get_option('blog_public') !== '0') {
            update_option('blog_public', '0'); // never let Google index the test copy
        }
        return;
    }
    if (!mcr_is_live_host() || get_option('mcr_golive_v1')) {
        return;
    }
    update_option('blog_public', '1');
    update_option('timezone_string', 'America/Toronto');
    update_option('gmt_offset', '');
    foreach (get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => 5, 'name' => 'hello-world']) as $p) {
        wp_trash_post($p->ID);
    }
    foreach (get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => 5, 'title' => 'Bonjour tout le monde !']) as $p) {
        wp_trash_post($p->ID);
    }
    update_option('mcrt_cache_ver', (string) time()); // homepage cache rebuilt with the real address
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
    if (function_exists('mcr_llms_write')) {
        mcr_llms_write();
    }
    update_option('mcr_golive_v1', time(), false);
    update_option('mcr_golive_notice', 1, false);
    flush_rewrite_rules(false);
}, 99);

// After going live: a reminder with what to do in Google Search Console.
add_action('admin_notices', function () {
    if (!get_option('mcr_golive_notice') || !current_user_can('manage_options')) {
        return;
    }
    if (isset($_GET['mcr_golive_ok'])) { // phpcs:ignore WordPress.Security.NonceVerification
        delete_option('mcr_golive_notice');
        return;
    }
    $sitemap = home_url('/wp-sitemap.xml');
    echo '<div class="notice notice-success"><p><strong>Le nouveau site est en ligne et visible par Google.</strong> '
        . 'Prochaine étape : dans Google Search Console, ajoutez le plan du site <code>' . esc_html($sitemap) . '</code> '
        . 'puis demandez l’indexation de la page d’accueil. '
        . '<a href="' . esc_url(add_query_arg('mcr_golive_ok', 1)) . '">Masquer</a></p></div>';
});

