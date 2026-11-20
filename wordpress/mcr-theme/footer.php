<?php
defined('ABSPATH') || exit;
$en = mcr_is_en();
$free = function_exists('mcr_setting') ? (float) mcr_setting('delivery', 'free_min_subtotal') : 99;
$shop_cats = array_slice(array_values(array_filter(mcr_top_categories(), fn($c) => !in_array($c->slug, ['autres', 'tout'], true))), 0, 8);
?>
</main>

<footer class="footer">
  <?php if (!is_front_page() && !is_cart() && !is_checkout()) : ?>
  <div class="footer__perks">
    <div class="container footer__perks-in">
      <p><?php echo mcr_icon('truck', 20); ?><span><strong><?php mcr_e('Livraison 7 jours sur 7'); ?></strong> <?php echo esc_html($en ? sprintf('free from %s', mcrt_money($free)) : sprintf('gratuite dès %s', mcrt_money($free))); ?></span></p>
      <p><?php echo mcr_icon('store', 20); ?><span><strong><?php echo esc_html($en ? 'In-store pickup' : 'Cueillette en magasin'); ?></strong> <?php echo esc_html($en ? 'free' : 'gratuite'); ?></span></p>
      <p><?php echo mcr_icon('leaf', 20); ?><span><strong><?php echo esc_html($en ? 'Authentic products' : 'Produits authentiques'); ?></strong> <?php echo esc_html($en ? 'from Africa and Quebec' : 'd’Afrique et d’ici'); ?></span></p>
      <p><?php echo mcr_icon('whatsapp', 20); ?><span><strong><?php echo esc_html($en ? 'Questions?' : 'Une question ?'); ?></strong> <?php echo esc_html(mcrt_store('phone')); ?></span></p>
    </div>
  </div>
  <?php endif; ?>
  <div class="container footer__grid">
    <div class="footer__brand">
      <img src="<?php echo esc_url(MCRT_URL . '/assets/MRC-logo-whit.png'); ?>" alt="<?php echo esc_attr(mcrt_store('name', 'Marché Chemin-du-Roy')); ?>" width="188" height="59" loading="lazy">
      <p><?php echo esc_html($en ? 'African and local grocery, frozen foods, wigs and hair care in Trois-Rivières. The flavours that bring us together.' : 'Épicerie africaine et d’ici, surgelés, perruques et soins capillaires à Trois-Rivières. Les saveurs qui nous rapprochent.'); ?></p>
      <p class="footer__social">
        <?php if (mcrt_store('facebook')) : ?><a href="<?php echo esc_url(mcrt_store('facebook')); ?>" rel="noopener" target="_blank" aria-label="Facebook"><?php echo mcr_icon('facebook', 19); ?></a><?php endif; ?>
        <?php if (mcrt_store('instagram')) : ?><a href="<?php echo esc_url(mcrt_store('instagram')); ?>" rel="noopener" target="_blank" aria-label="Instagram"><?php echo mcr_icon('instagram', 19); ?></a><?php endif; ?>
        <?php if ($wa = mcrt_whatsapp_link($en ? 'Hello, I have a question:' : 'Bonjour, j’ai une question :')) : ?><a href="<?php echo esc_url($wa); ?>" rel="noopener" target="_blank" aria-label="WhatsApp"><?php echo mcr_icon('whatsapp', 19); ?></a><?php endif; ?>
      </p>
    </div>
    <div>
      <h3><?php echo esc_html($en ? 'Shop' : 'Magasiner'); ?></h3>
      <ul class="footer__links">
        <?php foreach ($shop_cats as $c) : ?><li><a href="<?php echo esc_url(get_term_link($c)); ?>"><?php echo esc_html($c->name); ?></a></li><?php endforeach; ?>
        <li><a href="<?php echo esc_url(add_query_arg('promotions', '1', mcr_shop_url())); ?>"><?php mcr_e('Promotions'); ?></a></li>
      </ul>
    </div>
    <div>
      <h3><?php mcr_e('Informations'); ?></h3>
      <ul class="footer__links">
        <?php foreach (['a-propos' => 'À propos', 'livraison' => 'Livraison', 'contact' => 'Contact', 'conditions-generales' => 'Conditions générales', 'politique-de-remboursement-et-de-retour' => 'Retours et remboursements', 'politique-de-confidentialite' => 'Confidentialité'] as $slug => $label) : ?>
          <li><a href="<?php echo esc_url(home_url("/{$slug}/")); ?>"><?php mcr_e($label); ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php mcr_e('Mon compte'); ?></a></li>
      </ul>
    </div>
    <div>
      <h3><?php mcr_e('Magasin'); ?></h3>
      <ul class="footer__info">
        <li><?php echo mcr_icon('map-pin', 17); ?><a href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(mcrt_store('street')); ?><br><?php echo esc_html(mcrt_store('city') . ', ' . mcrt_store('region') . ' ' . mcrt_store('postal_code')); ?></a></li>
        <?php foreach (mcrt_hours_lines() as [$days, $time]) : ?>
          <li><?php echo mcr_icon('clock', 17); ?><span><?php echo esc_html("{$days} : {$time}"); ?></span></li>
        <?php endforeach; ?>
        <li><?php echo mcr_icon('phone', 17); ?><a href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo esc_html(mcrt_store('phone')); ?></a></li>
        <li><?php echo mcr_icon('mail', 17); ?><a href="mailto:<?php echo esc_attr(mcrt_store('email')); ?>"><?php echo esc_html(mcrt_store('email')); ?></a></li>
      </ul>
    </div>
  </div>
  <div class="container footer__bottom">
    <p>© <?php echo esc_html(gmdate('Y') . ' ' . mcrt_store('name', 'Marché Chemin-du-Roy')); ?>. <?php mcr_e('Tous droits réservés.'); ?></p>
    <p><?php mcr_e('Prix en dollars canadiens, taxes en sus s’il y a lieu.'); ?></p>
    <p class="footer__lang"><a href="<?php echo esc_url(mcr_switch_url('fr')); ?>" <?php echo $en ? '' : 'aria-current="true"'; ?>>Français</a><a href="<?php echo esc_url(mcr_switch_url('en')); ?>" <?php echo $en ? 'aria-current="true"' : ''; ?>>English</a></p>
  </div>
