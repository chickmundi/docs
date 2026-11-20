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
        // Homepage: hero text and the order of the sections (MCR Gestion > Paramètres > Page d'accueil).
        'home' => [
            'hero_title_fr' => 'Les saveurs d’Afrique et d’ici,',
            'hero_highlight_fr' => 'livrées chez vous.',
            'hero_title_en' => 'African and local flavours,',
            'hero_highlight_en' => 'delivered to your door.',
            'hero_lead_fr' => '', // empty: automatic text with the number of products
            'hero_lead_en' => '',
            'sections' => [
                ['type' => 'categories', 'on' => true],
                ['type' => 'promos', 'on' => true],
                ['type' => 'new', 'on' => true],
                ['type' => 'split', 'on' => true],
                ['type' => 'category', 'on' => true, 'slug' => 'epicerie', 'title_fr' => 'Épicerie et saveurs d’Afrique', 'eyebrow_fr' => 'Garde-manger', 'title_en' => 'Grocery and African flavours', 'eyebrow_en' => 'Pantry'],
                ['type' => 'category', 'on' => true, 'slug' => 'surgeles', 'title_fr' => 'Produits congelés', 'eyebrow_fr' => 'Offrez le meilleur à votre famille', 'title_en' => 'Frozen foods', 'eyebrow_en' => 'The best for your family'],
                ['type' => 'category', 'on' => true, 'slug' => 'perruques', 'title_fr' => 'Nos perruques', 'eyebrow_fr' => 'Naturel, semi-naturel et synthétique', 'title_en' => 'Our wigs', 'eyebrow_en' => 'Natural, semi-natural and synthetic'],
                ['type' => 'category', 'on' => true, 'slug' => 'extensions-capillaires', 'title_fr' => 'Extensions et tresses', 'eyebrow_fr' => 'Crochets, mèches, tissages', 'title_en' => 'Extensions and braids', 'eyebrow_en' => 'Crochet, braiding hair, weaves'],
                ['type' => 'category', 'on' => true, 'slug' => 'soins-capillaires', 'title_fr' => 'Soins capillaires', 'eyebrow_fr' => 'Votre routine beauté', 'title_en' => 'Hair care', 'eyebrow_en' => 'Your beauty routine'],
                ['type' => 'visit', 'on' => true],
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
