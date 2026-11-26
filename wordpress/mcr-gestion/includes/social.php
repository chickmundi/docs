<?php
/**
 * Facebook posts, one a day, made from the shop's own products (MCR Gestion > Publications Facebook).
 *
 * Every morning (6 h, store time) the post of the day is prepared:
 *  - products chosen by the theme of the day (new arrivals on Monday, beauty on Wednesday, deals on Friday…),
 *    in stock, with a photo, not posted in the last 3 weeks; a promotion that starts soon takes the day over;
 *  - a 1080 × 1350 image drawn on the server (brand colours, real photos, real prices);
 *  - a caption written by Claude (if an API key is set in MCR Gestion > Optimiseur IA), or from a template,
 *    with the exact prices and a link to the shop.
 * The team then copies the text and shares the image, or the post goes out by itself at the chosen time
 * when a Facebook Page is connected (Graph API, Page access token).
 * Only real data: names, prices and discounts come from WooCommerce.
 */
defined('ABSPATH') || exit;

const MCR_SOCIAL_GRAPH = 'https://graph.facebook.com/v21.0/';

// ---------------------------------------------------------------- Settings and storage

function mcr_social_settings(): array
{
    $s = get_option('mcr_social_settings', []);
    return (is_array($s) ? $s : []) + ['auto' => false, 'time' => '11:30', 'page_id' => '', 'page_name' => '', 'hashtags' => '#TroisRivieres #EpicerieAfricaine #MarcheCheminDuRoy', 'days_off' => []];
}

/** The Page access token (kept apart, never shown again in the admin). */
function mcr_social_token(): string
{
    return (string) get_option('mcr_social_fb_token', '');
}

function mcr_social_posts(): array
{
    $p = get_option('mcr_social_posts', []);
    return is_array($p) ? $p : [];
}

function mcr_social_save_post(array $post): void
{
    $all = mcr_social_posts();
    $all[$post['date']] = $post;
    krsort($all);
    update_option('mcr_social_posts', array_slice($all, 0, 40, true), false);
}

function mcr_social_today(): string
{
    return wp_date('Y-m-d', null, mcr_store_tz());
}

/** Products posted in the last 21 days, so the same ones do not come back too soon. */
function mcr_social_recent_ids(): array
{
    $ids = [];
    $limit = wp_date('Y-m-d', time() - 21 * DAY_IN_SECONDS, mcr_store_tz());
    foreach (mcr_social_posts() as $d => $p) {
        if ($d >= $limit) {
            $ids = array_merge($ids, array_map('intval', (array) ($p['products'] ?? [])));
        }
    }
    return array_unique($ids);
}

// ---------------------------------------------------------------- Themes of the week

/** Theme for a day: [key, title FR, title EN, format single|grid, source]. Monday = 1. */
function mcr_social_themes(): array
{
    return [
        1 => ['new', 'Nouveautés de la semaine', 'New this week', 'grid', ['new']],
        2 => ['grocery', 'Coup de cœur épicerie', 'Grocery pick of the day', 'single', ['cat', ['epicerie', 'epices-et-condiments']]],
        3 => ['beauty', 'Mercredi beauté', 'Beauty Wednesday', 'single', ['cat', ['perruques', 'extensions-capillaires', 'soins-capillaires', 'soins-corporels']]],
        4 => ['meat', 'Viandes et poissons', 'Meat and fish', 'grid', ['cat', ['viandes', 'poissons-et-fruits-de-mer', 'surgeles']]],
        5 => ['deals', 'Promos du week-end', 'Weekend deals', 'grid', ['sale']],
        6 => ['favs', 'Les favoris de nos clients', 'Our customers’ favourites', 'grid', ['popular']],
        7 => ['related', 'Souvent achetés ensemble', 'Often bought together', 'grid', ['related']],
    ];
}

/**
 * Name used in posts: the French name kept for posts (meta _mcr_social_name, e.g. for wigs whose shop name
 * stays the brand's English name), else the product name.
 */
function mcr_social_name(WC_Product $p): string
{
    $id = $p->get_parent_id() ?: $p->get_id();
    $n = trim((string) get_post_meta($id, '_mcr_social_name', true));
    return $n !== '' ? $n : $p->get_name();
}

/** A product that can be shown: published, visible, in stock, with a photo and a price. */
function mcr_social_ok(WC_Product $p): bool
{
    return $p->is_visible() && $p->is_in_stock() && $p->get_image_id() && (float) $p->get_price() > 0;
}

/** Choose $n products for a source, skipping $avoid. A little randomness keeps posts varied. */
function mcr_social_pick(array $source, int $n, array $avoid = []): array
{
    $args = ['status' => 'publish', 'limit' => 80, 'return' => 'ids', 'stock_status' => 'instock', 'visibility' => 'catalog'];
    switch ($source[0]) {
        case 'new':
            $args += ['orderby' => 'date', 'order' => 'DESC', 'date_created' => '>' . (time() - 60 * DAY_IN_SECONDS)];
            break;
        case 'sale':
            $args['include'] = array_merge([0], wc_get_product_ids_on_sale());
            $args['orderby'] = 'rand';
            break;
        case 'cat':
            $args += ['category' => (array) $source[1], 'orderby' => 'popularity'];
            break;
        default:
            $args += ['orderby' => 'popularity'];
    }
    $ids = array_values(array_diff(wc_get_products($args), $avoid));
    // Keep the best 30, then shuffle with today's date as seed: varied but stable within a day.
    $ids = array_slice($ids, 0, 30);
    mt_srand(crc32(mcr_social_today() . implode(',', (array) $source)));
    shuffle($ids);
    mt_srand();
    $out = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if ($p && mcr_social_ok($p)) {
            $out[] = $id;
            if (count($out) >= $n) {
                break;
            }
        }
    }
    return $out;
}

