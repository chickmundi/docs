<?php
defined('ABSPATH') || exit;
get_header();

$rail = function (array $args): array {
    $q = new WC_Product_Query(array_merge(['status' => 'publish', 'limit' => 12, 'stock_status' => 'instock', 'orderby' => 'date', 'order' => 'DESC'], $args));
    return $q->get_products();
};
$autres = get_term_by('slug', 'autres', 'product_cat');
$on_sale = array_slice(wc_get_product_ids_on_sale(), 0, 40);
$deals = $on_sale ? $rail(['include' => $on_sale]) : [];
usort($deals, function ($a, $b) {
    $pct = fn($p) => $p->get_regular_price() ? 1 - $p->get_price() / $p->get_regular_price() : 0;
    return $pct($b) <=> $pct($a);
});
$newest = $rail([]);
$home = function_exists('mcr_setting') ? mcr_setting('home') : [];
$sections = $home['sections'] ?? [
    ['type' => 'categories', 'on' => true], ['type' => 'promos', 'on' => true], ['type' => 'new', 'on' => true],
    ['type' => 'split', 'on' => true], ['type' => 'visit', 'on' => true],
];
$delivery = function_exists('mcr_setting') ? mcr_setting('delivery') : ['start' => '09:00', 'end' => '18:00', 'free_radius_km' => 15];
$clock = function (string $t) use (&$en): string {
    [$h, $m] = array_map('intval', explode(':', $t . ':0'));
    return $en ? date('g', mktime($h, $m)) . ($m ? date(':i', mktime($h, $m)) : '') . ' ' . date('a', mktime($h, $m)) : ($m ? sprintf('%d h %02d', $h, $m) : sprintf('%d h', $h));
};
$tones = ['ochre', 'terracotta', 'leaf', 'brand'];
$en = mcr_is_en();
$free = function_exists('mcr_setting') ? (float) mcr_setting('delivery', 'free_min_subtotal') : 99;
$count = (int) wp_count_posts('product')->publish;
?>
<section class="hero wax-bg">
  <div class="container hero__grid">
    <div class="hero__copy">
      <p class="hero__eyebrow"><?php echo mcr_icon('map-pin', 16); ?> <?php echo esc_html($en ? 'Trois-Rivières, on the Chemin du Roy' : 'Trois-Rivières, sur le Chemin du Roy'); ?></p>
      <h1><?php echo esc_html($home['hero_title_' . ($en ? 'en' : 'fr')] ?? ''); ?> <span><?php echo esc_html($home['hero_highlight_' . ($en ? 'en' : 'fr')] ?? ''); ?></span></h1>
      <p class="hero__lead"><?php $lead = trim((string) ($home['hero_lead_' . ($en ? 'en' : 'fr')] ?? '')); echo esc_html($lead !== '' ? $lead : ($en
          ? sprintf('African and Québec groceries, frozen meat and fish, wigs, extensions and hair care. Over %d products, in store and online.', floor($count / 100) * 100)
          : sprintf('Épicerie africaine et québécoise, viandes et poissons surgelés, perruques, extensions et soins capillaires. Plus de %d produits, en magasin et en ligne.', floor($count / 100) * 100))); ?></p>
      <div class="hero__cta">
        <a class="btn btn--accent" href="<?php echo esc_url(get_term_link('epicerie', 'product_cat')); ?>"><?php echo mcr_icon('shopping-basket', 18); ?> <?php mcr_e('Faire l’épicerie'); ?></a>
        <a class="btn btn--ghost hero__ghost" href="<?php echo esc_url(get_term_link('perruques', 'product_cat')); ?>"><?php echo mcr_icon('sparkles', 18); ?> <?php mcr_e('Beauté et cheveux'); ?></a>
      </div>
      <ul class="hero__facts">
        <li><span><?php echo mcr_icon('truck', 18); ?></span><?php echo esc_html($en ? sprintf('Delivery 7 days a week, free from %s', mcrt_money($free)) : sprintf('Livraison 7 jours sur 7, gratuite dès %s', mcrt_money($free))); ?></li>
        <li><span><?php echo mcr_icon('store', 18); ?></span><?php mcr_e('Retrait gratuit en magasin'); ?></li>
        <li><span><?php echo mcr_icon('banknote', 18); ?></span><?php mcr_e('Transfert d’argent Ria'); ?></li>
      </ul>
    </div>
    <div class="hero__art" aria-hidden="true">
      <div class="hero__ring"></div>
      <div class="hero__blob hero__blob--a"><img src="<?php echo esc_url(MCRT_URL . '/assets/MRC_0008_Layer-1.png'); ?>" alt="" width="447" height="347"></div>
      <div class="hero__blob hero__blob--b"><img src="<?php echo esc_url(MCRT_URL . '/assets/0005_Layer.png'); ?>" alt="" width="485" height="485"></div>
      <div class="hero__chip hero__chip--a"><?php echo mcr_icon('leaf', 18); ?> <?php echo esc_html($en ? 'Authentic products' : 'Produits authentiques'); ?></div>
      <div class="hero__chip hero__chip--b"><?php echo mcr_icon('clock', 18); ?> <?php echo esc_html(($en ? 'Delivered ' : 'Livré de ') . $clock($delivery['start']) . ($en ? ' to ' : ' à ') . $clock($delivery['end'])); ?></div>
    </div>
  </div>
