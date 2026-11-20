<?php
/** Shop sidebar: category tree with icons and counts (folds into a button on phones). */
defined('ABSPATH') || exit;
$current = is_product_category() ? get_queried_object() : null;
$current_id = $current->term_id ?? 0;
$parent_id = $current ? ($current->parent ?: $current->term_id) : 0;
$on_sale = count(wc_get_product_ids_on_sale());
?>
<details class="side" open data-side>
  <summary class="side__toggle"><?php echo mcr_icon('sliders-horizontal', 18); ?><span><?php mcr_e('Catégories'); ?></span><?php echo mcr_icon('chevron-down', 18, 'side__chev'); ?></summary>
  <nav class="side__body" aria-label="<?php echo esc_attr(mcr_t('Catégories')); ?>">
    <h2 class="side__title"><?php mcr_e('Catégories'); ?></h2>
    <ul class="side__quick">
      <li><a href="<?php echo esc_url(mcr_shop_url()); ?>" <?php echo is_shop() && empty($_GET['promotions']) ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon('store', 18); ?> <?php mcr_e('Tous les produits'); ?> <span><?php echo (int) wp_count_posts('product')->publish; ?></span></a></li>
      <li><a href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>" <?php echo !empty($_GET['promotions']) ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon('badge-percent', 18); ?> <?php mcr_e('Promotions'); ?> <span><?php echo (int) $on_sale; ?></span></a></li>
      <li><a href="<?php echo esc_url(add_query_arg('orderby', 'date', mcr_shop_url())); ?>"><?php echo mcr_icon('sparkles', 18); ?> <?php mcr_e('Nouveautés'); ?></a></li>
    </ul>
    <ul class="side__tree">
      <?php foreach (mcr_top_categories() as $c) : $kids = mcr_child_categories($c->term_id); $open = $c->term_id === $parent_id; ?>
        <li class="<?php echo $open ? 'is-open' : ''; ?>">
          <div class="side__row">
            <a href="<?php echo esc_url(get_term_link($c)); ?>" <?php echo $c->term_id === $current_id ? 'aria-current="page"' : ''; ?>><span class="side__ic"><?php echo mcr_icon(mcr_category_icon($c), 17); ?></span><span class="side__name"><?php echo esc_html($c->name); ?></span><span class="side__n"><?php echo (int) mcr_category_count($c); ?></span></a>
            <?php if ($kids) : ?><button type="button" class="side__exp" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr(mcr_t('Sous-catégories') . ' : ' . $c->name); ?>" data-side-exp><?php echo mcr_icon('chevron-down', 16); ?></button><?php endif; ?>
          </div>
          <?php if ($kids) : ?>
            <ul class="side__kids" <?php echo $open ? '' : 'hidden'; ?>>
              <?php foreach ($kids as $k) : ?><li><a href="<?php echo esc_url(get_term_link($k)); ?>" <?php echo $k->term_id === $current_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html($k->name); ?> <span class="side__n"><?php echo (int) $k->count; ?></span></a></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>
</details>