/** Price facts for a product: [price, regular, pct, from] (variable products: lowest price, "dès"). */
function mcr_social_price(WC_Product $p): array
{
    if ($p->is_type('variable')) {
        $price = (float) $p->get_variation_price('min', true);
        $reg = (float) $p->get_variation_regular_price('min', true);
        $from = $price !== (float) $p->get_variation_price('max', true);
    } else {
        $price = (float) wc_get_price_to_display($p);
        $reg = (float) wc_get_price_to_display($p, ['price' => $p->get_regular_price()]);
        $from = false;
    }
    $pct = $p->is_on_sale() && $reg > $price ? (int) round((1 - $price / $reg) * 100) : 0;
    return [$price, $reg, $pct, $from];
}

/** Text for a price: "9,99 $" / "dès 9,99 $" / "15,29 $ / kg". */
function mcr_social_money(float $v, bool $from = false, string $unit = ''): string
{
    return ($from ? 'dès ' : '') . html_entity_decode(wp_strip_all_tags(wc_price($v))) . ($unit ? ' / ' . $unit : '');
}

/** "kg" or "lb" for products sold by weight, else "". */
function mcr_social_unit(WC_Product $p): string
{
    $w = function_exists('mcrw_settings') ? mcrw_settings($p) : null;
    return $w ? (string) $w['unit'] : '';
}

