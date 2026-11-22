<?php
/**
 * Homepage promotions pop-up. Everything comes from the promotions that are running now:
 *  1. the "Promo de la semaine" (MCR Gestion > Promotions), if it is on sale and in stock;
 *  2. otherwise the first active campaign shown as a banner;
 *  3. otherwise the products on sale with the biggest discounts.
 * Nothing is running → no pop-up. Shown at most once every 5 minutes per visitor (theme.js, cookie).
 */
defined('ABSPATH') || exit;
if (!function_exists('wc_get_product_ids_on_sale')) {
    return;
}
$en = mcr_is_en();

// Products on sale, best discount first (cached a few minutes).
$best = get_transient('mcrt_popup_sale');
if (!is_array($best)) {
    $ids = array_values(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product' && get_post_status($id) === 'publish'));
    $rows = [];
    foreach (array_slice($ids, 0, 80) as $id) {
        $p = wc_get_product($id);
        if ($p && $p->is_visible() && $p->is_in_stock() && ($pct = mcrt_discount($p)) > 0) {
            $rows[$id] = $pct;
        }
    }
    arsort($rows);
    $best = ['count' => count($rows), 'ids' => array_slice(array_keys($rows), 0, 4)];
    set_transient('mcrt_popup_sale', $best, 10 * MINUTE_IN_SECONDS);
}

$week = function_exists('mcr_promo_of_week') ? mcr_promo_of_week() : null;
$banners = function_exists('mcr_promo_banners') ? mcr_promo_banners() : [];
$banner = $banners[0] ?? null;
if (!$week && !$banner && !$best['count']) {
    return;
}

$url = mcrt_promos_url();
$count = (int) $best['count'];
$thumbs = array_values(array_filter(array_map('wc_get_product', array_diff($best['ids'], $week ? [$week->get_id()] : [])), fn($p) => $p instanceof WC_Product));
$thumbs = array_slice($thumbs, 0, 3);
$end = 0;
if ($week) {
    $end = mcrt_sale_end($week);
} elseif ($banner) {
    $end = (int) $banner['ends'];
}
$simple = $week && $week->is_type('simple') && $week->is_purchasable();
$name = $week ? ($en ? (mcr_en_meta($week->get_id(), 'title') ?: $week->get_name()) : $week->get_name()) : '';
?>
<dialog class="ppop" id="promo-popup" aria-labelledby="ppop-h" data-promo-popup>
  <button type="button" class="ppop__x" data-ppop-close aria-label="<?php echo esc_attr($en ? 'Close' : 'Fermer'); ?>">×</button>
  <div class="ppop__band">
    <p class="ppop__eyebrow"><?php echo mcr_icon('badge-percent', 18); ?> <?php echo esc_html($en ? 'Deals on now' : 'Promotions en cours'); ?></p>
    <?php if ($week) : ?>
      <h2 id="ppop-h"><?php echo esc_html($en ? 'Deal of the week' : 'Promo de la semaine'); ?></h2>
    <?php elseif ($banner) : ?>
      <h2 id="ppop-h"><?php echo esc_html($banner['title'] ?: $banner['label']); ?></h2>
    <?php else : ?>
      <h2 id="ppop-h"><?php echo esc_html(sprintf($en ? '%d products on sale' : '%d produits en promotion', $count)); ?></h2>
    <?php endif; ?>
  </div>

  <div class="ppop__body">
    <?php if ($week) : $pct = mcrt_discount($week); $save = mcrt_saving($week); ?>
      <a class="ppop__hero" href="<?php echo esc_url($week->get_permalink()); ?>">
        <span class="ppop__img"><?php echo $week->get_image('woocommerce_thumbnail', ['loading' => 'lazy', 'alt' => '']); // phpcs:ignore ?>
          <?php if ($pct) : ?><span class="ppop__pct">-<?php echo (int) $pct; ?> %</span><?php endif; ?></span>
        <span class="ppop__info">
          <strong class="ppop__name"><?php echo esc_html($name); ?></strong>
          <span class="ppop__price"><?php echo $week->get_price_html(); // phpcs:ignore ?></span>
          <?php if ($save > 0) : ?><span class="ppop__save"><?php echo wp_kses_post(sprintf($en ? 'You save <b>%s</b>' : 'Vous économisez <b>%s</b>', wc_price($save))); ?></span><?php endif; ?>
          <?php foreach (mcrt_deal_labels($week) as $l) : ?><span class="ppop__save"><?php echo esc_html(($en ? 'Deal: ' : 'Offre : ') . $l); ?></span><?php endforeach; ?>
        </span>
      </a>
    <?php elseif ($banner) : ?>
      <div class="ppop__deal">
        <span class="ppop__label"><?php echo esc_html($banner['label']); ?></span>
        <?php if ($banner['text'] !== '') : ?><p><?php echo esc_html($banner['text']); ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($end > time()) : ?>
      <p class="ppop__ends"><?php echo mcr_icon('clock', 16); ?> <?php echo esc_html($en ? 'Ends in' : 'Se termine dans'); ?> <strong data-countdown="<?php echo (int) $end; ?>"><?php echo esc_html(mcrt_end_label($end)); ?></strong></p>
    <?php endif; ?>

    <?php if ($thumbs) : ?>
      <p class="ppop__sub"><?php echo esc_html($week || $banner ? ($en ? 'Also on sale' : 'Aussi en promotion') : ($en ? 'Biggest discounts' : 'Les plus gros rabais')); ?></p>
      <ul class="ppop__list">
        <?php foreach ($thumbs as $t) : $tn = $en ? (mcr_en_meta($t->get_id(), 'title') ?: $t->get_name()) : $t->get_name(); ?>
          <li><a href="<?php echo esc_url($t->get_permalink()); ?>">
            <span class="ppop__timg"><?php echo $t->get_image('woocommerce_gallery_thumbnail', ['loading' => 'lazy', 'alt' => '']); // phpcs:ignore ?><span class="ppop__tpct">-<?php echo (int) mcrt_discount($t); ?> %</span></span>
            <span class="ppop__tname"><?php echo esc_html($tn); ?></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="ppop__ctas">
      <?php if ($simple) : ?>
        <a class="btn btn--block" href="<?php echo esc_url($week->add_to_cart_url()); ?>" data-add-to-cart="<?php echo (int) $week->get_id(); ?>" data-name="<?php echo esc_attr($name); ?>" data-ppop-close rel="nofollow"><?php echo mcr_icon('shopping-cart', 18); ?> <?php echo esc_html(mcr_t('Ajouter au panier')); ?></a>
      <?php endif; ?>
      <a class="btn btn--block <?php echo $simple ? 'btn--ghost' : 'btn--gold'; ?>" href="<?php echo esc_url($url); ?>" data-ppop-go>
        <?php echo esc_html($count > 1 ? sprintf($en ? 'See all %d deals' : 'Voir les %d promotions', $count) : ($en ? 'See the deals' : 'Voir les promotions')); ?> <?php echo mcr_icon('arrow-right', 18); ?>
      </a>
      <button type="button" class="ppop__later" data-ppop-close><?php echo esc_html($en ? 'Maybe later' : 'Plus tard'); ?></button>
    </div>
  </div>
</dialog>
