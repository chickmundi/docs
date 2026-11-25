<?php
/**
 * Promotions page (/promotions/): campaign banners with their real end date, the promo of the
 * week, quantity deals and kits, then every product on sale by department, sorted.
 * Only real prices: products with a WooCommerce sale price, deals created in MCR Gestion.
 */
defined('ABSPATH') || exit;
get_header();
$en = mcr_is_en();
$money = fn($v) => wc_price((float) $v);

// ---- Products on sale
$ids = array_values(array_filter(wc_get_product_ids_on_sale(), fn($id) => get_post_type($id) === 'product' && get_post_status($id) === 'publish'));
$depts = ['epicerie' => $en ? 'Groceries' : 'Épicerie', 'beaute-cheveux' => $en ? 'Beauty & hair' : 'Beauté et cheveux', 'soins-corporels' => $en ? 'Body care' : 'Soins corporels'];
$dept = sanitize_key(wp_unslash($_GET['rayon'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
$dept = isset($depts[$dept]) ? $dept : '';
$sort = sanitize_key(wp_unslash($_GET['tri'] ?? 'rabais')); // phpcs:ignore WordPress.Security.NonceVerification
$sort = in_array($sort, ['rabais', 'prix', 'fin'], true) ? $sort : 'rabais';
$counts = array_fill_keys(array_keys($depts), 0);
$items = [];
foreach ($ids as $id) {
    $p = wc_get_product($id);
    if (!$p || !$p->is_visible()) {
        continue;
    }
    $d = wp_get_post_terms($id, 'mcr_department', ['fields' => 'slugs']);
    $d = is_array($d) ? $d : [];
    foreach ($d as $s) {
        if (isset($counts[$s])) {
            $counts[$s]++;
        }
    }
    if ($dept && !in_array($dept, $d, true)) {
        continue;
    }
    $items[] = ['p' => $p, 'pct' => mcrt_discount($p), 'price' => (float) $p->get_price(), 'end' => mcrt_sale_end($p), 'stock' => $p->is_in_stock()];
}
usort($items, function ($a, $b) use ($sort) {
    if ($a['stock'] !== $b['stock']) {
        return $a['stock'] ? -1 : 1;
    }
    return match ($sort) {
        'prix' => $a['price'] <=> $b['price'],
        'fin' => ($a['end'] ?: PHP_INT_MAX) <=> ($b['end'] ?: PHP_INT_MAX),
        default => $b['pct'] <=> $a['pct'],
    };
});
$best = $items ? max(array_column($items, 'pct')) : 0;
$per = 24;
$page = max(1, (int) ($_GET['pg'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
$pages = max(1, (int) ceil(count($items) / $per));
$shown = array_slice($items, ($page - 1) * $per, $per);
$base = get_permalink();

// ---- Campaigns, deals, promo of the week
$banners = function_exists('mcr_promo_banners') ? mcr_promo_banners() : [];
$deals = function_exists('mcr_promo_active_deals') ? mcr_promo_active_deals() : [];
$week = function_exists('mcr_promo_of_week') ? mcr_promo_of_week() : null;
$instore = function_exists('mcr_promo_store_campaigns') ? mcr_promo_store_campaigns() : [];
$street = function_exists('mcr_setting') ? (string) mcr_setting('store', 'street') : '';
?>
<section class="promo-hero">
  <div class="container promo-hero__in">
    <nav class="breadcrumbs" aria-label="<?php echo esc_attr(mcr_t('Fil d’Ariane')); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php mcr_e('Accueil'); ?></a> <?php echo mcr_icon('chevron-right', 14); ?> <span><?php echo esc_html($en ? 'Deals' : 'Promotions'); ?></span></nav>
    <p class="eyebrow eyebrow--light"><?php echo mcr_icon('badge-percent', 16); ?> <?php echo esc_html($en ? 'While supplies last' : 'Dans la limite des stocks'); ?></p>
    <h1><?php echo esc_html($en ? 'Deals of the moment' : 'Les promotions du moment'); ?></h1>
    <p class="promo-hero__sub">
      <?php echo esc_html(sprintf($en ? '%d products on sale' : '%d produits en promotion', count($ids))); ?>
      <?php if ($best) : ?><span class="promo-hero__best"><?php echo esc_html(($en ? 'up to ' : 'jusqu’à ') . '-' . $best . ' %'); ?></span><?php endif; ?>
      <?php if ($deals) : ?><span><?php echo esc_html(sprintf($en ? '· %d bundle deal%s' : '· %d offre%s groupée%s', count($deals), count($deals) > 1 ? 's' : '', $en ? '' : (count($deals) > 1 ? 's' : ''))); ?></span><?php endif; ?>
    </p>
  </div>
</section>

<?php if ($banners) : ?>
<section class="section section--tight">
  <div class="container promo-banners">
    <?php foreach ($banners as $b) : ?>
      <article class="promo-banner" data-reveal>
        <span class="promo-banner__label"><?php echo esc_html($b['label']); ?></span>
        <div class="promo-banner__body">
          <?php if (($b['where'] ?? 'online') !== 'online') : ?><span class="where-pill<?php echo $b['where'] === 'store' ? ' where-pill--store' : ''; ?>"><?php echo mcr_icon('store', 14); ?> <?php echo esc_html($b['where_label']); ?></span><?php endif; ?>
          <h2><?php echo esc_html($b['title']); ?></h2>
          <?php if ($b['text']) : ?><p><?php echo esc_html($b['text']); ?></p><?php endif; ?>
          <?php if ($b['ends']) : ?><p class="promo-banner__ends"><?php echo mcr_icon('clock', 16); ?> <?php echo esc_html($en ? 'Ends in' : 'Se termine dans'); ?> <strong data-countdown="<?php echo (int) $b['ends']; ?>"><?php echo esc_html(mcrt_end_label($b['ends'])); ?></strong></p><?php endif; ?>
        </div>
        <?php if (($b['where'] ?? 'online') === 'store') : ?>
          <?php if ($street) : ?><span class="promo-banner__store"><?php echo mcr_icon('map-pin', 16); ?> <?php echo esc_html($street); ?></span><?php endif; ?>
        <?php elseif ($b['type'] === 'bundle') : ?>
          <a class="btn btn--gold" href="<?php echo esc_url(add_query_arg('mcr_kit', $b['id'], home_url('/'))); ?>"><?php echo mcr_icon('shopping-basket', 18); ?> <?php echo esc_html($en ? 'Add the kit' : 'Ajouter le kit'); ?></a>
        <?php elseif ($b['cats']) : $t = get_term((int) $b['cats'][0], 'product_cat'); ?>
          <?php if ($t && !is_wp_error($t)) : ?><a class="btn btn--gold" href="<?php echo esc_url(get_term_link($t)); ?>"><?php echo esc_html($en ? 'See products' : 'Voir les produits'); ?> <?php echo mcr_icon('arrow-right', 16); ?></a><?php endif; ?>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($week) { get_template_part('template-parts/promo-week', null, ['product' => $week]); } ?>

<?php if ($deals) : ?>
<section class="section section--tight" aria-labelledby="h-bundles">
  <div class="container">
    <?php mcrt_section_head($en ? 'Buy more, pay less' : 'Achetez plus, payez moins', $en ? 'Bundle deals' : 'Offres groupées', '', '', 'h-bundles'); ?>
    <div class="bundles">
      <?php foreach ($deals as $d) :
          $prods = array_filter(array_map('wc_get_product', array_slice($d['type'] === 'bundle' ? $d['products'] : $d['targets'], 0, $d['type'] === 'bundle' ? 6 : 4)), fn($p) => $p && $p->is_visible());
          if (!$prods) {
              continue;
          }
          $normal = $d['type'] === 'bundle' ? array_sum(array_map(fn($p) => (float) $p->get_price(), $prods)) : 0;
          [, $to] = mcr_promo_range($d);
          ?>
        <article class="bundle" data-reveal>
          <div class="bundle__thumbs"><?php foreach ($prods as $p) : ?><a href="<?php echo esc_url($p->get_permalink()); ?>" title="<?php echo esc_attr($p->get_name()); ?>"><?php echo $p->get_image('thumbnail', ['alt' => esc_attr($p->get_name()), 'loading' => 'lazy']); ?></a><?php endforeach; ?></div>
          <div class="bundle__body">
            <span class="bundle__label"><?php echo esc_html(mcr_promo_label($d)); ?></span>
            <h3><?php echo esc_html(mcr_promo_title($d)); ?></h3>
            <?php if ($d['type'] === 'bundle') : ?>
              <p class="bundle__list"><?php echo esc_html(implode(' + ', array_map(fn($p) => $p->get_name(), $prods))); ?></p>
              <p class="bundle__price"><del><?php echo $money($normal); // phpcs:ignore ?></del> <strong><?php echo $money($d['price']); // phpcs:ignore ?></strong><?php if ($normal > $d['price']) : ?> <span><?php echo wp_kses_post(sprintf($en ? 'save %s' : 'économisez %s', $money($normal - $d['price']))); ?></span><?php endif; ?></p>
            <?php else : ?>
              <p class="bundle__list"><?php echo esc_html(sprintf($en ? 'On %s. The price is applied in the cart.' : 'Sur %s. Le prix s’applique au panier.', implode(', ', array_map(fn($p) => $p->get_name(), $prods)) . (count($d['targets']) > count($prods) ? ($en ? '…' : '…') : ''))); ?></p>
            <?php endif; ?>
            <?php if ($to) : ?><p class="bundle__ends"><?php echo mcr_icon('clock', 14); ?> <?php echo esc_html(mcrt_end_label($to)); ?></p><?php endif; ?>
            <?php if ($d['type'] === 'bundle') : ?>
              <a class="btn btn--sm" href="<?php echo esc_url(add_query_arg('mcr_kit', $d['id'], home_url('/'))); ?>"><?php echo mcr_icon('plus', 16); ?> <?php echo esc_html($en ? 'Add the kit to cart' : 'Ajouter le kit au panier'); ?></a>
            <?php else : $first = reset($prods); ?>
              <a class="btn btn--sm btn--ghost" href="<?php echo esc_url($first->get_permalink()); ?>"><?php echo esc_html($en ? 'See the product' : 'Voir le produit'); ?></a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($instore) : ?>
<section class="section section--tight" aria-labelledby="h-instore" id="en-magasin">
  <div class="container">
    <?php mcrt_section_head($street ? ($en ? 'At ' : 'Au ') . $street : ($en ? 'At the store' : 'Au magasin'), $en ? 'In-store only deals' : 'Promotions en magasin seulement', '', '', 'h-instore'); ?>
    <p class="instore__note"><?php echo mcr_icon('store', 16); ?> <?php echo esc_html($en ? 'These deals apply at the counter in our store. They are not applied to online orders.' : 'Ces promotions s’appliquent à la caisse, en magasin. Elles ne s’appliquent pas aux commandes en ligne.'); ?></p>
    <div class="bundles">
      <?php foreach ($instore as $d) :
          $prods = array_values(array_filter(array_map('wc_get_product', array_slice($d['type'] === 'bundle' ? $d['products'] : $d['targets'], 0, 6)), fn($p) => $p && $p->is_visible()));
          if (!$prods) {
              continue;
          }
          [, $to] = mcr_promo_range($d);
          $more = count($d['type'] === 'bundle' ? $d['products'] : $d['targets']) - count($prods);
          $cat = $d['type'] !== 'bundle' && !empty($d['cats']) ? get_term((int) $d['cats'][0], 'product_cat') : null;
          $text = trim((string) ($en && !empty($d['text_en']) ? $d['text_en'] : ($d['text_fr'] ?? '')));
          ?>
        <article class="bundle bundle--store" data-reveal>
          <div class="bundle__thumbs"><?php foreach ($prods as $p) : ?><a href="<?php echo esc_url($p->get_permalink()); ?>" title="<?php echo esc_attr($p->get_name()); ?>"><?php echo $p->get_image('thumbnail', ['alt' => esc_attr($p->get_name()), 'loading' => 'lazy']); ?></a><?php endforeach; ?></div>
          <div class="bundle__body">
            <span class="bundle__label"><?php echo esc_html(mcr_promo_label($d)); ?></span> <span class="where-pill where-pill--store"><?php echo mcr_icon('store', 14); ?> <?php echo esc_html($en ? 'In store only' : 'En magasin seulement'); ?></span>
            <h3><?php echo esc_html(mcr_promo_title($d)); ?></h3>
            <?php if ($text) : ?><p class="bundle__list"><?php echo esc_html($text); ?></p><?php endif; ?>
            <p class="bundle__list"><?php echo esc_html(implode($d['type'] === 'bundle' ? ' + ' : ', ', array_map(fn($p) => $p->get_name(), $prods)) . ($more > 0 ? sprintf($en ? ' and %d more' : ' et %d autres', $more) : '')); ?></p>
            <?php if ($to) : ?><p class="bundle__ends"><?php echo mcr_icon('clock', 14); ?> <?php echo esc_html(mcrt_end_label($to)); ?></p><?php endif; ?>
            <?php if ($cat && !is_wp_error($cat)) : ?><a class="btn btn--sm btn--ghost" href="<?php echo esc_url(get_term_link($cat)); ?>"><?php echo esc_html($en ? 'See the products' : 'Voir les produits'); ?></a><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section" aria-labelledby="h-onsale" id="produits">
  <div class="container">
    <?php mcrt_section_head($en ? 'Real prices, real savings' : 'Vrais prix, vraies économies', $en ? 'All products on sale' : 'Tous les produits en promotion', '', '', 'h-onsale'); ?>
    <div class="promo-tools">
      <ul class="chips">
        <li><a href="<?php echo esc_url(remove_query_arg(['rayon', 'pg'], add_query_arg('tri', $sort, $base)) . '#produits'); ?>"<?php echo $dept ? '' : ' aria-current="true"'; ?>><?php echo esc_html($en ? 'All' : 'Tout'); ?> <small><?php echo count($ids); ?></small></a></li>
        <?php foreach ($depts as $slug => $label) : if (!$counts[$slug]) { continue; } ?>
          <li><a href="<?php echo esc_url(add_query_arg(['rayon' => $slug, 'tri' => $sort], $base) . '#produits'); ?>"<?php echo $dept === $slug ? ' aria-current="true"' : ''; ?>><?php echo esc_html($label); ?> <small><?php echo (int) $counts[$slug]; ?></small></a></li>
        <?php endforeach; ?>
      </ul>
      <form class="promo-sort" method="get" action="<?php echo esc_url($base); ?>#produits">
        <?php if ($dept) : ?><input type="hidden" name="rayon" value="<?php echo esc_attr($dept); ?>"><?php endif; ?>
        <label for="tri" class="sr-only"><?php echo esc_html($en ? 'Sort by' : 'Trier par'); ?></label>
        <select id="tri" name="tri" onchange="this.form.submit()">
          <option value="rabais"<?php selected($sort, 'rabais'); ?>><?php echo esc_html($en ? 'Biggest discount' : 'Plus gros rabais'); ?></option>
          <option value="prix"<?php selected($sort, 'prix'); ?>><?php echo esc_html($en ? 'Lowest price' : 'Prix le plus bas'); ?></option>
          <option value="fin"<?php selected($sort, 'fin'); ?>><?php echo esc_html($en ? 'Ending soon' : 'Se termine bientôt'); ?></option>
        </select>
        <noscript><button class="btn btn--sm" type="submit">OK</button></noscript>
      </form>
    </div>
    <?php if ($shown) : ?>
      <?php mcrt_product_row(array_column($shown, 'p'), 'prow--grid promo-grid', ['show_saving' => true]); ?>
      <?php if ($pages > 1) : ?>
        <nav class="pager" aria-label="<?php echo esc_attr($en ? 'Pages' : 'Pages'); ?>">
          <?php for ($i = 1; $i <= $pages; $i++) : ?>
            <a href="<?php echo esc_url(add_query_arg(array_filter(['rayon' => $dept, 'tri' => $sort, 'pg' => $i > 1 ? $i : null]), $base) . '#produits'); ?>"<?php echo $i === $page ? ' aria-current="page"' : ''; ?>><?php echo (int) $i; ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php else : ?>
      <p class="promo-empty"><?php echo esc_html($en ? 'No products on sale in this aisle right now. Come back soon!' : 'Aucun produit en promotion dans ce rayon pour le moment. Revenez bientôt !'); ?></p>
    <?php endif; ?>
  </div>
</section>
<?php
get_footer();