/** Link to the shop with tracking, so sales from Facebook show in reports. */
function mcr_social_link(string $url, string $date): string
{
    return add_query_arg(['utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'post-' . $date], $url);
}

/**
 * Build the plan for a date (products, theme, link). A promotion with a banner that starts within
 * 3 days, or ends within 2 days, takes the day over.
 */
function mcr_social_plan(string $date, array $avoid = []): array
{
    $tz = mcr_store_tz();
    $dow = (int) (new DateTimeImmutable($date, $tz))->format('N');
    $avoid = array_merge($avoid, mcr_social_recent_ids());
    if (function_exists('mcr_promo_events')) {
        // Judge from the morning of the post's day, not from now (posts can be prepared in advance).
        $ref = max(time(), (new DateTimeImmutable($date . ' 06:00', $tz))->getTimestamp());
        foreach (mcr_promo_events() as $e) {
            $soon = $e['starts'] > $ref && $e['starts'] - $ref <= 3 * DAY_IN_SECONDS;
            $running = $e['starts'] <= $ref && $e['ends'] && $e['ends'] > $ref && $e['ends'] - $ref <= 2 * DAY_IN_SECONDS;
            if (!$soon && !$running) {
                continue;
            }
            $e['state'] = $soon ? 'scheduled' : 'active';
            $items = array_values(array_filter(mcr_promo_event_items($e), fn($x) => $x['stock'] && !in_array($x['id'], $avoid, true)));
            // Take turns between the offers (e.g. one wig, one grocery item…), a different mix each day.
            $byPart = [];
            foreach ($items as $it) {
                $byPart[$it['part']][] = $it;
            }
            mt_srand(crc32($date));
            foreach ($byPart as &$list) {
                $list = array_slice($list, 0, 20);
                shuffle($list);
            }
            unset($list);
            mt_srand();
            $ids = [];
            for ($round = 0; count($ids) < 4 && $round < 20; $round++) {
                foreach ($byPart as $list) {
                    $it = $list[$round] ?? null;
                    if ($it && ($p = wc_get_product($it['id'])) && $p->get_image_id() && count($ids) < 4) {
                        $ids[] = $it['id'];
                    }
                }
            }
            if ($ids) {
                return ['date' => $date, 'theme' => 'event', 'title' => $e['title'], 'format' => 'event', 'products' => $ids, 'event' => [
                    'title' => $e['title'], 'parts' => $e['parts'], 'state' => $e['state'], 'starts' => $e['starts'], 'ends' => $e['ends'], 'where' => $e['where_label'],
                    'prices' => array_column(array_filter($items, fn($x) => in_array($x['id'], $ids, true)), null, 'id'),
                ], 'link' => mcr_social_link(mcr_promotions_url(), $date)];
            }
        }
    }
    [$key, $title, , $format, $source] = mcr_social_themes()[$dow];
    if ($source[0] === 'related') {
        $r = mcr_social_plan_related($date, 0, $avoid);
        if ($r) {
            return $r;
        }
        [$key, $title, $format, $source] = ['breakfast', 'Dimanche gourmand', 'single', ['cat', ['petit-dejeuner', 'epices-et-condiments']]];
    }
    $ids = mcr_social_pick($source, $format === 'single' ? 1 : 4, $avoid);
    if (!$ids && $source[0] !== 'popular') { // nothing new / on sale: fall back on popular products
        $ids = mcr_social_pick(['popular'], $format === 'single' ? 1 : 4, $avoid);
        if ($key === 'deals') {
            [$key, $title] = ['favs', 'Les favoris de nos clients'];
        }
    }
    if ($format === 'grid' && count($ids) < 3) {
        $format = 'single';
        $ids = array_slice($ids, 0, 1);
    }
    $url = $format === 'single' && $ids ? get_permalink($ids[0]) : ($key === 'deals' ? mcr_promotions_url() : (($source[0] === 'cat' && ($t = get_term_by('slug', $source[1][0], 'product_cat'))) ? get_term_link($t) : (function_exists('mcr_shop_url') ? mcr_shop_url() : home_url('/'))));
    return ['date' => $date, 'theme' => $key, 'title' => $title, 'format' => $format, 'products' => $ids, 'link' => mcr_social_link((string) $url, $date)];
}

/**
 * "Souvent achetés ensemble": a product and up to 3 products bought with it in real orders
 * (MCR Gestion's "Souvent achetés ensemble" data), else its cross-sells, else popular products of its aisle.
 * $hero = 0: a popular product that has partners, chosen for the day.
 */
function mcr_social_plan_related(string $date, int $hero = 0, array $avoid = []): ?array
{
    $pairs = function_exists('mcr_fbt_pairs') ? mcr_fbt_pairs() : [];
    // Same department only (groceries with groceries, beauty with beauty), so the group makes sense.
    $dept = function (int $id): array {
        $beauty = ['perruques', 'extensions-capillaires', 'soins-capillaires', 'soins-corporels'];
        $fam = [];
        foreach ((array) wp_get_post_terms($id, 'product_cat', ['fields' => 'all']) as $t) {
            if (!$t instanceof WP_Term) {
                continue;
            }
            $top = $t;
            while ($top->parent && ($up = get_term($top->parent, 'product_cat')) && !is_wp_error($up)) {
                $top = $up;
            }
            if ($top->slug !== 'tout') {
                $fam[in_array($top->slug, $beauty, true) ? 'beauty' : 'food'] = true;
            }
        }
        return $fam ? array_keys($fam) : ['food']; // only in « Tout »: counted as groceries
    };
    $partners = function (int $id) use ($pairs, $avoid, $dept): array {
        $out = [];
        $base = wc_get_product($id);
        $mine = $dept($id);
        $cands = array_merge(array_keys($pairs[$id] ?? []), $base ? $base->get_cross_sell_ids() : [], $base ? $base->get_upsell_ids() : []);
        foreach (array_unique($cands) as $pid) {
            $p = wc_get_product($pid);
            if ($mine && !array_intersect($mine, $dept((int) $pid))) {
                continue;
            }
            if ($pid !== $id && $p && mcr_social_ok($p) && !in_array($pid, $avoid, true)) {
                $out[] = $pid;
            }
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    };
    if (!$hero) {
        $cands = array_keys(array_filter($pairs, fn($m) => count($m) >= 2));
        if (!$cands) {
            $cands = wc_get_products(['status' => 'publish', 'limit' => 60, 'return' => 'ids', 'stock_status' => 'instock', 'orderby' => 'popularity']);
        }
        mt_srand(crc32($date . 'related'));
        shuffle($cands);
        mt_srand();
        $fallback = 0;
        foreach ($cands as $id) {
            $p = wc_get_product($id);
            if (!$p || !mcr_social_ok($p) || in_array($id, $avoid, true)) {
                continue;
            }
            $n = count($partners((int) $id));
            if ($n >= 2) {
                $hero = (int) $id;
                break;
            }
            if ($n >= 1 && !$fallback) {
                $fallback = (int) $id;
            }
        }
        if (!$hero) { // few real pairs: a popular product, completed with its aisle below
            $hero = $fallback ?: (int) (mcr_social_pick(['popular'], 1, $avoid)[0] ?? 0);
        }
    }
    $hp = $hero ? wc_get_product($hero) : null;
    if (!$hp) {
        return null;
    }
    $ids = array_merge([$hero], $partners($hero));
    if (count($ids) < 3) { // not enough real partners: popular products of the same aisle
        // Most specific real aisle of the product (a sub-category first, never « Tout »).
        $terms = array_filter((array) wp_get_post_terms($hero, 'product_cat'), fn($t) => $t instanceof WP_Term && !in_array($t->slug, ['tout', 'uncategorized', 'non-classe'], true));
        usort($terms, fn($a, $b) => ($b->parent ? 1 : 0) <=> ($a->parent ? 1 : 0));
        $more = $terms ? mcr_social_pick(['cat', [$terms[0]->slug]], 4 - count($ids), array_merge($avoid, $ids)) : [];
        $ids = array_merge($ids, $more);
    }
    if (count($ids) < 2) {
        return null;
    }
    return ['date' => $date, 'theme' => 'related', 'title' => 'Souvent achetés ensemble', 'format' => count($ids) >= 3 ? 'grid' : 'single', 'products' => array_slice($ids, 0, 4), 'hero' => $hero, 'link' => mcr_social_link(get_permalink($hero), $date)];
}

/** A group of products from one aisle (category), for the "Un rayon" posts. */
function mcr_social_plan_category(string $date, string $slug, array $avoid = []): ?array
{
    $t = get_term_by('slug', $slug, 'product_cat');
    if (!$t) {
        return null;
    }
    $ids = mcr_social_pick(['cat', [$slug]], 4, $avoid);
    if (!$ids) {
        $ids = mcr_social_pick(['cat', [$slug]], 4);
    }
    if (!$ids) {
        return null;
    }
    $name = function_exists('mcr_is_en') ? $t->name : $t->name;
    return ['date' => $date, 'theme' => 'category', 'title' => 'Le rayon ' . $name, 'format' => count($ids) >= 3 ? 'grid' : 'single', 'products' => $ids, 'cat' => $slug, 'link' => mcr_social_link((string) get_term_link($t), $date)];
}

// ---------------------------------------------------------------- Caption

/** Facts sent to Claude (and used by the template): names and exact prices only. */
function mcr_social_facts(array $plan): array
{
    $items = [];
    foreach ($plan['products'] as $id) {
        $p = wc_get_product($id);
        if (!$p) {
            continue;
        }
        [$price, $reg, $pct, $from] = mcr_social_price($p);
        if (($plan['format'] ?? '') === 'event' && isset($plan['event']['prices'][$id])) {
            $ev = $plan['event']['prices'][$id];
            [$price, $reg, $pct, $from] = [(float) $ev['price'], (float) $ev['regular'], (int) $ev['pct'], $p->is_type('variable')];
        }
        $cat = wp_get_post_terms($id, 'product_cat', ['fields' => 'names']);
        $items[] = array_filter([
            'name' => mcr_social_name($p),
            'price' => mcr_social_money($price, $from, mcr_social_unit($p)),
            'regular_price' => $pct ? mcr_social_money($reg, false, mcr_social_unit($p)) : null,
            'discount' => $pct ? '-' . $pct . ' %' : null,
            'category' => is_array($cat) ? ($cat[0] ?? '') : '',
            'about' => mb_substr(trim(wp_strip_all_tags($p->get_short_description())), 0, 220),
        ]);
    }
    $f = ['theme' => $plan['title'], 'products' => $items, 'link' => $plan['link']];
    if (($plan['format'] ?? '') === 'event') {
        $e = $plan['event'];
        $f['promotion'] = ['name' => $e['title'], 'offers' => $e['parts'], 'when' => function_exists('mcrt_event_when') ? mcrt_event_when($e) : wp_date('j F', $e['starts'], mcr_store_tz()), 'where' => $e['where'], 'status' => $e['state'] === 'scheduled' ? 'starts soon (prices are not on yet)' : 'running now'];
    }
    return $f;
}

function mcr_social_ai_key(): string
{
    if (defined('MCR_ANTHROPIC_API_KEY') && MCR_ANTHROPIC_API_KEY) {
        return (string) MCR_ANTHROPIC_API_KEY;
    }
    return (string) get_option('mcr_ai_key', '');
}

/** Caption written by Claude, or a WP_Error (the template is used then). */
function mcr_social_caption_ai(array $plan)
{
    $key = mcr_social_ai_key();
    if ($key === '') {
        return new WP_Error('mcr_social_nokey', 'Pas de clé Claude : texte modèle utilisé.');
    }
    $s = mcr_social_settings();
    $system = 'You write the daily Facebook post of Marché Chemin-du-Roy, a neighbourhood market in Trois-Rivières (Québec): '
        . 'groceries from here and around the world, African specialities, frozen meat and fish, wigs, beauty and hair care. '
        . 'Online orders with delivery in Trois-Rivières (free from 99 $ within 15 km) or free pickup at 65 rue Notre-Dame Est. '
        . 'Write in French as spoken in Québec: warm, lively, proud of the community, never pushy. '
        . 'Structure: a catchy first line (a question, a craving or a little scene), 2 to 4 short lines about the products with their exact prices as given, '
        . 'then one call to action with the link exactly as given on its own line, then the hashtags given. Use 2 to 4 emojis at most. '
        . 'The whole post must be in French: if a product name contains English words (brand or model names of wigs, for example), keep the brand and model but describe the product in French (e.g. "la perruque Butta Lace Bohemian"). '
        . 'For a "Souvent achetés ensemble" post, present the products as things that go well together (a meal, a routine, a kit), based only on their names and descriptions. '
        . 'Rules: use only the facts given. Never invent prices, discounts, quantities, stock levels, dates, health claims or awards. '
        . 'If a promotion has not started yet, say when it starts and do not say the prices are on now. Keep it under 700 characters.';
    $body = [
        'model' => defined('MCR_AI_MODEL') ? MCR_AI_MODEL : 'claude-opus-5-5',
        'max_tokens' => 1500,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => "Post facts (JSON):\n" . wp_json_encode(mcr_social_facts($plan), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\nHashtags to end with: " . $s['hashtags']]],
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => ['type' => 'object', 'properties' => ['caption' => ['type' => 'string']], 'required' => ['caption'], 'additionalProperties' => false]]],
        'fallbacks' => 'default',
    ];
    $res = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => ['x-api-key' => $key, 'anthropic-version' => '2026-10-21', 'anthropic-beta' => 'server-side-fallback-2026-07-01', 'content-type' => 'application/json'],
        'body' => wp_json_encode($body),
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    $data = json_decode((string) wp_remote_retrieve_body($res), true);
    if ((int) wp_remote_retrieve_response_code($res) !== 200 || !is_array($data)) {
        return new WP_Error('mcr_social_ai', 'Claude : ' . ($data['error']['message'] ?? 'erreur ' . wp_remote_retrieve_response_code($res)));
    }
    foreach ((array) ($data['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') {
            $j = json_decode((string) $b['text'], true);
            $cap = trim((string) ($j['caption'] ?? ''));
            if ($cap !== '' && str_contains($cap, wp_parse_url($plan['link'], PHP_URL_HOST))) {
                return $cap;
            }
            if ($cap !== '') {
                return $cap . "\n\n👉 " . $plan['link'];
            }
        }
    }
    return new WP_Error('mcr_social_ai', 'Réponse de Claude illisible.');
}

/** Caption from a template (no API key, or Claude unavailable). Real names and prices only. */
function mcr_social_caption_template(array $plan): string
{
    $f = mcr_social_facts($plan);
    $s = mcr_social_settings();
    $line = fn($it) => '• ' . $it['name'] . ' : ' . (isset($it['regular_price']) ? $it['price'] . ' au lieu de ' . $it['regular_price'] : $it['price']);
    $hooks = [
        'new' => 'Du nouveau sur nos tablettes cette semaine ! ✨',
        'grocery' => 'Notre coup de cœur épicerie du jour 🧺',
        'beauty' => 'C’est mercredi beauté chez Marché Chemin-du-Roy 💇🏾‍♀️',
        'meat' => 'Envie d’un bon plat mijoté cette semaine ? 🍲',
        'deals' => 'Les promos du week-end sont arrivées ! 🛒',
        'favs' => 'Les produits que nos clients adorent ❤️',
        'breakfast' => 'Un dimanche tout en saveurs ☕',
        'related' => 'Nos clients les prennent souvent ensemble 🛒',
        'category' => $plan['title'] . ' : nos incontournables 🧺',
    ];
    if (($plan['format'] ?? '') === 'event') {
        $p = $f['promotion'];
        $head = ($plan['event']['state'] === 'scheduled' ? '⏳ ' . $p['name'] . ' arrive bientôt !' : '🔥 ' . $p['name'] . ' : c’est maintenant !') . "\n" . implode("\n", array_map(fn($o) => '• ' . $o, $p['offers'])) . "\n" . ucfirst($p['when']) . ($p['where'] ? ' (' . mb_strtolower($p['where']) . ')' : '') . '.';
        $body = "\nQuelques exemples :\n" . implode("\n", array_map($line, $f['products']));
    } else {
        $head = $hooks[$plan['theme']] ?? $plan['title'];
        $body = "\n" . implode("\n", array_map($line, $f['products']));
        if ($plan['theme'] === 'related' && count($f['products']) > 1) {
            $body = "\nVous prenez " . $f['products'][0]['name'] . ' (' . $f['products'][0]['price'] . ") ? Pensez aussi à :\n" . implode("\n", array_map($line, array_slice($f['products'], 1)));
        }
        if (count($f['products']) === 1 && !empty($f['products'][0]['about'])) {
            $body .= "\n" . $f['products'][0]['about'];
        }
    }
    return $head . $body . "\n\nCommandez en ligne : livraison à Trois-Rivières ou cueillette gratuite au 65 rue Notre-Dame Est.\n👉 " . $plan['link'] . "\n\n" . $s['hashtags'];
}

// ---------------------------------------------------------------- Image (1080 × 1350, drawn on the server)

function mcr_social_font(string $f): string
{
    return MCR_DIR . 'assets/fonts/' . $f . '.ttf';
}

/** Open an attachment (large size) as a GD image. */
function mcr_social_open($att_id)
{
    $file = get_attached_file($att_id);
    $meta = wp_get_attachment_metadata($att_id);
    foreach (['large', 'woocommerce_single', 'medium_large'] as $size) {
        if (!empty($meta['sizes'][$size]['file']) && $file) {
            $try = path_join(dirname($file), $meta['sizes'][$size]['file']);
            if (is_readable($try)) {
                $file = $try;
                break;
            }
        }
    }
    if (!$file || !is_readable($file)) {
        return null;
    }
    $data = @file_get_contents($file); // phpcs:ignore
    $im = $data ? @imagecreatefromstring($data) : false;
    return $im ?: null;
}

/** Draw $src inside the box, kept whole (contain), on a white background already drawn. */
function mcr_social_fit($dst, $src, int $x, int $y, int $w, int $h, int $pad = 0): void
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $k = min(($w - 2 * $pad) / $sw, ($h - 2 * $pad) / $sh);
    $nw = (int) round($sw * $k);
    $nh = (int) round($sh * $k);
    imagecopyresampled($dst, $src, $x + (int) (($w - $nw) / 2), $y + (int) (($h - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
}

function mcr_social_rrect($im, int $x, int $y, int $w, int $h, int $r, int $col): void
{
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $col);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $col);
    foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $col);
    }
}

/** Width of a text. */
function mcr_social_tw(string $font, float $size, string $text): int
{
    $b = imagettfbbox($size, 0, $font, $text);
    return (int) abs($b[2] - $b[0]);
}

/** Write text wrapped to $maxw, at most $lines lines (last one ends with "…"). Returns the y after the text. */
function mcr_social_text($im, string $font, float $size, int $col, int $x, int $y, string $text, int $maxw, int $lines = 2, float $lh = 1.2, string $align = 'left'): int
{
    $words = preg_split('/\s+/u', trim($text));
    $rows = [];
    $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        if (mcr_social_tw($font, $size, $try) <= $maxw || $cur === '') {
            $cur = $try;
        } else {
            $rows[] = $cur;
            $cur = $w;
        }
    }
    if ($cur !== '') {
        $rows[] = $cur;
    }
    if (count($rows) > $lines) {
        $rows = array_slice($rows, 0, $lines);
        $last = $rows[$lines - 1];
        while ($last !== '' && mcr_social_tw($font, $size, $last . '…') > $maxw) {
            $last = mb_substr($last, 0, -1);
        }
        $rows[$lines - 1] = rtrim($last) . '…';
    }
    foreach ($rows as $r) {
        $y += (int) round($size * $lh * 1.33);
        $xx = $align === 'center' ? $x + (int) (($maxw - mcr_social_tw($font, $size, $r)) / 2) : $x;
        imagettftext($im, $size, 0, $xx, $y, $col, $font, $r);
    }
    return $y;
}

