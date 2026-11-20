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
