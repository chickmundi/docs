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
?>
<section class="section section--deals" aria-labelledby="h-deals">
  <div class="container">
    <div class="deals">
      <div class="deals__intro" data-reveal>
        <p class="eyebrow eyebrow--light"><?php echo mcr_icon('badge-percent', 16); ?> <?php echo esc_html($en ? 'While supplies last' : 'Dans la limite des stocks'); ?></p>
        <h2 id="h-deals"><?php echo esc_html($en ? 'Deals of the moment' : 'Les offres du moment'); ?></h2>
        <?php if ($best > 0) : ?><p class="deals__big"><small><?php echo esc_html($en ? 'up to' : 'jusqu’à'); ?></small>-<?php echo (int) $best; ?> %</p><?php endif; ?>
        <a class="btn btn--gold" href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>"><?php echo esc_html(sprintf($en ? 'All %d deals' : 'Les %d promotions', count($ids))); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
      </div>
      <?php mcrt_product_row($products, 'prow--scroll deals__row'); ?>
    </div>
  </div>
</section>