/** A pill with text (price, label). Returns its width. */
function mcr_social_pill($im, string $font, float $size, int $bg, int $fg, int $x, int $y, string $text, int $padx = 26, int $h = 0): int
{
    $tw = mcr_social_tw($font, $size, $text);
    $h = $h ?: (int) round($size * 2.3);
    $w = $tw + 2 * $padx;
    mcr_social_rrect($im, $x, $y, $w, $h, (int) ($h / 2), $bg);
    imagettftext($im, $size, 0, $x + $padx, $y + (int) ($h / 2 + $size * .48), $fg, $font, $text);
    return $w;
}

/** Draw the post image and save it in uploads/mcr-social/. Returns [path, url] or WP_Error. */
function mcr_social_render(array $plan)
{
    if (!function_exists('imagettftext')) {
        return new WP_Error('mcr_social_gd', 'Le serveur ne peut pas dessiner d’image (extension GD).');
    }
    $W = 1080;
    $H = 1350;
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    $c = fn($hex) => imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $green = $c('#174b35');
    $deep = $c('#103b2b');
    $ink = $c('#18231d');
    $gold = $c('#e6b74f');
    $gold3 = $c('#f2d48b');
    $terra = $c('#c96b43');
    $cream = $c('#f8f6ef');
    $white = $c('#ffffff');
    $muted = $c('#c9d8ce');
    $event = ($plan['format'] ?? '') === 'event';
    imagefilledrectangle($im, 0, 0, $W, $H, $event ? $ink : $green);
    // Kente-like stripe at the top and the bottom (brand detail).
    $stripe = [$gold, $green, $terra, $ink];
    $widths = [52, 18, 52, 14];
    foreach ([0, $H - 14] as $sy) {
        $x = 0;
        $i = 0;
        while ($x < $W) {
            imagefilledrectangle($im, $x, $sy, $x + $widths[$i % 4], $sy + 14, $stripe[$i % 4] === ($event ? $ink : $green) ? $deep : $stripe[$i % 4]);
            $x += $widths[$i % 4];
            $i++;
        }
    }
    // Logo and theme label.
    $logo = @imagecreatefrompng(MCR_DIR . 'assets/social-logo.png');
    if ($logo) {
        mcr_social_fit($im, $logo, 60, 46, 300, 96);
        imagedestroy($logo);
    }
    $fB = mcr_social_font('jakarta-800');
    $fR = mcr_social_font('jakarta-500');
    $fD = mcr_social_font('fraunces-600');
    $fI = mcr_social_font('fraunces-600-italic');
    $label = $event ? ($plan['event']['state'] === 'scheduled' ? 'BIENTÔT' : 'EN COURS') : ($plan['format'] === 'single' ? 'COUP DE CŒUR' : 'SÉLECTION DU JOUR');
    $lw = mcr_social_tw($fB, 19, $label) + 52;
    mcr_social_pill($im, $fB, 19, $gold, $deep, $W - 60 - $lw, 70, $label);

    $products = array_values(array_filter(array_map('wc_get_product', $plan['products'])));
    $priceOf = function (WC_Product $p) use ($plan, $event) {
        if ($event && isset($plan['event']['prices'][$p->get_id()])) {
            $e = $plan['event']['prices'][$p->get_id()];
            return [(float) $e['price'], (float) $e['regular'], (int) $e['pct'], $p->is_type('variable')];
        }
        return mcr_social_price($p);
    };

    if (($plan['format'] ?? '') === 'single' && $products) {
        $p = $products[0];
        mcr_social_rrect($im, 60, 170, $W - 120, 640, 36, $white);
        if ($src = mcr_social_open($p->get_image_id())) {
            mcr_social_fit($im, $src, 60, 170, $W - 120, 640, 46);
            imagedestroy($src);
        }
        [$price, $reg, $pct, $from] = $priceOf($p);
        if ($pct) {
            imagefilledellipse($im, $W - 150, 260, 170, 170, $terra);
            $t = '-' . $pct . ' %';
            imagettftext($im, 40, 0, $W - 150 - (int) (mcr_social_tw($fB, 40, $t) / 2), 280, $white, $fB, $t);
        }
        $y = mcr_social_text($im, $fI, 40, $gold3, 60, 832, $plan['title'], $W - 120, 1);
        $y = mcr_social_text($im, $fB, 46, $white, 60, $y + 4, mcr_social_name($p), $W - 120, 2, 1.12);
        $px = 60;
        $py = $y + 30;
        $pw = mcr_social_pill($im, $fB, 40, $gold, $deep, $px, $py, mcr_social_money($price, $from, mcr_social_unit($p)), 34, 92);
        if ($pct) {
            $old = mcr_social_money($reg, false, mcr_social_unit($p));
            $ox = $px + $pw + 26;
            imagettftext($im, 30, 0, $ox, $py + 60, $muted, $fR, $old);
            imagesetthickness($im, 3);
            imageline($im, $ox - 4, $py + 49, $ox + mcr_social_tw($fR, 30, $old) + 4, $py + 49, $muted);
        }
    } else {
        // Grid of up to 4 products (theme days and promotions).
        $top = 170;
        if ($event) {
            $e = $plan['event'];
            $y = mcr_social_text($im, $fD, 66, $white, 60, 150, $e['title'], $W - 120, 1, 1.05);
            foreach (array_slice($e['parts'], 0, 2) as $pt) {
                $y = mcr_social_text($im, $fB, 34, $gold3, 60, $y + 2, $pt, $W - 120, 1, 1.15);
            }
            $top = $y + 34;
        } else {
            $y = mcr_social_text($im, $fI, 54, $gold3, 60, 150, $plan['title'], $W - 120, 1, 1.05);
            $top = $y + 34;
        }
        $foot = $event ? 1140 : 1170;
        $cell = 18;
        $cw = (int) (($W - 120 - $cell) / 2);
        $ch = (int) (($foot - $top - $cell) / 2);
        foreach (array_slice($products, 0, 4) as $i => $p) {
            $x = 60 + ($i % 2) * ($cw + $cell);
            $y0 = $top + (int) ($i / 2) * ($ch + $cell);
            mcr_social_rrect($im, $x, $y0, $cw, $ch, 26, $white);
            $imgH = $ch - 96;
            if ($src = mcr_social_open($p->get_image_id())) {
                mcr_social_fit($im, $src, $x, $y0, $cw, $imgH, 22);
                imagedestroy($src);
            }
            mcr_social_text($im, $fB, 20, $ink, $x + 22, $y0 + $imgH - 14, mcr_social_name($p), $cw - 44, 1, 1.1);
            [$price, $reg, $pct, $from] = $priceOf($p);
            mcr_social_pill($im, $fB, 22, $gold, $deep, $x + 18, $y0 + $ch - 62, mcr_social_money($price, $from, mcr_social_unit($p)), 18, 48);
            if ($pct) {
                $t = '-' . $pct . ' %';
                $tw = mcr_social_tw($fB, 20, $t) + 32;
                mcr_social_pill($im, $fB, 20, $terra, $white, $x + $cw - 18 - $tw, $y0 + 16, $t, 16, 44);
            }
        }
        if ($event) {
            $when = function_exists('mcrt_event_when') ? mcrt_event_when($plan['event']) : '';
            $line = ucfirst($when) . ($plan['event']['where'] ? ' · ' . mb_strtolower($plan['event']['where']) : '');
            if (mcr_social_tw($fB, 26, $line) > $W - 120) {
                $line = ucfirst($when); // too long: keep the date only
            }
            mcr_social_text($im, $fB, 26, $gold3, 60, 1162, $line, $W - 120, 1, 1.1, 'center');
        }
    }
    // Footer.
    mcr_social_text($im, $fB, 30, $white, 60, 1222, 'marchecheminduroy.com', $W - 120, 1, 1.1, 'center');
    mcr_social_text($im, $fR, 22, $muted, 60, 1262, 'Livraison à Trois-Rivières · Cueillette gratuite en magasin', $W - 120, 1, 1.1, 'center');

    $up = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'mcr-social';
    wp_mkdir_p($dir);
    $name = $plan['date'] . '-' . substr(md5(wp_json_encode($plan['products']) . microtime()), 0, 6) . '.jpg';
    imagejpeg($im, $dir . '/' . $name, 90);
    imagedestroy($im);
    return [$dir . '/' . $name, trailingslashit($up['baseurl']) . 'mcr-social/' . $name];
}

