<?php
/**
 * CHICK Studio: landing page for custom WordPress plugin development.
 * - Front page: the landing (index.php), English and French.
 * - Quote requests: POST /wp-json/chick/v1/lead, saved as "Demandes" in the admin and emailed.
 * The licence server (chick-licences plugin) keeps working: this theme only draws the public page.
 */
defined('ABSPATH') || exit;

const CHICK_STUDIO_VERSION = '1.0.3';

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
});

// Leads: private post type, listed in the admin.
add_action('init', function () {
    register_post_type('chick_lead', [
        'labels' => ['name' => 'Demandes', 'singular_name' => 'Demande', 'menu_name' => 'Demandes de projet', 'edit_item' => 'Demande', 'all_items' => 'Toutes les demandes'],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-email-alt',
        'menu_position' => 3,
        'supports' => ['title', 'editor'],
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'do_not_allow'],
        'map_meta_cap' => true,
    ]);
});

add_filter('manage_chick_lead_posts_columns', fn($c) => ['cb' => $c['cb'], 'title' => 'Client', 'lead_email' => 'Courriel', 'lead_site' => 'Site', 'lead_budget' => 'Budget', 'date' => 'Reçue']);
add_action('manage_chick_lead_posts_custom_column', function ($col, $id) {
    $v = (string) get_post_meta($id, '_' . $col, true);
    if ($col === 'lead_email' && $v) {
        echo '<a href="mailto:' . esc_attr($v) . '">' . esc_html($v) . '</a>';
    } elseif ($col === 'lead_site' && $v) {
        echo '<a href="' . esc_url($v) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('#^https?://#', '', $v)) . '</a>';
    } else {
        echo esc_html($v);
    }
}, 10, 2);

function chick_studio_notify_email(): string
{
    $s = function_exists('chick_lic_settings') ? chick_lic_settings() : [];
    return !empty($s['notify_email']) ? (string) $s['notify_email'] : (string) get_option('admin_email');
}

add_action('rest_api_init', function () {
    register_rest_route('chick/v1', '/lead', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            // Bots: hidden field filled, or sent faster than a person can type.
            if (trim((string) $r->get_param('website_url_confirm')) !== '' || (time() - (int) $r->get_param('t')) < 3) {
                return ['ok' => true];
            }
            $ip = substr(md5((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 12);
            $n = (int) get_transient('chick_lead_' . $ip);
            if ($n >= 5) {
                return new WP_REST_Response(['code' => 'too_many'], 429);
            }
            $name = sanitize_text_field((string) $r->get_param('name'));
            $email = sanitize_email((string) $r->get_param('email'));
            $site = esc_url_raw(trim((string) $r->get_param('site')));
            $need = sanitize_textarea_field((string) $r->get_param('need'));
            $budget = sanitize_text_field((string) $r->get_param('budget'));
            $lang = $r->get_param('lang') === 'fr' ? 'fr' : 'en';
            if ($name === '' || !is_email($email) || mb_strlen($need) < 15) {
                return new WP_REST_Response(['code' => 'invalid', 'fields' => array_values(array_filter([$name === '' ? 'name' : '', !is_email($email) ? 'email' : '', mb_strlen($need) < 15 ? 'need' : '']))], 400);
            }
            $id = wp_insert_post([
                'post_type' => 'chick_lead',
                'post_status' => 'private',
                'post_title' => $name,
                'post_content' => $need,
            ]);
            foreach (['lead_email' => $email, 'lead_site' => $site, 'lead_budget' => $budget, 'lead_lang' => $lang] as $k => $v) {
                update_post_meta($id, '_' . $k, $v);
            }
            set_transient('chick_lead_' . $ip, $n + 1, HOUR_IN_SECONDS);
            wp_mail(
                chick_studio_notify_email(),
                'Nouvelle demande de plugin : ' . $name,
                "Nom : {$name}\nCourriel : {$email}\nSite : {$site}\nBudget : {$budget}\nLangue : {$lang}\n\nBesoin :\n{$need}\n\nToutes les demandes : " . admin_url('edit.php?post_type=chick_lead'),
                ['Reply-To: ' . $name . ' <' . $email . '>']
            );
            return ['ok' => true];
        },
    ]);
});

// Public page: indexable, with its own title, description and structured data.
add_filter('pre_get_document_title', fn() => 'CHICK Service | Custom WordPress plugins, built for your business');

add_action('wp_head', function () {
    $desc = 'Custom WordPress and WooCommerce plugins for the feature your site is missing. Fixed quote before any work, tested on a copy of your site, and the code is yours.';
    $url = home_url('/');
    echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:type" content="website"><meta property="og:title" content="Custom WordPress plugins, built for your business"><meta property="og:description" content="' . esc_attr($desc) . '"><meta property="og:url" content="' . esc_url($url) . '"><meta name="twitter:card" content="summary">' . "\n";
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProfessionalService',
        'name' => 'CHICK Service',
        'url' => $url,
        'description' => $desc,
        'serviceType' => 'Custom WordPress plugin development',
        'areaServed' => ['Canada', 'United States', 'Europe'],
        'availableLanguage' => ['English', 'French'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}, 1);

// Keep the page light: no block-library CSS, emoji script or embeds on this one-page site.
add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}, 100);
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
add_filter('show_admin_bar', '__return_false');

// Only the landing page is public: other front-end addresses go to it.
add_action('template_redirect', function () {
    if (!is_front_page() && !is_admin() && !is_robots() && !is_feed() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && !(function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request()) && !str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'sitemap')) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
});
add_filter('wp_sitemaps_add_provider', fn($p, $name) => $name === 'posts' ? $p : false, 10, 2);
add_filter('wp_sitemaps_post_types', fn() => []);
