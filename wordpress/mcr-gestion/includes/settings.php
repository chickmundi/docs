<?php
/**
 * Store settings (one option per group), with the store's real defaults.
 */
defined('ABSPATH') || exit;

function mcr_default_settings(): array
{
    return [
        'store' => [
            'name' => 'Marché Chemin-du-Roy',
            'phone' => '+1 819-372-9966',
            'email' => 'sc@marchecheminduroy.com',
            'whatsapp' => '15145787442',
            'whatsapp_display' => '+1 514-578-7442',
            'street' => '65 Rue Notre-Dame E',
            'city' => 'Trois-Rivières',
            'region' => 'QC',
            'postal_code' => 'G8T 4B8',
            'lat' => 46.3629863,
            'lon' => -72.5258898,
            'facebook' => 'https://www.facebook.com/MarcheCheminduRoy',
            'instagram' => 'https://www.instagram.com/marchechemindu/',
            'coupons_enabled' => false,
        ],
        // Store opening hours (not delivery hours). 1 = Monday ... 7 = Sunday.
        'hours' => [
            1 => ['open' => '08:30', 'close' => '22:00', 'closed' => false],
            2 => ['open' => '08:30', 'close' => '22:00', 'closed' => false],
            3 => ['open' => '08:30', 'close' => '22:00', 'closed' => false],
            4 => ['open' => '08:30', 'close' => '22:00', 'closed' => false],
            5 => ['open' => '08:30', 'close' => '22:00', 'closed' => false],
            6 => ['open' => '08:30', 'close' => '22:00', 'closed' => false],
            7 => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
        ],
        'delivery' => [
            'enabled' => true,
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'start' => '09:00',
            'end' => '18:00',
            'distance_method' => 'road', // road | straight_line | manual
            'straight_line_factor' => 1.3,
            'max_km' => 15,
            'tiers' => [
                ['from_km' => 0, 'to_km' => 5, 'fee' => 5.99],
                ['from_km' => 5, 'to_km' => 9, 'fee' => 6.99],
                ['from_km' => 9, 'to_km' => 11, 'fee' => 7.99],
                ['from_km' => 11, 'to_km' => 13, 'fee' => 8.99],
                ['from_km' => 13, 'to_km' => 15, 'fee' => 9.99],
            ],
            'free_min_subtotal' => 99,
            'free_radius_km' => 15,
            'prep_minutes' => 60,
            'eta_fr' => 'Livraison le jour même pour les commandes passées avant 16 h.',
            'eta_en' => 'Same-day delivery for orders placed before 4 p.m.',
            'instructions_fr' => 'Nous vous appelons avant la livraison. Les produits surgelés sont livrés en sac isotherme.',
            'instructions_en' => 'We call before delivering. Frozen products are delivered in an insulated bag.',
        ],
        'pickup' => [
            'enabled' => true,
            'prep_minutes' => 45,
            'use_store_hours' => true,
            'slots_enabled' => false,
            'slot_minutes' => 60,
            'instructions_fr' => 'Présentez votre numéro de commande à la caisse.',
            'instructions_en' => 'Show your order number at the counter.',
        ],
        // Homepage: hero text, banners and the order of the sections (MCR Gestion > Paramètres > Page d'accueil).
        'home' => [
            'hero_eyebrow_fr' => 'Épicerie africaine et beauté à Trois-Rivières',
            'hero_eyebrow_en' => 'African grocery and beauty in Trois-Rivières',
            'hero_title_fr' => 'Les saveurs d’Afrique,',
            'hero_highlight_fr' => 'près de chez vous.',
            'hero_title_en' => 'The flavours of Africa,',
            'hero_highlight_en' => 'close to home.',
            'hero_lead_fr' => '', // empty: automatic text with the number of products
            'hero_lead_en' => '',
            'newsletter_shortcode' => '', // e.g. the shortcode of a Hostinger Reach or Contact Form 7 form
            'banners' => [
                ['on' => true, 'tone' => 'green', 'cat' => 'epices-et-condiments', 'url' => '', 'image' => '',
                    'eyebrow_fr' => 'Saveurs d’Afrique', 'title_fr' => 'Épices, farines et condiments', 'text_fr' => 'Gari, fufu, piment, cube… tout pour cuisiner comme à la maison.', 'cta_fr' => 'Découvrir',
                    'eyebrow_en' => 'Flavours of Africa', 'title_en' => 'Spices, flours and condiments', 'text_en' => 'Gari, fufu, chili, stock cubes… everything to cook like at home.', 'cta_en' => 'Discover'],
                ['on' => true, 'tone' => 'terracotta', 'cat' => 'soins-capillaires', 'url' => '', 'image' => '',
                    'eyebrow_fr' => 'Beauté et soins', 'title_fr' => 'Votre routine capillaire', 'text_fr' => 'Shampoings, revitalisants, huiles et soins pour cheveux naturels.', 'cta_fr' => 'Voir les soins',
                    'eyebrow_en' => 'Beauty and care', 'title_en' => 'Your hair care routine', 'text_en' => 'Shampoos, conditioners, oils and care for natural hair.', 'cta_en' => 'See hair care'],
                ['on' => true, 'tone' => 'gold', 'cat' => 'petit-dejeuner', 'url' => '', 'image' => '',
                    'eyebrow_fr' => 'Bien commencer la journée', 'title_fr' => 'Le petit-déjeuner', 'text_fr' => 'Céréales, boissons chaudes, tartinades et biscuits.', 'cta_fr' => 'Faire le plein',
                    'eyebrow_en' => 'Start the day right', 'title_en' => 'Breakfast', 'text_en' => 'Cereals, hot drinks, spreads and biscuits.', 'cta_en' => 'Stock up'],
            ],
            'sections' => [
                ['type' => 'deals', 'on' => true],
                ['type' => 'categories', 'on' => true],
                ['type' => 'banners', 'on' => true],
                ['type' => 'popular', 'on' => true],
                ['type' => 'collections', 'on' => true],
                ['type' => 'community', 'on' => true],
                ['type' => 'trust', 'on' => true],
                ['type' => 'newsletter', 'on' => true],
            ],
        ],
        'announcement' => [
            'enabled' => true,
            'text_fr' => 'Livraison GRATUITE pour toute commande de 99 $ et plus, jusqu’à 15 km. Passez votre première commande dès maintenant !',
            'text_en' => 'FREE delivery on orders of $99 or more, within 15 km. Place your first order now!',
        ],
    ];
}

