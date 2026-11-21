<?php
/**
 * Page not found: a friendly "empty basket", a search box pre-filled from the address,
 * products that match the lost address (or popular ones), the store's aisles and help.
 */
defined('ABSPATH') || exit;
$en = mcr_is_en();

// Words from the address the visitor tried: /produit/gari-jaune-2lbs/ -> "gari jaune 2lbs".
$path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$parts = array_values(array_filter(explode('/', $path), fn($s) => $s !== '' && !in_array($s, ['fr', 'en', 'produit', 'product', 'product-category', 'categorie-produit', 'boutique', 'shop', 'page'], true)));
$guess = $parts ? strtolower(urldecode((string) end($parts))) : '';
$guess = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', preg_replace('/\.(html?|php)$/', '', $guess))));
if (mb_strlen($guess) < 3 || is_numeric(str_replace(' ', '', $guess))) {
    $guess = '';
}

// Products that look like what was asked for: the whole phrase, then its first meaningful words.
$matches = [];
$found = '';
if ($guess !== '') {
    $words = array_values(array_filter(explode(' ', $guess), fn($w) => mb_strlen($w) > 2 && !in_array($w, ['les', 'des', 'pour', 'avec', 'the', 'and', 'for', 'with'], true)));
    foreach (array_unique([$guess, implode(' ', array_slice($words, 0, 2)), $words[0] ?? '']) as $q) {
        if ($q === '') {
            continue;
        }
        $ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'fields' => 'ids', 'posts_per_page' => 8]);
        $matches = array_values(array_filter(array_map('wc_get_product', $ids), fn($p) => $p && $p->is_visible()));
        if ($matches) {
            $found = $q;
            break;
        }
    }
}
$popular = $matches ? [] : mcrt_products(['limit' => 8, 'orderby' => 'popularity']);
$cats = array_values(array_filter(mcr_top_categories(), fn($c) => !in_array($c->slug, ['autres', 'tout'], true) && mcr_category_count($c) > 0));
$wa = mcrt_whatsapp_link($en ? 'Hello, I am looking for a product on your website: ' : 'Bonjour, je cherche un produit sur votre site : ');