// ---------------------------------------------------------------- Make, publish, schedule

/** Prepare the post of a date (new products unless $keep_products). Returns the post or WP_Error. */
function mcr_social_make(string $date, array $opts = [])
{
    $old = mcr_social_posts()[$date] ?? null;
    if (!empty($opts['type']) && $opts['type'] !== 'theme') {
        $avoid = mcr_social_recent_ids();
        $plan = $opts['type'] === 'related'
            ? mcr_social_plan_related($date, absint($opts['hero'] ?? 0), $avoid)
            : mcr_social_plan_category($date, sanitize_title($opts['cat'] ?? ''), $avoid);
        if (!$plan) {
            return new WP_Error('mcr_social_empty', $opts['type'] === 'related' ? 'Pas assez de produits liés pour ce produit : choisissez-en un autre.' : 'Aucun produit en stock avec photo dans ce rayon.');
        }
    } elseif (!empty($opts['products'])) {
        $plan = mcr_social_plan($date);
        $plan['products'] = array_values(array_filter(array_map('absint', $opts['products'])));
        if ($plan['format'] === 'grid' && count($plan['products']) === 1) {
            $plan['format'] = 'single';
        } elseif ($plan['format'] === 'single' && count($plan['products']) > 1) {
            $plan['format'] = 'grid';
        }
        if ($plan['format'] === 'single') {
            $plan['link'] = mcr_social_link(get_permalink($plan['products'][0]), $date);
        }
    } else {
        $plan = mcr_social_plan($date, $old && !empty($opts['other']) ? (array) $old['products'] : []);
    }
    if (!$plan['products']) {
        return new WP_Error('mcr_social_empty', 'Aucun produit en stock avec photo pour ce thème.');
    }
    $img = mcr_social_render($plan);
    if (is_wp_error($img)) {
        return $img;
    }
    if ($old && !empty($old['image_path']) && is_file($old['image_path']) && $old['image_path'] !== $img[0]) {
        wp_delete_file($old['image_path']);
    }
    $cap = mcr_social_caption_ai($plan);
    $post = $plan + [
        'caption' => is_wp_error($cap) ? mcr_social_caption_template($plan) : $cap,
        'caption_by' => is_wp_error($cap) ? 'modèle' : 'Claude',
        'note' => is_wp_error($cap) ? $cap->get_error_message() : '',
        'image_path' => $img[0],
        'image_url' => $img[1],
        'status' => 'ready',
        'made' => time(),
    ];
    mcr_social_save_post($post);
    return $post;
}

