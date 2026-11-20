<?php
/**
 * Shop filters: categories, price range, in stock, promotions. Every option is a real
 * WooCommerce query (min_price / max_price are WooCommerce's own price filter).
 * On phones it opens as a panel from the "Filtres" button.
 */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$current = is_product_category() ? get_queried_object() : null;
$current_id = $current->term_id ?? 0;
$parent_id = $current ? ($current->parent ?: $current->term_id) : 0;
$on_sale = count(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product'));
$keep = [];
foreach (['s', 'post_type', 'orderby'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $keep[$k] = sanitize_text_field(wp_unslash($_GET[$k]));
    }
}
$action = is_search() ? home_url('/') : ($current ? get_term_link($current) : mcr_shop_url());
?>
<aside class="filters" id="filters" aria-label="<?php echo esc_attr($en ? 'Filters' : 'Filtres'); ?>" data-filters>
  <div class="filters__head">
    <strong><?php echo esc_html($en ? 'Filters' : 'Filtres'); ?></strong>
    <button type="button" class="drawer__x" data-filters-close aria-label="<?php echo esc_attr(mcr_t('Fermer')); ?>"><?php echo mcr_icon('x', 22); ?></button>
  </div>
  <div class="filters__body">
    <section class="fbox">
      <h2 class="fbox__title"><?php mcr_e('Catégories'); ?></h2>
      <ul class="side__quick">
        <li><a href="<?php echo esc_url(mcr_shop_url()); ?>" <?php echo is_shop() && empty($_GET['promotions']) && !is_search() ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon('store', 17); ?> <?php mcr_e('Tous les produits'); ?></a></li>
        <li><a href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>" <?php echo !empty($_GET['promotions']) && !$current ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon('badge-percent', 17); ?> <?php mcr_e('Promotions'); ?> <span><?php echo (int) $on_sale; ?></span></a></li>
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
    </section>

    <form class="fbox" method="get" action="<?php echo esc_url($action); ?>">
      <?php foreach ($keep as $k => $v) : ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
      <h2 class="fbox__title"><?php echo esc_html($en ? 'Price' : 'Prix'); ?></h2>
      <div class="fbox__price">
        <label><span><?php echo esc_html($en ? 'Min' : 'Min.'); ?></span><input type="number" name="min_price" min="0" step="1" inputmode="numeric" value="<?php echo isset($_GET['min_price']) ? esc_attr((int) $_GET['min_price']) : ''; ?>" placeholder="0"></label>
        <span aria-hidden="true">–</span>
        <label><span><?php echo esc_html($en ? 'Max' : 'Max.'); ?></span><input type="number" name="max_price" min="0" step="1" inputmode="numeric" value="<?php echo isset($_GET['max_price']) ? esc_attr((int) $_GET['max_price']) : ''; ?>" placeholder="500"></label>
      </div>
      <h2 class="fbox__title"><?php echo esc_html($en ? 'Availability' : 'Disponibilité'); ?></h2>
      <label class="check"><input type="checkbox" name="stock" value="instock" <?php checked(!empty($_GET['stock'])); ?>> <span><?php echo esc_html($en ? 'In stock only' : 'En stock seulement'); ?></span></label>
      <label class="check"><input type="checkbox" name="promotions" value="1" <?php checked(!empty($_GET['promotions'])); ?>> <span><?php echo esc_html($en ? 'On sale' : 'En promotion'); ?></span></label>
      <button class="btn btn--block" type="submit"><?php echo esc_html($en ? 'Apply' : 'Appliquer'); ?></button>
    </form>
  </div>
</aside>
<div class="filters__backdrop" data-filters-close hidden></div>