get_header();
?>
<section class="nf">
  <div class="container nf__in">
    <div class="nf__art" aria-hidden="true">
      <svg viewBox="0 0 360 200" class="nf__svg">
        <text x="40" y="150" class="nf__4">4</text>
        <text x="250" y="150" class="nf__4">4</text>
        <!-- an empty woven basket in place of the 0 -->
        <g transform="translate(180 112)">
          <ellipse cx="0" cy="62" rx="58" ry="8" fill="rgba(23,75,53,.12)"/>
          <path d="M-48 -26 C-46 30 -34 56 0 56 C34 56 46 30 48 -26 Z" fill="#d9a066"/>
          <path d="M-46 -8 C-20 0 20 0 46 -8 M-44 10 C-20 18 20 18 44 10 M-38 28 C-18 35 18 35 38 28 M-28 44 C-12 49 12 49 28 44" stroke="#a8692f" stroke-width="4" fill="none" stroke-linecap="round"/>
          <path d="M-30 -24 C-28 20 -18 50 -8 55 M-10 -26 C-10 20 -4 50 0 56 M10 -26 C10 20 4 50 0 56 M30 -24 C28 20 18 50 8 55" stroke="#c4843f" stroke-width="3" fill="none" opacity=".8"/>
          <ellipse cx="0" cy="-26" rx="48" ry="11" fill="#8a4e24"/>
          <ellipse cx="0" cy="-27" rx="40" ry="7" fill="#5e3216"/>
          <path d="M-40 -28 C-38 -78 38 -78 40 -28" stroke="#8a4e24" stroke-width="7" fill="none" stroke-linecap="round"/>
        </g>
        <!-- a plantain that rolled away -->
        <g class="nf__plantain" transform="translate(300 172) rotate(-12)">
          <path d="M-34 2 C-20 14 18 14 34 -4 C26 4 -14 6 -34 -4 Z" fill="#e6b74f"/>
          <path d="M-34 -4 C-14 6 26 4 34 -4" stroke="#b8861f" stroke-width="2" fill="none"/>
          <path d="M34 -4 l6 -4" stroke="#5e3216" stroke-width="4" stroke-linecap="round"/>
        </g>
        <circle cx="96" cy="40" r="5" fill="#c96b43"/><circle cx="270" cy="34" r="4" fill="#e6b74f"/><circle cx="330" cy="70" r="3" fill="#174b35"/>
      </svg>
    </div>
    <p class="eyebrow"><?php echo esc_html($en ? 'Error 404 · Page not found' : 'Erreur 404 · Page introuvable'); ?></p>
    <h1><?php echo esc_html($en ? 'Oops! This aisle is empty.' : 'Oups ! Ce rayon est vide.'); ?></h1>
    <p class="nf__lead"><?php echo esc_html($en ? 'The page you are looking for does not exist or has moved. The market is still open, though: let’s find what you need.' : 'La page que vous cherchez n’existe pas ou a été déplacée. Mais le marché reste ouvert : trouvons ce qu’il vous faut.'); ?></p>

    <form class="nf__search" action="<?php echo esc_url(home_url('/')); ?>" role="search">
      <label for="nf-q" class="sr-only"><?php mcr_e('Rechercher un produit'); ?></label>
      <?php echo mcr_icon('search', 20); ?>
      <input id="nf-q" name="s" type="search" value="<?php echo esc_attr($found); ?>" placeholder="<?php echo esc_attr(mcr_t('Rechercher : gari, perruque, plantain…')); ?>">
      <input type="hidden" name="post_type" value="product">
      <button class="btn" type="submit"><?php mcr_e('Rechercher'); ?></button>
    </form>

    <div class="nf__actions">
      <a class="btn btn--ghost" href="<?php echo esc_url(home_url('/')); ?>"><?php echo mcr_icon('house', 18); ?> <?php mcr_e('Accueil'); ?></a>
      <a class="btn btn--ghost" href="<?php echo esc_url(mcr_shop_url()); ?>"><?php echo mcr_icon('shopping-basket', 18); ?> <?php echo esc_html($en ? 'All products' : 'Tous les produits'); ?></a>
      <?php if ($wa) : ?>
        <a class="btn btn--wa" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('whatsapp', 18); ?> <?php echo esc_html($en ? 'Ask us on WhatsApp' : 'Demandez-nous sur WhatsApp'); ?></a>
      <?php else : ?>
        <a class="btn btn--ghost" href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo mcr_icon('phone', 18); ?> <?php echo esc_html((string) mcrt_store('phone', '+1 819-372-9966')); ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($matches || $popular) : ?>
<section class="section">
  <div class="container">
    <?php
    if ($matches) {
        mcrt_section_head($en ? 'Were you looking for…' : 'Vous cherchiez peut-être…', sprintf($en ? 'Products like “%s”' : 'Produits proches de « %s »', $found), add_query_arg(['s' => $found, 'post_type' => 'product'], home_url('/')), $en ? 'See all results' : 'Tous les résultats');
    } else {
        mcrt_section_head($en ? 'Customer favourites' : 'Les préférés de nos clients', $en ? 'Popular products' : 'Produits populaires', mcr_shop_url(), $en ? 'All products' : 'Tous les produits');
    }
    ?>
    <div class="prow-wrap" data-carousel><?php mcrt_product_row($matches ?: $popular, 'prow--scroll'); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($cats) : ?>
<section class="section nf-cats">
  <div class="container">
    <?php mcrt_section_head($en ? 'Browse the aisles' : 'Parcourez nos rayons', $en ? 'Our categories' : 'Nos catégories'); ?>
    <ul class="nf-cats__list">
      <?php foreach ($cats as $c) : $img = mcrt_cat_image($c, 'medium'); ?>
        <li><a href="<?php echo esc_url(get_term_link($c)); ?>">
          <span class="nf-cats__img"><?php echo $img ?: mcr_icon(mcr_category_icon($c), 24); ?></span>
          <span><strong><?php echo esc_html($c->name); ?></strong><small><?php echo esc_html(mcr_category_count($c) . ' ' . ($en ? 'products' : 'produits')); ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>
<?php
get_footer();
