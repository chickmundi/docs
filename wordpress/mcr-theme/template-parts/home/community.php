<?php
/** "Les saveurs qui nous rapprochent": the store, its story, address and hours. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
?>
<section class="community has-pbg" aria-labelledby="h-comm">
  <?php echo mcrt_photo_bg('shop-spices'); ?>
  <div class="container community__grid">
    <div class="community__copy" data-reveal>
      <p class="eyebrow eyebrow--gold"><?php echo esc_html($en ? 'More than a grocery store' : 'Bien plus qu’une épicerie'); ?></p>
      <h2 id="h-comm" class="display"><?php echo esc_html($en ? 'The flavours that bring us together' : 'Les saveurs qui nous rapprochent'); ?></h2>
      <p><?php echo esc_html($en
          ? 'In the heart of Trois-Rivières, Marché Chemin du Roy brings together the flavours of Africa and Quebec: familiar products, a warm welcome and a team that knows what you are looking for.'
          : 'Au cœur de Trois-Rivières, le Marché Chemin du Roy réunit les saveurs d’Afrique et d’ici : des produits qui rappellent la maison, un accueil chaleureux et une équipe qui sait ce que vous cherchez.'); ?></p>
      <div class="community__info">
        <div><?php echo mcr_icon('map-pin', 20); ?><p><strong><?php echo esc_html(mcrt_store('street')); ?></strong><span><?php echo esc_html(mcrt_store('city') . ', ' . mcrt_store('region') . ' ' . mcrt_store('postal_code')); ?></span></p></div>
        <div><?php echo mcr_icon('clock', 20); ?><p><?php foreach (mcrt_hours_lines() as [$days, $time]) : ?><span><strong><?php echo esc_html($days); ?></strong> <?php echo esc_html($time); ?></span><?php endforeach; ?></p></div>
      </div>
      <div class="hero__cta">
        <a class="btn btn--gold" href="<?php echo esc_url(mcr_shop_url()); ?>"><?php echo esc_html($en ? 'Explore the store' : 'Explorer la boutique'); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
        <a class="btn btn--line" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('navigation', 17); ?> <?php mcr_e('Itinéraire'); ?></a>
        <a class="btn btn--line" href="<?php echo esc_url(home_url('/a-propos/')); ?>"><?php echo esc_html($en ? 'Our story' : 'Notre histoire'); ?></a>
      </div>
    </div>
    <div class="community__art" aria-hidden="true">
      <span class="community__ring"></span>
      <span class="community__img community__img--a"><?php echo mcrt_cat_image('epicerie', 'medium'); ?></span>
      <span class="community__img community__img--b"><?php echo mcrt_cat_image('viandes', 'medium'); ?></span>
      <span class="community__img community__img--c"><?php echo mcrt_cat_image('soins-capillaires', 'medium'); ?></span>
    </div>
  </div>
</section>
