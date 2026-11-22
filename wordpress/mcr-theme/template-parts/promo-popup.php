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
    <span class="ppop__fx" aria-hidden="true">
      <svg class="ppop__sun" viewBox="0 0 200 200"><g fill="none" stroke="currentColor"><circle cx="100" cy="100" r="62" stroke-width="10" stroke-dasharray="14 10"/><circle cx="100" cy="100" r="88" stroke-width="3" stroke-dasharray="2 9" stroke-linecap="round"/><circle cx="100" cy="100" r="36" stroke-width="6"/></g></svg>
      <i class="ppop__bit b1"></i><i class="ppop__bit b2"></i><i class="ppop__bit b3"></i><i class="ppop__bit b4"></i><i class="ppop__bit b5"></i><i class="ppop__bit b6"></i>
      <svg class="ppop__tag" viewBox="0 0 64 64"><path d="M6 30 30 6h24a4 4 0 0 1 4 4v24L34 58a4 4 0 0 1-5.6 0L6 35.6a4 4 0 0 1 0-5.6Z" fill="currentColor"/><circle cx="46" cy="18" r="5" fill="#a9532f"/><path d="M24 40 40 24" stroke="#a9532f" stroke-width="4" stroke-linecap="round"/><circle cx="25" cy="27" r="3.5" fill="#a9532f"/><circle cx="38" cy="38" r="3.5" fill="#a9532f"/></svg>
    </span>
    <p class="ppop__eyebrow"><?php echo mcr_icon('badge-percent', 18); ?> <?php echo esc_html($en ? 'Deals on now' : 'Promotions en cours'); ?></p>
    <?php if ($week) : ?>
      <h2 id="ppop-h" tabindex="-1"><?php echo esc_html($en ? 'Deal of the week' : 'Promo de la semaine'); ?></h2>
    <?php elseif ($banner) : ?>
      <h2 id="ppop-h" tabindex="-1"><?php echo esc_html($banner['title'] ?: $banner['label']); ?></h2>
    <?php else : ?>
      <h2 id="ppop-h" tabindex="-1"><?php echo esc_html(sprintf($en ? '%d products on sale' : '%d produits en promotion', $count)); ?></h2>
    <?php endif; ?>
  </div>

  <div class="ppop__body">
    <?php if ($week) : $pct = mcrt_discount($week); $save = mcrt_saving($week); ?>
      <a class="ppop__hero" href="<?php echo esc_url($week->get_permalink()); ?>">
        <span class="ppop__img"><?php echo $week->get_image('woocommerce_thumbnail', ['loading' => 'lazy', 'alt' => '']); // phpcs:ignore ?>
          <?php if ($pct) : ?><svg class="ppop__burst" viewBox="0 0 100 100" aria-hidden="true"><path fill="currentColor" d="M50 0l7 16 15-9-1 17 17-2-8 15 16 7-15 8 9 15-17-1 2 17-15-8-7 16-7-16-15 8 2-17-17 1 9-15-15-8 16-7-8-15 17 2-1-17 15 9z"/></svg><span class="ppop__pct">-<?php echo (int) $pct; ?> %</span><?php endif; ?></span>
        <span class="ppop__info">
          <strong class="ppop__name"><?php echo esc_html($name); ?></strong>
          <span class="ppop__price"><?php echo $week->get_price_html(); // phpcs:ignore ?></span>
          <?php if ($save > 0) : ?><span class="ppop__save"><?php echo wp_kses_post(sprintf($en ? 'You save <b>%s</b>' : 'Vous économisez <b>%s</b>', wc_price($save))); ?></span><?php endif; ?>
          <?php foreach (mcrt_deal_labels($week) as $l) : ?><span class="ppop__save"><?php echo esc_html(($en ? 'Deal: ' : 'Offre : ') . $l); ?></span><?php endforeach; ?>
        </span>
      </a>
    <?php elseif ($banner) : ?>
      <div class="ppop__deal">
        <span class="ppop__labelwrap"><svg class="ppop__burst" viewBox="0 0 100 100" aria-hidden="true"><path fill="currentColor" d="M50 0l7 16 15-9-1 17 17-2-8 15 16 7-15 8 9 15-17-1 2 17-15-8-7 16-7-16-15 8 2-17-17 1 9-15-15-8 16-7-8-15 17 2-1-17 15 9z"/></svg><span class="ppop__label"><?php echo esc_html($banner['label']); ?></span></span>
        <?php if ($banner['text'] !== '') : ?><p><?php echo esc_html($banner['text']); ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($end > time()) : ?>
      <p class="ppop__ends"><span class="ppop__clock"><?php echo mcr_icon('clock', 16); ?></span> <?php echo esc_html($en ? 'Ends in' : 'Se termine dans'); ?> <strong data-countdown="<?php echo (int) $end; ?>"><?php echo esc_html(mcrt_end_label($end)); ?></strong></p>
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
      <a class="btn btn--block <?php echo $simple ? 'btn--ghost' : 'btn--gold'; ?>" href="<?php echo esc_url($url); ?>" data-ppop-go><span class="ppop__shine" aria-hidden="true"></span>
        <?php echo esc_html($count > 1 ? sprintf($en ? 'See all %d deals' : 'Voir les %d promotions', $count) : ($en ? 'See the deals' : 'Voir les promotions')); ?> <span class="ppop__arrow"><?php echo mcr_icon('arrow-right', 18); ?></span>
      </a>
      <button type="button" class="ppop__later" data-ppop-close><?php echo esc_html($en ? 'Maybe later' : 'Plus tard'); ?></button>
    </div>
  </div>
</dialog>