/** Re-draw only the image (after a product change) or only the caption. */
function mcr_social_rewrite(string $date)
{
    $post = mcr_social_posts()[$date] ?? null;
    if (!$post) {
        return new WP_Error('mcr_social_none', 'Pas de publication pour ce jour.');
    }
    $cap = mcr_social_caption_ai($post);
    if (is_wp_error($cap)) {
        return $cap;
    }
    $post['caption'] = $cap;
    $post['caption_by'] = 'Claude';
    mcr_social_save_post($post);
    return $post;
}

/** Publish a prepared post on the Facebook Page. */
function mcr_social_publish(string $date)
{
    $s = mcr_social_settings();
    $token = mcr_social_token();
    $post = mcr_social_posts()[$date] ?? null;
    if (!$post) {
        return new WP_Error('mcr_social_none', 'Pas de publication pour ce jour.');
    }
    if ($s['page_id'] === '' || $token === '') {
        return new WP_Error('mcr_social_nofb', 'Page Facebook non connectée.');
    }
    if (($post['status'] ?? '') === 'published') {
        return $post;
    }
    $res = wp_remote_post(MCR_SOCIAL_GRAPH . rawurlencode($s['page_id']) . '/photos', [
        'timeout' => 60,
        'body' => ['url' => $post['image_url'], 'caption' => $post['caption'], 'published' => 'true', 'access_token' => $token],
    ]);
    $data = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
    if (is_wp_error($res) || empty($data['id'])) {
        $msg = is_wp_error($res) ? $res->get_error_message() : (string) ($data['error']['message'] ?? 'réponse inattendue');
        $post['status'] = 'error';
        $post['error'] = $msg;
        mcr_social_save_post($post);
        return new WP_Error('mcr_social_fb', 'Facebook : ' . $msg);
    }
    $post['status'] = 'published';
    $post['fb_id'] = (string) ($data['post_id'] ?? $data['id']);
    $post['published'] = time();
    unset($post['error']);
    mcr_social_save_post($post);
    return $post;
}

