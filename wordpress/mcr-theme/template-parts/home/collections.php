<?php
/** "Faites vos courses selon vos besoins": shopping needs, each linked to a real category with real products. */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$needs = [
    ['epices-et-condiments', 'utensils', $en ? 'African cooking' : 'Cuisine africaine', $en ? 'Spices, flours and sauces for your favourite dishes.' : 'Épices, farines et sauces pour vos plats préférés.'],
    ['garde-manger', 'shopping-basket', $en ? 'Pantry essentials' : 'Les essentiels du garde-manger', $en ? 'Rice, oils, canned goods: everyday basics.' : 'Riz, huiles, conserves : les indispensables du quotidien.'],
    ['petit-dejeuner', 'coffee', $en ? 'Family breakfast' : 'Le petit-déjeuner en famille', $en ? 'Cereals, hot drinks and spreads for everyone.' : 'Céréales, boissons chaudes et tartinades pour toute la famille.'],
    ['soins-corporels', 'droplet', $en ? 'Beauty and well-being' : 'Beauté et bien-être', $en ? 'Body care, soaps and moisturisers.' : 'Soins du corps, savons et hydratants.'],
];
$cards = [];
foreach ($needs as [$slug, $icon, $title, $text]) {
    $term = get_term_by('slug', $slug, 'product_cat');
    if (!$term) {
        continue;
    }
    $products = mcrt_pick(mcrt_products(['category' => [$slug], 'limit' => 12, 'orderby' => 'popularity']), 3, 'need-' . $slug);
    if (count($products) < 1) {
        continue;
    }
    $cards[] = [$term, $icon, $title, $text, $products];
}
if (!$cards) {
    return;
}
?>
<section class="section" aria-labelledby="h-needs">
  <div class="container">
    <?php mcrt_section_head($en ? 'Shop by need' : 'Selon vos besoins', $en ? 'What are you shopping for today?' : 'Que cherchez-vous aujourd’hui ?', '', '', 'h-needs'); ?>
    <ul class="needs">
      <?php foreach ($cards as [$term, $icon, $title, $text, $products]) : ?>
        <li data-reveal>
          <a class="need" href="<?php echo esc_url(get_term_link($term)); ?>">
            <span class="need__ic"><?php echo mcr_icon($icon, 22); ?></span>
            <strong class="need__title"><?php echo esc_html($title); ?></strong>
            <span class="need__text"><?php echo esc_html($text); ?></span>
            <span class="need__thumbs" aria-hidden="true">
              <?php foreach ($products as $p) : ?><span><?php echo $p->get_image('woocommerce_gallery_thumbnail', ['alt' => '', 'loading' => 'lazy']); ?></span><?php endforeach; ?>
            </span>
            <span class="link-arrow"><?php echo esc_html(sprintf($en ? '%d products' : '%d produits', mcr_category_count($term))); ?> <?php echo mcr_icon('arrow-right', 15); ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
