<?php
/** Product card. Expects $args['product'] (WC_Product). */
defined('ABSPATH') || exit;
$product = $args['product'] ?? null;
if (!$product instanceof WC_Product) {
    return;
}
$pct = 0;
if ($product->is_on_sale() && !$product->is_type('variable')) {
    $reg = (float) $product->get_regular_price();
    $pct = $reg > 0 ? (int) round((1 - (float) $product->get_price() / $reg) * 100) : 0;
}
$simple_buyable = $product->is_type('simple') && $product->is_in_stock() && $product->is_purchasable();
?>
<article class="card" data-reveal>
  <button class="card__wish" type="button" data-wish="<?php echo esc_attr(mcrt_wish_data($product)); ?>" aria-pressed="false" aria-label="<?php echo esc_attr(mcr_t('Ajouter à ma liste')); ?>"><?php echo mcr_icon('heart', 18); ?></button>
  <a href="<?php echo esc_url($product->get_permalink()); ?>" class="card__media" tabindex="-1" aria-hidden="true">
    <?php echo $product->get_image('woocommerce_thumbnail', ['loading' => 'lazy', 'alt' => '']); ?>
    <span class="card__badges">
      <?php if ($pct > 0) : ?><span class="badge">-<?php echo (int) $pct; ?>%</span><?php endif; ?>
      <?php if (!$product->is_in_stock()) : ?><span class="badge badge--muted"><?php mcr_e('Épuisé'); ?></span><?php endif; ?>
    </span>
  </a>
  <div class="card__body">
    <h3 class="card__title"><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
    <p class="price <?php echo $pct ? 'price--sale' : ''; ?>"><?php echo $product->get_price_html(); // phpcs:ignore ?></p>
    <?php if ($simple_buyable) : ?>
      <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="btn card__btn" data-add-to-cart="<?php echo esc_attr($product->get_id()); ?>" data-name="<?php echo esc_attr($product->get_name()); ?>" rel="nofollow"><?php echo mcr_icon('shopping-cart', 17); ?> <?php mcr_e('Ajouter'); ?></a>
    <?php else : ?>
      <a href="<?php echo esc_url($product->get_permalink()); ?>" class="btn btn--ghost card__btn"><?php echo esc_html($product->add_to_cart_text()); ?> <?php echo mcr_icon('chevron-right', 16); ?></a>
    <?php endif; ?>
  </div>
</article>
