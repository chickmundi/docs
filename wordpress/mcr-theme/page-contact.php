<?php
/**
 * Contact page (/contact/): ways to reach the store, live opening status, map, form if the page has one.
 * Phone, email, WhatsApp, address and hours come from MCR Gestion > Paramètres.
 */
defined('ABSPATH') || exit;
get_header();
$en = mcr_is_en();
$hours = function_exists('mcr_setting') ? (array) mcr_setting('hours') : [];
$wa = mcrt_whatsapp_link($en ? 'Hello Marché Chemin-du-Roy, I have a question:' : 'Bonjour Marché Chemin-du-Roy, j’ai une question :');
$address = mcrt_store('street') . ', ' . mcrt_store('city') . ', ' . mcrt_store('region') . ' ' . mcrt_store('postal_code');
$form = '';
while (have_posts()) {
    the_post();
    if (preg_match('/\[(contact-form-7|wpforms|formidable|gravityform|hostinger-reach)[^\]]*\]/', get_post_field('post_content', get_the_ID()), $m)) {
        $form = do_shortcode($m[0]);
    }
}
$places = [
    ['Cap-de-la-Madeleine', 46.3720, -72.5070, 'town'],
    [$en ? 'Downtown Trois-Rivières' : 'Centre-ville de Trois-Rivières', 46.3500, -72.5560, 'town'],
    ['Sanctuaire Notre-Dame-du-Cap', 46.3752, -72.5129, 'poi'],
    [$en ? 'Saint-Maurice River' : 'Rivière Saint-Maurice', 46.3850, -72.5520, 'river'],
    [$en ? 'St. Lawrence River' : 'Fleuve Saint-Laurent', 46.3360, -72.5050, 'river'],
    ['Laviolette', 46.3070, -72.5700, 'town'],
];
$days = $en ? [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] : [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
$fmt = function (string $t) use ($en): string {
    [$h, $m] = array_map('intval', explode(':', $t . ':0'));
    return $en ? date('g', mktime($h, $m)) . ($m ? date(':i', mktime($h, $m)) : '') . ' ' . date('a', mktime($h, $m)) : $h . ' h' . ($m ? sprintf(' %02d', $m) : '');
};
?>
<section class="phead phead--page has-pbg">
  <?php echo mcrt_photo_bg('shop-beauty', 'light', true); ?>
  <div class="container phead__in">
    <div class="phead__copy">
      <nav class="breadcrumbs" aria-label="<?php echo esc_attr(mcr_t('Fil d’Ariane')); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php mcr_e('Accueil'); ?></a> <?php echo mcr_icon('chevron-right', 14); ?> <span><?php the_title(); ?></span></nav>
      <h1><?php echo esc_html($en ? 'Contact us' : 'Nous joindre'); ?></h1>
      <p class="phead__intro"><?php echo esc_html($en ? 'A question about a product, an order or a delivery? Our team answers by phone, WhatsApp or email, and welcomes you in store 7 days a week.' : 'Une question sur un produit, une commande ou une livraison ? Notre équipe vous répond par téléphone, WhatsApp ou courriel, et vous accueille en magasin 7 jours sur 7.'); ?></p>
    </div>
  </div>
</section>

<div class="container ct">
  <ul class="ct-ways">
    <li data-reveal><a class="ct-way" href="<?php echo esc_url(mcrt_phone_href()); ?>"><span class="ct-way__ic"><?php echo mcr_icon('phone', 24); ?></span><span class="ct-way__label"><?php echo esc_html($en ? 'Call us' : 'Appelez-nous'); ?></span><strong><?php echo esc_html(mcrt_store('phone')); ?></strong><span class="ct-way__hint"><?php echo esc_html($en ? 'During store hours' : 'Pendant les heures d’ouverture'); ?></span></a></li>
    <?php if ($wa) : ?><li data-reveal style="--d:.05s"><a class="ct-way ct-way--wa" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><span class="ct-way__ic"><?php echo mcr_icon('whatsapp', 24); ?></span><span class="ct-way__label">WhatsApp</span><strong><?php echo esc_html(mcrt_store('whatsapp_display')); ?></strong><span class="ct-way__hint"><?php echo esc_html($en ? 'Questions and orders' : 'Questions et commandes'); ?></span></a></li><?php endif; ?>
    <li data-reveal style="--d:.1s"><a class="ct-way" href="mailto:<?php echo esc_attr(mcrt_store('email')); ?>"><span class="ct-way__ic"><?php echo mcr_icon('mail', 24); ?></span><span class="ct-way__label"><?php echo esc_html($en ? 'Email' : 'Courriel'); ?></span><strong><?php echo esc_html(mcrt_store('email')); ?></strong><span class="ct-way__hint"><?php echo esc_html($en ? 'We reply as soon as possible' : 'Nous répondons au plus vite'); ?></span></a></li>
    <li data-reveal style="--d:.15s"><a class="ct-way" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><span class="ct-way__ic"><?php echo mcr_icon('map-pin', 24); ?></span><span class="ct-way__label"><?php echo esc_html($en ? 'Visit us' : 'Venez nous voir'); ?></span><strong><?php echo esc_html(mcrt_store('street')); ?></strong><span class="ct-way__hint"><?php echo esc_html(mcrt_store('city') . ', ' . mcrt_store('region') . ' ' . mcrt_store('postal_code')); ?></span></a></li>
  </ul>

  <section class="ct-main">
    <figure class="ct-map" data-reveal>
      <?php echo mcrt_area_map(3.2, $places, 'ctmap'); // phpcs:ignore -- built and escaped by the helper ?>
      <figcaption>
        <span><?php echo mcr_icon('map-pin', 16); ?> <?php echo esc_html($address); ?></span>
        <a class="btn" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('navigation', 17); ?> <?php echo esc_html($en ? 'Get directions' : 'Obtenir l’itinéraire'); ?></a>
      </figcaption>
    </figure>

    <div class="ct-side">
      <div class="ct-hours" data-hours='<?php echo esc_attr(wp_json_encode($hours)); ?>' data-reveal>
        <div class="ct-hours__head">
          <h2><?php echo esc_html($en ? 'Store hours' : 'Heures d’ouverture'); ?></h2>
          <span class="ct-status" data-status hidden></span>
        </div>
        <ul>
          <?php foreach ($days as $n => $label) : $h = $hours[$n] ?? null; ?>
            <li data-day="<?php echo (int) $n; ?>"><span><?php echo esc_html($label); ?></span><strong><?php echo $h && empty($h['closed']) ? esc_html($fmt($h['open']) . ' – ' . $fmt($h['close'])) : esc_html($en ? 'Closed' : 'Fermé'); ?></strong></li>
          <?php endforeach; ?>
        </ul>
        <p class="ct-hours__note"><?php echo mcr_icon('truck', 16); ?> <?php echo esc_html(($en ? 'Deliveries: every day, ' : 'Livraisons : tous les jours, ') . (function_exists('mcr_setting') ? $fmt((string) mcr_setting('delivery', 'start')) . ' – ' . $fmt((string) mcr_setting('delivery', 'end')) : '')); ?></p>
      </div>

      <?php if ($form) : ?>
        <div class="ct-form" data-reveal><h2><?php echo esc_html($en ? 'Write to us' : 'Écrivez-nous'); ?></h2><?php echo $form; // phpcs:ignore -- output of the store's form plugin ?></div>
      <?php else : ?>
        <div class="ct-form ct-form--wa" data-reveal>
          <h2><?php echo esc_html($en ? 'The fastest way to reach us' : 'Le plus rapide pour nous joindre'); ?></h2>
          <p><?php echo esc_html($en ? 'Send us a WhatsApp message: a photo of the product you are looking for is all we need. We answer during store hours.' : 'Envoyez-nous un message WhatsApp : une photo du produit que vous cherchez suffit. Nous répondons pendant les heures d’ouverture.'); ?></p>
          <?php if ($wa) : ?><a class="btn btn--wa btn--lg" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('whatsapp', 20); ?> <?php echo esc_html($en ? 'Write on WhatsApp' : 'Écrire sur WhatsApp'); ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="ct-help" aria-labelledby="h-help">
    <?php mcrt_section_head($en ? 'Quick answers' : 'Réponses rapides', $en ? 'Maybe your answer is here' : 'Votre réponse est peut-être ici', '', '', 'h-help'); ?>
    <ul class="ct-links">
      <li data-reveal><a href="<?php echo esc_url(home_url('/livraison/')); ?>"><?php echo mcr_icon('truck', 22); ?><span><strong><?php echo esc_html($en ? 'Delivery prices' : 'Tarifs de livraison'); ?></strong><small><?php echo esc_html($en ? 'Zones, prices, free delivery' : 'Zones, prix, livraison gratuite'); ?></small></span></a></li>
      <li data-reveal style="--d:.05s"><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php echo mcr_icon('package', 22); ?><span><strong><?php echo esc_html($en ? 'Track my order' : 'Suivre ma commande'); ?></strong><small><?php echo esc_html($en ? 'In your customer account' : 'Dans votre compte client'); ?></small></span></a></li>
      <li data-reveal style="--d:.1s"><a href="<?php echo esc_url(home_url('/politique-de-remboursement-et-de-retour/')); ?>"><?php echo mcr_icon('shield-check', 22); ?><span><strong><?php echo esc_html($en ? 'Returns and refunds' : 'Retours et remboursements'); ?></strong><small><?php echo esc_html($en ? 'Within 24 hours of delivery' : 'Dans les 24 heures suivant la réception'); ?></small></span></a></li>
      <li data-reveal style="--d:.15s"><a href="<?php echo esc_url(home_url('/a-propos/')); ?>"><?php echo mcr_icon('store', 22); ?><span><strong><?php echo esc_html($en ? 'About the store' : 'À propos du magasin'); ?></strong><small><?php echo esc_html($en ? 'Our story and products' : 'Notre histoire et nos produits'); ?></small></span></a></li>
    </ul>
  </section>
</div>

<script>
(function () {
  var box = document.querySelector('[data-hours]');
  if (!box || !window.Intl) return;
  var en = <?php echo $en ? 'true' : 'false'; ?>;
  var hours = JSON.parse(box.getAttribute('data-hours') || '{}');
  var parts = new Intl.DateTimeFormat('en-CA', { timeZone: <?php $tz = wp_timezone_string(); echo wp_json_encode($tz && strpos($tz, '/') !== false ? $tz : 'America/Toronto'); ?>, weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date());
  var get = function (t) { return (parts.filter(function (p) { return p.type === t; })[0] || {}).value; };
  var day = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 }[get('weekday')];
  var now = parseInt(get('hour'), 10) % 24 * 60 + parseInt(get('minute'), 10);
  var h = hours[day];
  var mins = function (t) { var a = String(t).split(':'); return a[0] * 60 + +a[1]; };
  var li = box.querySelector('[data-day="' + day + '"]');
  if (li) li.classList.add('is-today');
  var open = h && !h.closed && now >= mins(h.open) && now < mins(h.close);
  var el = box.querySelector('[data-status]');
  el.hidden = false;
  el.className = 'ct-status ' + (open ? 'is-open' : 'is-closed');
  el.textContent = open ? (en ? 'Open now' : 'Ouvert maintenant') : (en ? 'Closed now' : 'Fermé pour le moment');
})();
</script>
<?php
get_footer();
