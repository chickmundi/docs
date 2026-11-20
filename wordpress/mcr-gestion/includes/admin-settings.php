<?php
/**
 * "MCR Gestion" menu: store, hours, delivery, pickup and announcement settings, and the expiry list.
 */
defined('ABSPATH') || exit;

const MCR_DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

add_action('admin_menu', function () {
    add_menu_page('MCR Gestion', 'MCR Gestion', 'manage_woocommerce', 'mcr', 'mcr_render_settings_page', 'dashicons-store', 56);
    add_submenu_page('mcr', 'Paramètres du commerce', 'Paramètres', 'manage_woocommerce', 'mcr', 'mcr_render_settings_page');
    add_submenu_page('mcr', 'Péremption', 'Péremption', 'manage_woocommerce', 'mcr-expiry', 'mcr_render_expiry_page');
});

function mcr_settings_tabs(): array
{
    return ['store' => 'Commerce', 'hours' => 'Heures du magasin', 'delivery' => 'Livraison', 'pickup' => 'Retrait en magasin', 'announcement' => 'Bandeau d’annonce', 'home' => 'Page d’accueil'];
}

add_action('admin_post_mcr_save_settings', function () {
    $group = sanitize_key($_POST['group'] ?? '');
    if (!current_user_can('manage_woocommerce') || !array_key_exists($group, mcr_settings_tabs())) {
        wp_die('Accès refusé.');
    }
    check_admin_referer("mcr_settings_{$group}");
    $in = wp_unslash($_POST['mcr'] ?? []);
    $current = mcr_setting($group);
    $errors = [];

    switch ($group) {
        case 'store':
            foreach (['name', 'phone', 'email', 'whatsapp_display', 'street', 'city', 'region', 'postal_code'] as $k) {
                $current[$k] = sanitize_text_field($in[$k] ?? '');
            }
            $current['whatsapp'] = preg_replace('/\D/', '', (string) ($in['whatsapp'] ?? ''));
            foreach (['facebook', 'instagram'] as $k) {
                $current[$k] = esc_url_raw($in[$k] ?? '');
            }
            foreach (['lat', 'lon'] as $k) {
                $current[$k] = (float) str_replace(',', '.', (string) ($in[$k] ?? $current[$k]));
            }
            break;
        case 'hours':
            foreach (MCR_DAYS as $d => $_) {
                $current[$d] = [
                    'open' => preg_match('/^\d{2}:\d{2}$/', $in[$d]['open'] ?? '') ? $in[$d]['open'] : '08:30',
                    'close' => preg_match('/^\d{2}:\d{2}$/', $in[$d]['close'] ?? '') ? $in[$d]['close'] : '22:00',
                    'closed' => !empty($in[$d]['closed']),
                ];
            }
            break;
        case 'delivery':
            $current['enabled'] = !empty($in['enabled']);
            $current['days'] = array_values(array_map('intval', array_intersect(array_keys(MCR_DAYS), array_map('intval', (array) ($in['days'] ?? [])))));
            foreach (['start', 'end'] as $k) {
                $current[$k] = preg_match('/^\d{2}:\d{2}$/', $in[$k] ?? '') ? $in[$k] : $current[$k];
            }
            $current['distance_method'] = in_array($in['distance_method'] ?? '', ['road', 'straight_line', 'manual'], true) ? $in['distance_method'] : 'road';
            foreach (['straight_line_factor', 'max_km', 'free_radius_km'] as $k) {
                $current[$k] = max(0, (float) str_replace(',', '.', (string) ($in[$k] ?? 0)));
            }
            $current['free_min_subtotal'] = ($in['free_min_subtotal'] ?? '') === '' ? null : max(0, (float) str_replace(',', '.', (string) $in['free_min_subtotal']));
            $current['prep_minutes'] = max(0, (int) ($in['prep_minutes'] ?? 0));
            foreach (['eta_fr', 'eta_en', 'instructions_fr', 'instructions_en'] as $k) {
                $current[$k] = sanitize_textarea_field($in[$k] ?? '');
            }
            $tiers = [];
            foreach ((array) ($in['tiers'] ?? []) as $t) {
                if (($t['to_km'] ?? '') === '' || !empty($t['remove'])) {
                    continue;
                }
                $tiers[] = ['from_km' => (float) str_replace(',', '.', $t['from_km']), 'to_km' => (float) str_replace(',', '.', $t['to_km']), 'fee' => round((float) str_replace(',', '.', $t['fee']), 2)];
            }
            usort($tiers, fn($a, $b) => $a['from_km'] <=> $b['from_km']);
            foreach ($tiers as $i => $t) {
                if ($t['to_km'] <= $t['from_km']) {
                    $errors[] = sprintf('Palier %d : la fin doit dépasser le début.', $i + 1);
                }
                if ($i > 0 && abs($t['from_km'] - $tiers[$i - 1]['to_km']) > 0.001) {
                    $errors[] = sprintf('Palier %d : devrait commencer à %s km.', $i + 1, $tiers[$i - 1]['to_km']);
                }
            }
            if ($tiers && abs(end($tiers)['to_km'] - $current['max_km']) > 0.001) {
                $errors[] = 'Le dernier palier doit se terminer à la distance maximale.';
            }
            $current['tiers'] = $tiers;
            break;
        case 'pickup':
            $current['enabled'] = !empty($in['enabled']);
            $current['use_store_hours'] = !empty($in['use_store_hours']);
            $current['slots_enabled'] = !empty($in['slots_enabled']);
            $current['prep_minutes'] = max(0, (int) ($in['prep_minutes'] ?? 0));
            $current['slot_minutes'] = in_array((int) ($in['slot_minutes'] ?? 60), [30, 60, 120], true) ? (int) $in['slot_minutes'] : 60;
            foreach (['instructions_fr', 'instructions_en'] as $k) {
                $current[$k] = sanitize_textarea_field($in[$k] ?? '');
            }
            break;
        case 'home':
            foreach (['hero_title_fr', 'hero_highlight_fr', 'hero_title_en', 'hero_highlight_en'] as $k) {
                $current[$k] = sanitize_text_field($in[$k] ?? '');
            }
            foreach (['hero_lead_fr', 'hero_lead_en'] as $k) {
                $current[$k] = sanitize_textarea_field($in[$k] ?? '');
            }
            $rows = [];
            foreach ((array) ($in['sections'] ?? []) as $r) {
                $type = sanitize_key($r['type'] ?? '');
                if (!array_key_exists($type, mcr_home_section_types())) {
                    continue;
                }
                $row = ['type' => $type, 'on' => !empty($r['on']), 'pos' => (float) ($r['pos'] ?? 99)];
                if ($type === 'category') {
                    $row['slug'] = sanitize_title($r['slug'] ?? '');
                    if ($row['slug'] === '') {
                        continue; // empty "new row"
                    }
                    if (!get_term_by('slug', $row['slug'], 'product_cat')) {
                        $errors[] = "La catégorie « {$row['slug']} » n’existe pas.";
                    }
                    foreach (['title_fr', 'eyebrow_fr', 'title_en', 'eyebrow_en'] as $k) {
                        $row[$k] = sanitize_text_field($r[$k] ?? '');
                    }
                }
                $rows[] = $row;
            }
            usort($rows, fn($a, $b) => $a['pos'] <=> $b['pos']);
            $current['sections'] = array_map(function ($r) {
                unset($r['pos']);
                return $r;
            }, $rows);
            break;
        case 'announcement':
            $current['enabled'] = !empty($in['enabled']);
            foreach (['text_fr', 'text_en'] as $k) {
                $current[$k] = sanitize_text_field($in[$k] ?? '');
            }
            break;
    }

    if ($errors) {
        set_transient('mcr_settings_errors', $errors, 60);
    } else {
        update_option("mcr_{$group}", $current);
        set_transient('mcr_settings_saved', 1, 60);
    }
    wp_safe_redirect(admin_url("admin.php?page=mcr&tab={$group}"));
    exit;
});

