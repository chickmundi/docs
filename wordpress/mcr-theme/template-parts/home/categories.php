<?php
/** "Nos catégories": the store's real top categories, with their own images and product counts. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$cats = array_values(array_filter(mcr_top_categories(), fn($c) => !in_array($c->slug, ['autres', 'tout'], true) && mcr_category_count($c) > 0));
if (!$cats) {
    return;
}
$tones = ['sage', 'sand', 'terra', 'gold'];
?>
<section class="section" aria-labelledby="h-cats">
  <div class="container">
    <?php mcrt_section_head($en ? 'Browse the aisles' : 'Parcourez nos rayons', $en ? 'Our categories' : 'Nos catégories', mcr_shop_url(), $en ? 'All products' : 'Tous les produits', 'h-cats'); ?>
    <ul class="cats">
      <?php foreach ($cats as $i => $c) : $img = mcrt_cat_image($c, 'medium'); ?>
        <li data-reveal style="--d:<?php echo esc_attr(($i % 6) * 0.04); ?>s">
          <a class="cat cat--<?php echo esc_attr($tones[$i % 4]); ?>" href="<?php echo esc_url(get_term_link($c)); ?>">
            <span class="cat__img"><?php echo $img ?: mcr_icon(mcr_category_icon($c), 44); ?></span>
            <span class="cat__name"><?php echo esc_html($c->name); ?></span>
            <span class="cat__n"><?php echo esc_html(mcr_category_count($c) . ' ' . ($en ? 'products' : 'produits')); ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
