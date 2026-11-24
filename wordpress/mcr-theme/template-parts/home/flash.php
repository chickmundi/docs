<?php
/**
 * "Ventes éclair": running flash sales (live countdown, real units sold) and the next ones.
 * Not cached: prices, units sold and times change during the day.
 */
defined('ABSPATH') || exit;
if (!function_exists('mcr_flash_showcase')) {
    return;
}
$show = mcr_flash_showcase();
if (!$show['active'] && !$show['next']) {
    return;
}
$en = mcr_is_en();
$items = array_slice(array_merge($show['active'], $show['active'] ? [] : array_slice($show['next'], 0, 1)), 0, 4);
$upcoming = $show['active'] ? ($show['next'][0] ?? null) : null;
?>
<section class="flash" aria-labelledby="h-flash">
  <span class="flash__bolts" aria-hidden="true"><?php echo mcr_icon('zap', 120, 'flash__bolt flash__bolt--a') . mcr_icon('zap', 70, 'flash__bolt flash__bolt--b'); ?></span>
  <div class="container">
    <div class="flash__head">
      <h2 id="h-flash"><span class="flash__ic"><?php echo mcr_icon('zap', 26); ?></span><?php echo esc_html($en ? 'Flash sales' : 'Ventes éclair'); ?></h2>
      <p><?php echo esc_html($en ? 'Limited quantities at a lightning price. When they are gone, they are gone.' : 'Quantités limitées à prix éclair. Quand c’est fini, c’est fini.'); ?></p>
    </div>
    <ul class="flash__grid">
      <?php foreach ($items as $d) :
          $p = wc_get_product((int) $d['product']);
          if (!$p || !$p->is_visible()) {
              continue;
          }
          [$start, $end] = mcr_flash_window($d);
          $live = $d['state'] === 'active';
          $sold = (int) ($d['sold'] ?? 0);
          $qty = max(1, (int) $d['qty']);
          $pct = (int) min(100, round($sold / $qty * 100));
          $regular = (float) ($p->is_type('variable') ? $p->get_variation_regular_price('min') : $p->get_regular_price());
          $flash = $d['mode'] === 'price' ? min((float) $d['value'], $regular) : $regular * (1 - (float) $d['value'] / 100);
          $off = $regular > 0 ? (int) round((1 - $flash / $regular) * 100) : 0;
          $name = $en && function_exists('mcr_en_meta') ? (mcr_en_meta($p->get_id(), 'title') ?: $p->get_name()) : $p->get_name();
          $simple = $p->is_type('simple') && $p->is_purchasable() && $p->is_in_stock() && apply_filters('mcr_quick_add', true, $p);
          ?>
        <li class="fcard<?php echo $live ? ' is-live' : ($d['state'] === 'soldout' ? ' is-soldout' : ' is-next'); ?>">
          <a class="fcard__img" href="<?php echo esc_url($p->get_permalink()); ?>" tabindex="-1" aria-hidden="true">
            <?php echo $p->get_image('woocommerce_thumbnail', ['loading' => 'lazy', 'alt' => '']); // phpcs:ignore ?>
            <?php if ($off > 0) : ?><span class="fcard__off">-<?php echo (int) $off; ?> %</span><?php endif; ?>
          </a>
          <div class="fcard__body">
            <a class="fcard__name" href="<?php echo esc_url($p->get_permalink()); ?>"><?php echo esc_html($name); ?></a>
            <p class="fcard__price"><ins><?php echo wc_price($flash); // phpcs:ignore ?></ins> <del><?php echo wc_price($regular); // phpcs:ignore ?></del></p>
            <?php if ($live) : ?>
              <div class="fcard__bar" role="img" aria-label="<?php echo esc_attr(sprintf($en ? '%1$d sold out of %2$d' : '%1$d vendus sur %2$d', $sold, $qty)); ?>"><span style="width:<?php echo max(6, $pct); ?>%"></span></div>
              <p class="fcard__sold"><?php echo esc_html(sprintf($en ? '%1$d sold · %2$d left' : '%1$d vendus · plus que %2$d', $sold, max(0, $qty - $sold))); ?></p>
              <p class="fcard__time"><?php echo mcr_icon('clock', 15); ?> <?php echo esc_html($en ? 'Ends in' : 'Se termine dans'); ?> <b data-countdown="<?php echo (int) $end; ?>" data-countdown-reload><?php echo esc_html(function_exists('mcrt_end_label') ? mcrt_end_label($end) : ''); ?></b></p>
              <a class="btn btn--block fcard__go" href="<?php echo esc_url($simple ? $p->add_to_cart_url() : $p->get_permalink()); ?>"<?php echo $simple ? ' data-add-to-cart="' . (int) $p->get_id() . '" data-name="' . esc_attr($name) . '" rel="nofollow"' : ''; ?>><?php echo mcr_icon('zap', 16); ?> <?php echo esc_html($simple ? ($en ? 'Grab it' : 'J’en profite') : ($en ? 'Choose' : 'Choisir')); ?></a>
            <?php elseif ($d['state'] === 'soldout') : ?>
              <p class="fcard__soldout"><?php echo esc_html($en ? 'Sold out — all units are gone!' : 'Épuisé — tout est parti !'); ?></p>
            <?php else : ?>
              <p class="fcard__time fcard__time--next"><?php echo mcr_icon('clock', 15); ?> <?php echo esc_html($en ? 'Starts in' : 'Commence dans'); ?> <b data-countdown="<?php echo (int) $start; ?>" data-countdown-reload><?php echo esc_html(function_exists('mcrt_end_label') ? mcrt_end_label($start) : ''); ?></b></p>
              <p class="fcard__sold"><?php echo esc_html(sprintf($en ? '%1$d units at %2$s · %3$s' : '%1$d unités à %2$s · %3$s', $qty, html_entity_decode(wp_strip_all_tags(wc_price($flash))), wp_date($en ? 'D g:i a' : 'l H \h i', $start, mcr_flash_tz()))); ?></p>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($upcoming) : $pn = wc_get_product((int) $upcoming['product']); [$ns] = mcr_flash_window($upcoming); ?>
      <p class="flash__next"><?php echo mcr_icon('clock', 16); ?> <?php echo esc_html($en ? 'Next flash sale' : 'Prochaine vente éclair'); ?> : <strong><?php echo esc_html($pn ? $pn->get_name() : ''); ?></strong> — <?php echo esc_html($en ? 'in' : 'dans'); ?> <b data-countdown="<?php echo (int) $ns; ?>" data-countdown-reload><?php echo esc_html(function_exists('mcrt_end_label') ? mcrt_end_label($ns) : ''); ?></b></p>
    <?php endif; ?>
  </div>
</section>
