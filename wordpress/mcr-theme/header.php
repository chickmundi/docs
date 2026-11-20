<?php
defined('ABSPATH') || exit;
$en = mcr_is_en();
$ann = function_exists('mcr_setting') ? mcr_setting('announcement') : ['enabled' => false];
$delivery = function_exists('mcr_setting') ? mcr_setting('delivery') : ['start' => '09:00', 'end' => '18:00'];
$fmt = fn($t) => $en ? date('g a', strtotime($t)) : (int) substr($t, 0, 2) . ' h' . (substr($t, 3, 2) !== '00' ? ' ' . substr($t, 3, 2) : '');

// Navigation from the real store categories.
$cat = fn(string $slug) => get_term_by('slug', $slug, 'product_cat') ?: null;
$grocery_kids = array_filter(array_merge(
    $cat('epicerie') ? mcr_child_categories($cat('epicerie')->term_id) : [],
    array_map($cat, ['epices-et-condiments', 'petit-dejeuner', 'fruits-et-legumes', 'viandes', 'poissons-et-fruits-de-mer', 'surgeles'])
));
$grocery_kids = array_values(array_filter($grocery_kids, fn($t) => $t && $t->slug !== 'autres'));
$menu = [];
if ($t = $cat('epicerie')) {
    $menu[] = ['term' => $t, 'kids' => $grocery_kids, 'feature' => $cat('fruits-et-legumes') ?: $t];
}
foreach (['surgeles', 'perruques', 'extensions-capillaires', 'soins-capillaires', 'soins-corporels'] as $slug) {
    if ($t = $cat($slug)) {
        $menu[] = ['term' => $t, 'kids' => mcr_child_categories($t->term_id), 'feature' => $t];
    }
}
$current = is_product_category() ? get_queried_object() : null;
$is_current = function (WP_Term $top, array $kids) use ($current): bool {
    if (!$current) {
        return false;
    }
    foreach (array_merge([$top], $kids) as $t) {
        if ($t && $t->term_id === $current->term_id) {
            return true;
        }
    }
    return false;
};
$account_url = wc_get_page_permalink('myaccount');
$logged = is_user_logged_in();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#123d2b">
<script>document.documentElement.classList.add('js');</script>
<link rel="icon" type="image/png" href="<?php echo esc_url(MCRT_URL . '/assets/favicon.png'); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip" href="#main"><?php mcr_e('Aller au contenu'); ?></a>

<?php if (!empty($ann['enabled']) && mcr_l($ann, 'text') !== '') : ?>
<div class="announce">
  <div class="container announce__inner"><?php echo mcr_icon('truck', 16); ?><p><?php echo esc_html(mcr_l($ann, 'text')); ?></p><a href="<?php echo esc_url(home_url('/livraison/')); ?>"><?php echo esc_html($en ? 'Details' : 'Détails'); ?></a></div>
</div>
<?php endif; ?>

<div class="topbar">
  <div class="container topbar__inner">
    <ul class="topbar__info">
      <li><?php echo mcr_icon('clock', 15); ?> <?php mcr_e('Nous vous livrons tous les jours de'); ?> <strong><?php echo esc_html($fmt($delivery['start']) . ($en ? ' to ' : ' à ') . $fmt($delivery['end'])); ?></strong></li>
      <li><?php echo mcr_icon('ria', 16); ?> <?php mcr_e('Transfert d’argent Ria'); ?></li>
    </ul>
    <ul class="topbar__links">
      <?php foreach (['a-propos' => 'À propos', 'livraison' => 'Livraison', 'contact' => 'Contact'] as $slug => $label) : ?>
        <li><a href="<?php echo esc_url(home_url("/{$slug}/")); ?>"><?php mcr_e($label); ?></a></li>
      <?php endforeach; ?>
      <li><a href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo mcr_icon('phone', 14); ?> <?php echo esc_html(mcrt_store('phone', '+1 819-372-9966')); ?></a></li>
      <li class="lang" aria-label="<?php echo esc_attr($en ? 'Language' : 'Langue'); ?>"><a href="<?php echo esc_url(mcr_switch_url('fr')); ?>" lang="fr" hreflang="fr" <?php echo $en ? '' : 'aria-current="true"'; ?>>FR</a><a href="<?php echo esc_url(mcr_switch_url('en')); ?>" lang="en" hreflang="en" <?php echo $en ? 'aria-current="true"' : ''; ?>>EN</a></li>
    </ul>
  </div>
