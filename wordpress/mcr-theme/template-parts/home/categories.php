<?php
/** "Nos catégories": the store's real top categories, with their own images and product counts. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$cats = array_values(array_filter(mcr_top_categories(), fn($c) => !in_array($c->slug, ['autres', 'tout'], true) && mcr_category_count($c) > 0));
if (!$cats) {
    return;
}
$tones = ['sage', 'sand', 'terra', 'gold'];
// Balance the last row at each screen size (2, 3, 4 and 6 columns): the tiles of an
// incomplete last row get wider so the row is always full.
$n = count($cats);
$spans = [];
foreach ([2, 3, 4, 6] as $cols) {
    $r = $n % $cols;
    for ($i = 0; $i < $n; $i++) {
        $spans[$i][$cols] = 1;
    }
    if ($r) {
        $base = intdiv($cols, $r);
        $extra = $cols % $r;
        for ($k = 0; $k < $r; $k++) {
            $spans[$n - $r + $k][$cols] = $base + ($k >= $r - $extra ? 1 : 0);
        }
    }
}
?>
<section class="section" aria-labelledby="h-cats">
  <div class="container">
    <?php mcrt_section_head($en ? 'Browse the aisles' : 'Parcourez nos rayons', $en ? 'Our categories' : 'Nos catégories', mcr_shop_url(), $en ? 'All products' : 'Tous les produits', 'h-cats'); ?>
    <ul class="cats">
      <?php foreach ($cats as $i => $c) : $img = mcrt_cat_image($c, 'medium'); ?>
        <?php
          $style = '--d:' . (($i % 6) * 0.04) . 's';
          $wide = [];
          foreach ($spans[$i] as $cols => $sp) {
              if ($sp > 1) {
                  $style .= ";--c{$cols}:{$sp}";
                  $wide[] = "is-wide-{$cols}";
              }
          }
        ?>
        <li data-reveal class="<?php echo esc_attr(implode(' ', $wide)); ?>" style="<?php echo esc_attr($style); ?>">
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
