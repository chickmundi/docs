<?php
/** Hero: main banner (headline, two buttons, quick facts) and two side banners. */
defined('ABSPATH') || exit;
$home = $args['home'] ?? [];
$en = mcr_is_en();
$l = $en ? 'en' : 'fr';
$count = (int) wp_count_posts('product')->publish;
$free = function_exists('mcr_setting') ? (float) mcr_setting('delivery', 'free_min_subtotal') : 99;
$lead = trim((string) ($home['hero_lead_' . $l] ?? ''));
if ($lead === '') {
    $lead = $en
        ? sprintf('African and local groceries, frozen meat and fish, wigs and hair care: over %d products, delivered in Trois-Rivières or ready for pickup.', floor($count / 100) * 100)
        : sprintf('Épicerie africaine et d’ici, viandes et poissons surgelés, perruques et soins capillaires : plus de %d produits, livrés à Trois-Rivières ou prêts pour la cueillette.', floor($count / 100) * 100);
}
// Largest real discount right now, for the side banner.
$sale_ids = array_values(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product' && get_post_status($id) === 'publish'));
$best = 0;
foreach (array_slice($sale_ids, 0, 80) as $id) {
    if (($p = wc_get_product($id)) && $p->is_in_stock()) {
        $best = max($best, mcrt_discount($p));
    }
}
$shop = mcr_shop_url();
?>
<section class="hero">
  <div class="container hero__grid">
    <div class="hero__main">
      <div class="hero__copy">
        <p class="eyebrow eyebrow--light"><?php echo mcr_icon('map-pin', 15); ?> <?php echo esc_html($home['hero_eyebrow_' . $l] ?? ''); ?></p>
        <h1 class="hero__title"><?php echo esc_html($home['hero_title_' . $l] ?? ''); ?> <em><?php echo esc_html($home['hero_highlight_' . $l] ?? ''); ?></em></h1>
        <p class="hero__lead"><?php echo esc_html($lead); ?></p>
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
      <a class="hside hside--gold" href="<?php echo esc_url(add_query_arg('promotions', '1', $shop)); ?>">
        <span class="hside__txt">
          <span class="eyebrow"><?php echo esc_html($en ? 'Deals of the week' : 'Promotions de la semaine'); ?></span>
          <strong><?php echo $best > 0 ? esc_html(($en ? 'Up to ' : 'Jusqu’à ') . '-' . $best . ' %') : esc_html($en ? 'Our deals' : 'Nos promotions'); ?></strong>
          <span class="link-arrow"><?php echo esc_html(sprintf($en ? '%d products on sale' : '%d produits en promotion', count($sale_ids))); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
        </span>
        <span class="hside__img"><?php echo mcrt_cat_image('petit-dejeuner', 'medium', ['loading' => 'eager']); ?></span>
      </a>
    </div>
  </div>
</section>
