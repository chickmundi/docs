<?php
/** Hero: main banner (headline, two buttons, quick facts) and two side banners. */
defined('ABSPATH') || exit;
$home = $args['home'] ?? [];
$en = mcr_is_en();
$l = $en ? 'en' : 'fr';
$count = (int) wp_count_posts('product')->publish;
$free = function_exists('mcr_setting') ? (float) mcr_setting('delivery', 'free_min_subtotal') : 99;
$lead = trim((string) ($home['hero_lead_' . $l] ?? '')); // custom text from MCR Gestion, used as is
$rounded = (int) (floor($count / 100) * 100);
$rotate = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($home['hero_rotate_' . $l] ?? '')))));
$slogan = preg_replace('/ +([!?:;»])/u', "\u{00A0}$1", trim((string) ($home['hero_slogan_' . $l] ?? '')));
// Title words appear one after the other.
$wi = 0;
$words = function (string $text) use (&$wi): string {
    $out = [];
    $text = preg_replace('/ +([!?:;»])/u', "\u{00A0}$1", trim($text)); // French spacing: "vous !" stays together
    foreach (preg_split('/[ \t\r\n]+/', $text) as $w) {
        if ($w !== '') {
            $out[] = '<span class="hw"><span style="--i:' . $wi++ . '">' . esc_html($w) . '</span></span>';
        }
    }
    return implode(' ', $out);
};
// Largest real discount right now, for the side banner.
$sale_ids = array_values(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product' && get_post_status($id) === 'publish'));
$best = 0;
foreach (array_slice($sale_ids, 0, 80) as $id) {
    if (($p = wc_get_product($id)) && $p->is_in_stock()) {
        $best = max($best, mcrt_discount($p));
    }
}
$shop = mcr_shop_url();
// The gold card shows the promotions only when products are really on sale and no promotion
// section (Black Friday…) is already shown just below; otherwise it shows the new arrivals.
$has_event = function_exists('mcr_promo_events') && mcr_promo_events();
$show_deals = $sale_ids && !$has_event;
?>
<section class="hero">
  <div class="container hero__grid">
    <div class="hero__main">
      <div class="hero__copy">
        <p class="eyebrow eyebrow--light"><?php echo mcr_icon('map-pin', 15); ?> <?php echo esc_html($home['hero_eyebrow_' . $l] ?? ''); ?></p>
        <h1 class="hero__title"><?php echo $words((string) ($home['hero_title_' . $l] ?? '')); // phpcs:ignore ?> <em><?php echo $words((string) ($home['hero_highlight_' . $l] ?? '')); // phpcs:ignore ?></em></h1>
        <?php if ($lead !== '') : ?>
          <p class="hero__lead"><?php echo esc_html($lead); ?></p>
        <?php else : ?>
          <?php if ($rotate) : ?>
            <p class="hero__rot">
              <span class="hero__rot-intro"><?php echo esc_html($en ? 'Your everyday essentials,' : 'Vos essentiels du quotidien,'); ?></span>
              <span class="rot" data-rotate aria-hidden="true"><?php foreach ($rotate as $i => $w) : ?><span class="rot__w<?php echo $i ? '' : ' is-on'; ?>"><?php echo esc_html($w); ?></span><?php endforeach; ?></span>
              <span class="rot__all"><?php echo esc_html(implode(', ', $rotate)); ?></span>
            </p>
          <?php endif; ?>
          <p class="hero__lead"><?php echo wp_kses_post(sprintf($en ? 'Over %s products to discover, for the needs of the whole community.' : 'Plus de %s produits à découvrir pour répondre aux besoins de toute la communauté.', '<b class="hero__num" data-countup="' . $rounded . '">' . esc_html(number_format_i18n($rounded)) . '</b>')); ?></p>
        <?php endif; ?>
        <?php if ($slogan !== '') : ?><p class="hero__slogan"><span><?php echo esc_html($slogan); ?></span></p><?php endif; ?>
        <div class="hero__cta">
          <a class="btn btn--gold btn--lg" href="<?php echo esc_url($shop); ?>"><?php echo mcr_icon('shopping-basket', 19); ?> <?php echo esc_html($en ? 'Start shopping' : 'Faire mes achats'); ?></a>
          <a class="btn btn--line btn--lg" href="<?php echo esc_url(add_query_arg('orderby', 'date', $shop)); ?>"><?php echo esc_html($en ? 'New arrivals' : 'Explorer les nouveautés'); ?></a>
        </div>
        <ul class="hero__facts">
          <li><?php echo mcr_icon('truck', 18); ?><span><?php echo esc_html($en ? sprintf('Delivery 7 days a week, free from %s', mcrt_money($free)) : sprintf('Livraison 7 jours sur 7, gratuite dès %s', mcrt_money($free))); ?></span></li>
          <li><?php echo mcr_icon('store', 18); ?><span><?php echo esc_html($en ? 'Free in-store pickup' : 'Cueillette gratuite en magasin'); ?></span></li>
        </ul>
      </div>
      <div class="hero__art" aria-hidden="true">
        <span class="hero__disc"></span>
        <span class="hero__img hero__img--a"><?php echo mcrt_cat_image('fruits-et-legumes', 'large', ['loading' => 'eager', 'fetchpriority' => 'high']); ?></span>
        <span class="hero__img hero__img--b"><?php echo mcrt_cat_image('epices-et-condiments', 'medium', ['loading' => 'eager']); ?></span>
        <span class="hero__img hero__img--c"><?php echo mcrt_cat_image('epicerie', 'medium', ['loading' => 'eager']); ?></span>
        <span class="hero__tag"><strong><?php echo esc_html(number_format_i18n(floor($count / 100) * 100)); ?>+</strong><small><?php echo esc_html($en ? 'products' : 'produits'); ?></small></span>
      </div>
    </div>
    <div class="hero__side">
      <a class="hside hside--terra" href="<?php echo esc_url(get_term_link('perruques', 'product_cat')); ?>">
        <span class="hside__txt">
          <span class="eyebrow eyebrow--light"><?php echo esc_html($en ? 'Beauty and hair' : 'Beauté et cheveux'); ?></span>
          <strong><?php echo esc_html($en ? 'Wigs, braids and extensions' : 'Perruques, mèches et extensions'); ?></strong>
          <span class="link-arrow"><?php echo esc_html($en ? 'Shop the collection' : 'Voir la collection'); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
        </span>
        <span class="hside__img"><?php echo mcrt_cat_image('perruques', 'medium', ['loading' => 'eager']); ?></span>
      </a>
      <?php if ($show_deals) : ?>
      <a class="hside hside--gold" href="<?php echo esc_url(mcrt_promos_url()); ?>">
        <span class="hside__txt">
          <span class="eyebrow"><?php echo esc_html($en ? 'Deals' : 'Promotions'); ?></span>
          <strong><?php echo $best > 0 ? esc_html(($en ? 'Up to ' : 'Jusqu’à ') . '-' . $best . ' %') : esc_html($en ? 'Our deals' : 'Nos promotions'); ?></strong>
          <span class="link-arrow"><?php echo esc_html(sprintf($en ? '%d products on sale' : '%d produits en promotion', count($sale_ids))); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
        </span>
      <?php else : ?>
      <a class="hside hside--gold" href="<?php echo esc_url(add_query_arg('orderby', 'date', $shop)); ?>">
        <span class="hside__txt">
          <span class="eyebrow"><?php echo esc_html($en ? 'Just arrived' : 'Fraîchement arrivés'); ?></span>
          <strong><?php echo esc_html($en ? 'New arrivals' : 'Nos nouveautés'); ?></strong>
          <span class="link-arrow"><?php echo esc_html($en ? 'Discover' : 'Découvrir'); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
        </span>
      <?php endif; ?>
        <span class="hside__img"><?php echo mcrt_cat_image('petit-dejeuner', 'medium', ['loading' => 'eager']); ?></span>
      </a>
    </div>
  </div>
</section>
