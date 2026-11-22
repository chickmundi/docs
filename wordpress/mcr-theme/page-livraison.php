<?php
/**
 * Delivery page (/livraison/): zone map, prices, free delivery, address check, pickup.
 * Every number comes from MCR Gestion > Paramètres > Livraison / Retrait.
 */
defined('ABSPATH') || exit;
get_header();

$en = mcr_is_en();
$d = function_exists('mcr_setting') ? mcr_setting('delivery') : [];
$pk = function_exists('mcr_setting') ? mcr_setting('pickup') : [];
$tiers = array_values(array_filter((array) ($d['tiers'] ?? []), fn($t) => isset($t['to_km'], $t['fee'])));
usort($tiers, fn($a, $b) => $a['from_km'] <=> $b['from_km']);
$max_km = (float) ($d['max_km'] ?? 15);
$free_min = (float) ($d['free_min_subtotal'] ?? 99);
$free_km = (float) ($d['free_radius_km'] ?? $max_km);
$clock = function (string $t) use ($en): string {
    [$h, $m] = array_map('intval', explode(':', $t . ':0'));
    return $en ? date('g', mktime($h, $m)) . ($m ? date(':i', mktime($h, $m)) : '') . ' ' . date('a', mktime($h, $m)) : ($m ? sprintf('%d h %02d', $h, $m) : sprintf('%d h', $h));
};
$days = (array) ($d['days'] ?? []);
$every_day = count($days) >= 7;
$km = fn(float $v) => $en ? rtrim(rtrim(number_format($v, 1), '0'), '.') : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
$colors = ['#2d7a57', '#5a9a5e', '#a3b95a', '#e6b74f', '#c96b43', '#9e4b2b', '#7a3a22'];

// ---- Map illustration: 1 km = $s px around the store, real places placed from their coordinates.
$W = 640; $C = 320; $R = 270; $s = $R / max($max_km, 1);
$lat0 = (float) mcrt_store('lat', 46.3629863);
$lon0 = (float) mcrt_store('lon', -72.5258898);
$pt = function (float $lat, float $lon) use ($lat0, $lon0, $s, $C): array {
    return [$C + ($lon - $lon0) * 76.7 * $s, $C - ($lat - $lat0) * 111.2 * $s];
};
$places = [
    ['Cap-de-la-Madeleine', 46.3755, -72.5010],
    ['Trois-Rivières-Ouest', 46.3360, -72.6000],
    ['Saint-Louis-de-France', 46.4300, -72.5500],
    ['Sainte-Marthe-du-Cap', 46.4040, -72.4400],
    ['Pointe-du-Lac', 46.2900, -72.6900],
    ['Bécancour', 46.3300, -72.4300],
    ['Champlain', 46.4500, -72.3500],
];
// The St. Lawrence (south of the city, flowing north-east) and the Saint-Maurice (from the north).
$river = [[46.239, -72.760], [46.290, -72.660], [46.326, -72.580], [46.341, -72.535], [46.360, -72.470], [46.400, -72.400], [46.455, -72.320], [46.500, -72.250]];
$maurice = [[46.520, -72.585], [46.470, -72.575], [46.420, -72.560], [46.385, -72.548], [46.360, -72.540], [46.345, -72.533]];
$path = function (array $pts) use ($pt): string {
    $out = '';
    foreach ($pts as $i => [$la, $lo]) {
        [$x, $y] = $pt($la, $lo);
        $out .= ($i ? ' L' : 'M') . round($x, 1) . ' ' . round($y, 1);
    }
    return $out;
};
$ajax = admin_url('admin-ajax.php');
?>
<section class="phead phead--page dl-head has-pbg">
  <?php echo mcrt_photo_bg('shop-staples', 'light', true); ?>
  <div class="container phead__in">
    <div class="phead__copy">
      <nav class="breadcrumbs" aria-label="<?php echo esc_attr(mcr_t('Fil d’Ariane')); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php mcr_e('Accueil'); ?></a> <?php echo mcr_icon('chevron-right', 14); ?> <span><?php the_title(); ?></span></nav>
      <h1><?php echo esc_html($en ? 'Delivery and pickup' : 'Livraison et cueillette'); ?></h1>
      <p class="phead__intro"><?php echo esc_html($en
          ? sprintf('We deliver within %s km of the store, %s from %s to %s. The fee depends on the driving distance, and delivery is free from %s.', $km($max_km), $every_day ? '7 days a week' : 'on delivery days', $clock($d['start'] ?? '09:00'), $clock($d['end'] ?? '18:00'), mcrt_money($free_min))
          : sprintf('Nous livrons jusqu’à %s km du magasin, %s de %s à %s. Le prix dépend de la distance en voiture, et la livraison est gratuite dès %s d’achat.', $km($max_km), $every_day ? '7 jours sur 7' : 'les jours de livraison', $clock($d['start'] ?? '09:00'), $clock($d['end'] ?? '18:00'), mcrt_money($free_min))); ?></p>
    </div>
  </div>
