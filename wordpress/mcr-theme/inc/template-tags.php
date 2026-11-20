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
