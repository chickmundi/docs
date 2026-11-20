<?php
/* "Ma liste": wishlist saved in the visitor's browser. */
defined('ABSPATH') || exit;
get_header();
?>
<div class="container">
  <div class="list-head"><span class="list-head__ic"><?php echo mcr_icon('heart', 26); ?></span><div><h1><?php mcr_e('Ma liste'); ?></h1><p class="muted"><?php echo esc_html(mcr_is_en() ? 'Products you saved for later, on this device.' : 'Les produits que vous gardez pour plus tard, sur cet appareil.'); ?></p></div></div>
  <p class="empty" data-wish-empty hidden><?php echo esc_html(mcr_is_en() ? 'Your list is empty. Tap the heart on a product to add it here.' : 'Votre liste est vide. Touchez le cœur sur un produit pour l’ajouter ici.'); ?> <a class="btn" href="<?php echo esc_url(mcr_shop_url()); ?>"><?php mcr_e('Voir la boutique'); ?></a></p>
  <ul class="grid" data-wish-list></ul>
</div>
<?php
get_footer();