</div>

<header class="mast">
  <div class="container mast__inner">
    <button class="mast__menu" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false" aria-label="<?php echo esc_attr(mcr_t('Menu')); ?>"><?php echo mcr_icon('menu', 24); ?></button>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="mast__logo" aria-label="<?php echo esc_attr(mcrt_store('name', 'Marché Chemin-du-Roy') . ($en ? ', home' : ', accueil')); ?>">
      <img src="<?php echo esc_url(MCRT_URL . '/assets/MRC-logo-whit.png'); ?>" alt="<?php echo esc_attr(mcrt_store('name', 'Marché Chemin-du-Roy')); ?>" width="375" height="117" fetchpriority="high">
    </a>
    <form class="hsearch" action="<?php echo esc_url(home_url('/')); ?>" role="search" data-search>
      <label for="q" class="sr-only"><?php mcr_e('Rechercher un produit'); ?></label>
      <span class="search__ic" aria-hidden="true"><?php echo mcr_icon('search', 20); ?></span>
      <input id="q" name="s" type="search" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php echo esc_attr(mcr_t('Rechercher : gari, perruque, plantain…')); ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list">
      <input type="hidden" name="post_type" value="product">
      <button class="search__go" type="submit"><?php mcr_e('Rechercher'); ?></button>
      <div class="search__panel" id="search-results" role="listbox" hidden></div>
    </form>
    <nav class="mast__actions" aria-label="<?php echo esc_attr(mcr_t('Raccourcis')); ?>">
      <a href="<?php echo esc_url($account_url); ?>" class="act act--account"><?php echo mcr_icon('user', 22); ?><span class="act__txt"><small><?php echo esc_html($logged ? ($en ? 'Hello' : 'Bonjour') : ($en ? 'Sign in' : 'Se connecter')); ?></small><?php mcr_e('Mon compte'); ?></span></a>
      <a href="<?php echo esc_url(home_url('/ma-liste/')); ?>" class="act act--wish" aria-label="<?php echo esc_attr(mcr_t('Ma liste')); ?>"><span class="act__ic"><?php echo mcr_icon('heart', 22); ?><span class="count" data-wish-count hidden>0</span></span><span class="act__txt"><small><?php echo esc_html($en ? 'Saved' : 'Mes favoris'); ?></small><?php mcr_e('Ma liste'); ?></span></a>
      <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="act act--cart" aria-label="<?php echo esc_attr(mcr_t('Panier')); ?>"><span class="act__ic"><?php echo mcr_icon('shopping-cart', 22); ?><span class="count" data-cart-count <?php echo mcr_cart_count() ? '' : 'hidden'; ?>><?php echo (int) mcr_cart_count(); ?></span></span><span class="act__txt"><small><?php echo esc_html($en ? 'My' : 'Mon'); ?></small><?php mcr_e('Panier'); ?></span></a>
    </nav>
  </div>
</header>

<nav class="nav" aria-label="<?php echo esc_attr(mcr_t('Menu principal')); ?>">
  <div class="container nav__inner">
    <button class="nav__all" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false"><?php echo mcr_icon('layout-grid', 18); ?> <?php mcr_e('Toutes les catégories'); ?></button>
    <ul class="nav__list">
      <?php foreach ($menu as $m) : $t = $m['term']; $kids = $m['kids']; ?>
        <li class="<?php echo $kids ? 'has-mega' : ''; ?>">
          <a href="<?php echo esc_url(get_term_link($t)); ?>" <?php echo $is_current($t, $kids) ? 'aria-current="page"' : ''; ?> <?php echo $kids ? 'aria-haspopup="true"' : ''; ?>><?php echo esc_html($t->name); ?><?php echo $kids ? mcr_icon('chevron-down', 15, 'nav__chev') : ''; ?></a>
          <?php if ($kids) : ?>
            <div class="mega">
              <div class="mega__cols">
                <p class="mega__title"><?php echo esc_html($t->name); ?></p>
                <ul>
                  <?php foreach ($kids as $k) : ?>
                    <li><a href="<?php echo esc_url(get_term_link($k)); ?>"><span class="mega__ic"><?php echo mcr_icon(mcr_category_icon($k), 18); ?></span><span><?php echo esc_html($k->name); ?></span><small><?php echo (int) mcr_category_count($k); ?></small></a></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <a class="mega__feature" href="<?php echo esc_url(get_term_link($t)); ?>">
                <span class="mega__img"><?php echo mcrt_cat_image($m['feature'], 'medium'); ?></span>
                <strong><?php echo esc_html(($en ? 'All of ' : 'Tout le rayon ') . $t->name); ?></strong>
                <span class="link-arrow"><?php echo esc_html((int) mcr_category_count($t) . ' ' . ($en ? 'products' : 'produits')); ?> <?php echo mcr_icon('arrow-right', 15); ?></span>
              </a>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <ul class="nav__end">
      <li><a class="nav__promo" href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>"><?php echo mcr_icon('badge-percent', 17); ?> <?php mcr_e('Promotions'); ?></a></li>
      <li><a href="<?php echo esc_url(add_query_arg('orderby', 'date', mcr_shop_url())); ?>"><?php echo mcr_icon('sparkles', 17); ?> <?php mcr_e('Nouveautés'); ?></a></li>
    </ul>
  </div>
