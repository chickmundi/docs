<?php
defined('ABSPATH') || exit;
get_header();
while (have_posts()) : the_post();
    $wc = is_cart() || is_checkout() || is_account_page();
    ?>
    <div class="container">
      <nav class="breadcrumbs" aria-label="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>"><?php mcr_e('Accueil'); ?></a> <?php echo mcr_icon('chevron-right', 14); ?> <span><?php the_title(); ?></span></nav>
      <article class="<?php echo $wc ? 'wc-page' : 'page prose'; ?>">
        <h1><?php the_title(); ?></h1>
        <?php the_content(); ?>
      </article>
    </div>
    <?php
endwhile;
get_footer();
