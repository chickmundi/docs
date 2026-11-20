<?php
/** Shopping benefits: only what the store really offers (values from the settings). */
defined('ABSPATH') || exit;
$en = mcr_is_en();
$d = function_exists('mcr_setting') ? mcr_setting('delivery') : [];
$free = (float) ($d['free_min_subtotal'] ?? 99);
$km = (float) ($d['max_km'] ?? 15);
$pickup_min = function_exists('mcr_setting') ? (int) mcr_setting('pickup', 'prep_minutes') : 45;
$items = [
    ['truck', $en ? 'Local delivery' : 'Livraison locale', $en ? sprintf('7 days a week within %s km, free from %s.', $km, mcrt_money($free)) : sprintf('7 jours sur 7 jusqu’à %s km, gratuite dès %s.', $km, mcrt_money($free))],
    ['store', $en ? 'In-store pickup' : 'Cueillette en magasin', $en ? sprintf('Free, ready in about %d minutes.', $pickup_min) : sprintf('Gratuite, prête en environ %d minutes.', $pickup_min)],
    ['leaf', $en ? 'Authentic products' : 'Produits authentiques', $en ? 'African and local brands chosen with care.' : 'Des marques d’Afrique et d’ici, choisies avec soin.'],
    ['whatsapp', $en ? 'Friendly service' : 'Service attentionné', $en ? 'A question? Call us or write on WhatsApp.' : 'Une question ? Appelez-nous ou écrivez sur WhatsApp.'],
    ['banknote', $en ? 'Ria money transfer' : 'Transfert d’argent Ria', $en ? 'Send money from the store.' : 'Envoyez de l’argent depuis le magasin.'],
];
?>
<section class="section section--tight" aria-label="<?php echo esc_attr($en ? 'Why shop with us' : 'Pourquoi magasiner chez nous'); ?>">
  <div class="container">
    <ul class="trust">
      <?php foreach ($items as $i => [$icon, $title, $text]) : ?>
        <li data-reveal style="--d:<?php echo esc_attr($i * 0.05); ?>s"><span class="trust__ic"><?php echo mcr_icon($icon, 22); ?></span><p><strong><?php echo esc_html($title); ?></strong><span><?php echo esc_html($text); ?></span></p></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