</section>

<div class="container dl">
  <!-- Free delivery banner -->
  <div class="dl-free" data-reveal>
    <span class="dl-free__ic"><?php echo mcr_icon('truck', 28); ?></span>
    <p><strong><?php echo esc_html($en ? sprintf('Free delivery from %s', mcrt_money($free_min)) : sprintf('Livraison gratuite dès %s', mcrt_money($free_min))); ?></strong>
      <span><?php echo esc_html($en ? sprintf('For every address within %s km of the store, whatever the zone.', $km($free_km)) : sprintf('Pour toutes les adresses à %s km ou moins du magasin, peu importe la zone.', $km($free_km))); ?></span></p>
    <a class="btn btn--gold" href="<?php echo esc_url(mcr_shop_url()); ?>"><?php echo esc_html($en ? 'Start shopping' : 'Faire mes achats'); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
  </div>

  <!-- Map + prices -->
  <section class="dl-zones" aria-labelledby="h-zones">
    <figure class="dl-map" data-reveal>
      <svg viewBox="0 0 <?php echo (int) $W; ?> <?php echo (int) $W; ?>" role="img" aria-labelledby="map-title map-desc">
        <title id="map-title"><?php echo esc_html($en ? 'Delivery zones around the store' : 'Zones de livraison autour du magasin'); ?></title>
        <desc id="map-desc"><?php echo esc_html(implode('. ', array_map(fn($t) => sprintf($en ? 'From %s to %s km: %s' : 'De %s à %s km : %s', $km((float) $t['from_km']), $km((float) $t['to_km']), mcrt_money((float) $t['fee'])), $tiers))); ?></desc>
        <defs>
          <pattern id="hatch" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="10" height="10" fill="#efe8da"/><line x1="0" y1="0" x2="0" y2="10" stroke="#e0d6c1" stroke-width="4"/></pattern>
          <clipPath id="disc"><rect x="0" y="0" width="<?php echo (int) $W; ?>" height="<?php echo (int) $W; ?>" rx="28"/></clipPath>
          <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="3" stdDeviation="3" flood-opacity=".25"/></filter>
        </defs>
        <g clip-path="url(#disc)">
          <rect width="<?php echo (int) $W; ?>" height="<?php echo (int) $W; ?>" fill="url(#hatch)"/>
          <?php foreach (array_reverse($tiers, true) as $i => $t) : $r = (float) $t['to_km'] * $s; ?>
            <circle class="dl-ring" data-tier="<?php echo (int) $i; ?>" cx="<?php echo $C; ?>" cy="<?php echo $C; ?>" r="<?php echo esc_attr(round($r, 1)); ?>" fill="<?php echo esc_attr($colors[$i % 7]); ?>" fill-opacity=".34" stroke="<?php echo esc_attr($colors[$i % 7]); ?>" stroke-width="2"/>
          <?php endforeach; ?>
          <path d="<?php echo esc_attr($path($river)); ?>" fill="none" stroke="#9cc6dd" stroke-width="<?php echo esc_attr(round(1.6 * $s, 1)); ?>" stroke-linecap="round" stroke-linejoin="round" opacity=".95"/>
          <path d="<?php echo esc_attr($path($maurice)); ?>" fill="none" stroke="#9cc6dd" stroke-width="<?php echo esc_attr(round(.5 * $s, 1)); ?>" stroke-linecap="round" stroke-linejoin="round" opacity=".95"/>
          <?php [$rx, $ry] = $pt(46.300, -72.640); ?>
          <text class="dl-map__river" x="<?php echo round($rx); ?>" y="<?php echo round($ry + 26); ?>" transform="rotate(-32 <?php echo round($rx); ?> <?php echo round($ry + 26); ?>)"><?php echo esc_html($en ? 'St. Lawrence River' : 'Fleuve Saint-Laurent'); ?></text>
          <?php foreach ($places as [$name, $la, $lo]) : [$x, $y] = $pt($la, $lo); if ($x < 20 || $x > $W - 20 || $y < 20 || $y > $W - 20) { continue; } ?>
            <g class="dl-map__place"><circle cx="<?php echo round($x); ?>" cy="<?php echo round($y); ?>" r="3.5"/><text x="<?php echo round($x + 7); ?>" y="<?php echo round($y + 4); ?>"><?php echo esc_html($name); ?></text></g>
          <?php endforeach; ?>
          <?php foreach ($tiers as $i => $t) : $mid = ((float) $t['from_km'] + (float) $t['to_km']) / 2 * $s; $a = deg2rad(90); $lx = $C + cos($a) * max($mid, 46); $ly = $C + sin($a) * max($mid, 46); ?>
            <g class="dl-map__tag" filter="url(#shadow)"><rect x="<?php echo round($lx - 34); ?>" y="<?php echo round($ly - 13); ?>" width="68" height="24" rx="12" fill="#fff"/><text x="<?php echo round($lx); ?>" y="<?php echo round($ly + 4); ?>" text-anchor="middle" fill="<?php echo esc_attr($colors[$i % 7]); ?>"><?php echo esc_html(mcrt_money((float) $t['fee'])); ?></text></g>
          <?php endforeach; ?>
          <g class="dl-map__store" filter="url(#shadow)">
            <circle cx="<?php echo $C; ?>" cy="<?php echo $C; ?>" r="22" fill="#174b35"/>
            <path d="M<?php echo $C - 9; ?> <?php echo $C - 2; ?> l3 -7 h12 l3 7 v9 h-18z M<?php echo $C - 3; ?> <?php echo $C + 7; ?> v-5 h6 v5" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round"/>
          </g>
          <text class="dl-map__storelabel" x="<?php echo $C; ?>" y="<?php echo $C - 32; ?>" text-anchor="middle"><?php echo esc_html(mcrt_store('name', 'Marché Chemin-du-Roy')); ?></text>
          <text class="dl-map__edge" x="<?php echo $C; ?>" y="<?php echo round($C + $R + 22); ?>" text-anchor="middle"><?php echo esc_html(($en ? 'Beyond ' : 'Au-delà de ') . $km($max_km) . ' km : ' . ($en ? 'in-store pickup' : 'cueillette en magasin')); ?></text>
        </g>
      </svg>
      <figcaption><?php echo esc_html($en ? 'Simplified illustration. Rings show the distance from the store; the price uses the actual driving distance.' : 'Illustration simplifiée. Les cercles montrent la distance du magasin; le prix se calcule selon la distance réelle en voiture.'); ?></figcaption>
    </figure>

    <div class="dl-prices">
      <p class="eyebrow"><?php echo esc_html($en ? 'Delivery prices' : 'Tarifs de livraison'); ?></p>
      <h2 id="h-zones"><?php echo esc_html($en ? 'One price per zone' : 'Un prix par zone'); ?></h2>
      <ol class="dl-tiers">
        <?php foreach ($tiers as $i => $t) : $last = $i === count($tiers) - 1; ?>
          <li data-tier="<?php echo (int) $i; ?>" style="--c:<?php echo esc_attr($colors[$i % 7]); ?>">
            <span class="dl-tiers__dot" aria-hidden="true"></span>
            <span class="dl-tiers__km"><?php echo esc_html($i === 0
                ? sprintf($en ? 'Under %s km' : 'Moins de %s km', $km((float) $t['to_km']))
                : ($last
                    ? sprintf($en ? '%s to %s km' : 'De %s à %s km', $km((float) $t['from_km']), $km((float) $t['to_km']))
                    : sprintf($en ? '%s to under %s km' : 'De %s à moins de %s km', $km((float) $t['from_km']), $km((float) $t['to_km'])))); ?></span>
            <strong class="dl-tiers__fee"><?php echo esc_html(mcrt_money((float) $t['fee'])); ?></strong>
          </li>
        <?php endforeach; ?>
        <li class="dl-tiers__out">
          <span class="dl-tiers__dot" aria-hidden="true"></span>
          <span class="dl-tiers__km"><?php echo esc_html(sprintf($en ? 'Over %s km' : 'Plus de %s km', $km($max_km))); ?></span>
          <strong class="dl-tiers__fee"><?php echo esc_html($en ? 'Pickup only' : 'Cueillette seulement'); ?></strong>
        </li>
      </ol>
      <p class="dl-free-note"><?php echo mcr_icon('circle-check', 18); ?> <?php echo esc_html($en ? sprintf('Orders of %s or more: free in every zone.', mcrt_money($free_min)) : sprintf('Commande de %s ou plus : gratuite dans toutes les zones.', mcrt_money($free_min))); ?></p>

      <form class="dl-check" data-dl-check action="<?php echo esc_url($ajax); ?>">
        <label for="dl-address"><strong><?php echo esc_html($en ? 'What is my delivery price?' : 'Combien coûte ma livraison ?'); ?></strong></label>
        <div class="dl-check__row">
          <input id="dl-address" type="text" name="address" autocomplete="street-address" placeholder="<?php echo esc_attr($en ? 'Your address, e.g. 1500 boul. des Récollets' : 'Votre adresse, ex. 1500 boul. des Récollets'); ?>" required>
          <button class="btn" type="submit"><?php echo esc_html($en ? 'Check' : 'Vérifier'); ?></button>
        </div>
        <p class="dl-check__out" data-dl-out aria-live="polite"></p>
      </form>
    </div>
  </section>

  <!-- How it works -->
  <section class="dl-section" aria-labelledby="h-how">
    <?php mcrt_section_head($en ? 'Simple and fast' : 'Simple et rapide', $en ? 'How delivery works' : 'Comment se passe la livraison', '', '', 'h-how'); ?>
    <ol class="dl-steps">
      <li data-reveal><span class="dl-steps__n">1</span><?php echo mcr_icon('shopping-cart', 24); ?><strong><?php echo esc_html($en ? 'Order online' : 'Commandez en ligne'); ?></strong><span><?php echo esc_html($en ? 'Choose “Delivery” at checkout. The price appears as soon as your address is entered.' : 'Choisissez « Livraison » à la caisse. Le prix s’affiche dès que votre adresse est entrée.'); ?></span></li>
      <li data-reveal style="--d:.06s"><span class="dl-steps__n">2</span><?php echo mcr_icon('package', 24); ?><strong><?php echo esc_html($en ? 'We prepare your order' : 'Nous préparons votre commande'); ?></strong><span><?php echo esc_html(mcr_l($d, 'eta') ?: ($en ? 'Same-day delivery when possible.' : 'Livraison le jour même lorsque possible.')); ?></span></li>
      <li data-reveal style="--d:.12s"><span class="dl-steps__n">3</span><?php echo mcr_icon('phone', 24); ?><strong><?php echo esc_html($en ? 'We call, then deliver' : 'On vous appelle, puis on livre'); ?></strong><span><?php echo esc_html(mcr_l($d, 'instructions') ?: ($en ? 'We call before delivering.' : 'Nous vous appelons avant la livraison.')); ?></span></li>
    </ol>
  </section>

  <!-- Hours + pickup -->
  <section class="dl-cards">
    <div class="dl-card" data-reveal>
      <span class="dl-card__ic"><?php echo mcr_icon('clock', 22); ?></span>
      <h3><?php echo esc_html($en ? 'Delivery hours' : 'Heures de livraison'); ?></h3>
      <p class="dl-card__big"><?php echo esc_html($clock($d['start'] ?? '09:00') . ($en ? ' to ' : ' à ') . $clock($d['end'] ?? '18:00')); ?></p>
      <p><?php echo esc_html($every_day ? ($en ? 'Every day, including weekends.' : 'Tous les jours, fins de semaine comprises.') : ($en ? 'On delivery days.' : 'Les jours de livraison.')); ?></p>
    </div>
    <div class="dl-card dl-card--pickup" data-reveal style="--d:.06s">
      <span class="dl-card__ic"><?php echo mcr_icon('store', 22); ?></span>
      <h3><?php echo esc_html($en ? 'Free in-store pickup' : 'Cueillette gratuite en magasin'); ?></h3>
      <p class="dl-card__big"><?php echo esc_html(mcrt_store('street')); ?></p>
      <p><?php echo esc_html(($en ? sprintf('Ready in about %d minutes during store hours. ', (int) ($pk['prep_minutes'] ?? 45)) : sprintf('Prête en environ %d minutes pendant les heures d’ouverture. ', (int) ($pk['prep_minutes'] ?? 45))) . mcr_l($pk, 'instructions')); ?></p>
      <ul class="dl-hours"><?php foreach (mcrt_hours_lines() as [$dd, $tt]) : ?><li><strong><?php echo esc_html($dd); ?></strong> <?php echo esc_html($tt); ?></li><?php endforeach; ?></ul>
      <a class="link-arrow" href="<?php echo esc_url(mcrt_map_url()); ?>" target="_blank" rel="noopener"><?php mcr_e('Itinéraire'); ?> <?php echo mcr_icon('arrow-right', 15); ?></a>
    </div>
    <div class="dl-card" data-reveal style="--d:.12s">
      <span class="dl-card__ic"><?php echo mcr_icon('whatsapp', 22); ?></span>
      <h3><?php echo esc_html($en ? 'A question?' : 'Une question ?'); ?></h3>
      <p class="dl-card__big"><a href="<?php echo esc_url(mcrt_phone_href()); ?>"><?php echo esc_html(mcrt_store('phone')); ?></a></p>
      <p><?php echo esc_html($en ? 'Call us or write on WhatsApp, we will gladly help.' : 'Appelez-nous ou écrivez sur WhatsApp, on vous aide avec plaisir.'); ?></p>
      <?php if ($wa = mcrt_whatsapp_link($en ? 'Hello, I have a question about delivery:' : 'Bonjour, j’ai une question sur la livraison :')) : ?><a class="btn btn--wa" href="<?php echo esc_url($wa); ?>" target="_blank" rel="noopener"><?php echo mcr_icon('whatsapp', 18); ?> WhatsApp</a><?php endif; ?>
    </div>
  </section>

  <!-- FAQ -->
  <section class="dl-section dl-faq" aria-labelledby="h-faq">
    <?php mcrt_section_head($en ? 'Good to know' : 'Bon à savoir', $en ? 'Frequently asked questions' : 'Questions fréquentes', '', '', 'h-faq'); ?>
    <details><summary><?php echo esc_html($en ? 'How is the distance calculated?' : 'Comment la distance est-elle calculée ?'); ?></summary><p><?php echo esc_html($en ? 'We use the driving distance between the store and your address, rounded to the nearest 0.1 km. The price is shown at checkout before you pay.' : 'Nous utilisons la distance en voiture entre le magasin et votre adresse, arrondie au 0,1 km. Le prix s’affiche à la caisse avant de payer.'); ?></p></details>
    <details><summary><?php echo esc_html(sprintf($en ? 'I live more than %s km away. Can I still order?' : 'J’habite à plus de %s km. Puis-je commander ?', $km($max_km))); ?></summary><p><?php echo esc_html($en ? 'Yes: choose free in-store pickup at checkout, or contact us on WhatsApp.' : 'Oui : choisissez la cueillette gratuite en magasin à la caisse, ou écrivez-nous sur WhatsApp.'); ?></p></details>
    <details><summary><?php echo esc_html($en ? 'What if nobody is home?' : 'Et si personne n’est à la maison ?'); ?></summary><p><?php echo esc_html($en ? 'We call before every delivery. If nobody is there, we bring the order back to the store so you can pick it up.' : 'Nous appelons avant chaque livraison. Si personne n’est là, nous rapportons la commande au magasin pour que vous puissiez la récupérer.'); ?></p></details>
    <details><summary><?php echo esc_html($en ? 'How are frozen products delivered?' : 'Comment sont livrés les produits surgelés ?'); ?></summary><p><?php echo esc_html(mcr_l($d, 'instructions') ?: ($en ? 'In an insulated bag.' : 'En sac isotherme.')); ?></p></details>
  </section>
