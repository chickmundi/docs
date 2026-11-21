<?php
/** "Les offres du moment": products with a real WooCommerce sale price, biggest discount first. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$ids = array_values(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product'));
if (!$ids) {
    return;
}
$products = mcrt_products(['include' => array_slice($ids, 0, 60), 'limit' => 60]);
usort($products, fn($a, $b) => mcrt_discount($b) <=> mcrt_discount($a));
$products = array_slice($products, 0, 10);
if (!$products) {
    return;
}
$best = mcrt_discount($products[0]);
$banner = function_exists('mcr_promo_banners') ? (mcr_promo_banners()[0] ?? null) : null;
$week = function_exists('mcr_promo_of_week') ? mcr_promo_of_week() : null;
?>
<?php if ($week) { get_template_part('template-parts/promo-week', null, ['product' => $week]); } ?>
<section class="section section--deals" aria-labelledby="h-deals">
  <div class="container">
    <div class="deals">
      <div class="deals__intro" data-reveal>
        <p class="eyebrow eyebrow--light"><?php echo mcr_icon('badge-percent', 16); ?> <?php echo esc_html($en ? 'While supplies last' : 'Dans la limite des stocks'); ?></p>
        <h2 id="h-deals"><?php echo esc_html($banner ? $banner['title'] : ($en ? 'Deals of the moment' : 'Les offres du moment')); ?></h2>
        <?php if ($banner && $banner['text']) : ?><p class="deals__text"><?php echo esc_html($banner['text']); ?></p><?php endif; ?>
        <?php if ($banner && $banner['ends']) : ?><p class="deals__ends"><?php echo mcr_icon('clock', 15); ?> <?php echo esc_html($en ? 'Ends in' : 'Se termine dans'); ?> <strong data-countdown="<?php echo (int) $banner['ends']; ?>"><?php echo esc_html(mcrt_end_label($banner['ends'])); ?></strong></p><?php endif; ?>
        <?php if ($best > 0) : ?><p class="deals__big"><small><?php echo esc_html($en ? 'up to' : 'jusqu’à'); ?></small>-<?php echo (int) $best; ?> %</p><?php endif; ?>
        <a class="btn btn--gold" href="<?php echo esc_url(mcrt_promos_url()); ?>"><?php echo esc_html(sprintf($en ? 'All %d deals' : 'Les %d promotions', count($ids))); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
      </div>
      <div class="prow-wrap" data-carousel data-autoplay><?php mcrt_product_row($products, 'prow--scroll deals__row'); ?></div>
    </div>
  </div>
</section>
