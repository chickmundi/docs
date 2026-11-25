<?php
/**
 * One-time copy from the old site (backup.marchecheminduroy.com): swatch pictures per product and
 * option, the sold-by-weight settings, and variant photos. Called in batches by an administrator:
 * POST /wp-json/mcr-variantes/v1/import  {swatches: [...], weights: [...], variation_images: [...]}
 * Pictures are downloaded once and reused (attachment meta _mcrv_src keeps the original address).
 */
defined('ABSPATH') || exit;

function mcrv_sideload(string $url, string $title, int $parent = 0)
{
    $url = esc_url_raw($url);
    if (!$url || !preg_match('#^https://backup\.marchecheminduroy\.com/wp-content/uploads/#', $url)) {
        return new WP_Error('url', 'Adresse d’image refusée : ' . $url);
    }
    $found = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mcrv_src', 'meta_value' => $url]);
    if ($found) {
        return (int) $found[0];
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        return $tmp;
    }
    $file = ['name' => wp_basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp];
    $id = media_handle_sideload($file, $parent, $title);
    if (is_wp_error($id)) {
        @unlink($tmp); // phpcs:ignore
        return $id;
    }
    update_post_meta($id, '_mcrv_src', $url);
    update_post_meta($id, '_wp_attachment_image_alt', $title);
    return (int) $id;
}

