<?php
/** "Nouveautés": the latest products added to the store. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
// The 30 newest products, a different 10 on each visit.
$products = mcrt_pick(mcrt_products(['limit' => 30, 'orderby' => 'date', 'order' => 'DESC']), 10, 'new');
if (!$products) {
    return;
}
?>
<section class="section" aria-labelledby="h-new">
  <div class="container">
    <?php mcrt_section_head($en ? 'Just arrived' : 'Fraîchement arrivés', $en ? 'New arrivals' : 'Nouveautés', add_query_arg('orderby', 'date', mcr_shop_url()), $en ? 'See all' : 'Tout voir', 'h-new'); ?>
    <div class="prow-wrap" data-carousel><?php mcrt_product_row($products, 'prow--scroll', ['show_new' => true]); ?></div>
  </div>
</section>
