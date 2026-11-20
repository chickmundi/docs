<?php
/**
 * Empty cart.
 *
 * @package MCR
 * @version 7.0.1
 */
defined('ABSPATH') || exit;

$en = function_exists('mcr_is_en') && mcr_is_en();
do_action('woocommerce_cart_is_empty');
?>
<div class="cartx-empty">
  <span class="cartx-empty__ic"><?php echo mcr_icon('shopping-basket', 40); ?></span>
  <h2><?php echo esc_html($en ? 'Your cart is empty' : 'Votre panier est vide'); ?></h2>
  <p><?php echo esc_html($en ? 'Groceries, frozen foods, wigs and hair care: everything is a few clicks away.' : 'Épicerie, surgelés, perruques et soins capillaires : tout est à quelques clics.'); ?></p>
  <div class="cartx-empty__cta">
    <a class="btn" href="<?php echo esc_url(function_exists('mcr_shop_url') ? mcr_shop_url() : wc_get_page_permalink('shop')); ?>"><?php echo mcr_icon('shopping-basket', 18); ?> <?php echo esc_html($en ? 'Start shopping' : 'Commencer mes achats'); ?></a>
    <a class="btn btn--ghost" href="<?php echo esc_url(add_query_arg('promotions', '1', function_exists('mcr_shop_url') ? mcr_shop_url() : wc_get_page_permalink('shop'))); ?>"><?php echo mcr_icon('badge-percent', 18); ?> <?php echo esc_html($en ? 'See the deals' : 'Voir les promotions'); ?></a>
  </div>
</div>
