<?php
/**
 * About page (/a-propos/): the store's story (text from its existing page), values,
 * what it sells (linked to the real aisles), key figures and a visit section.
 */
defined('ABSPATH') || exit;
get_header();
$en = mcr_is_en();
$count = (int) wp_count_posts('product')->publish;
$cats = array_values(array_filter(mcr_top_categories(), fn($c) => !in_array($c->slug, ['autres', 'tout'], true)));
$link = function (string $slug) {
    $t = get_term_by('slug', $slug, 'product_cat');
    return $t ? get_term_link($t) : mcr_shop_url();
};
$market = [
    ['fruits-et-legumes', $en ? 'Fresh produce' : 'Produits frais', $en ? 'Plantains, yams, okra, assorted vegetables and more.' : 'Bananes plantains, ignames, gombo, assortiment de légumes et plus.'],
    ['farines', $en ? 'African products' : 'Produits africains', $en ? 'Traditional flours (fufu, gari), authentic spices, oils, condiments and more.' : 'Farines traditionnelles (fufu, gari), épices authentiques, huiles, condiments et plus.'],
    ['surgeles', $en ? 'Frozen products' : 'Produits surgelés', $en ? 'Smoked fish, meat, bobolo, eru, placali, attiéké and more.' : 'Poissons fumés, viandes, bobolo, eru, placali, attiéké et plus.'],
    ['perruques', $en ? 'Hair products' : 'Produits capillaires', $en ? 'Braiding hair, wigs, weaves, crochet, accessories and hair care.' : 'Mèches, perruques, tissages, crochets, accessoires et soins pour cheveux.'],
];
?>
<section class="ab-hero has-pbg">
  <?php echo mcrt_photo_bg('shop-staples', 'dark', true); ?>
  <div class="container ab-hero__grid">
    <div class="ab-hero__copy" data-reveal>
      <nav class="breadcrumbs breadcrumbs--light" aria-label="<?php echo esc_attr(mcr_t('Fil d’Ariane')); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php mcr_e('Accueil'); ?></a> <?php echo mcr_icon('chevron-right', 14); ?> <span><?php the_title(); ?></span></nav>
      <p class="eyebrow eyebrow--light"><?php echo esc_html($en ? 'About us' : 'À propos'); ?></p>
      <h1 class="display"><?php echo esc_html($en ? 'A passion for authentic flavours' : 'Une passion pour les saveurs authentiques'); ?></h1>
      <p class="ab-hero__lead"><?php echo esc_html($en
          ? 'Much more than a grocery store, Marché Chemin du Roy is a true culinary destination in the heart of Trois-Rivières. Since we opened, we have been sharing the culinary riches of Quebec and Africa, in a welcoming meeting place where cultural diversity comes through in our products.'
          : 'Bien plus qu’une simple épicerie, le Marché Chemin du Roy est une véritable destination culinaire au cœur de Trois-Rivières. Depuis notre création, nous vous faisons découvrir les richesses gastronomiques d’ici et d’Afrique, dans un lieu de rencontre accueillant où la diversité culturelle s’exprime à travers nos produits.'); ?></p>
      <div class="hero__cta">
        <a class="btn btn--gold btn--lg" href="<?php echo esc_url(mcr_shop_url()); ?>"><?php echo mcr_icon('shopping-basket', 18); ?> <?php echo esc_html($en ? 'Shop online' : 'Magasiner en ligne'); ?></a>
        <a class="btn btn--line btn--lg" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('navigation', 18); ?> <?php echo esc_html($en ? 'Visit the store' : 'Visiter le magasin'); ?></a>
      </div>
    </div>
    <div class="ab-hero__art" aria-hidden="true">
      <span class="ab-hero__tile ab-hero__tile--a"><?php echo mcrt_cat_image('fruits-et-legumes', 'medium', ['loading' => 'eager']); ?></span>
      <span class="ab-hero__tile ab-hero__tile--b"><?php echo mcrt_cat_image('epices-et-condiments', 'medium', ['loading' => 'eager']); ?></span>
      <span class="ab-hero__tile ab-hero__tile--c"><?php echo mcrt_cat_image('perruques', 'medium', ['loading' => 'eager']); ?></span>
      <span class="ab-hero__tile ab-hero__tile--d"><?php echo mcrt_cat_image('viandes', 'medium', ['loading' => 'eager']); ?></span>
    </div>
  </div>
