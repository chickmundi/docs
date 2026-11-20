<?php
defined('ABSPATH') || exit;
$free = function_exists('mcr_setting') ? mcr_setting('delivery', 'free_min_subtotal') : 99;
$is = fn(string $cond) => $cond;
?>
</main>

<footer class="footer wax-bg">
  <div class="kente" aria-hidden="true"></div>
  <div class="container footer__perks">
    <div><?php echo mcr_icon('truck', 26); ?><p><strong><?php mcr_e('Livraison 7 jours sur 7'); ?></strong><span><?php echo esc_html(mcr_is_en() ? sprintf('Free from %s', mcrt_money((float) $free)) : sprintf('Gratuite dès %s', mcrt_money((float) $free))); ?></span></p></div>
    <div><?php echo mcr_icon('store', 26); ?><p><strong><?php mcr_e('Retrait en magasin'); ?></strong><span><?php echo esc_html((mcr_is_en() ? 'Free, at ' : 'Gratuit, au ') . mcrt_store('street', '65 Rue Notre-Dame E')); ?></span></p></div>
    <div><?php echo mcr_icon('credit-card', 26); ?><p><strong><?php mcr_e('Paiement flexible'); ?></strong><span><?php mcr_e('En ligne, à la livraison ou en magasin'); ?></span></p></div>
    <div><?php echo mcr_icon('shield-check', 26); ?><p><strong><?php mcr_e('Qualité garantie'); ?></strong><span><?php mcr_e('Des produits choisis avec soin'); ?></span></p></div>
  </div>
  <div class="container footer__grid">
    <div>
      <img src="<?php echo esc_url(MCRT_URL . '/assets/MRC-logo-whit.png'); ?>" alt="<?php echo esc_attr(mcrt_store('name', 'Marché Chemin-du-Roy')); ?>" width="188" height="59" loading="lazy">
      <p class="footer__tag"><?php echo esc_html(mcr_is_en() ? 'Grocery, African flavours and hair beauty in Trois-Rivières.' : 'Épicerie, saveurs d’Afrique et beauté capillaire à Trois-Rivières.'); ?></p>
      <p class="footer__social">
        <a href="<?php echo esc_url(mcrt_store('facebook')); ?>" rel="noopener" target="_blank" aria-label="Facebook"><?php echo mcr_icon('facebook', 20); ?></a>
        <a href="<?php echo esc_url(mcrt_store('instagram')); ?>" rel="noopener" target="_blank" aria-label="Instagram"><?php echo mcr_icon('instagram', 20); ?></a>
      </p>
    </div>
    <div>
      <h3><?php mcr_e('Magasin'); ?></h3>
      <ul>
        <li><?php echo mcr_icon('map-pin', 16); ?><span><?php echo esc_html(mcrt_store('street')); ?><br><?php echo esc_html(mcrt_store('city') . ', ' . mcrt_store('region') . ' ' . mcrt_store('postal_code')); ?></span></li>
        <?php foreach (mcrt_hours_lines() as [$days, $time]) : ?>
          <li><?php echo mcr_icon('clock', 16); ?><span><?php echo esc_html("{$days} : {$time}"); ?></span></li>
        <?php endforeach; ?>
        <li><?php echo mcr_icon('navigation', 16); ?><a href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php mcr_e('Itinéraire'); ?></a></li>
      </ul>
    </div>
    <div>
      <h3><?php mcr_e('Nous joindre'); ?></h3>
      <ul>
        <li><?php echo mcr_icon('phone', 16); ?><a href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo esc_html(mcrt_store('phone')); ?></a></li>
        <li><?php echo mcr_icon('mail', 16); ?><a href="mailto:<?php echo esc_attr(mcrt_store('email')); ?>"><?php echo esc_html(mcrt_store('email')); ?></a></li>
        <?php if ($wa = mcrt_whatsapp_link(mcr_is_en() ? 'Hello, I have a question:' : 'Bonjour, j’ai une question :')) : ?>
          <li><?php echo mcr_icon('whatsapp', 16); ?><a href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener">WhatsApp : <?php echo esc_html(mcrt_store('whatsapp_display')); ?></a></li>
        <?php endif; ?>
        <li><?php echo mcr_icon('banknote', 16); ?><span><?php mcr_e('Transfert d’argent Ria'); ?></span></li>
      </ul>
    </div>
    <div>
      <h3><?php mcr_e('Informations'); ?></h3>
      <ul class="footer__links">
        <?php foreach (['a-propos' => 'À propos', 'contact' => 'Contact', 'livraison' => 'Livraison', 'conditions-generales' => 'Conditions générales', 'politique-de-remboursement-et-de-retour' => 'Retours et remboursements', 'politique-de-confidentialite' => 'Confidentialité'] as $slug => $label) : ?>
          <li><a href="<?php echo esc_url(home_url("/{$slug}/")); ?>"><?php mcr_e($label); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="container footer__bottom">
    <p>© <?php echo esc_html(gmdate('Y') . ' ' . mcrt_store('name', 'Marché Chemin-du-Roy')); ?>. <?php mcr_e('Tous droits réservés.'); ?></p>
    <p><?php mcr_e('Prix en dollars canadiens, taxes en sus s’il y a lieu.'); ?></p>
  </div>
</footer>

<?php if (!is_product() && !is_cart() && !is_checkout() && ($wa = mcrt_whatsapp_link(mcr_is_en() ? 'Hello Marché Chemin-du-Roy, I have a question:' : 'Bonjour Marché Chemin-du-Roy, j’ai une question :'))) : ?>
<a class="wa-fab" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr(mcr_t('Service client')); ?> WhatsApp">
  <span class="wa-fab__icon"><?php echo mcr_icon('whatsapp', 28); ?></span>
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
