<?php
/** Shop, category and search results: header band, filters, sort, product grid. */
defined('ABSPATH') || exit;
get_header();
$en = mcr_is_en();
$term = is_product_category() ? get_queried_object() : null;
$promo = !empty($_GET['promotions']);
$newest = ($_GET['orderby'] ?? '') === 'date' && !$term && !is_search();
if ($term) {
    $title = $term->name;
} elseif (is_search()) {
    $title = sprintf($en ? 'Results for “%s”' : 'Résultats pour « %s »', get_search_query());
} elseif ($promo) {
    $title = mcr_t('Promotions');
} elseif ($newest) {
    $title = mcr_t('Nouveautés');
} else {
    $title = mcr_t('Tous les produits');
}
global $wp_query;
$total = (int) $wp_query->found_posts;
$parent = $term ? ($term->parent ? get_term($term->parent) : $term) : null;
$siblings = $parent ? mcr_child_categories($parent->term_id) : [];
$img = $term ? (mcrt_cat_image($term, 'medium', ['loading' => 'eager']) ?: ($parent ? mcrt_cat_image($parent, 'medium', ['loading' => 'eager']) : '')) : '';

// Active filters, each with a link that removes it.
$active = [];
$base = remove_query_arg(['paged', 'product-page']);
if (isset($_GET['min_price']) || isset($_GET['max_price'])) {
    $min = isset($_GET['min_price']) ? wc_price((float) $_GET['min_price']) : '';
    $max = isset($_GET['max_price']) ? wc_price((float) $_GET['max_price']) : '';
    $active[] = [($min && $max) ? "{$min} – {$max}" : ($min ? ($en ? 'From ' : 'Dès ') . $min : ($en ? 'Up to ' : 'Jusqu’à ') . $max), remove_query_arg(['min_price', 'max_price'], $base)];
}
if (!empty($_GET['stock'])) {
    $active[] = [esc_html($en ? 'In stock' : 'En stock'), remove_query_arg('stock', $base)];
}
if ($promo && ($term || is_search())) {
    $active[] = [esc_html(mcr_t('Promotions')), remove_query_arg('promotions', $base)];
}
?>
<section class="phead<?php echo $img ? ' phead--img' : ''; ?>">
  <div class="container phead__in">
    <div class="phead__copy">
      <?php woocommerce_breadcrumb(); ?>
      <h1><?php echo esc_html($title); ?></h1>
      <?php if ($term && trim(wp_strip_all_tags($term->description)) !== '') : ?><p class="phead__intro"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($term->description), 40)); ?></p><?php endif; ?>
      <p class="phead__count"><?php echo esc_html($total . ' ' . mcr_t($total > 1 ? 'produits' : 'produit')); ?></p>
    </div>
    <?php if ($img) : ?><span class="phead__img" aria-hidden="true"><?php echo $img; // phpcs:ignore ?></span><?php endif; ?>
  </div>
</section>

<div class="container shop">
  <?php get_template_part('template-parts/sidebar'); ?>
  <div class="shop__main">
    <?php if ($siblings) : ?>
      <ul class="chips" aria-label="<?php echo esc_attr(mcr_t('Sous-catégories')); ?>">
        <li><a href="<?php echo esc_url(get_term_link($parent)); ?>" <?php echo $term->term_id === $parent->term_id ? 'aria-current="page"' : ''; ?>><?php mcr_e('Tout voir'); ?></a></li>
        <?php foreach ($siblings as $s) : ?><li><a href="<?php echo esc_url(get_term_link($s)); ?>" <?php echo $s->term_id === $term->term_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html($s->name); ?> <small><?php echo (int) $s->count; ?></small></a></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="tools">
      <button class="tools__filters" type="button" data-filters-open aria-controls="filters" aria-expanded="false"><?php echo mcr_icon('sliders-horizontal', 18); ?> <?php echo esc_html($en ? 'Filters' : 'Filtres'); ?><?php echo $active ? ' <span class="count">' . count($active) . '</span>' : ''; ?></button>
      <p class="tools__count"><?php echo esc_html(sprintf($en ? '%d products' : '%d produits', $total)); ?></p>
      <?php if (woocommerce_product_loop()) { woocommerce_catalog_ordering(); } ?>
    </div>
    <?php if ($active) : ?>
      <ul class="pills" aria-label="<?php echo esc_attr($en ? 'Active filters' : 'Filtres actifs'); ?>">
        <?php foreach ($active as [$label, $url]) : ?><li><a href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr(($en ? 'Remove filter: ' : 'Retirer le filtre : ') . wp_strip_all_tags($label)); ?>"><?php echo wp_kses_post($label); ?> <?php echo mcr_icon('x', 14); ?></a></li><?php endforeach; ?>
        <li><a class="pills__clear" href="<?php echo esc_url(remove_query_arg(['min_price', 'max_price', 'stock', 'paged'], $term || is_search() ? remove_query_arg('promotions', $base) : $base)); ?>"><?php echo esc_html($en ? 'Clear all' : 'Tout effacer'); ?></a></li>
      </ul>
    <?php endif; ?>

    <?php if (woocommerce_product_loop()) : ?>
      <ul class="pgrid">
        <?php while (have_posts()) : the_post(); wc_get_template_part('content', 'product'); endwhile; ?>
      </ul>
      <?php woocommerce_pagination(); ?>
    <?php else : ?>
      <div class="noresult">
        <span class="noresult__ic"><?php echo mcr_icon('search', 32); ?></span>
        <h2><?php echo esc_html($en ? 'No products found' : 'Aucun produit trouvé'); ?></h2>
        <p><?php echo esc_html($active ? ($en ? 'Try removing a filter.' : 'Essayez de retirer un filtre.') : ($en ? 'Check the spelling or try a more general word (e.g. “rice”, “wig”).' : 'Vérifiez l’orthographe ou essayez un mot plus général (ex. « riz », « perruque »).')); ?></p>
        <ul class="chips chips--center">
          <?php foreach (array_slice(mcr_top_categories(), 0, 8) as $c) : if (in_array($c->slug, ['autres', 'tout'], true)) { continue; } ?>
            <li><a href="<?php echo esc_url(get_term_link($c)); ?>"><?php echo esc_html($c->name); ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
get_footer();
