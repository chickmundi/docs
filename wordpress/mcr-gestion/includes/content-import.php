<?php
/**
 * One-time product content cleanup (data/content-v1.json), written for this catalogue:
 *  - clean French titles (only where the title is still the one exported, so manual edits are
 *    kept) and English titles;
 *  - missing or wrong-language short descriptions, in French and English;
 *  - full descriptions in French and English where the current one only repeated the title;
 *  - image descriptions (alt text) in French, and in English for /en/ pages;
 *  - category descriptions in French and English (only where empty).
 * Product web addresses (slugs) never change. Runs in batches on admin page loads.
 */
defined('ABSPATH') || exit;

const MCR_CONTENT_VERSION = 1;

function mcr_content_data(): array
{
    static $d = null;
    if ($d === null) {
        $file = MCR_DIR . 'data/content-v' . MCR_CONTENT_VERSION . '.json';
        $d = is_readable($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }
    return $d;
}

function mcr_content_apply_product(int $old_id, array $r): bool
{
    $id = function_exists('mcr_current_product_id') ? mcr_current_product_id($old_id) : $old_id;
    $p = $id ? wc_get_product($id) : null;
    if (!$p) {
        return false;
    }
    $old_name = $p->get_name();
    $untouched = trim(html_entity_decode($old_name, ENT_QUOTES)) === trim((string) $r['o']);
    if ($untouched && $r['tf'] !== '') {
        $p->set_name($r['tf']);
    }
    if (!empty($r['sf'])) {
        $p->set_short_description($r['sf']);
    }
    if (!empty($r['df']) && mb_strlen(trim(wp_strip_all_tags($p->get_description()))) < 40) {
        $p->set_description($r['df']);
    }
    $p->save();
    if ($r['te'] !== '') {
        update_post_meta($id, '_mcr_title_en', $r['te']);
    }
    if (!empty($r['se'])) {
        update_post_meta($id, '_mcr_short_description_en', $r['se']);
    }
    if (!empty($r['de']) && mb_strlen(trim(wp_strip_all_tags((string) get_post_meta($id, '_mcr_description_en', true)))) < 40) {
        update_post_meta($id, '_mcr_description_en', $r['de']);
    }
    // Image descriptions: the clean title, in each language.
    $title_fr = $untouched ? $r['tf'] : $p->get_name();
    foreach (array_unique(array_filter(array_merge([$p->get_image_id()], $p->get_gallery_image_ids()))) as $img) {
        $alt = trim((string) get_post_meta($img, '_wp_attachment_image_alt', true));
        if ($alt === '' || $alt === trim($old_name) || $alt === trim((string) $r['o'])) {
            update_post_meta($img, '_wp_attachment_image_alt', $title_fr);
        }
        if ($r['te'] !== '') {
            update_post_meta($img, '_mcr_alt_en', $r['te']);
        }
    }
    return true;
}

function mcr_content_apply_cats(): int
{
    $n = 0;
    foreach ((array) (mcr_content_data()['cats'] ?? []) as $slug => $c) {
        $t = get_term_by('slug', $slug, 'product_cat');
        if (!$t) {
            continue;
        }
        if (trim((string) $t->description) === '' && !empty($c['df'])) {
            wp_update_term($t->term_id, 'product_cat', ['description' => $c['df']]);
            $n++;
        }
        if (trim((string) get_term_meta($t->term_id, 'mcr_description_en', true)) === '' && !empty($c['de'])) {
            update_term_meta($t->term_id, 'mcr_description_en', $c['de']);
        }
        if (trim((string) get_term_meta($t->term_id, 'mcr_name_en', true)) === '' && !empty($c['ne'])) {
            update_term_meta($t->term_id, 'mcr_name_en', $c['ne']);
        }
    }
    return $n;
}

add_action('admin_init', function () {
    $key = 'mcr_content_v' . MCR_CONTENT_VERSION;
    $state = get_option($key);
    if ((is_array($state) && !empty($state['done'])) || !current_user_can('manage_woocommerce') || wp_doing_ajax() || !mcr_content_data()) {
        return;
    }
    $state = is_array($state) ? $state : ['offset' => 0, 'products' => 0, 'cats' => null];
    if ($state['cats'] === null) {
        $state['cats'] = mcr_content_apply_cats();
    }
    $all = (array) (mcr_content_data()['products'] ?? []);
    $ids = array_keys($all);
    if (function_exists('set_time_limit')) {
        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
    wp_suspend_cache_invalidation(false);
    foreach (array_slice($ids, (int) $state['offset'], 120) as $old) {
        if (mcr_content_apply_product((int) $old, (array) $all[$old])) {
            $state['products']++;
        }
        $state['offset']++;
    }
    $state['done'] = $state['offset'] >= count($ids);
    update_option($key, $state, false);
    if ($state['done']) {
        wc_delete_product_transients();
        delete_option('mcr_fbt_pairs');
    }
});

