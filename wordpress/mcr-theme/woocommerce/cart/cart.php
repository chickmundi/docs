<?php
/**
 * Cart page: item cards with a quantity stepper (updates by itself), and a
 * summary with the free-delivery progress.
 *
 * @package MCR
 * @version 10.1.0
 */
defined('ABSPATH') || exit;

$en = function_exists('mcr_is_en') && mcr_is_en();
$count = WC()->cart->get_cart_contents_count();

do_action('woocommerce_before_cart');
?>
<div class="cartx">
  <form class="woocommerce-cart-form cartx__main" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
    <?php do_action('woocommerce_before_cart_table'); ?>
    <div class="cartx__head">
      <h2><?php echo esc_html($en ? 'Your cart' : 'Votre panier'); ?> <span>(<?php echo esc_html($count . ' ' . ($en ? ($count > 1 ? 'items' : 'item') : ($count > 1 ? 'articles' : 'article'))); ?>)</span></h2>
      <a class="cartx__more" href="<?php echo esc_url(function_exists('mcr_shop_url') ? mcr_shop_url() : wc_get_page_permalink('shop')); ?>"><?php echo mcr_icon('arrow-right', 16); ?> <?php echo esc_html($en ? 'Keep shopping' : 'Continuer mes achats'); ?></a>
    </div>

    <ul class="cartx__items shop_table cart woocommerce-cart-form__contents">
      <?php do_action('woocommerce_before_cart_contents'); ?>
      <?php
      foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
          $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
          $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
          $product_name = apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key);
          if (!$_product || !$_product->exists() || $cart_item['quantity'] <= 0 || !apply_filters('woocommerce_cart_item_visible', true, $cart_item, $cart_item_key)) {
              continue;
          }
          $permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
          $thumb = apply_filters('woocommerce_cart_item_thumbnail', $_product->get_image('woocommerce_thumbnail'), $cart_item, $cart_item_key);
          if ($_product->is_sold_individually()) {
              $min = 1;
              $max = 1;
          } else {
              $min = 0;
              $max = $_product->get_max_purchase_quantity();
          }
          ?>
          <li class="cartx__item woocommerce-cart-form__cart-item <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
            <a class="cartx__img" href="<?php echo esc_url($permalink ?: '#'); ?>" tabindex="-1" aria-hidden="true"><?php echo $thumb; // phpcs:ignore ?></a>
            <div class="cartx__info">
              <div class="cartx__top">
                <h3 class="cartx__name"><?php echo $permalink ? '<a href="' . esc_url($permalink) . '">' . wp_kses_post($product_name) . '</a>' : wp_kses_post($product_name); ?></h3>
                <span class="product-remove">
                  <?php
                  echo apply_filters( // phpcs:ignore
                      'woocommerce_cart_item_remove_link',
                      sprintf(
                          '<a role="button" href="%s" class="remove cartx__remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">%s</a>',
                          esc_url(wc_get_cart_remove_url($cart_item_key)),
                          esc_attr(sprintf($en ? 'Remove %s from cart' : 'Retirer %s du panier', wp_strip_all_tags($product_name))),
                          esc_attr($product_id),
                          esc_attr($_product->get_sku()),
                          mcr_icon('trash-2', 18)
                      ),
                      $cart_item_key
                  );
                  ?>
                </span>
              </div>
              <?php echo wc_get_formatted_cart_item_data($cart_item); // phpcs:ignore ?>
              <p class="cartx__unit"><?php echo apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price($_product), $cart_item, $cart_item_key); // phpcs:ignore ?><?php echo esc_html($en ? ' each' : ' / unité'); ?></p>
              <?php if ($_product->backorders_require_notification() && $_product->is_on_backorder($cart_item['quantity'])) : ?>
                <p class="backorder_notification"><?php echo esc_html__('Available on backorder', 'woocommerce'); ?></p>
              <?php endif; ?>
              <div class="cartx__row">
                <div class="cartx__qty" data-stepper>
                  <button type="button" class="cartx__step" data-step="-1" aria-label="<?php echo esc_attr($en ? 'Decrease quantity' : 'Diminuer la quantité'); ?>"><?php echo mcr_icon('minus', 16); ?></button>
                  <?php
                  echo apply_filters( // phpcs:ignore
                      'woocommerce_cart_item_quantity',
                      woocommerce_quantity_input([
                          'input_name' => "cart[{$cart_item_key}][qty]",
                          'input_value' => $cart_item['quantity'],
                          'max_value' => $max,
                          'min_value' => $min,
                          'product_name' => $product_name,
                      ], $_product, false),
                      $cart_item_key,
                      $cart_item
                  );
                  ?>
                  <button type="button" class="cartx__step" data-step="1" aria-label="<?php echo esc_attr($en ? 'Increase quantity' : 'Augmenter la quantité'); ?>"><?php echo mcr_icon('plus', 16); ?></button>
                </div>
                <strong class="cartx__line"><?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); // phpcs:ignore ?></strong>
              </div>
            </div>
          </li>
      <?php endforeach; ?>
      <?php do_action('woocommerce_cart_contents'); ?>
    </ul>

    <div class="cartx__actions actions">
      <?php do_action('woocommerce_cart_actions'); ?>
      <button type="submit" class="button cartx__update" name="update_cart" value="<?php esc_attr_e('Update cart', 'woocommerce'); ?>"><?php echo esc_html($en ? 'Update cart' : 'Mettre à jour'); ?></button>
      <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
    </div>
    <?php do_action('woocommerce_after_cart_contents'); ?>
    <?php do_action('woocommerce_after_cart_table'); ?>
  </form>

  <?php do_action('woocommerce_before_cart_collaterals'); ?>
  <aside class="cart-collaterals cartx__side">
    <?php do_action('woocommerce_cart_collaterals'); ?>
  </aside>
</div>
<?php do_action('woocommerce_after_cart'); ?>
