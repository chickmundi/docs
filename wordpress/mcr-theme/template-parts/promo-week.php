<?php
/** "Promo de la semaine": one product chosen in MCR Gestion > Promotions, shown big. Expects $args['product']. */
defined('ABSPATH') || exit;
$week = $args['product'] ?? null;
if (!$week instanceof WC_Product) {
    return;
}
$en = mcr_is_en();
$wpct = mcrt_discount($week);
$wend = mcrt_sale_end($week);
$wsave = mcrt_saving($week);
$wdeals = mcrt_deal_labels($week);
$simple = $week->is_type('simple') && $week->is_purchasable() && apply_filters('mcr_quick_add', true, $week);
?>
<section class="section section--tight" aria-labelledby="h-week">
  <div class="container">
    <article class="week" data-reveal>
      <a class="week__img" href="<?php echo esc_url($week->get_permalink()); ?>" tabindex="-1" aria-hidden="true">
        <?php echo $week->get_image('woocommerce_single', ['loading' => 'lazy', 'alt' => '']); ?>
        <?php if ($wpct) : ?><span class="week__pct">-<?php echo (int) $wpct; ?> %</span><?php endif; ?>
      </a>
      <div class="week__body">
        <p class="eyebrow"><?php echo esc_html($en ? '⭐ Deal of the week' : '⭐ Promo de la semaine'); ?></p>
        <h2 id="h-week"><a href="<?php echo esc_url($week->get_permalink()); ?>"><?php echo esc_html($week->get_name()); ?></a></h2>
        <p class="week__price"><?php echo $week->get_price_html(); // phpcs:ignore ?></p>
        <?php if ($wsave > 0) : ?><p class="week__save"><?php echo wp_kses_post(sprintf($en ? 'You save <strong>%s</strong>' : 'Vous économisez <strong>%s</strong>', wc_price($wsave))); ?></p><?php endif; ?>
        <?php foreach ($wdeals as $l) : ?><p class="week__save"><?php echo esc_html(($en ? 'Deal: ' : 'Offre : ') . $l); ?></p><?php endforeach; ?>
        <?php if ($wend > time()) : ?><p class="week__ends"><?php echo mcr_icon('clock', 16); ?> <?php echo esc_html($en ? 'Ends in' : 'Se termine dans'); ?> <strong data-countdown="<?php echo (int) $wend; ?>"><?php echo esc_html(mcrt_end_label($wend)); ?></strong></p><?php endif; ?>
        <a class="btn" href="<?php echo esc_url($simple ? $week->add_to_cart_url() : $week->get_permalink()); ?>"<?php echo $simple ? ' data-add-to-cart="' . (int) $week->get_id() . '" data-name="' . esc_attr($week->get_name()) . '" rel="nofollow"' : ''; ?>><?php echo mcr_icon('shopping-cart', 18); ?> <?php echo esc_html($simple ? mcr_t('Ajouter au panier') : ($en ? 'Choose options' : 'Choisir les options')); ?></a>
      </div>
    </article>
  </div>
</section>
