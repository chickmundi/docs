<?php
/** Promotional banners, edited in MCR Gestion > Paramètres > Page d'accueil. */
defined('ABSPATH') || exit;
$home = $args['home'] ?? [];
$l = mcr_is_en() ? 'en' : 'fr';
$banners = array_values(array_filter((array) ($home['banners'] ?? []), fn($b) => !empty($b['on']) && trim((string) ($b['title_fr'] ?? '')) !== ''));
if (!$banners) {
    return;
}
?>
<section class="section section--tight" aria-label="<?php echo esc_attr($l === 'en' ? 'Featured' : 'À la une'); ?>">
  <div class="container">
    <div class="banners banners--<?php echo count($banners); ?>">
      <?php foreach ($banners as $i => $b) :
          $term = !empty($b['cat']) ? get_term_by('slug', $b['cat'], 'product_cat') : null;
          $url = $b['url'] ?: ($term ? get_term_link($term) : mcr_shop_url());
          $txt = fn($k) => trim((string) ($b["{$k}_{$l}"] ?? '')) ?: trim((string) ($b["{$k}_fr"] ?? ''));
          $img = $b['image'] ? sprintf('<img src="%s" alt="" loading="lazy" decoding="async">', esc_url($b['image'])) : ($term ? mcrt_cat_image($term, 'medium') : '');
          ?>
        <a class="banner banner--<?php echo esc_attr($b['tone'] ?: 'green'); ?><?php echo $i === 0 ? ' banner--wide' : ''; ?>" href="<?php echo esc_url(is_wp_error($url) ? mcr_shop_url() : $url); ?>" data-reveal>
          <span class="banner__txt">
            <?php if ($txt('eyebrow')) : ?><span class="eyebrow"><?php echo esc_html($txt('eyebrow')); ?></span><?php endif; ?>
            <strong class="banner__title"><?php echo esc_html($txt('title')); ?></strong>
            <?php if ($txt('text')) : ?><span class="banner__text"><?php echo esc_html($txt('text')); ?></span><?php endif; ?>
            <span class="banner__cta"><?php echo esc_html($txt('cta') ?: ($l === 'en' ? 'Discover' : 'Découvrir')); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
          </span>
          <?php if ($img) : ?><span class="banner__img"><?php echo $img; // phpcs:ignore ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
