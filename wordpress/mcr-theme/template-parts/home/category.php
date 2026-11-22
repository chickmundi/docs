<?php
/** A category row chosen in the homepage settings. */
defined('ABSPATH') || exit;
$sec = $args['section'] ?? [];
$term = get_term_by('slug', (string) ($sec['slug'] ?? ''), 'product_cat');
if (!$term) {
    return;
}
$l = mcr_is_en() ? 'en' : 'fr';
// A different 10 among the aisle's 30 best sellers on each visit.
$products = mcrt_pick(mcrt_products(['category' => [$term->slug], 'limit' => 30, 'orderby' => 'popularity']), 10, 'cat-' . $term->slug);
if (!$products) {
    return;
}
$id = 'h-cat-' . (int) ($args['index'] ?? 0);
?>
<section class="section" aria-labelledby="<?php echo esc_attr($id); ?>">
  <div class="container">
    <?php mcrt_section_head(trim((string) ($sec['eyebrow_' . $l] ?? '')), trim((string) ($sec['title_' . $l] ?? '')) ?: $term->name, get_term_link($term), $l === 'en' ? 'See all' : 'Tout voir', $id); ?>
    <div class="prow-wrap" data-carousel><?php mcrt_product_row($products, 'prow--scroll'); ?></div>
  </div>
</section>