</div>

<script>
(function () {
  var form = document.querySelector('[data-dl-check]');
  if (!form || !window.fetch) return;
  var out = form.querySelector('[data-dl-out]');
  var en = <?php echo $en ? 'true' : 'false'; ?>;
  var money = function (v) { return en ? '$' + Number(v).toFixed(2) : Number(v).toFixed(2).replace('.', ',') + ' $'; };
  var kmf = function (v) { return (en ? String(v) : String(v).replace('.', ',')) + ' km'; };
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var addr = form.address.value.trim();
    if (addr.length < 5) return;
    out.className = 'dl-check__out is-loading';
    out.textContent = en ? 'Calculating…' : 'Calcul en cours…';
    document.querySelectorAll('[data-tier]').forEach(function (el) { el.classList.remove('is-hit'); });
    var body = new URLSearchParams({ action: 'mcr_delivery_quote', address: addr });
    fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) {
        var m = j && j.data && j.data.message;
        out.className = 'dl-check__out is-warn';
        out.textContent = m === 'limit' ? (en ? 'Too many checks, please try again in a few minutes.' : 'Trop de vérifications, réessayez dans quelques minutes.')
          : (en ? 'Address not found. Add the city (e.g. “Trois-Rivières”) and try again.' : 'Adresse introuvable. Ajoutez la ville (ex. « Trois-Rivières ») et réessayez.');
        return;
      }
      var d = j.data;
      if (!d.ok) {
        out.className = 'dl-check__out is-warn';
        out.innerHTML = '';
        out.textContent = (en ? 'Your address is ' + kmf(d.km) + ' away: beyond our delivery area. In-store pickup is free.' : 'Votre adresse est à ' + kmf(d.km) + ' : hors de notre zone de livraison. La cueillette en magasin est gratuite.');
        return;
      }
      var tiers = document.querySelectorAll('.dl-tiers li[data-tier]');
      tiers.forEach(function (li) { if (li.querySelector('.dl-tiers__fee').textContent.replace(/[^\d,.]/g, '').replace(',', '.') == Number(d.fee).toFixed(2)) { li.classList.add('is-hit'); document.querySelectorAll('.dl-ring[data-tier="' + li.getAttribute('data-tier') + '"]').forEach(function (c) { c.classList.add('is-hit'); }); } });
      out.className = 'dl-check__out is-ok';
      out.textContent = (en ? 'About ' + kmf(d.km) + ' by road: delivery ' + money(d.fee) + (d.free_eligible ? ', or free from ' + money(d.free_from) + ' of purchases.' : '.')
                            : 'Environ ' + kmf(d.km) + ' en voiture : livraison ' + money(d.fee) + (d.free_eligible ? ', ou gratuite dès ' + money(d.free_from) + ' d’achat.' : '.'));
    }).catch(function () {
      out.className = 'dl-check__out is-warn';
      out.textContent = en ? 'The check is unavailable right now. The exact price is shown at checkout.' : 'La vérification est indisponible pour le moment. Le prix exact s’affiche à la caisse.';
    });
  });
})();
</script>
<?php
get_footer();
