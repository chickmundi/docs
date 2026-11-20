<?php
defined('ABSPATH') || exit;
get_header();
while (have_posts()) : the_post();
    $wc = is_cart() || is_checkout() || is_account_page();
    $crumbs = '<nav class="breadcrumbs" aria-label="' . esc_attr(mcr_t('Fil d’Ariane')) . '"><a href="' . esc_url(home_url('/')) . '">' . esc_html(mcr_t('Accueil')) . '</a> ' . mcr_icon('chevron-right', 14) . ' <span>' . esc_html(get_the_title()) . '</span></nav>';
    if ($wc) : ?>
      <div class="container wc-page">
        <?php echo $crumbs; // phpcs:ignore ?>
        <?php if (!(is_cart() && WC()->cart && !WC()->cart->is_empty())) : ?><h1 class="wc-page__title"><?php the_title(); ?></h1><?php endif; ?>
        <?php the_content(); ?>
      </div>
    <?php else : ?>
      <section class="phead phead--page">
        <div class="container phead__in"><div class="phead__copy"><?php echo $crumbs; // phpcs:ignore ?><h1><?php the_title(); ?></h1></div></div>
      </section>
      <div class="container">
        <article class="prose page-card"><?php the_content(); ?></article>
      </div>
    <?php endif;
endwhile;
get_footer();
