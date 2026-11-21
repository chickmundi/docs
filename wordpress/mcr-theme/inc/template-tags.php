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
