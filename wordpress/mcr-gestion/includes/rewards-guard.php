<?php
/**
 * Anti-abuse checks for the welcome wheel and referral gifts.
 *
 *  - Every order keeps its phone number as plain digits (_mcr_phone_digits) and a delivery
 *    address key (_mcr_addr_key: postal code + civic number + apartment). Existing orders are
 *    indexed in batches, so "(819) 372-9966", "819-372-9966" and "+1 819 372 9966" are one number.
 *  - A gift for new customers is refused if the phone, the email OR the delivery address
 *    already has an order.
 *  - Phone numbers that cannot exist are refused (fictional 555-01xx, invalid area codes, 000…).
 *  - One spin per device, and at most 3 spins per internet connection in 30 days.
 */
defined('ABSPATH') || exit;

/** Digits with the country code ("18193729966"), or '' if it is not a phone number. */
function mcr_phone_digits(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (str_starts_with(trim($raw), '00')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10) {
        $d = '1' . $d;
    }
    return strlen($d) >= 11 && strlen($d) <= 15 ? $d : '';
}

/** Could this number really exist? (North American numbering rules; basic checks elsewhere) */
function mcr_phone_plausible(string $d): bool
{
    if ($d === '' || preg_match('/^(\d)\1+$/', substr($d, -7)) || preg_match('/(0123456|1234567|2345678|3456789|9876543)/', $d)) {
        return false;
    }
    if (strlen($d) === 11 && $d[0] === '1') {
        $area = substr($d, 1, 3);
        $exch = substr($d, 4, 3);
        $line = substr($d, 7, 4);
        if ($area[0] < '2' || $exch[0] < '2' || substr($area, 1) === '11' || substr($exch, 1) === '11') {
            return false; // area codes and exchanges never start with 0 or 1; N11 are service codes
        }
        if ($exch === '555' && $line >= '0100' && $line <= '0199') {
            return false; // reserved for films and examples
        }
        if (in_array($area, ['555', '800', '833', '844', '855', '866', '877', '888', '900', '976'], true)) {
            return false; // toll-free and premium numbers are not personal phones
        }
    }
    return true;
}

/** Key of a delivery address: "G8T4B8|65|12" (postal code, civic number, apartment). '' if unusable. */
function mcr_addr_key(string $street, string $street2, string $postcode): string
{
    $pc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $postcode));
    $all = trim($street . ' ' . $street2);
    $unit = '';
    // Apartment written as "app. 12", "apt 12", "unité 12", "suite 12", "#12"
    if (preg_match('/(?:\b(?:app|apt|appartement|unit[ée]?|suite|bureau)\b\.?|#)\s*([0-9A-Z]+)/iu', $all, $u)) {
        $unit = strtoupper($u[1]);
        $street = str_replace($u[0], ' ', $street);
    } elseif (preg_match('/^\s*([0-9A-Z]{1,5})\s*$/i', $street2, $u)) {
        $unit = strtoupper($u[1]); // address line 2 holding only the apartment
    }
    if (preg_match('/^\s*(\d+)\s*-\s*(\d+)/', $street, $m)) {
        $unit = $unit ?: $m[1]; // "12-65 rue X": apartment 12 at number 65
        $civic = $m[2];
    } elseif (preg_match('/(\d+)/', $street, $m)) {
        $civic = $m[1];
    } else {
        return '';
    }
    return strlen($pc) >= 5 ? $pc . '|' . $civic . '|' . $unit : '';
}

/** The delivery address key of an order ('' for in-store pickup). */
function mcr_order_addr_key(WC_Order $o): string
{
    if ($o->get_meta('_mcr_fulfilment') === 'pickup') {
        return '';
    }
    $street = $o->get_shipping_address_1() ?: $o->get_billing_address_1();
    if (function_exists('mcr_setting') && trim(strtolower($street)) === trim(strtolower((string) mcr_setting('store', 'street')))) {
        return ''; // the store's own address (pickup)
    }
    return mcr_addr_key($street, $o->get_shipping_address_2() ?: $o->get_billing_address_2(), $o->get_shipping_postcode() ?: $o->get_billing_postcode());
}

// Index every order when it is saved.
add_action('woocommerce_before_order_object_save', function ($o) {
    if (!$o instanceof WC_Order || $o instanceof WC_Order_Refund) {
        return;
    }
    $d = mcr_phone_digits($o->get_billing_phone());
    if ($d !== (string) $o->get_meta('_mcr_phone_digits')) {
        $o->update_meta_data('_mcr_phone_digits', $d);
    }
    $k = mcr_order_addr_key($o);
    if ($k !== (string) $o->get_meta('_mcr_addr_key')) {
        $o->update_meta_data('_mcr_addr_key', $k);
    }
});

// Index existing orders, 200 at a time, on admin page loads.
add_action('admin_init', function () {
    if (get_option('mcr_order_index_v1') || wp_doing_ajax() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $orders = wc_get_orders(['limit' => 200, 'status' => array_keys(wc_get_order_statuses()), 'type' => 'shop_order', 'meta_query' => [['key' => '_mcr_phone_digits', 'compare' => 'NOT EXISTS']]]);
    foreach ($orders as $o) {
        $o->update_meta_data('_mcr_phone_digits', mcr_phone_digits($o->get_billing_phone()));
        $o->update_meta_data('_mcr_addr_key', mcr_order_addr_key($o));
        $o->save_meta_data();
    }
    if (count($orders) < 200) {
        update_option('mcr_order_index_v1', time(), false);
    }
});

/** Statuses that count as "this person already ordered". */
function mcr_real_order_statuses(): array
{
    $s = ['processing', 'completed', 'on-hold', 'refunded'];
    if (defined('MCR_WA_STATUS')) {
        $s[] = str_replace('wc-', '', MCR_WA_STATUS);
    }
    return $s;
}

/**
 * Has this person already ordered? Returns what matched: 'phone', 'email', 'address' or ''.
 */
function mcr_customer_seen(string $email, string $digits, string $addr, int $exclude = 0): string
{
    $base = ['status' => mcr_real_order_statuses(), 'type' => 'shop_order', 'limit' => 1, 'return' => 'ids', 'exclude' => $exclude ? [$exclude] : []];
    if ($digits !== '' && wc_get_orders($base + ['meta_query' => [['key' => '_mcr_phone_digits', 'value' => $digits]]])) {
        return 'phone';
    }
    if ($email !== '' && is_email($email) && wc_get_orders($base + ['billing_email' => $email])) {
        return 'email';
    }
    if ($addr !== '' && wc_get_orders($base + ['meta_query' => [['key' => '_mcr_addr_key', 'value' => $addr]]])) {
        return 'address';
    }
    return '';
}

/** Address key from checkout data. */
function mcr_checkout_addr_key(array $data): string
{
    $street = (string) ($data['shipping_address_1'] ?? '') ?: (string) ($data['billing_address_1'] ?? '');
    $street2 = (string) ($data['shipping_address_2'] ?? '') ?: (string) ($data['billing_address_2'] ?? '');
    $pc = (string) ($data['shipping_postcode'] ?? '') ?: (string) ($data['billing_postcode'] ?? '');
    $method = implode(',', (array) ($data['shipping_method'] ?? []));
    if (str_contains($method, 'pickup')) {
        return '';
    }
    return mcr_addr_key($street, $street2, $pc);
}