</section>

<?php
$section = function (string $eyebrow, string $title, string $icon, string $href, array $products, string $class = '') {
    if (!$products) {
        return;
    }
    echo '<section class="section ' . esc_attr($class) . '"><div class="container"><div class="section__head" data-reveal><div>';
    echo ($eyebrow !== '' ? '<p class="eyebrow">' . esc_html($eyebrow) . '</p>' : '') . '<h2 class="with-ic"><span class="h-ic">' . mcr_icon($icon, 22) . '</span>' . esc_html($title) . '</h2></div>';
    echo '<a href="' . esc_url($href) . '">' . esc_html(mcr_t('Voir tout')) . ' ' . mcr_icon('arrow-right', 16) . '</a></div><ul class="grid grid--rail">';
    foreach ($products as $p) {
        echo '<li class="grid__item">';
        get_template_part('template-parts/card', null, ['product' => $p]);
        echo '</li>';
    }
    echo '</ul></div></section>';
};

foreach ($sections as $sec) {
    if (empty($sec['on'])) {
        continue;
    }
    switch ($sec['type'] ?? '') {
        case 'categories': ?>
<section class="section">
  <div class="container">
    <div class="section__head" data-reveal>
      <div><p class="eyebrow"><?php echo esc_html($en ? 'A grocery store like no other' : 'Une épicerie pas comme les autres'); ?></p><h2><?php mcr_e('Magasiner par catégorie'); ?></h2></div>
      <a href="<?php echo esc_url(mcr_shop_url()); ?>"><?php mcr_e('Tous les produits'); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
    </div>
    <ul class="tiles">
      <?php foreach (mcr_top_categories() as $i => $c) : $thumb = get_term_meta($c->term_id, 'thumbnail_id', true); ?>
        <li data-reveal style="--delay:<?php echo esc_attr(($i % 6) * 0.05); ?>s">
          <a href="<?php echo esc_url(get_term_link($c)); ?>" class="tile tile--<?php echo esc_attr($tones[$i % 4]); ?>">
            <span class="tile__ic"><?php echo mcr_icon(mcr_category_icon($c), 20); ?></span>
            <span class="tile__name"><?php echo esc_html($c->name); ?></span>
            <span class="tile__n"><?php echo esc_html(mcr_category_count($c) . ' ' . mcr_t('produits')); ?></span>
            <?php if ($thumb) echo wp_get_attachment_image($thumb, 'thumbnail', false, ['alt' => '', 'loading' => 'lazy']); ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php
            break;
        case 'promos':
            $section($en ? 'While supplies last' : 'Dans la limite des stocks', mcr_t('Promotions'), 'badge-percent', add_query_arg('promotions', '1', mcr_shop_url()), $deals, 'section--deals');
            break;
        case 'new':
            $section($en ? 'This week' : 'Cette semaine', mcr_t('Nouveautés'), 'sparkles', add_query_arg('orderby', 'date', mcr_shop_url()), $newest);
            break;
        case 'split': ?>
<section class="container">
  <div class="split">
    <a class="split__card split__card--food wax-bg" href="<?php echo esc_url(get_term_link('epices-et-condiments', 'product_cat')); ?>" data-reveal>
      <p class="eyebrow"><?php echo esc_html($en ? 'Authentic flavours' : 'Saveurs authentiques'); ?></p>
      <h2><?php echo esc_html($en ? 'African spices, flours and condiments' : 'Épices, farines et condiments d’Afrique'); ?></h2>
      <span class="btn split__btn"><?php echo esc_html($en ? 'Discover' : 'Découvrir'); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
    </a>
    <a class="split__card split__card--hair wax-bg" href="<?php echo esc_url(get_term_link('soins-capillaires', 'product_cat')); ?>" data-reveal style="--delay:.08s">
      <p class="eyebrow"><?php echo esc_html($en ? 'Best deals' : 'Les meilleures offres'); ?></p>
      <h2><?php echo esc_html($en ? 'Transform your hair care routine' : 'Transformez votre routine de soins capillaires'); ?></h2>
      <span class="btn split__btn"><?php echo esc_html($en ? 'Discover' : 'Découvrir'); ?> <?php echo mcr_icon('arrow-right', 16); ?></span>
    </a>
  </div>
</section>
<?php
            break;
        case 'category':
            $term = get_term_by('slug', (string) ($sec['slug'] ?? ''), 'product_cat');
            if (!$term) {
                break;
            }
            $products = $rail(['category' => [$term->slug], 'limit' => 24]);
            if ($autres && $term->slug !== 'autres') {
                $products = array_values(array_filter($products, fn($p) => !in_array($autres->term_id, $p->get_category_ids(), true)));
            }
            $l = $en ? 'en' : 'fr';
            $title = trim((string) ($sec['title_' . $l] ?? '')) ?: $term->name;
            $section(trim((string) ($sec['eyebrow_' . $l] ?? '')), $title, mcr_category_icon($term), get_term_link($term), array_slice($products, 0, 12));
            break;
        case 'visit': ?>
<section class="container">
  <div class="visit wax-bg" data-reveal>
    <div>
      <p class="eyebrow"><?php mcr_e('Venez nous voir'); ?></p>
      <h2><?php echo esc_html(mcrt_store('street') . ', ' . mcrt_store('city')); ?></h2>
      <ul class="visit__info">
        <?php foreach (mcrt_hours_lines() as [$days, $time]) : ?><li><?php echo mcr_icon('clock', 18); ?><span><strong><?php echo esc_html($days); ?></strong> <?php echo esc_html($time); ?></span></li><?php endforeach; ?>
      </ul>
      <div class="hero__cta">
        <a class="btn" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('navigation', 18); ?> <?php mcr_e('Itinéraire'); ?></a>
        <a class="btn btn--ghost" href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo mcr_icon('phone', 18); ?> <?php echo esc_html(mcrt_store('phone')); ?></a>
      </div>
    </div>
    <ul class="visit__perks">
      <li><span class="perk-ic perk-ic--ochre"><?php echo mcr_icon('truck', 22); ?></span><strong><?php mcr_e('Livraison'); ?></strong><span><?php echo esc_html($en ? sprintf('Free from %s within %s km.', mcrt_money($free), (float) $delivery['free_radius_km']) : sprintf('Gratuite dès %s jusqu’à %s km.', mcrt_money($free), (float) $delivery['free_radius_km'])); ?></span></li>
      <li><span class="perk-ic perk-ic--leaf"><?php echo mcr_icon('store', 22); ?></span><strong><?php mcr_e('Retrait en magasin'); ?></strong><span><?php echo esc_html($en ? 'Free, ready when you are.' : 'Gratuit, prêt quand vous l’êtes.'); ?></span></li>
      <li><span class="perk-ic perk-ic--terracotta"><?php echo mcr_icon('credit-card', 22); ?></span><strong><?php mcr_e('Paiement flexible'); ?></strong><span><?php mcr_e('En ligne, à la livraison ou en magasin'); ?></span></li>
      <li><span class="perk-ic perk-ic--brand"><?php echo mcr_icon('banknote', 22); ?></span><strong>Ria</strong><span><?php mcr_e('Transfert d’argent Ria'); ?></span></li>
    </ul>
  </div>
</section>
<?php
            break;
    }
}
get_footer();
