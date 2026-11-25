<?php
/**
 * Small template helpers.
 */
defined('ABSPATH') || exit;

/** Store setting with a theme default (works without the plugin). */
function mcrt_store(string $key, $default = '')
{
    $v = function_exists('mcr_setting') ? mcr_setting('store', $key) : null;
    return $v ?? $default;
}

function mcrt_phone_href(): string
{
    return 'tel:+' . preg_replace('/\D/', '', (string) mcrt_store('phone', '+1 819-372-9966'));
}

function mcrt_whatsapp_link(string $text): ?string
{
    $n = (string) mcrt_store('whatsapp', '');
    return $n ? 'https://wa.me/' . $n . '?text=' . rawurlencode($text) : null;
}

function mcrt_map_url(): string
{
    $addr = mcrt_store('street', '65 Rue Notre-Dame E') . ', ' . mcrt_store('city', 'Trois-Rivières') . ', ' . mcrt_store('region', 'QC') . ' ' . mcrt_store('postal_code', 'G8T 4B8');
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($addr);
}

function mcrt_money(float $amount): string
{
    return wp_strip_all_tags(wc_price($amount));
}

/** Store hours, grouped ("Lundi au samedi : 8 h 30 à 22 h"). */
function mcrt_hours_lines(): array
{
    $hours = function_exists('mcr_setting') ? mcr_setting('hours') : [];
    $days = mcr_is_en() ? [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] : [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    $fmt = function (string $t): string {
        [$h, $m] = array_map('intval', explode(':', $t));
        return mcr_is_en() ? date('g:i a', mktime($h, $m)) : ($m ? sprintf('%d h %02d', $h, $m) : sprintf('%d h', $h));
    };
    $lines = [];
    $start = null;
    $prev = null;
    for ($d = 1; $d <= 8; $d++) {
        $cur = $d <= 7 ? ($hours[$d] ?? null) : null;
        $label = $cur ? ($cur['closed'] ? (mcr_is_en() ? 'closed' : 'fermé') : $fmt($cur['open']) . (mcr_is_en() ? ' to ' : ' à ') . $fmt($cur['close'])) : null;
        if ($prev !== null && $label !== $prev) {
            $range = $start === $d - 1 ? $days[$start] : $days[$start] . (mcr_is_en() ? ' to ' : ' au ') . lcfirst($days[$d - 1]);
            $lines[] = [$range, $prev];
            $start = $d;
        } elseif ($prev === null) {
            $start = $d;
        }
        $prev = $label;
    }
    return $lines;
}

/** Wishlist button data: just the product number (the script reads the rest from the page). */
function mcrt_wish_data(WC_Product $product): string
{
    return '{"id":' . (int) $product->get_id() . '}';
}

// ---------------------------------------------------------------- Redesign helpers

/** Category image (the store's own media) as an <img>, or '' when the category has none. */
function mcrt_cat_image($term, string $size = 'medium', array $attr = []): string
{
    $term = is_string($term) ? get_term_by('slug', $term, 'product_cat') : $term;
    if (!$term instanceof WP_Term) {
        return '';
    }
    $id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
    return $id ? wp_get_attachment_image($id, $size, false, array_merge(['alt' => '', 'loading' => 'lazy', 'decoding' => 'async'], $attr)) : '';
}

/** The product's most specific category, ignoring "Tout" and "Autres". */
function mcrt_primary_cat(WC_Product $product): ?WP_Term
{
    $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($id, 'product_cat');
    if (!$terms || is_wp_error($terms)) {
        return null;
    }
    $terms = array_values(array_filter($terms, fn($t) => !in_array($t->slug, ['tout', 'autres', 'uncategorized'], true)));
    // Most specific first: sub-categories, then the smallest aisle (e.g. "Surgelés" before "Épicerie").
    usort($terms, fn($a, $b) => [($b->parent ? 1 : 0), $a->count] <=> [($a->parent ? 1 : 0), $b->count]);
    return $terms[0] ?? null;
}

/** Real discount in % (largest one for variable products), 0 when not on sale. */
function mcrt_discount(WC_Product $product): int
{
    if (!$product->is_on_sale()) {
        return 0;
    }
    if ($product->is_type('variable')) {
        $prices = $product->get_variation_prices(true);
        $best = 0;
        foreach ($prices['regular_price'] as $vid => $reg) {
            $sale = (float) ($prices['sale_price'][$vid] ?? $reg);
            if ((float) $reg > 0 && $sale < (float) $reg) {
                $best = max($best, (int) round((1 - $sale / (float) $reg) * 100));
            }
        }
        return $best;
    }
    $reg = (float) $product->get_regular_price();
    return $reg > 0 ? (int) round((1 - (float) $product->get_price() / $reg) * 100) : 0;
}

/** Products in stock from WooCommerce (real data only). */
function mcrt_products(array $args): array
{
    $q = new WC_Product_Query(array_merge(['status' => 'publish', 'limit' => 10, 'stock_status' => 'instock', 'visibility' => 'catalog'], $args));
    return $q->get_products();
}

/** Short "what can you choose" line for variable products: "3 couleurs · 4 longueurs". */
function mcrt_variation_hint(WC_Product $product): string
{
    if (!$product->is_type('variable')) {
        return '';
    }
    $parts = [];
    foreach ($product->get_variation_attributes() as $name => $values) {
        $values = array_filter((array) $values);
        $n = count($values);
        if ($n > 1) {
            $parts[] = wc_attribute_label($name, $product) . (mcr_is_en() ? ": {$n} options" : " : {$n} choix");
        }
    }
    return implode(' · ', array_slice($parts, 0, 2));
}

/** Homepage sections from the settings (older saved lists fall back to the new default order). */
function mcrt_home_sections(): array
{
    $home = function_exists('mcr_setting') ? mcr_setting('home') : [];
    $sections = (array) ($home['sections'] ?? []);
    $types = array_column($sections, 'type');
    if (!$sections || !array_intersect(['popular', 'banners', 'deals'], $types)) {
        // Saved with the previous homepage layout: use the new default order.
        $sections = function_exists('mcr_default_settings') ? mcr_default_settings()['home']['sections'] : [];
    }
    return $sections;
}

/** Section heading used by every homepage block. */
function mcrt_section_head(string $eyebrow, string $title, string $link = '', string $link_text = '', string $id = ''): void
{
    echo '<div class="shead" data-reveal><div>';
    if ($eyebrow !== '') {
        echo '<p class="eyebrow">' . esc_html($eyebrow) . '</p>';
    }
    printf('<h2%s>%s</h2></div>', $id ? ' id="' . esc_attr($id) . '"' : '', esc_html($title));
    if ($link) {
        printf('<a class="link-arrow" href="%s">%s %s</a>', esc_url($link), esc_html($link_text), mcr_icon('arrow-right', 16));
    }
    echo '</div>';
}

/** A horizontal row of product cards (scrolls on phones, grid on desktop). */
function mcrt_product_row(array $products, string $class = '', array $card = []): void
{
    if (!$products) {
        return;
    }
    echo '<ul class="prow ' . esc_attr($class) . '">';
    foreach ($products as $p) {
        echo '<li>';
        get_template_part('template-parts/card', null, array_merge($card, ['product' => $p]));
        echo '</li>';
    }
    echo '</ul>';
}

/** Number of distinct published products in a category and its sub-categories (cached 12 h). */
function mcrt_distinct_count(WP_Term $term): int
{
    $key = 'mcr_cnt_' . $term->term_id;
    $n = get_transient($key);
    if ($n === false) {
        $q = new WP_Query([
            'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false,
            'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term->term_id, 'include_children' => true]],
        ]);
        $n = (int) $q->found_posts;
        set_transient($key, $n, 12 * HOUR_IN_SECONDS);
    }
    return (int) $n;
}

/**
 * Illustrated area map around the store (rivers and nearby places from real coordinates).
 * $radius_km: how far the map reaches from the store.
 */
function mcrt_area_map(float $radius_km, array $places, string $id = 'map'): string
{
    $W = 640; $C = 320; $s = 280 / max($radius_km, .5);
    $lat0 = (float) mcrt_store('lat', 46.3629863);
    $lon0 = (float) mcrt_store('lon', -72.5258898);
    $pt = fn(float $la, float $lo) => [$C + ($lo - $lon0) * 76.7 * $s, $C - ($la - $lat0) * 111.2 * $s];
    $path = function (array $pts) use ($pt): string {
        $o = '';
        foreach ($pts as $i => [$la, $lo]) { [$x, $y] = $pt($la, $lo); $o .= ($i ? ' L' : 'M') . round($x, 1) . ' ' . round($y, 1); }
        return $o;
    };
    $river = [[46.290, -72.660], [46.318, -72.600], [46.333, -72.555], [46.343, -72.530], [46.355, -72.510], [46.372, -72.478], [46.400, -72.430], [46.455, -72.330]];
    $maurice = [[46.440, -72.580], [46.410, -72.566], [46.385, -72.552], [46.366, -72.543], [46.352, -72.538], [46.343, -72.532]];
    $en = mcr_is_en();
    ob_start(); ?>
    <svg viewBox="0 0 <?php echo $W; ?> <?php echo $W; ?>" role="img" aria-labelledby="<?php echo esc_attr($id); ?>-t">
      <title id="<?php echo esc_attr($id); ?>-t"><?php echo esc_html(($en ? 'Map: ' : 'Plan : ') . mcrt_store('street') . ', ' . mcrt_store('city')); ?></title>
      <defs>
        <pattern id="<?php echo esc_attr($id); ?>-grid" width="40" height="40" patternUnits="userSpaceOnUse"><rect width="40" height="40" fill="#f3eee2"/><path d="M40 0H0V40" fill="none" stroke="#e6dece" stroke-width="1"/></pattern>
        <clipPath id="<?php echo esc_attr($id); ?>-clip"><rect width="<?php echo $W; ?>" height="<?php echo $W; ?>" rx="28"/></clipPath>
        <filter id="<?php echo esc_attr($id); ?>-sh" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="4" stdDeviation="4" flood-opacity=".28"/></filter>
      </defs>
      <g clip-path="url(#<?php echo esc_attr($id); ?>-clip)">
        <rect width="<?php echo $W; ?>" height="<?php echo $W; ?>" fill="url(#<?php echo esc_attr($id); ?>-grid)"/>
        <circle cx="<?php echo $C; ?>" cy="<?php echo $C; ?>" r="<?php echo round(1 * $s); ?>" fill="#174b35" fill-opacity=".08" stroke="#174b35" stroke-opacity=".25" stroke-dasharray="6 6"/>
        <path d="<?php echo esc_attr($path($river)); ?>" fill="none" stroke="#a9cfe2" stroke-width="<?php echo round(min(.85 * $s, 110)); ?>" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="<?php echo esc_attr($path($maurice)); ?>" fill="none" stroke="#a9cfe2" stroke-width="<?php echo round(min(.3 * $s, 40)); ?>" stroke-linecap="round" stroke-linejoin="round"/>
        <?php foreach ($places as [$name, $la, $lo, $kind]) : [$x, $y] = $pt($la, $lo); if ($x < 16 || $x > $W - 16 || $y < 16 || $y > $W - 16) { continue; } ?>
          <g class="amap__place amap__place--<?php echo esc_attr($kind); ?>"><circle cx="<?php echo round($x); ?>" cy="<?php echo round($y); ?>" r="<?php echo $kind === 'river' ? 0 : 4; ?>"/><text x="<?php echo round($x + ($kind === 'river' ? 0 : 8)); ?>" y="<?php echo round($y + 4); ?>"><?php echo esc_html($name); ?></text></g>
        <?php endforeach; ?>
        <g filter="url(#<?php echo esc_attr($id); ?>-sh)">
          <path d="M<?php echo $C; ?> <?php echo $C + 4; ?> c-14 -18 -26 -30 -26 -46 a26 26 0 1 1 52 0 c0 16 -12 28 -26 46z" fill="#c96b43"/>
          <circle cx="<?php echo $C; ?>" cy="<?php echo $C - 42; ?>" r="11" fill="#fff"/>
        </g>
        <g class="amap__label" filter="url(#<?php echo esc_attr($id); ?>-sh)">
          <rect x="<?php echo $C - 110; ?>" y="<?php echo $C + 14; ?>" width="220" height="48" rx="14" fill="#174b35"/>
          <text x="<?php echo $C; ?>" y="<?php echo $C + 35; ?>" text-anchor="middle" class="amap__name"><?php echo esc_html(mcrt_store('name', 'Marché Chemin-du-Roy')); ?></text>
          <text x="<?php echo $C; ?>" y="<?php echo $C + 53; ?>" text-anchor="middle" class="amap__addr"><?php echo esc_html(mcrt_store('street')); ?></text>
        </g>
        <g class="amap__north" transform="translate(<?php echo $W - 44; ?> 44)"><circle r="20" fill="#fff"/><path d="M0 -12 L6 6 L0 2 L-6 6z" fill="#174b35"/><text y="-24" text-anchor="middle">N</text></g>
      </g>
    </svg>
    <?php
    return (string) ob_get_clean();
}

// ---------- Promotions ----------

/** Address of the Promotions page (falls back to the shop filter). */
function mcrt_promos_url(): string
{
    return function_exists('mcr_promotions_url') ? mcr_promotions_url() : add_query_arg('promotions', '1', mcr_shop_url());
}

/** Money saved on a product on sale (largest saving for variable products). */
function mcrt_saving(WC_Product $p): float
{
    if (!$p->is_on_sale()) {
        return 0.0;
    }
    if ($p->is_type('variable')) {
        $prices = $p->get_variation_prices(true);
        $best = 0.0;
        foreach ($prices['regular_price'] as $vid => $reg) {
            $best = max($best, (float) $reg - (float) ($prices['sale_price'][$vid] ?? $reg));
        }
        return $best;
    }
    return max(0, (float) wc_get_price_to_display($p, ['price' => $p->get_regular_price()]) - (float) wc_get_price_to_display($p));
}

/** Real end of the sale (timestamp) or 0 when the sale has no end date. */
function mcrt_sale_end(WC_Product $p): int
{
    if (!$p->is_on_sale()) {
        return 0;
    }
    $items = $p->is_type('variable') ? array_filter(array_map('wc_get_product', $p->get_visible_children())) : [$p];
    $end = 0;
    foreach ($items as $v) {
        $to = $v->is_on_sale() ? $v->get_date_on_sale_to() : null;
        if ($to) {
            $end = $end ? min($end, $to->getTimestamp()) : $to->getTimestamp();
        }
    }
    return $end;
}

/** "Encore 2 j" / "Jusqu’au 15 déc." for a sale end, or ''. */
function mcrt_end_label(int $end, bool $short = true): string
{
    if (!$end || $end < time()) {
        return '';
    }
    $en = mcr_is_en();
    $left = $end - time();
    if ($left < DAY_IN_SECONDS) {
        $h = max(1, (int) floor($left / HOUR_IN_SECONDS));
        return $en ? sprintf('Ends in %d h', $h) : sprintf('Fin dans %d h', $h);
    }
    if ($left < 7 * DAY_IN_SECONDS) {
        $d = max(1, (int) floor($left / DAY_IN_SECONDS));
        return $en ? sprintf('%d day%s left', $d, $d > 1 ? 's' : '') : sprintf('Encore %d j', $d);
    }
    return ($en ? 'Until ' : 'Jusqu’au ') . wp_date($en ? 'M j' : 'j M', $end, mcrt_tz());
}

/** "Seulement le jeudi 26 novembre", "Du 26 au 28 novembre", for a promotion event. */
function mcrt_event_when(array $e): string
{
    $en = mcr_is_en();
    $tz = mcrt_tz();
    if ($e['ends'] && wp_date('Ymd', $e['starts'], $tz) === wp_date('Ymd', $e['ends'], $tz)) {
        return $en ? 'Only on ' . wp_date('l, F j', $e['starts'], $tz) : 'Seulement le ' . mb_strtolower(wp_date('l j F', $e['starts'], $tz));
    }
    $from = wp_date($en ? 'F j' : 'j F', $e['starts'], $tz);
    return $e['ends'] ? ($en ? 'From ' . $from . ' to ' . wp_date('F j', $e['ends'], $tz) : 'Du ' . $from . ' au ' . wp_date('j F', $e['ends'], $tz)) : ($en ? 'From ' . $from : 'Dès le ' . $from);
}

/** Deal labels for a product ("2 pour 5,00 $", "Kit 24,99 $"). */
function mcrt_deal_labels(WC_Product $p): array
{
    return function_exists('mcr_product_deals') ? array_map(fn($d) => mcr_promo_label($d), mcr_product_deals($p)) : [];
}

/** Store time zone (WordPress setting, or Montréal when it is left on UTC). */
function mcrt_tz(): DateTimeZone
{
    return function_exists('mcr_store_tz') ? mcr_store_tz() : wp_timezone();
}

// ---------- Photos of the store (assets/photos), used as backgrounds with a gradient fade ----------

/** The five store photos, with what they show. */
function mcrt_store_photos(): array
{
    $en = mcr_is_en();
    return [
        'shop-spices' => $en ? 'Spices, sauces and peanut butters' : 'Épices, sauces et pâtes d’arachide',
        'shop-breakfast' => $en ? 'Breakfast and snacks' : 'Petit-déjeuner et collations',
        'shop-pantry' => $en ? 'Canned goods, pasta and sauces' : 'Conserves, pâtes et sauces',
        'shop-staples' => $en ? 'Everyday essentials' : 'Les essentiels du quotidien',
        'shop-beauty' => $en ? 'Beauty and hair care' : 'Beauté et soins capillaires',
    ];
}

/** <img> of a store photo (720 / 1280 px versions). */
function mcrt_photo_img(string $name, string $sizes = '100vw', bool $eager = false, string $alt = ''): string
{
    $base = MCRT_URL . '/assets/photos/' . sanitize_file_name($name);
    if (!is_readable(MCRT_DIR . '/assets/photos/' . sanitize_file_name($name) . '-720.webp')) {
        return '';
    }
    return sprintf('<img src="%1$s-720.webp" srcset="%1$s-720.webp 720w, %1$s-1280.webp 1280w" sizes="%2$s" alt="%3$s" loading="%4$s" decoding="async">', esc_url($base), esc_attr($sizes), esc_attr($alt), $eager ? 'eager' : 'lazy');
}

/** Full-size background photo with a gradient fade. $tone: dark (green sections) or light (pale headers). */
function mcrt_photo_bg(string $name, string $tone = 'dark', bool $eager = false): string
{
    $img = mcrt_photo_img($name, '100vw', $eager);
    return $img ? '<span class="pbg pbg--' . esc_attr($tone) . '" aria-hidden="true">' . $img . '</span>' : '';
}

/** Which store photo goes with a product category (by the category or its top-level parent); '' if none fits. */
function mcrt_cat_photo(?WP_Term $term): string
{
    $map = [
        'soins-capillaires' => 'shop-beauty', 'soins-corporels' => 'shop-beauty', 'perruques' => 'shop-beauty', 'extensions-capillaires' => 'shop-beauty', 'beaute' => 'shop-beauty', 'cosmetiques' => 'shop-beauty',
        'petit-dejeuner' => 'shop-breakfast', 'collations' => 'shop-breakfast', 'boissons' => 'shop-breakfast', 'biscuits' => 'shop-breakfast',
        'conserves' => 'shop-pantry', 'pates-et-riz' => 'shop-pantry', 'sauces' => 'shop-pantry',
        'garde-manger' => 'shop-staples', 'huiles' => 'shop-staples', 'riz' => 'shop-staples',
        'epices-et-condiments' => 'shop-spices', 'farines' => 'shop-spices', 'epicerie' => 'shop-spices',
    ];
    if (!$term) {
        return 'shop-staples';
    }
    for ($t = $term, $i = 0; $t && $i < 4; $i++) {
        if (isset($map[$t->slug])) {
            return $map[$t->slug];
        }
        $t = $t->parent ? get_term($t->parent, 'product_cat') : null;
        $t = $t instanceof WP_Term ? $t : null;
    }
    return ''; // meat, fish, frozen, fresh produce: no matching store photo
}
