<?php
defined('ABSPATH') || exit;
get_header();
echo '<div class="container"><article class="page prose">';
if (have_posts()) {
    while (have_posts()) {
        the_post();
        echo '<h2><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2>';
        the_excerpt();
    }
} else {
    echo '<p>' . esc_html(mcr_t('Aucun produit trouvé.')) . '</p>';
}
echo '</article></div>';
get_footer();
