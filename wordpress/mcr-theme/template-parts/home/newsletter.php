<?php
/**
 * Newsletter: shows the store's own sign-up form when its shortcode is set in the settings
 * (Hostinger Reach, Contact Form 7…). Without one, it invites customers to get the
 * deals on WhatsApp, which the store really uses. No fake subscription form.
 */
defined('ABSPATH') || exit;
$home = $args['home'] ?? [];
$en = mcr_is_en();
$code = trim((string) ($home['newsletter_shortcode'] ?? ''));
$form = '';
if ($code !== '' && preg_match('/^\[([\w-]+)/', $code, $m) && shortcode_exists($m[1])) {
    $form = do_shortcode($code);
}
$wa = mcrt_whatsapp_link($en ? 'Hello! I would like to receive your deals and new arrivals on WhatsApp.' : 'Bonjour ! J’aimerais recevoir vos promotions et nouveautés sur WhatsApp.');
if ($form === '' && !$wa) {
    return;
}
?>
<section class="section section--tight" aria-labelledby="h-news">
  <div class="container">
    <div class="news" data-reveal>
      <div class="news__copy">
        <span class="news__ic"><?php echo mcr_icon($form ? 'mail' : 'whatsapp', 26); ?></span>
        <div>
          <h2 id="h-news"><?php echo esc_html($en ? 'Never miss a deal' : 'Ne manquez aucune promotion'); ?></h2>
          <p><?php echo esc_html($form
              ? ($en ? 'Deals, new arrivals and seasonal products, a few times a month.' : 'Promotions, nouveautés et produits de saison, quelques fois par mois.')
              : ($en ? 'Get our weekly deals and new arrivals on WhatsApp. One message to the store is all it takes.' : 'Recevez nos promotions de la semaine et nos arrivages sur WhatsApp. Un simple message au magasin suffit.')); ?></p>
        </div>
      </div>
      <div class="news__action">
        <?php if ($form) : ?>
          <div class="news__form"><?php echo $form; // phpcs:ignore -- output of the store's own form plugin. ?></div>
        <?php else : ?>
          <a class="btn btn--wa btn--lg" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('whatsapp', 20); ?> <?php echo esc_html($en ? 'Get the deals on WhatsApp' : 'Recevoir les promos sur WhatsApp'); ?></a>
        <?php endif; ?>
        <p class="news__note"><?php echo mcr_icon('lock', 14); ?> <?php echo esc_html($en ? 'No spam. You can stop at any time.' : 'Pas de pourriel. Vous pouvez arrêter à tout moment.'); ?></p>
      </div>
    </div>
  </div>
</section>