</nav>

<div class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr(mcr_t('Menu')); ?>" hidden>
  <div class="drawer__backdrop" data-drawer-close></div>
  <div class="drawer__panel">
    <div class="drawer__head">
      <img src="<?php echo esc_url(MCRT_URL . '/assets/MRC-logo-whit.png'); ?>" alt="" width="120" height="37">
      <button class="drawer__x" type="button" aria-label="<?php echo esc_attr(mcr_t('Fermer')); ?>" data-drawer-close><?php echo mcr_icon('x', 22); ?></button>
    </div>
    <div class="drawer__body">
      <ul class="drawer__quick">
        <li><a href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>"><?php echo mcr_icon('badge-percent', 20); ?> <?php mcr_e('Promotions'); ?></a></li>
        <li><a href="<?php echo esc_url(add_query_arg('orderby', 'date', mcr_shop_url())); ?>"><?php echo mcr_icon('sparkles', 20); ?> <?php mcr_e('Nouveautés'); ?></a></li>
        <li><a href="<?php echo esc_url($account_url); ?>"><?php echo mcr_icon('user', 20); ?> <?php mcr_e('Mon compte'); ?></a></li>
      </ul>
      <p class="drawer__label"><?php mcr_e('Nos catégories'); ?></p>
      <ul class="drawer__list">
        <?php foreach (mcr_top_categories() as $c) : $kids = mcr_child_categories($c->term_id); ?>
          <li>
            <?php if ($kids) : ?>
              <details><summary><span class="drawer__ic"><?php echo mcr_icon(mcr_category_icon($c), 20); ?></span><span class="drawer__name"><?php echo esc_html($c->name); ?></span><?php echo mcr_icon('chevron-down', 18, 'drawer__chev'); ?></summary>
                <ul><li><a href="<?php echo esc_url(get_term_link($c)); ?>"><?php mcr_e('Tout voir'); ?></a></li>
                  <?php foreach ($kids as $k) : ?><li><a href="<?php echo esc_url(get_term_link($k)); ?>"><?php echo esc_html($k->name); ?></a></li><?php endforeach; ?>
                </ul>
              </details>
            <?php else : ?>
              <a href="<?php echo esc_url(get_term_link($c)); ?>"><span class="drawer__ic"><?php echo mcr_icon(mcr_category_icon($c), 20); ?></span><span class="drawer__name"><?php echo esc_html($c->name); ?></span></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="drawer__label"><?php mcr_e('Informations'); ?></p>
      <ul class="drawer__links">
        <?php foreach (['a-propos' => 'À propos', 'livraison' => 'Livraison', 'contact' => 'Contact'] as $slug => $label) : ?>
          <li><a href="<?php echo esc_url(home_url("/{$slug}/")); ?>"><?php mcr_e($label); ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo mcr_icon('phone', 16); ?> <?php echo esc_html(mcrt_store('phone')); ?></a></li>
      </ul>
      <p class="drawer__lang"><a href="<?php echo esc_url(mcr_switch_url('fr')); ?>" <?php echo $en ? '' : 'aria-current="true"'; ?>>Français</a><a href="<?php echo esc_url(mcr_switch_url('en')); ?>" <?php echo $en ? 'aria-current="true"' : ''; ?>>English</a></p>
    </div>
  </div>
</div>

<main id="main">
