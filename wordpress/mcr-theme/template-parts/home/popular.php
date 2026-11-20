<?php
/** "Vos produits préférés": best sellers (WooCommerce sales count), filterable by aisle. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$tabs = [
    'all' => [$en ? 'All' : 'Tous', []],
    'epicerie' => [$en ? 'Grocery' : 'Épicerie', ['epicerie', 'epices-et-condiments', 'fruits-et-legumes']],
    'petit-dejeuner' => [$en ? 'Breakfast' : 'Petit-déjeuner', ['petit-dejeuner']],
    'frais' => [$en ? 'Meat, fish and frozen' : 'Viandes, poissons et surgelés', ['viandes', 'poissons-et-fruits-de-mer', 'surgeles']],
    'beaute' => [$en ? 'Beauty' : 'Beauté', ['soins-capillaires', 'soins-corporels']],
    'cheveux' => [$en ? 'Wigs and extensions' : 'Perruques et extensions', ['perruques', 'extensions-capillaires']],
];
$panels = [];
foreach ($tabs as $key => [$label, $cats]) {
    $cats = array_values(array_filter($cats, fn($s) => get_term_by('slug', $s, 'product_cat')));
    if ($key !== 'all' && !$cats) {
        continue;
    }
    $args = ['limit' => 8, 'orderby' => 'popularity', 'order' => 'DESC'];
    if ($cats) {
        $args['category'] = $cats;
    }
    $products = mcrt_products($args);
    if ($products) {
        $panels[$key] = [$label, $products];
    }
}
if (!$panels) {
    return;
}
?>
<section class="section section--sand" aria-labelledby="h-pop">
  <div class="container">
    <div class="shead shead--tabs" data-reveal>
      <div><p class="eyebrow"><?php echo esc_html($en ? 'Best sellers' : 'Les plus populaires'); ?></p><h2 id="h-pop"><?php echo esc_html($en ? 'Your favourite products' : 'Vos produits préférés'); ?></h2></div>
      <div class="tabs" role="tablist" aria-label="<?php echo esc_attr($en ? 'Aisles' : 'Rayons'); ?>" data-tabs>
        <?php $first = true; foreach ($panels as $key => [$label]) : ?>
          <button type="button" role="tab" id="tab-<?php echo esc_attr($key); ?>" aria-controls="pane-<?php echo esc_attr($key); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html($label); ?></button>
        <?php $first = false; endforeach; ?>
      </div>
    </div>
    <?php $first = true; foreach ($panels as $key => [$label, $products]) : ?>
      <div class="tabpane" role="tabpanel" id="pane-<?php echo esc_attr($key); ?>" aria-labelledby="tab-<?php echo esc_attr($key); ?>" <?php echo $first ? '' : 'hidden'; ?>>
        <?php mcrt_product_row($products, 'prow--grid'); ?>
      </div>
    <?php $first = false; endforeach; ?>
  </div>
</section>
