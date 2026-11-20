<?php
/** A category row chosen in the homepage settings. */
defined('ABSPATH') || exit;
$sec = $args['section'] ?? [];
$term = get_term_by('slug', (string) ($sec['slug'] ?? ''), 'product_cat');
if (!$term) {
    return;
}
$l = mcr_is_en() ? 'en' : 'fr';
$products = mcrt_products(['category' => [$term->slug], 'limit' => 10, 'orderby' => 'popularity']);
if (!$products) {
    return;
}
$id = 'h-cat-' . (int) ($args['index'] ?? 0);
?>
<section class="section" aria-labelledby="<?php echo esc_attr($id); ?>">
  <div class="container">
    <?php mcrt_section_head(trim((string) ($sec['eyebrow_' . $l] ?? '')), trim((string) ($sec['title_' . $l] ?? '')) ?: $term->name, get_term_link($term), $l === 'en' ? 'See all' : 'Tout voir', $id); ?>
    <?php mcrt_product_row($products, 'prow--scroll'); ?>
  </div>
</section>
