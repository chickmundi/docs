<?php
defined('ABSPATH') || exit;
$ann = function_exists('mcr_setting') ? mcr_setting('announcement') : ['enabled' => false];
$delivery = function_exists('mcr_setting') ? mcr_setting('delivery') : ['start' => '09:00', 'end' => '18:00'];
$fmt = fn($t) => mcr_is_en() ? date('g a', strtotime($t)) : (int) substr($t, 0, 2) . ' h' . (substr($t, 3, 2) !== '00' ? ' ' . substr($t, 3, 2) : '');
$path = $_SERVER['REQUEST_URI'] ?? '/';
$food = ['petit-dejeuner', 'epices-et-condiments', 'surgeles', 'viandes', 'poissons-et-fruits-de-mer', 'fruits-et-legumes'];
$groups = [];
foreach (['epicerie', 'perruques', 'extensions-capillaires', 'soins-capillaires', 'soins-corporels'] as $slug) {
    $top = get_term_by('slug', $slug, 'product_cat');
    if (!$top) {
        continue;
    }
    $items = mcr_child_categories($top->term_id);
    if ($slug === 'epicerie') {
        foreach ($food as $f) {
            if ($t = get_term_by('slug', $f, 'product_cat')) {
                $items[] = $t;
            }
        }
    }
    $groups[] = [$top, $items];
}
$current_cat = is_product_category() ? get_queried_object() : null;
$in_group = function (WP_Term $top, array $items) use ($current_cat): bool {
    if (!$current_cat) {
        return false;
    }
    foreach (array_merge([$top], $items) as $t) {
        if ($t->term_id === $current_cat->term_id) {
            return true;
        }
    }
    return false;
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#4a32f0">
<script>document.documentElement.classList.add('js');</script>
<link rel="icon" type="image/png" href="<?php echo esc_url(MCRT_URL . '/assets/favicon.png'); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip" href="#main"><?php mcr_e('Aller au contenu'); ?></a>

<?php if (!empty($ann['enabled'])) : ?>
<div class="announce">
  <div class="container announce__inner"><?php echo mcr_icon('truck', 16); ?><p><?php echo esc_html(mcr_l($ann, 'text')); ?></p></div>
</div>
<?php endif; ?>

<div class="utility">
  <div class="container utility__inner">
    <ul class="utility__left">
      <li><?php echo mcr_icon('ria', 18); ?> <?php mcr_e('Transfert d’argent Ria'); ?></li>
      <li><?php echo mcr_icon('clock', 16); ?> <?php mcr_e('Nous vous livrons tous les jours de'); ?> <strong><?php echo esc_html($fmt($delivery['start']) . (mcr_is_en() ? ' to ' : ' à ') . $fmt($delivery['end'])); ?></strong></li>
    </ul>
    <ul class="utility__right">
      <?php foreach (['a-propos' => 'À propos', 'livraison' => 'Livraison', 'contact' => 'Contact'] as $slug => $label) : ?>
        <li><a href="<?php echo esc_url(home_url("/{$slug}/")); ?>"><?php mcr_e($label); ?></a></li>
      <?php endforeach; ?>
      <?php if ($wa = mcrt_whatsapp_link(mcr_is_en() ? 'Hello, I have a question:' : 'Bonjour, j’ai une question :')) : ?>
        <li><a href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('whatsapp', 15); ?> WhatsApp</a></li>
      <?php endif; ?>
      <li><a href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo mcr_icon('phone', 14); ?> <?php echo esc_html(mcrt_store('phone', '+1 819-372-9966')); ?></a></li>
      <li class="lang"><a href="<?php echo esc_url(mcr_switch_url('fr')); ?>" lang="fr" <?php echo mcr_is_en() ? '' : 'aria-current="true"'; ?>>FR</a><a href="<?php echo esc_url(mcr_switch_url('en')); ?>" lang="en" <?php echo mcr_is_en() ? 'aria-current="true"' : ''; ?>>EN</a></li>
    </ul>
  </div>
</div>

<header class="site-header">
  <div class="mainbar">
    <div class="container mainbar__inner">
      <a href="<?php echo esc_url(home_url('/')); ?>" class="logo" aria-label="<?php echo esc_attr(mcrt_store('name', 'Marché Chemin-du-Roy')); ?>">
        <img src="<?php echo esc_url(MCRT_URL . '/assets/MRC-logo-whit.png'); ?>" alt="<?php echo esc_attr(mcrt_store('name', 'Marché Chemin-du-Roy')); ?>" width="375" height="117">
      </a>
      <button class="cats-btn" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false"><?php echo mcr_icon('layout-grid', 18); ?><span><?php mcr_e('Catégories'); ?></span></button>
      <form class="search" action="<?php echo esc_url(home_url('/')); ?>" role="search">
        <label for="q" class="sr-only"><?php mcr_e('Rechercher'); ?></label>
        <input id="q" name="s" type="search" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php echo esc_attr(mcr_t('Rechercher : gari, perruque, plantain…')); ?>" autocomplete="off">
        <input type="hidden" name="post_type" value="product">
        <button aria-label="<?php echo esc_attr(mcr_t('Rechercher')); ?>"><?php echo mcr_icon('search', 20); ?></button>
      </form>
      <nav class="actions" aria-label="<?php echo esc_attr(mcr_t('Raccourcis')); ?>">
        <a href="<?php echo esc_url(mcrt_phone_href()); ?>" class="action action--contact"><?php echo mcr_icon('phone', 22); ?><span class="action__label"><?php mcr_e('Nous joindre'); ?></span></a>
        <a href="<?php echo esc_url(home_url('/ma-liste/')); ?>" class="action action--wish" aria-label="<?php echo esc_attr(mcr_t('Ma liste')); ?>"><span class="action__icon"><?php echo mcr_icon('heart', 22); ?><span class="count" data-wish-count hidden>0</span></span><span class="action__label"><?php mcr_e('Ma liste'); ?></span></a>
        <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="action" aria-label="<?php echo esc_attr(mcr_t('Panier')); ?>"><span class="action__icon"><?php echo mcr_icon('shopping-cart', 22); ?><span class="count" data-cart-count <?php echo mcr_cart_count() ? '' : 'hidden'; ?>><?php echo (int) mcr_cart_count(); ?></span></span><span class="action__label"><?php mcr_e('Panier'); ?></span></a>
      </nav>
    </div>
  </div>
  <nav class="menu" aria-label="<?php echo esc_attr(mcr_t('Menu principal')); ?>">
    <div class="container menu__inner">
      <ul class="menu__list">
        <li><a href="<?php echo esc_url(home_url('/')); ?>" <?php echo is_front_page() ? 'aria-current="page"' : ''; ?>><?php mcr_e('Accueil'); ?></a></li>
        <li><a href="<?php echo esc_url(mcr_shop_url()); ?>" <?php echo is_shop() ? 'aria-current="page"' : ''; ?>><?php mcr_e('Boutique'); ?></a></li>
        <?php foreach ($groups as [$top, $items]) : ?>
          <li class="<?php echo $items ? 'has-sub' : ''; ?>">
            <a href="<?php echo esc_url(get_term_link($top)); ?>" <?php echo $in_group($top, $items) ? 'aria-current="page"' : ''; ?>><?php echo esc_html($top->name); ?><?php echo $items ? mcr_icon('chevron-down', 16, 'menu__chev') : ''; ?></a>
            <?php if ($items) : ?>
              <div class="menu__drop <?php echo count($items) > 6 ? 'menu__drop--wide' : ''; ?>">
                <ul>
                  <?php foreach ($items as $c) : ?>
                    <li><a href="<?php echo esc_url(get_term_link($c)); ?>"><span class="menu__ic"><?php echo mcr_icon(mcr_category_icon($c), 18); ?></span><?php echo esc_html($c->name); ?></a></li>
                  <?php endforeach; ?>
                </ul>
                <a class="menu__all" href="<?php echo esc_url(get_term_link($top)); ?>"><?php mcr_e('Tout voir'); ?> : <?php echo esc_html($top->name); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <ul class="menu__list menu__list--end">
        <li><a href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>"><?php echo mcr_icon('badge-percent', 18); ?> <?php mcr_e('Promotions'); ?></a></li>
        <li><a class="menu__hot" href="<?php echo esc_url(add_query_arg('orderby', 'date', mcr_shop_url())); ?>"><?php echo mcr_icon('sparkles', 18); ?> <?php mcr_e('Nouveautés'); ?></a></li>
      </ul>
    </div>
  </nav>
</header>
<div class="kente" aria-hidden="true"></div>

<div class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr(mcr_t('Catégories')); ?>" hidden>
  <div class="drawer__backdrop" data-drawer-close></div>
  <div class="drawer__panel">
    <div class="drawer__head"><strong><?php mcr_e('Catégories'); ?></strong><button class="icon-btn" type="button" aria-label="<?php echo esc_attr(mcr_t('Fermer')); ?>" data-drawer-close><?php echo mcr_icon('x', 22); ?></button></div>
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
    <ul class="drawer__links">
      <li><a href="<?php echo esc_url(mcr_shop_url()); ?>"><?php echo mcr_icon('store', 18); ?> <?php mcr_e('Tous les produits'); ?></a></li>
      <li><a href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>"><?php echo mcr_icon('badge-percent', 18); ?> <?php mcr_e('Promotions'); ?></a></li>
      <li><a href="<?php echo esc_url(home_url('/livraison/')); ?>"><?php echo mcr_icon('truck', 18); ?> <?php mcr_e('Livraison'); ?></a></li>
      <li><a href="<?php echo esc_url(home_url('/contact/')); ?>"><?php echo mcr_icon('phone', 18); ?> <?php mcr_e('Contact'); ?></a></li>
      <li class="lang"><a href="<?php echo esc_url(mcr_switch_url('fr')); ?>">Français</a> · <a href="<?php echo esc_url(mcr_switch_url('en')); ?>">English</a></li>
    </ul>
  </div>
</div>

<main id="main">
