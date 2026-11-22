<?php
/**
 * Homepage: hero, then the sections chosen in MCR Gestion > Paramètres > Page d'accueil.
 * Every product, price and discount comes from WooCommerce.
 */
defined('ABSPATH') || exit;
get_header();

$home = function_exists('mcr_setting') ? mcr_setting('home') : [];
get_template_part('template-parts/home/hero', null, ['home' => $home]);

mcrt_cached('home-sections', function () use ($home) {
    foreach (mcrt_home_sections() as $i => $sec) {
        if (empty($sec['on'])) {
            continue;
        }
        $type = sanitize_key($sec['type'] ?? '');
        if ($type === 'promos') {
            $type = 'deals';
        } elseif ($type === 'visit') {
            $type = 'community';
        } elseif ($type === 'split') {
            $type = 'banners';
        }
        if (locate_template("template-parts/home/{$type}.php")) {
            get_template_part("template-parts/home/{$type}", null, ['home' => $home, 'section' => $sec, 'index' => $i]);
        }
    }
});

get_footer();
