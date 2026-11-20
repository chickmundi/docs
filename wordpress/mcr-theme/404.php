<?php
defined('ABSPATH') || exit;
get_header();
?>
<div class="container notfound">
  <h1><?php mcr_e('Page introuvable'); ?></h1>
  <p><?php mcr_e('Cette page n’existe pas ou a été déplacée.'); ?></p>
  <p class="hero__cta" style="justify-content:center"><a class="btn" href="<?php echo esc_url(mcr_shop_url()); ?>"><?php mcr_e('Voir la boutique'); ?></a> <a class="btn btn--ghost" href="<?php echo esc_url(home_url('/')); ?>"><?php mcr_e('Accueil'); ?></a></p>
</div>
<?php
get_footer();
