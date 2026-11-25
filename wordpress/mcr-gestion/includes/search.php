<?php
/**
 * Product search in both languages: a word matches the product's name, description, English name,
 * SKU or one of its tags (hidden search words in French and English). Every word of the search must match.
 * Plurals: "plantains" also finds "plantain". Accents are ignored by the database ("creme" finds "crème").
 * Used by the search page, the search suggestions (Store API) and any other product search.
 */
defined('ABSPATH') || exit;

// The Store API (search suggestions, blocks) searches titles only through its own "search" variable:
// hand the words to the normal search so the rule below applies there too.
add_action('pre_get_posts', function (WP_Query $q) {
    $words = $q->get('search');
    if (is_string($words) && $words !== '' && in_array('product', (array) $q->get('post_type'), true)) {
        $q->set('s', $words);
        $q->set('search', '');
    }
}, 20);

add_filter('posts_search', function ($search, WP_Query $q) {
    if ($search === '') {
        return $search;
    }
    $types = (array) $q->get('post_type');
    if (!in_array('product', $types, true)) {
        return $search;
    }
    $terms = (array) $q->get('search_terms');
    if (!$terms) {
        return $search;
    }
    global $wpdb;
    $and = [];
    foreach ($terms as $t) {
        $t = trim((string) $t);
        if ($t === '' || str_starts_with($t, '-')) {
            continue;
        }
        // Plural to singular for words of 5+ letters ("plantains", "cheveux" -> "cheveu").
        if (mb_strlen($t) >= 5 && preg_match('/[sx]$/iu', $t)) {
            $t = mb_substr($t, 0, -1);
        }
        $like = '%' . $wpdb->esc_like($t) . '%';
        $and[] = $wpdb->prepare(
            "(({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s)"
            . " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} mcr_sm WHERE mcr_sm.post_id = {$wpdb->posts}.ID AND mcr_sm.meta_key IN ('_mcr_title_en', '_sku') AND mcr_sm.meta_value LIKE %s)"
            . " OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} mcr_tr JOIN {$wpdb->term_taxonomy} mcr_tt ON mcr_tt.term_taxonomy_id = mcr_tr.term_taxonomy_id AND mcr_tt.taxonomy = 'product_tag'"
            . " JOIN {$wpdb->terms} mcr_t ON mcr_t.term_id = mcr_tt.term_id WHERE mcr_tr.object_id = {$wpdb->posts}.ID AND mcr_t.name LIKE %s))",
            $like, $like, $like, $like, $like
        );
    }
    return $and ? ' AND (' . implode(' AND ', $and) . ') ' : $search;
}, 20, 2);