/** Get one settings group (merged over defaults), or one key in it: mcr_setting('delivery', 'max_km'). */
function mcr_setting(string $group, ?string $key = null)
{
    static $cache = [];
    if (!isset($cache[$group])) {
        $defaults = mcr_default_settings()[$group] ?? [];
        $saved = get_option("mcr_{$group}", []);
        $cache[$group] = is_array($saved) ? array_replace($defaults, $saved) : $defaults;
    }
    return $key === null ? $cache[$group] : ($cache[$group][$key] ?? null);
}

function mcr_settings_install_defaults(): void
{
    foreach (mcr_default_settings() as $group => $values) {
        if (get_option("mcr_{$group}", null) === null) {
            add_option("mcr_{$group}", $values, '', true);
        }
    }
}

/** One time: homepage settings saved with the previous layout get the new hero text and section order. */
add_action('init', function () {
    if (get_option('mcr_home_v2')) {
        return;
    }
    $saved = get_option('mcr_home', null);
    if (is_array($saved) && !isset($saved['banners'])) {
        $new = mcr_default_settings()['home'];
        $old_titles = ['Les saveurs d’Afrique et d’ici,', 'African and local flavours,'];
        foreach (['fr', 'en'] as $l) {
            if (in_array($saved["hero_title_{$l}"] ?? '', $old_titles, true)) {
                $saved["hero_title_{$l}"] = $new["hero_title_{$l}"];
                $saved["hero_highlight_{$l}"] = $new["hero_highlight_{$l}"];
            }
        }
        $custom = array_values(array_filter((array) ($saved['sections'] ?? []), fn($r) => ($r['type'] ?? '') === 'category' && !in_array($r['slug'] ?? '', ['epicerie', 'surgeles', 'perruques', 'extensions-capillaires', 'soins-capillaires'], true)));
        $saved['sections'] = array_merge($new['sections'], $custom);
        $saved['banners'] = $new['banners'];
        update_option('mcr_home', $saved);
    }
    update_option('mcr_home_v2', 1, false);
});