function mcr_input(string $name, $value, string $type = 'text', array $attrs = []): string
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
    }
    return sprintf('<input type="%s" name="mcr[%s]" value="%s" class="%s"%s>', esc_attr($type), esc_attr($name), esc_attr((string) $value), $type === 'text' || $type === 'email' || $type === 'url' ? 'regular-text' : 'small-text', $extra);
}

function mcr_render_settings_page(): void
{
    $tabs = mcr_settings_tabs();
    $tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'store';
    $s = mcr_setting($tab);
    echo '<div class="wrap"><h1>MCR Gestion : paramètres</h1><nav class="nav-tab-wrapper">';
    foreach ($tabs as $k => $label) {
        printf('<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url(admin_url("admin.php?page=mcr&tab={$k}")), esc_html($label));
    }
    echo '</nav>';
    if (get_transient('mcr_settings_saved')) {
        delete_transient('mcr_settings_saved');
        echo '<div class="notice notice-success is-dismissible"><p>Enregistré.</p></div>';
    }
    if ($errs = get_transient('mcr_settings_errors')) {
        delete_transient('mcr_settings_errors');
        echo '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $errs)) . '</p><p>Rien n’a été enregistré.</p></div>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mcr_save_settings"><input type="hidden" name="group" value="' . esc_attr($tab) . '">';
    wp_nonce_field("mcr_settings_{$tab}");
    echo '<table class="form-table" role="presentation">';
    $row = fn(string $label, string $html, string $help = '') => printf('<tr><th scope="row">%s</th><td>%s%s</td></tr>', esc_html($label), $html, $help ? '<p class="description">' . esc_html($help) . '</p>' : '');

    switch ($tab) {
        case 'store':
            $row('Nom', mcr_input('name', $s['name']));
            $row('Téléphone', mcr_input('phone', $s['phone']));
            $row('Courriel', mcr_input('email', $s['email'], 'email'));
            $row('WhatsApp (chiffres, avec indicatif)', mcr_input('whatsapp', $s['whatsapp']), 'Ex. 15145787442. Vide = boutons WhatsApp masqués.');
            $row('WhatsApp (affichage)', mcr_input('whatsapp_display', $s['whatsapp_display']));
            $row('Adresse', mcr_input('street', $s['street']));
            $row('Ville', mcr_input('city', $s['city']));
            $row('Province', mcr_input('region', $s['region']));
            $row('Code postal', mcr_input('postal_code', $s['postal_code']));
            $row('Point de départ des livraisons', mcr_input('lat', $s['lat'], 'text', ['size' => 12]) . ' ' . mcr_input('lon', $s['lon'], 'text', ['size' => 12]), 'Latitude et longitude du magasin (65 Rue Notre-Dame E). À changer seulement si le magasin déménage.');
            $row('Facebook', mcr_input('facebook', $s['facebook'], 'url'));
            $row('Instagram', mcr_input('instagram', $s['instagram'], 'url'));
            break;
        case 'hours':
            echo '<tr><td colspan="2"><p class="description">Heures d’ouverture du magasin. Les heures de livraison se règlent dans l’onglet Livraison.</p></td></tr>';
            foreach (MCR_DAYS as $d => $day) {
                $h = $s[$d] ?? ['open' => '', 'close' => '', 'closed' => false];
                $row($day, sprintf('<input type="time" name="mcr[%1$d][open]" value="%2$s"> à <input type="time" name="mcr[%1$d][close]" value="%3$s"> <label><input type="checkbox" name="mcr[%1$d][closed]" value="1" %4$s> fermé</label>', $d, esc_attr($h['open']), esc_attr($h['close']), checked($h['closed'], true, false)));
            }
            break;
        case 'delivery':
            $row('Livraison', '<label><input type="checkbox" name="mcr[enabled]" value="1" ' . checked($s['enabled'], true, false) . '> Offrir la livraison</label>');
            $days = '';
            foreach (MCR_DAYS as $d => $day) {
                $days .= sprintf('<label style="margin-right:12px"><input type="checkbox" name="mcr[days][]" value="%d" %s> %s</label>', $d, checked(in_array($d, (array) $s['days'], true), true, false), esc_html($day));
            }
            $row('Jours de livraison', $days);
            $row('Heures de livraison', '<input type="time" name="mcr[start]" value="' . esc_attr($s['start']) . '"> à <input type="time" name="mcr[end]" value="' . esc_attr($s['end']) . '">');
            $methods = ['road' => 'Distance par la route (recommandé)', 'straight_line' => 'Ligne droite × facteur', 'manual' => 'Manuelle : frais confirmés par l’équipe'];
            $sel = '<select name="mcr[distance_method]">';
            foreach ($methods as $k => $l) {
                $sel .= '<option value="' . esc_attr($k) . '" ' . selected($s['distance_method'], $k, false) . '>' . esc_html($l) . '</option>';
            }
            $row('Calcul de la distance', $sel . '</select>', 'Par la route : adresse trouvée avec OpenStreetMap, trajet calculé avec OSRM (services gratuits, sans garantie). Si l’adresse est introuvable ou le service indisponible, la commande est acceptée avec « frais à confirmer » et l’équipe fixe les frais. Ligne droite : toujours disponible mais sous-estime le trajet, d’où le facteur.');
            $row('Facteur ligne droite', mcr_input('straight_line_factor', $s['straight_line_factor'], 'number', ['step' => '0.05', 'min' => '1']));
            $row('Distance maximale (km)', mcr_input('max_km', $s['max_km'], 'number', ['step' => '0.5', 'min' => '1']), 'Au-delà : pas de livraison, le client choisit le retrait.');
            $tiers = '<table class="widefat" style="max-width:520px"><thead><tr><th>De (km, inclus)</th><th>À (km)</th><th>Frais ($)</th><th></th></tr></thead><tbody>';
            $list = $s['tiers'];
            $list[] = ['from_km' => end($s['tiers'])['to_km'] ?? 0, 'to_km' => '', 'fee' => ''];
            foreach ($list as $i => $t) {
                $tiers .= sprintf('<tr><td><input type="number" step="0.5" name="mcr[tiers][%1$d][from_km]" value="%2$s" class="small-text"></td><td><input type="number" step="0.5" name="mcr[tiers][%1$d][to_km]" value="%3$s" class="small-text"></td><td><input type="number" step="0.01" name="mcr[tiers][%1$d][fee]" value="%4$s" class="small-text"></td><td>%5$s</td></tr>', $i, esc_attr($t['from_km']), esc_attr($t['to_km']), esc_attr($t['fee']), $t['to_km'] === '' ? '<em>nouveau</em>' : '<label><input type="checkbox" name="mcr[tiers][' . $i . '][remove]" value="1"> retirer</label>');
            }
            $row('Tarifs selon la distance', $tiers . '</tbody></table>', 'Chaque palier va de « De » (inclus) à « À » (exclu), sauf le dernier qui inclut sa fin. La distance est arrondie au 0,1 km.');
            $row('Livraison gratuite dès ($)', mcr_input('free_min_subtotal', $s['free_min_subtotal'] ?? '', 'number', ['step' => '0.01', 'min' => '0']), 'Vide = jamais gratuite.');
            $row('… dans un rayon de (km)', mcr_input('free_radius_km', $s['free_radius_km'], 'number', ['step' => '0.5', 'min' => '0']));
            $row('Préparation minimum (min)', mcr_input('prep_minutes', $s['prep_minutes'], 'number', ['min' => '0']));
            $row('Délai annoncé (FR / EN)', '<textarea name="mcr[eta_fr]" rows="2" class="large-text">' . esc_textarea($s['eta_fr']) . '</textarea><textarea name="mcr[eta_en]" rows="2" class="large-text">' . esc_textarea($s['eta_en']) . '</textarea>');
            $row('Instructions (FR / EN)', '<textarea name="mcr[instructions_fr]" rows="2" class="large-text">' . esc_textarea($s['instructions_fr']) . '</textarea><textarea name="mcr[instructions_en]" rows="2" class="large-text">' . esc_textarea($s['instructions_en']) . '</textarea>');
            break;
        case 'pickup':
            $row('Retrait en magasin', '<label><input type="checkbox" name="mcr[enabled]" value="1" ' . checked($s['enabled'], true, false) . '> Offrir le retrait (gratuit)</label>');
            $row('Heures de retrait', '<label><input type="checkbox" name="mcr[use_store_hours]" value="1" ' . checked($s['use_store_hours'], true, false) . '> Mêmes heures que le magasin</label>');
            $row('Préparation (minutes)', mcr_input('prep_minutes', $s['prep_minutes'], 'number', ['min' => '0']));
            $row('Plages horaires', '<label><input type="checkbox" name="mcr[slots_enabled]" value="1" ' . checked($s['slots_enabled'], true, false) . '> Le client choisit une plage de retrait</label> <select name="mcr[slot_minutes]">' . implode('', array_map(fn($m) => '<option value="' . $m . '" ' . selected((int) $s['slot_minutes'], $m, false) . '>' . $m . ' min</option>', [30, 60, 120])) . '</select>');
            $row('Instructions (FR / EN)', '<textarea name="mcr[instructions_fr]" rows="2" class="large-text">' . esc_textarea($s['instructions_fr']) . '</textarea><textarea name="mcr[instructions_en]" rows="2" class="large-text">' . esc_textarea($s['instructions_en']) . '</textarea>');
            break;
        case 'home':
            $row('Titre (français)', mcr_input('hero_title_fr', $s['hero_title_fr'], 'text', ['class' => 'large-text']) . '<br>' . mcr_input('hero_highlight_fr', $s['hero_highlight_fr'], 'text', ['class' => 'large-text']), 'Deuxième ligne : partie en couleur.');
            $row('Titre (anglais)', mcr_input('hero_title_en', $s['hero_title_en'], 'text', ['class' => 'large-text']) . '<br>' . mcr_input('hero_highlight_en', $s['hero_highlight_en'], 'text', ['class' => 'large-text']));
            $row('Texte d’accueil (français)', '<textarea name="mcr[hero_lead_fr]" rows="3" class="large-text">' . esc_textarea($s['hero_lead_fr']) . '</textarea>', 'Laisser vide pour le texte automatique (avec le nombre de produits).');
            $row('Texte d’accueil (anglais)', '<textarea name="mcr[hero_lead_en]" rows="3" class="large-text">' . esc_textarea($s['hero_lead_en']) . '</textarea>');
            echo '</table><h2>Sections de la page</h2><p>Changez l’ordre avec les numéros, décochez pour masquer. Une rangée de catégorie montre les 12 produits les plus récents en stock de cette catégorie.</p>';
            echo '<table class="widefat striped mcr-home"><thead><tr><th style="width:60px">Ordre</th><th style="width:70px">Afficher</th><th>Section</th><th>Catégorie</th><th>Titre FR / sur-titre FR</th><th>Titre EN / sur-titre EN</th></tr></thead><tbody>';
            $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
            $types = mcr_home_section_types();
            $sections = array_values((array) $s['sections']);
            for ($i = 0; $i < 3; $i++) {
                $sections[] = ['type' => 'category', 'on' => true, 'slug' => '', 'title_fr' => '', 'eyebrow_fr' => '', 'title_en' => '', 'eyebrow_en' => '', 'new' => true];
            }
            foreach ($sections as $i => $sec) {
                $n = "mcr[sections][{$i}]";
                echo '<tr><td><input type="number" step="1" name="' . $n . '[pos]" value="' . esc_attr(($i + 1) * 10) . '" class="small-text"><input type="hidden" name="' . $n . '[type]" value="' . esc_attr($sec['type']) . '"></td>';
                echo '<td><input type="checkbox" name="' . $n . '[on]" value="1" ' . checked(!empty($sec['on']), true, false) . '></td>';
                echo '<td>' . esc_html(!empty($sec['new']) ? 'Nouvelle rangée de catégorie' : $types[$sec['type']]) . '</td>';
                if ($sec['type'] === 'category') {
                    echo '<td><select name="' . $n . '[slug]"><option value="">' . (!empty($sec['new']) ? '— choisir —' : '— retirer —') . '</option>';
                    foreach ($cats as $c) {
                        printf('<option value="%s"%s>%s</option>', esc_attr($c->slug), selected($sec['slug'], $c->slug, false), esc_html(($c->parent ? '— ' : '') . $c->name));
                    }
                    echo '</select></td>';
                    foreach (['fr', 'en'] as $l) {
                        echo '<td><input type="text" name="' . $n . '[title_' . $l . ']" value="' . esc_attr($sec['title_' . $l]) . '" placeholder="Nom de la catégorie" style="width:100%"><br><input type="text" name="' . $n . '[eyebrow_' . $l . ']" value="' . esc_attr($sec['eyebrow_' . $l]) . '" placeholder="Sur-titre (facultatif)" style="width:100%"></td>';
                    }
                } else {
                    echo '<td colspan="3"></td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            break;
        case 'announcement':
            $row('Bandeau', '<label><input type="checkbox" name="mcr[enabled]" value="1" ' . checked($s['enabled'], true, false) . '> Afficher en haut du site</label>');
            $row('Texte (français)', mcr_input('text_fr', $s['text_fr'], 'text', ['class' => 'large-text']));
            $row('Texte (anglais)', mcr_input('text_en', $s['text_en'], 'text', ['class' => 'large-text']));
            break;
    }
    echo '</table>';
    submit_button('Enregistrer');
    echo '</form></div>';
}

function mcr_render_expiry_page(): void
{
    $days = in_array((int) ($_GET['days'] ?? 14), [7, 14, 30, 90], true) ? (int) $_GET['days'] : 14;
    $lots = mcr_expiring_lots($days);
    echo '<div class="wrap"><h1>Péremption</h1><p>Lots en stock qui expirent dans les ' . esc_html($days) . ' prochains jours ou déjà expirés. ';
    foreach ([7, 14, 30, 90] as $d) {
        printf('<a class="button%s" href="%s">%d jours</a> ', $d === $days ? ' button-primary' : '', esc_url(admin_url("admin.php?page=mcr-expiry&days={$d}")), $d);
    }
    echo '</p><table class="widefat striped"><thead><tr><th>Produit</th><th>Lot</th><th>Péremption</th><th>Quantité</th></tr></thead><tbody>';
    if (!$lots) {
        echo '<tr><td colspan="4">Aucun lot dans cette période. Les lots se saisissent dans la fiche des produits périssables.</td></tr>';
    }
    foreach ($lots as $l) {
        printf(
            '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s <strong style="color:%s">(%s)</strong></td><td>%s</td></tr>',
            esc_url(get_edit_post_link($l['product_id'])),
            esc_html($l['name']),
            esc_html($l['lot'] ?: '—'),
            esc_html(date_i18n('j F Y', strtotime($l['expiry']))),
            $l['days_left'] < 0 ? '#b32d2e' : '#996800',
            $l['days_left'] < 0 ? 'expiré' : esc_html($l['days_left'] . ' j'),
            esc_html($l['qty'])
        );
    }
    echo '</tbody></table></div>';
}

// Dashboard widget: expiring lots.
add_action('wp_dashboard_setup', function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    wp_add_dashboard_widget('mcr_expiry_widget', 'Péremption proche (14 jours)', function () {
        $lots = array_slice(mcr_expiring_lots(14), 0, 8);
        if (!$lots) {
            echo '<p>Aucun lot n’expire dans les 14 prochains jours.</p>';
            return;
        }
        echo '<ul>';
        foreach ($lots as $l) {
            printf('<li><a href="%s">%s</a> · lot %s · %s</li>', esc_url(get_edit_post_link($l['product_id'])), esc_html($l['name']), esc_html($l['lot'] ?: '—'), $l['days_left'] < 0 ? '<strong>expiré</strong>' : esc_html($l['days_left'] . ' j'));
        }
        echo '</ul><p><a href="' . esc_url(admin_url('admin.php?page=mcr-expiry')) . '">Voir tous les lots</a></p>';
    });
});

function mcr_home_section_types(): array
{
    return [
        'categories' => 'Magasiner par catégorie (tuiles)',
        'promos' => 'Promotions',
        'new' => 'Nouveautés',
        'split' => 'Deux bannières (épices / soins capillaires)',
        'category' => 'Rangée de catégorie',
        'visit' => 'Venez nous voir (adresse et heures)',
    ];
}