</section>

<div class="container ab">
  <ul class="ab-stats" aria-label="<?php echo esc_attr($en ? 'Key figures' : 'En chiffres'); ?>">
    <li data-reveal><strong><?php echo esc_html(number_format_i18n(floor($count / 100) * 100)); ?>+</strong><span><?php echo esc_html($en ? 'products in store and online' : 'produits en magasin et en ligne'); ?></span></li>
    <li data-reveal style="--d:.05s"><strong><?php echo (int) count($cats); ?></strong><span><?php echo esc_html($en ? 'aisles, from grocery to hair care' : 'rayons, de l’épicerie aux soins capillaires'); ?></span></li>
    <li data-reveal style="--d:.1s"><strong>7/7</strong><span><?php echo esc_html($en ? 'open and delivering every day' : 'ouvert et en livraison tous les jours'); ?></span></li>
    <li data-reveal style="--d:.15s"><strong><?php echo esc_html((float) (function_exists('mcr_setting') ? mcr_setting('delivery', 'max_km') : 15)); ?> km</strong><span><?php echo esc_html($en ? 'delivery area around the store' : 'de zone de livraison autour du magasin'); ?></span></li>
  </ul>

  <section class="ab-values" aria-labelledby="h-values">
    <?php mcrt_section_head($en ? 'What drives us' : 'Ce qui nous anime', $en ? 'Our commitments' : 'Nos engagements', '', '', 'h-values'); ?>
    <ul>
      <li data-reveal><span class="ab-values__ic"><?php echo mcr_icon('users', 26); ?></span><h3><?php echo esc_html($en ? 'Personal service' : 'Un service personnalisé'); ?></h3><p><?php echo esc_html($en ? 'Our passionate, knowledgeable team welcomes you with a smile and guides you, with service tailored to each customer for a unique, attentive, quality experience.' : 'Notre équipe passionnée et experte vous accueille avec le sourire et vous guide. Nous offrons un service adapté aux besoins de chaque client, pour une expérience unique, attentive et de qualité.'); ?></p></li>
      <li data-reveal style="--d:.06s"><span class="ab-values__ic"><?php echo mcr_icon('award', 26); ?></span><h3><?php echo esc_html($en ? 'Quality guaranteed' : 'Qualité garantie'); ?></h3><p><?php echo esc_html($en ? 'Because you deserve the best, we offer products carefully chosen for their quality and excellence.' : 'Parce que vous méritez le meilleur, nous proposons des produits soigneusement choisis pour leur qualité et leur excellence.'); ?></p></li>
      <li data-reveal style="--d:.12s"><span class="ab-values__ic"><?php echo mcr_icon('hand-heart', 26); ?></span><h3><?php echo esc_html($en ? 'Community and sharing' : 'Communauté et partage'); ?></h3><p><?php echo esc_html($en ? 'Our market celebrates the richness of African cultures and the local Quebec spirit. Through every product, we encourage sharing, closeness and support for our communities.' : 'Notre marché célèbre la richesse des cultures africaines et l’esprit québécois. À travers chaque produit, nous encourageons le partage, la proximité et le soutien aux communautés.'); ?></p></li>
    </ul>
  </section>

  <section class="shopgal" aria-labelledby="h-shopgal">
    <div class="container">
      <?php mcrt_section_head($en ? 'In store' : 'En magasin', $en ? 'Step inside our store' : 'Entrez dans notre magasin', '', '', 'h-shopgal'); ?>
      <ul class="shopgal__grid">
        <?php $gi = 0; foreach (mcrt_store_photos() as $name => $cap) : ?>
          <li class="shopgal__item shopgal__item--<?php echo (int) $gi; ?>" data-reveal style="--d:<?php echo esc_attr((string) ($gi * 0.06)); ?>s">
            <?php echo mcrt_photo_img($name, '(min-width: 900px) 40vw, 90vw', false, $cap); ?>
            <span class="shopgal__cap"><?php echo esc_html($cap); ?></span>
          </li>
        <?php $gi++; endforeach; ?>
      </ul>
    </div>
  </section>
  <section class="ab-offer" aria-labelledby="h-offer">
    <?php mcrt_section_head($en ? 'What makes us unique' : 'Notre exclusivité', $en ? 'A convenience store and a market' : 'Un dépanneur et un marché', mcr_shop_url(), $en ? 'Browse every aisle' : 'Voir tous les rayons', 'h-offer'); ?>
    <div class="ab-offer__grid">
      <div class="ab-dep" data-reveal>
        <span class="ab-dep__ic"><?php echo mcr_icon('store', 26); ?></span>
        <h3><?php echo esc_html($en ? 'Convenience store' : 'Produits du dépanneur'); ?></h3>
        <p><?php echo esc_html($en ? 'Beer, pet products, soft drinks, dairy, chips, canned goods, refreshments, household products and more: the everyday essentials, close to home.' : 'Bières, produits pour animaux, liqueurs, produits laitiers, chips, conserves, rafraîchissements, produits ménagers et plus : l’essentiel du quotidien, près de chez vous.'); ?></p>
        <a class="link-arrow" href="<?php echo esc_url($link('garde-manger')); ?>"><?php echo esc_html($en ? 'Pantry and everyday' : 'Garde-manger et quotidien'); ?> <?php echo mcr_icon('arrow-right', 15); ?></a>
      </div>
      <ul class="ab-market">
        <?php foreach ($market as $i => [$slug, $title, $text]) : ?>
          <li data-reveal style="--d:<?php echo esc_attr($i * .05); ?>s">
            <a href="<?php echo esc_url($link($slug)); ?>">
              <span class="ab-market__img"><?php echo mcrt_cat_image($slug, 'medium') ?: mcr_icon('shopping-basket', 40); ?></span>
              <span class="ab-market__txt"><strong><?php echo esc_html($title); ?></strong><span><?php echo esc_html($text); ?></span></span>
              <?php echo mcr_icon('arrow-right', 18, 'ab-market__go'); ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
