<?php
/**
 * Product card, used everywhere (home, shop, categories, search, related products).
 * Expects $args['product'] (WC_Product). Only real data: price, sale, stock, reviews.
 */
defined('ABSPATH') || exit;
$product = $args['product'] ?? null;
if (!$product instanceof WC_Product) {
    return;
}
$en = mcr_is_en();
$pct = mcrt_discount($product);
$cat = mcrt_primary_cat($product);
$in_stock = $product->is_in_stock();
$simple_buyable = ($product->is_type('simple') && $in_stock && $product->is_purchasable()) && apply_filters('mcr_quick_add', true, $product);
$by_weight = function_exists('mcrw_settings') && mcrw_settings($product);
$hint = mcrt_variation_hint($product);
$unit = (string) get_post_meta($product->get_id(), '_mcr_unit_label', true);
$is_new = !empty($args['show_new']) && $product->get_date_created() && $product->get_date_created()->getTimestamp() > time() - 30 * DAY_IN_SECONDS;
$price_html = $product->get_price_html();
if ($product->is_type('variable')) {
    $min = (float) $product->get_variation_price('min', true);
    $max = (float) $product->get_variation_price('max', true);
    if ($min !== $max) {
        $price_html = '<small class="pcard__from">' . esc_html($en ? 'From' : 'Dès') . '</small> ' . wc_price($min);
    }
}
$reviews = (int) $product->get_review_count();
$low = function_exists('mcr_low_stock_qty') ? mcr_low_stock_qty($product) : 0; // real stock only
$flash = function_exists('mcr_flash_for_product') ? mcr_flash_for_product($product->get_id()) : null;
$sold30 = function_exists('mcr_sold30_label') ? mcr_sold30_label($product) : '';
$eager = !empty($args['eager']);
$deal_labels = mcrt_deal_labels($product);
$end_label = $pct ? mcrt_end_label(mcrt_sale_end($product)) : '';
$saving = !empty($args['show_saving']) && $pct ? mcrt_saving($product) : 0;
?>
<article class="pcard<?php echo $in_stock ? '' : ' is-out'; ?>" data-reveal>
  <div class="pcard__media">
    <a href="<?php echo esc_url($product->get_permalink()); ?>" tabindex="-1" aria-hidden="true">
      <?php echo $product->get_image('woocommerce_thumbnail', ['loading' => $eager ? 'eager' : 'lazy', 'alt' => '', 'decoding' => 'async']); ?>
    </a>
    <span class="pcard__badges">
      <?php if ($flash) : ?><span class="badge badge--flash"><?php echo mcr_icon('zap', 12); ?> <?php echo esc_html($en ? 'Flash sale' : 'Vente éclair'); ?></span><?php endif; ?>
      <?php if ($pct > 0) : ?><span class="badge badge--sale">-<?php echo (int) $pct; ?> %</span><?php endif; ?>
      <?php foreach ($deal_labels as $dl) : ?><span class="badge badge--deal"><?php echo esc_html($dl); ?></span><?php endforeach; ?>
      <?php if ($end_label) : ?><span class="badge badge--time"><?php echo esc_html($end_label); ?></span><?php endif; ?>
      <?php if ($is_new && !$pct) : ?><span class="badge badge--new"><?php echo esc_html($en ? 'New' : 'Nouveau'); ?></span><?php endif; ?>
      <?php if (!$in_stock) : ?><span class="badge badge--muted"><?php mcr_e('Épuisé'); ?></span><?php endif; ?>
    </span>
    <?php if (($pct > 0 || $deal_labels) && function_exists('mcr_product_promo_online_only') && mcr_product_promo_online_only($product)) : ?><span class="badge badge--online pcard__where" title="<?php echo esc_attr($en ? 'Sale price for online orders only' : 'Prix promo pour les commandes en ligne seulement'); ?>"><?php echo esc_html($en ? 'Online' : 'En ligne'); ?></span><?php endif; ?>
    <button class="pcard__wish" type="button" data-wish="<?php echo esc_attr(mcrt_wish_data($product)); ?>" aria-pressed="false" aria-label="<?php echo esc_attr(mcr_t('Ajouter à ma liste') . ' : ' . $product->get_name()); ?>"><?php echo mcr_icon('heart', 18); ?></button>
  </div>
  <div class="pcard__body">
    <?php if ($cat) : ?><a class="pcard__cat" href="<?php echo esc_url(get_term_link($cat)); ?>"><?php echo esc_html($cat->name); ?></a><?php endif; ?>
    <h3 class="pcard__title"><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
    <?php if ($reviews > 0) : $avg = (float) $product->get_average_rating(); ?>
      <p class="pcard__rating" aria-label="<?php echo esc_attr(sprintf($en ? 'Rated %s out of 5 (%d reviews)' : 'Noté %s sur 5 (%d avis)', number_format_i18n($avg, 1), $reviews)); ?>">
        <span class="stars" style="--r:<?php echo esc_attr($avg / 5 * 100); ?>%"></span><small>(<?php echo (int) $reviews; ?>)</small>
      </p>
    <?php endif; ?>
    <?php if ($saving > 0) : ?><p class="pcard__save"><?php echo wp_kses_post(sprintf($en ? 'You save %s' : 'Économisez %s', wc_price($saving))); ?></p><?php endif; ?>
    <?php if ($sold30) : ?><p class="pcard__hot"><?php echo mcr_icon('flame', 13); ?> <?php echo esc_html($sold30); ?></p><?php endif; ?>
    <p class="pcard__meta">
      <?php if ($hint) : ?><span><?php echo esc_html($hint); ?></span><?php elseif ($unit) : ?><span><?php echo esc_html($en ? str_replace('par ', 'per ', $unit) : $unit); ?></span><?php endif; ?>
      <?php if ($low) : ?><span class="pcard__low"><?php echo esc_html(sprintf($en ? 'Only %d left' : 'Plus que %d', $low)); ?></span>
      <?php elseif ($in_stock) : ?><span class="pcard__stock"><?php echo esc_html($en ? 'In stock' : 'En stock'); ?></span><?php endif; ?>
    </p>
    <div class="pcard__foot">
      <span class="pcard__price<?php echo $pct ? ' is-sale' : ''; ?>"><?php echo $price_html; // phpcs:ignore ?></span>
      <?php if ($simple_buyable) : ?>
        <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="pcard__add" data-add-to-cart="<?php echo esc_attr($product->get_id()); ?>" data-name="<?php echo esc_attr($product->get_name()); ?>" rel="nofollow" aria-label="<?php echo esc_attr(($en ? 'Add to cart: ' : 'Ajouter au panier : ') . $product->get_name()); ?>"><?php echo mcr_icon('plus', 18); ?><span><?php mcr_e('Ajouter'); ?></span></a>
      <?php else : ?>
        <a href="<?php echo esc_url($product->get_permalink()); ?>" class="pcard__add pcard__add--ghost" aria-label="<?php echo esc_attr(($in_stock ? ($by_weight ? ($en ? 'Choose the weight: ' : 'Choisir le poids : ') : ($en ? 'Choose options: ' : 'Choisir les options : ')) : ($en ? 'View: ' : 'Voir : ')) . $product->get_name()); ?>"><span><?php echo esc_html($in_stock ? ($by_weight ? ($en ? 'Weight' : 'Poids') : 'Options') : ($en ? 'View' : 'Voir')); ?></span><?php echo mcr_icon('chevron-right', 16); ?></a>
      <?php endif; ?>
    </div>
  </div>
</article>