</footer>

<?php if (!is_product() && !is_cart() && !is_checkout() && ($wa = mcrt_whatsapp_link($en ? 'Hello Marché Chemin-du-Roy, I have a question:' : 'Bonjour Marché Chemin-du-Roy, j’ai une question :'))) : ?>
<a class="wa-fab" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr(mcr_t('Service client')); ?> WhatsApp">
  <span class="wa-fab__icon"><?php echo mcr_icon('whatsapp', 26); ?></span>
  <span class="wa-fab__text"><strong><?php mcr_e('Service client'); ?></strong><small><?php mcr_e('Écrivez-nous sur WhatsApp'); ?></small></span>
</a>
<?php endif; ?>

<nav class="tabbar" aria-label="<?php echo esc_attr(mcr_t('Navigation mobile')); ?>">
  <a href="<?php echo esc_url(home_url('/')); ?>" <?php echo is_front_page() ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon('house', 22); ?><span><?php mcr_e('Accueil'); ?></span></a>
  <button type="button" data-drawer-open aria-controls="drawer"><?php echo mcr_icon('layout-grid', 22); ?><span><?php mcr_e('Catégories'); ?></span></button>
  <a href="<?php echo esc_url(mcr_shop_url()); ?>" data-search-jump <?php echo is_search() ? 'aria-current="page"' : ''; ?>><?php echo mcr_icon('search', 22); ?><span><?php mcr_e('Recherche'); ?></span></a>
  <a href="<?php echo esc_url(home_url('/ma-liste/')); ?>"><span class="tabbar__icon"><?php echo mcr_icon('heart', 22); ?><span class="count" data-wish-count hidden>0</span></span><span><?php mcr_e('Ma liste'); ?></span></a>
  <a href="<?php echo esc_url(wc_get_cart_url()); ?>" <?php echo is_cart() ? 'aria-current="page"' : ''; ?>><span class="tabbar__icon"><?php echo mcr_icon('shopping-cart', 22); ?><span class="count" data-cart-count <?php echo mcr_cart_count() ? '' : 'hidden'; ?>><?php echo (int) mcr_cart_count(); ?></span></span><span><?php mcr_e('Panier'); ?></span></a>
</nav>

<?php wp_footer(); ?>
</body>
</html>
