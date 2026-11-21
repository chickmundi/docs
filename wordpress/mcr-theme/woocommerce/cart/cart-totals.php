<?php
/**
 * Cart summary: free-delivery progress, subtotal, total so far, checkout
 * button. The delivery price is shown at checkout, once the address is known.
 * On phones, a bar with the total and the checkout button stays at the bottom.
 *
 * @package MCR
 * @version 2.3.6
 */
defined('ABSPATH') || exit;

$en = function_exists('mcr_is_en') && mcr_is_en();
$cart = WC()->cart;
$items = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
$free_min = function_exists('mcr_setting') ? (float) mcr_setting('delivery', 'free_min_subtotal') : 0;
$free_km = function_exists('mcr_setting') ? (float) mcr_setting('delivery', 'free_radius_km') : 0;
$pre_tax = (float) $cart->get_cart_contents_total();
$left = max(0, $free_min - $pre_tax);
$pct = $free_min > 0 ? min(100, round($pre_tax / $free_min * 100)) : 100;
$taxes = (float) $cart->get_cart_contents_tax();
?>
<div class="cart_totals cartx__sum <?php echo (WC()->customer && WC()->customer->has_calculated_shipping()) ? 'calculated_shipping' : ''; ?>">
  <?php do_action('woocommerce_before_cart_totals'); ?>
  <h2><?php echo esc_html($en ? 'Summary' : 'Résumé'); ?></h2>

  <?php if ($free_min > 0) : ?>
    <div class="cartx__free<?php echo $left <= 0 ? ' is-done' : ''; ?>">
      <p><?php echo mcr_icon($left <= 0 ? 'circle-check' : 'truck', 18); ?>
        <span><?php
        if ($left <= 0) {
            echo esc_html($en ? sprintf('Free delivery unlocked (within %s km)!', (float) $free_km) : sprintf('Livraison gratuite débloquée (jusqu’à %s km) !', (float) $free_km));
        } else {
            echo wp_kses_post($en
                ? sprintf('Add <strong>%s</strong> more for free delivery.', wc_price($left))
                : sprintf('Plus que <strong>%s</strong> pour la livraison gratuite.', wc_price($left)));
        }
        ?></span>
      </p>
      <div class="cartx__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $pct; ?>"><i style="width:<?php echo (int) $pct; ?>%"></i></div>
    </div>
  <?php endif; ?>

  <dl class="cartx__lines">
    <div><dt><?php echo esc_html($en ? 'Subtotal' : 'Sous-total'); ?></dt><dd><?php wc_cart_totals_subtotal_html(); ?></dd></div>
    <?php foreach ($cart->get_coupons() as $code => $coupon) : ?>
      <div class="cartx__disc"><dt><?php wc_cart_totals_coupon_label($coupon); ?></dt><dd><?php wc_cart_totals_coupon_html($coupon); ?></dd></div>
    <?php endforeach; ?>
    <?php foreach ($cart->get_fees() as $fee) : ?>
      <div><dt><?php echo esc_html($fee->name); ?></dt><dd><?php wc_cart_totals_fee_html($fee); ?></dd></div>
    <?php endforeach; ?>
    <?php if ($taxes > 0 && !wc_prices_include_tax()) : ?>
      <div><dt><?php echo esc_html($en ? 'Taxes' : 'Taxes'); ?></dt><dd><?php echo wc_price($taxes); // phpcs:ignore ?></dd></div>
    <?php endif; ?>
    <div class="cartx__muted"><dt><?php echo esc_html($en ? 'Delivery or pickup' : 'Livraison ou retrait'); ?></dt><dd><?php echo esc_html($en ? 'Next step' : 'À l’étape suivante'); ?></dd></div>
    <div class="cartx__total"><dt><?php echo esc_html($en ? 'Total' : 'Total'); ?></dt><dd><?php echo wc_price($items); // phpcs:ignore ?></dd></div>
  </dl>

  <div class="wc-proceed-to-checkout">
    <?php do_action('woocommerce_proceed_to_checkout'); ?>
  </div>
  <ul class="cartx__trust">
    <li><?php echo mcr_icon('lock', 16); ?> <?php echo esc_html($en ? 'Secure payment' : 'Paiement sécurisé'); ?></li>
    <li><?php echo mcr_icon('store', 16); ?> <?php echo esc_html($en ? 'Free in-store pickup' : 'Retrait gratuit en magasin'); ?></li>
  </ul>

  <div class="cartx__sticky" aria-hidden="true">
    <div><small><?php echo esc_html($en ? 'Total' : 'Total'); ?></small><strong><?php echo wc_price($items); // phpcs:ignore ?></strong></div>
    <a class="btn cartx__sticky-btn" href="<?php echo esc_url(wc_get_checkout_url()); ?>" tabindex="-1"><?php echo esc_html($en ? 'Checkout' : 'Passer à la caisse'); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
  </div>
  <?php do_action('woocommerce_after_cart_totals'); ?>
</div>