/** The variation of a product whose attributes match (empty = any). */
function mcrv_find_variation(WC_Product $p, array $attrs): ?WC_Product_Variation
{
    foreach ($p->get_children() as $vid) {
        $v = wc_get_product($vid);
        if (!$v) {
            continue;
        }
        $mine = $v->get_attributes();
        $ok = true;
        foreach ($attrs as $k => $want) {
            $k = preg_replace('/^attribute_/', '', (string) $k);
            if ((string) ($mine[$k] ?? '') !== (string) $want && sanitize_title((string) ($mine[$k] ?? '')) !== sanitize_title((string) $want)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return $v;
        }
    }
    return null;
}

/**
 * Give each variant exactly the options it had on the old site, by variant ID (IDs were kept when
 * the products were copied). $rows = [{id: variation id, attrs: {"Couleur": "Melt caramel", ...}}].
 * Attributes are found on the product by name ("Couleur") or key ("couleur", "pa_couleur").
 * $apply = false only reports what would change.
 */
function mcrv_set_variants(array $rows, bool $apply): array
{
    $out = ['variants' => 0, 'unchanged' => 0, 'products' => [], 'problems' => [], 'changes' => []];
    foreach ($rows as $row) {
        $v = wc_get_product(absint($row['id'] ?? 0));
        if (!$v || !$v->is_type('variation')) {
            $out['problems'][] = 'Variante introuvable : ' . ($row['id'] ?? '?');
            continue;
        }
        $parent = wc_get_product($v->get_parent_id());
        $keys = [];
        foreach ($parent->get_attributes() as $key => $a) {
            if ($a->get_variation()) {
                $keys[mb_strtolower(wc_attribute_label($a->get_name(), $parent))] = [$key, $a];
                $keys[$key] = [$key, $a];
                $keys[preg_replace('/^pa_/', '', $key)] = [$key, $a];
            }
        }
        $cur = $v->get_attributes();
        $new = $cur;
        foreach ((array) ($row['attrs'] ?? []) as $label => $want) {
            $want = (string) $want;
            $hit = $keys[mb_strtolower((string) $label)] ?? $keys[sanitize_title((string) $label)] ?? $keys[preg_replace('/^pa_/', '', sanitize_title((string) $label))] ?? null;
            if (!$hit) {
                $out['problems'][] = $parent->get_name() . ' #' . $v->get_id() . ' : attribut « ' . $label . ' » absent';
                continue;
            }
            [$key, $a] = $hit;
            if ($want === '') {
                $new[$key] = '';
                continue;
            }
            if ($a->is_taxonomy()) {
                $term = get_term_by('name', $want, $key) ?: get_term_by('slug', sanitize_title($want), $key);
                if (!$term) {
                    $out['problems'][] = $parent->get_name() . ' #' . $v->get_id() . ' : ' . $want . ' absent de ' . $key;
                    continue;
                }
                $new[$key] = $term->slug;
                continue;
            }
            $options = $a->get_options();
            $exact = in_array($want, $options, true) ? $want : null;
            if ($exact === null) {
                foreach ($options as $o) {
                    if (mb_strtolower(trim($o)) === mb_strtolower(trim($want))) {
                        $exact = $o;
                        break;
                    }
                }
            }
            if ($exact === null) {
                $out['problems'][] = $parent->get_name() . ' #' . $v->get_id() . ' : « ' . $want . ' » n’est pas une option de ' . $a->get_name() . ' (' . implode(', ', $options) . ')';
                continue;
            }
            $new[$key] = $exact;
        }
        if ($new === $cur) {
            $out['unchanged']++;
            continue;
        }
        $out['variants']++;
        $out['products'][$parent->get_id()] = $parent->get_name();
        if (count($out['changes']) < 60) {
            $out['changes'][] = $parent->get_name() . ' #' . $v->get_id() . ' : ' . wp_json_encode($cur, JSON_UNESCAPED_UNICODE) . ' → ' . wp_json_encode($new, JSON_UNESCAPED_UNICODE);
        }
        if ($apply) {
            $v->set_attributes($new);
            $v->save();
            WC_Product_Variable::sync($parent->get_id());
            wc_delete_product_transients($parent->get_id());
        }
    }
    $out['products'] = count($out['products']);
    return $out;
}

/**
 * Product names exactly as on the old site: French name (the product title) and English name
 * (MCR Gestion's English title, _mcr_title_en). Descriptions, web addresses, prices and stock are not touched.
 * $rows = [{id, fr, en}]. An English name equal to the French one is removed, so English shows the French name.
 */
function mcrv_set_names(array $rows, bool $apply): array
{
    $out = ['fr' => 0, 'en' => 0, 'unchanged' => 0, 'missing' => [], 'samples' => []];
    foreach ($rows as $row) {
        $p = wc_get_product(absint($row['id'] ?? 0));
        if (!$p || $p->is_type('variation')) {
            $out['missing'][] = (string) ($row['id'] ?? '?');
            continue;
        }
        $fr = trim((string) ($row['fr'] ?? ''));
        $en = trim((string) ($row['en'] ?? ''));
        $cur_fr = $p->get_name('edit');
        $cur_en = (string) get_post_meta($p->get_id(), '_mcr_title_en', true);
        $want_en = $en !== '' && $en !== $fr ? $en : '';
        $chg_fr = $fr !== '' && $fr !== $cur_fr;
        $chg_en = $want_en !== $cur_en;
        if (!$chg_fr && !$chg_en) {
            $out['unchanged']++;
            continue;
        }
        $out['fr'] += $chg_fr ? 1 : 0;
        $out['en'] += $chg_en ? 1 : 0;
        if (count($out['samples']) < 30) {
            $out['samples'][] = '#' . $p->get_id() . ($chg_fr ? ' FR « ' . $cur_fr . ' » → « ' . $fr . ' »' : '') . ($chg_en ? ' EN « ' . $cur_en . ' » → « ' . $want_en . ' »' : '');
        }
        if ($apply) {
            if ($chg_fr) {
                $p->set_name($fr); // variation names follow
                $p->save();
            }
            if ($chg_en) {
                $want_en === '' ? delete_post_meta($p->get_id(), '_mcr_title_en') : update_post_meta($p->get_id(), '_mcr_title_en', $want_en);
            }
        }
    }
    return $out;
}

add_action('rest_api_init', function () {
    register_rest_route('mcr-variantes/v1', '/import', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        'callback' => function (WP_REST_Request $r) {
            $in = (array) $r->get_json_params();
            $out = ['swatches' => 0, 'weights' => 0, 'variation_images' => 0, 'errors' => []];
            foreach ((array) ($in['swatches'] ?? []) as $s) {
                $p = wc_get_product(absint($s['product'] ?? 0));
                if (!$p) {
                    $out['errors'][] = 'Produit introuvable : ' . ($s['product'] ?? '?');
                    continue;
                }
                $id = mcrv_sideload((string) ($s['image'] ?? ''), $p->get_name() . ' – ' . ($s['title'] ?? ''), $p->get_id());
                if (is_wp_error($id)) {
                    $out['errors'][] = $p->get_name() . ' / ' . ($s['title'] ?? '') . ' : ' . $id->get_error_message();
                    continue;
                }
                $map = mcrv_product_swatches($p->get_id());
                $map[mcrv_attr_key((string) $s['attr'])][mcrv_value_key((string) $s['value'])] = ['id' => $id, 'color' => ''];
                update_post_meta($p->get_id(), '_mcr_swatches', $map);
                $out['swatches']++;
            }
            foreach ((array) ($in['weights'] ?? []) as $w) {
                $p = wc_get_product(absint($w['product'] ?? 0));
                if (!$p || !$p->is_type('simple')) {
                    $out['errors'][] = 'Produit au poids introuvable : ' . ($w['product'] ?? '?');
                    continue;
                }
                $p->update_meta_data('_mcrw_on', 'yes');
                $p->update_meta_data('_mcrw_unit', ($w['unit'] ?? '') === 'lb' ? 'lb' : 'kg');
                foreach (['default', 'min', 'step', 'max'] as $k) {
                    $p->update_meta_data('_mcrw_' . $k, isset($w[$k]) && $w[$k] !== '' ? (string) (float) $w[$k] : '');
                }
                $p->save();
                $out['weights']++;
            }
            foreach ((array) ($in['variation_images'] ?? []) as $vi) {
                $p = wc_get_product(absint($vi['product'] ?? 0));
                $v = $p && $p->is_type('variable') ? mcrv_find_variation($p, (array) ($vi['attrs'] ?? [])) : null;
                if (!$v) {
                    $out['errors'][] = 'Variante introuvable : ' . ($vi['product'] ?? '?') . ' ' . wp_json_encode($vi['attrs'] ?? []);
                    continue;
                }
                $id = mcrv_sideload((string) ($vi['image'] ?? ''), $p->get_name(), $p->get_id());
                if (is_wp_error($id)) {
                    $out['errors'][] = $p->get_name() . ' : ' . $id->get_error_message();
                    continue;
                }
                $v->set_image_id($id);
                $v->save();
                $out['variation_images']++;
            }
            if (function_exists('mcrt_cache_flush')) {
                mcrt_cache_flush();
            }
            return $out;
        },
    ]);
    register_rest_route('mcr-variantes/v1', '/set-names', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        'callback' => function (WP_REST_Request $r) {
            $res = mcrv_set_names((array) $r->get_param('rows'), (bool) $r->get_param('apply'));
            if ($r->get_param('apply') && function_exists('mcrt_cache_flush')) {
                mcrt_cache_flush();
            }
            return $res;
        },
    ]);
    register_rest_route('mcr-variantes/v1', '/set-variants', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        'callback' => function (WP_REST_Request $r) {
            $res = mcrv_set_variants((array) $r->get_param('rows'), (bool) $r->get_param('apply'));
            if ($r->get_param('apply') && function_exists('mcrt_cache_flush')) {
                mcrt_cache_flush();
            }
            return $res;
        },
    ]);
});
