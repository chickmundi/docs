<?php
/**
 * Homepage: the next promotion event (about to start, or running with an end date), with a live countdown
 * and a few of its products. Only real campaigns from MCR Gestion > Promotions (with "bannière" on).
 */
defined('ABSPATH') || exit;
$events = function_exists('mcr_promo_events') ? mcr_promo_events() : [];
if (!$events) {
    return;
}
$e = $events[0];
$en = mcr_is_en();
$soon = $e['state'] === 'scheduled';
$at = $soon ? $e['starts'] : $e['ends'];
$items = array_slice(array_values(array_filter(mcr_promo_event_items($e), fn($x) => $x['stock'])), 0, 8);
$url = mcrt_promos_url() . ($soon ? '#bientot' : '#produits');
$when = mcrt_event_when($e);
?>
<section class="section evt<?php echo $soon ? ' is-soon' : ' is-live'; ?>" aria-labelledby="h-evt">
  <div class="container">
    <div class="evt__box" data-reveal>
      <div class="evt__intro">
        <p class="evt__eyebrow"><?php echo mcr_icon($soon ? 'clock' : 'badge-percent', 16); ?> <?php echo esc_html($soon ? ($en ? 'Coming soon' : 'Bientôt') : ($en ? 'On now' : 'En cours')); ?><?php if ($e['where_label']) : ?> · <?php echo esc_html($e['where_label']); ?><?php endif; ?></p>
        <h2 id="h-evt"><?php echo esc_html($e['title']); ?></h2>
        <ul class="evt__deals"><?php foreach ($e['parts'] as $pt) : ?><li><?php echo esc_html($pt); ?></li><?php endforeach; ?></ul>
        <p class="evt__count"><span><?php echo esc_html($soon ? ($en ? 'Starts in' : 'Commence dans') : ($en ? 'Ends in' : 'Se termine dans')); ?></span> <strong data-countdown="<?php echo (int) $at; ?>" data-countdown-reload><?php echo esc_html(mcrt_end_label($at)); ?></strong></p>
        <p class="evt__when"><?php echo esc_html($when); ?></p>
        <a class="btn btn--gold" href="<?php echo esc_url($url); ?>"><?php echo esc_html($soon ? ($en ? 'Preview the deals' : 'Voir les produits en promo') : ($en ? 'Shop the deals' : 'Profiter des promos')); ?> <?php echo mcr_icon('arrow-right', 16); ?></a>
      </div>
      <?php if ($items) : ?>
        <div class="prow-wrap evt__row" data-carousel>
          <ul class="prow prow--scroll">
            <?php foreach ($items as $it) : $p = wc_get_product($it['id']); if (!$p) { continue; } ?>
              <li><?php get_template_part('template-parts/card', null, $soon ? ['product' => $p, 'soon' => $it + ['when' => $when, 'starts' => $e['starts']]] : ['product' => $p]); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
