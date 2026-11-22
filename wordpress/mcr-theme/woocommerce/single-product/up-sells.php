<?php
/** "upsells" products on the product page: one swipeable row (same slider as the homepage) instead of a tall grid. */
defined('ABSPATH') || exit;
$items = array_values(array_filter((array) ($upsells ?? []), fn($p) => $p instanceof WC_Product && $p->is_visible() && $p->is_in_stock()));
if (!$items) {
    return;
}
?>
<section class="upsells products">
  <h2><?php echo esc_html(apply_filters('woocommerce_product_upsells_products_heading', mcr_is_en() ? 'You may also like' : 'Vous aimerez aussi')); ?></h2>
  <div class="prow-wrap" data-carousel><?php mcrt_product_row($items, 'prow--scroll'); ?></div>
</section>
