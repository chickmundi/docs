<?php
/** Shop, category and search results: banner + sidebar + product grid. */
defined('ABSPATH') || exit;
get_header();
$term = is_product_category() ? get_queried_object() : null;
$promo = !empty($_GET['promotions']);
$title = $term ? $term->name : (is_search() ? sprintf(mcr_is_en() ? 'Results for “%s”' : 'Résultats pour « %s »', get_search_query()) : ($promo ? mcr_t('Promotions') : mcr_t('Tous les produits')));
$icon = $term ? mcr_category_icon($term) : ($promo ? 'badge-percent' : (is_search() ? 'search' : 'store'));
global $wp_query;
$total = (int) $wp_query->found_posts;
$siblings = $term ? mcr_child_categories($term->parent ?: $term->term_id) : [];
?>
<section class="banner wax-bg">
  <div class="container">
    <?php woocommerce_breadcrumb(); ?>
    <div class="banner__row">
      <span class="banner__ic"><?php echo mcr_icon($icon, 28); ?></span>
      <div>
        <h1><?php echo esc_html($title); ?></h1>
        <p class="banner__count"><?php echo esc_html($total . ' ' . mcr_t($total > 1 ? 'produits' : 'produit')); ?></p>
      </div>
    </div>
    <?php if ($term && $term->description) : ?><p class="banner__intro"><?php echo esc_html(wp_strip_all_tags($term->description)); ?></p><?php endif; ?>
  </div>
</section>
<div class="container shop">
  <?php get_template_part('template-parts/sidebar'); ?>
  <div class="shop__main">
    <?php if ($siblings) : $parent = $term->parent ? get_term($term->parent) : $term; ?>
      <ul class="chips" aria-label="<?php echo esc_attr(mcr_t('Sous-catégories')); ?>">
        <li><a href="<?php echo esc_url(get_term_link($parent)); ?>" <?php echo !$term->parent ? 'aria-current="page"' : ''; ?>><?php mcr_e('Tout voir'); ?></a></li>
        <?php foreach ($siblings as $s) : ?><li><a href="<?php echo esc_url(get_term_link($s)); ?>" <?php echo $s->term_id === $term->term_id ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon(mcr_category_icon($s), 16); ?> <?php echo esc_html($s->name); ?></a></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (woocommerce_product_loop()) : ?>
      <div class="shop__tools"><?php woocommerce_catalog_ordering(); ?></div>
      <ul class="grid shop__grid">
        <?php while (have_posts()) : the_post(); wc_get_template_part('content', 'product'); endwhile; ?>
      </ul>
      <?php woocommerce_pagination(); ?>
    <?php else : ?>
      <p class="empty"><?php mcr_e('Aucun produit trouvé.'); ?></p>
    <?php endif; ?>
  </div>
</div>
<?php
get_footer();