</div>

<section class="community has-pbg" aria-labelledby="h-visit">
  <?php echo mcrt_photo_bg('shop-pantry'); ?>
  <div class="container community__grid">
    <div class="community__copy" data-reveal>
      <p class="eyebrow eyebrow--gold"><?php echo esc_html($en ? 'Come and see us' : 'Venez nous voir'); ?></p>
      <h2 id="h-visit" class="display"><?php echo esc_html($en ? 'Marché Chemin du Roy, your grocery store in Trois-Rivières' : 'Marché Chemin du Roy, votre épicerie à Trois-Rivières'); ?></h2>
      <div class="community__info">
        <div><?php echo mcr_icon('map-pin', 20); ?><p><strong><?php echo esc_html(mcrt_store('street')); ?></strong><span><?php echo esc_html(mcrt_store('city') . ', ' . mcrt_store('region') . ' ' . mcrt_store('postal_code')); ?></span></p></div>
        <div><?php echo mcr_icon('clock', 20); ?><p><?php foreach (mcrt_hours_lines() as [$dd, $tt]) : ?><span><strong><?php echo esc_html($dd); ?></strong> <?php echo esc_html($tt); ?></span><?php endforeach; ?></p></div>
      </div>
      <div class="hero__cta">
        <a class="btn btn--gold" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('navigation', 17); ?> <?php mcr_e('Itinéraire'); ?></a>
        <a class="btn btn--line" href="<?php echo esc_url(home_url('/contact/')); ?>"><?php echo esc_html($en ? 'Contact us' : 'Nous joindre'); ?></a>
      </div>
    </div>
    <figure class="ab-map" data-reveal>
      <?php echo mcrt_area_map(3.2, [['Cap-de-la-Madeleine', 46.3720, -72.5070, 'town'], [$en ? 'Downtown' : 'Centre-ville', 46.3500, -72.5560, 'town'], [$en ? 'St. Lawrence River' : 'Fleuve Saint-Laurent', 46.3360, -72.5050, 'river']], 'abmap'); // phpcs:ignore ?>
    </figure>
  </div>
</section>
<?php
get_footer();