/** Check the Page token: returns the Page name or WP_Error. */
function mcr_social_check_page(string $page_id, string $token)
{
    $res = wp_remote_get(add_query_arg(['fields' => 'name', 'access_token' => $token], MCR_SOCIAL_GRAPH . rawurlencode($page_id)), ['timeout' => 20]);
    $data = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
    if (is_wp_error($res) || empty($data['name'])) {
        return new WP_Error('mcr_social_fb', is_wp_error($res) ? $res->get_error_message() : (string) ($data['error']['message'] ?? 'Page introuvable'));
    }
    return (string) $data['name'];
}

/** Every morning: prepare today's post, notify the team, and plan the automatic publication. */
add_action('mcr_social_daily', function () {
    $s = mcr_social_settings();
    $date = mcr_social_today();
    $dow = (int) wp_date('N', null, mcr_store_tz());
    if (in_array($dow, array_map('intval', (array) $s['days_off']), true)) {
        return;
    }
    $post = mcr_social_posts()[$date] ?? null;
    if (!$post) {
        $post = mcr_social_make($date);
        if (is_wp_error($post)) {
            return;
        }
    }
    if ($s['auto'] && $s['page_id'] !== '' && mcr_social_token() !== '') {
        [$h, $m] = array_map('intval', explode(':', $s['time'] . ':0'));
        $at = (new DateTimeImmutable($date . ' 00:00', mcr_store_tz()))->setTime($h, $m)->getTimestamp();
        as_schedule_single_action(max(time() + 60, $at), 'mcr_social_auto', [$date], 'mcr');
    } elseif (function_exists('mcr_push_all') && ($post['status'] ?? '') === 'ready') {
        mcr_push_all(['title' => 'Publication Facebook du jour', 'body' => $post['title'] . ' : prête à partager.', 'url' => admin_url('admin.php?page=mcr-social')]);
    }
});
add_action('mcr_social_auto', function ($date) {
    $s = mcr_social_settings();
    if ($s['auto']) {
        $r = mcr_social_publish((string) $date);
        if (is_wp_error($r) && function_exists('mcr_push_all')) {
            mcr_push_all(['title' => 'Facebook : publication non envoyée', 'body' => $r->get_error_message(), 'url' => admin_url('admin.php?page=mcr-social')]);
        }
    }
});
add_action('init', function () {
    if (!function_exists('as_next_scheduled_action') || as_next_scheduled_action('mcr_social_daily', [], 'mcr')) {
        return;
    }
    $next = (new DateTimeImmutable('tomorrow 06:00', mcr_store_tz()))->getTimestamp();
    as_schedule_recurring_action($next, DAY_IN_SECONDS, 'mcr_social_daily', [], 'mcr');
});

// Store the French names used in posts: POST /wp-json/mcr/v1/social/names {rows: [{id, name}], apply}.
add_action('rest_api_init', function () {
    register_rest_route('mcr/v1', '/social/names', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        'callback' => function (WP_REST_Request $r) {
            $n = 0;
            foreach ((array) $r->get_param('rows') as $row) {
                $id = absint($row['id'] ?? 0);
                $name = sanitize_text_field($row['name'] ?? '');
                if ($id && $name !== '' && get_post_type($id) === 'product') {
                    if ($r->get_param('apply')) {
                        update_post_meta($id, '_mcr_social_name', $name);
                    }
                    $n++;
                }
            }
            return ['names' => $n];
        },
    ]);
});
