<?php
/**
 * Delivery by driving distance from the store, and in-store pickup, as
 * WooCommerce shipping methods. Prices, limits and the free-delivery rule
 * come from MCR Gestion > Paramètres > Livraison.
 *
 * Distance: the customer's address is located (OpenStreetMap Nominatim, then
 * Photon), then the driving distance is asked from OSRM. If the road service
 * does not answer, the straight-line distance × the factor in the settings is
 * used. Results are cached, so each address is looked up once.
 */
defined('ABSPATH') || exit;

// ---------------------------------------------------------------- Pricing

/** Round to 0.1 km first, so the tier always matches the distance shown. */
function mcr_round_km(float $km): float
{
    return round($km, 1);
}

/**
 * Price for a distance and a cart subtotal.
 * Returns ['ok' => bool, 'km' => float, 'fee' => float, 'free' => bool].
 * Tiers: "from" included, "to" excluded, except the last tier whose "to" is included.
 */
function mcr_delivery_quote(float $raw_km, float $subtotal): array
{
    $d = mcr_setting('delivery');
    $km = mcr_round_km($raw_km);
    $max = (float) $d['max_km'];
    if ($km > $max) {
        return ['ok' => false, 'km' => $km, 'fee' => 0.0, 'free' => false];
    }
    $tiers = array_values(array_filter((array) $d['tiers'], fn($t) => isset($t['from_km'], $t['to_km'], $t['fee'])));
    usort($tiers, fn($a, $b) => $a['from_km'] <=> $b['from_km']);
    $fee = null;
    $last = count($tiers) - 1;
    foreach ($tiers as $i => $t) {
        $from = (float) $t['from_km'];
        $to = (float) $t['to_km'];
        if ($km >= $from && ($i === $last ? $km <= $to : $km < $to)) {
            $fee = (float) $t['fee'];
            break;
        }
    }
    if ($fee === null) {
        if (!$tiers) {
            return ['ok' => false, 'km' => $km, 'fee' => 0.0, 'free' => false];
        }
        $fee = (float) end($tiers)['fee'];
    }
    $free = $subtotal >= (float) $d['free_min_subtotal'] && $km <= (float) $d['free_radius_km'];
    return ['ok' => true, 'km' => $km, 'fee' => $free ? 0.0 : $fee, 'free' => $free];
}

function mcr_max_delivery_fee(): float
{
    $fees = array_map(fn($t) => (float) ($t['fee'] ?? 0), (array) mcr_setting('delivery', 'tiers'));
    return $fees ? max($fees) : 0.0;
}

function mcr_format_km(float $km): string
{
    $dec = fmod($km, 1.0) == 0.0 ? 0 : 1;
    return mcr_is_en() ? number_format($km, $dec) . ' km' : number_format($km, $dec, ',', ' ') . ' km';
}

// ---------------------------------------------------------------- Distance

function mcr_http_json(string $url, int $timeout = 6)
{
    $res = wp_remote_get($url, [
        'timeout' => $timeout,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'MCR-Gestion/' . MCR_VERSION . ' (' . wp_parse_url(home_url(), PHP_URL_HOST) . ')',
        ],
    ]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return null;
    }
    return json_decode(wp_remote_retrieve_body($res), true);
}

/** Locate an address. Returns ['lat' => , 'lon' => ] or null. Cached 30 days. */
function mcr_geocode(string $address): ?array
{
    $address = trim(preg_replace('/\s+/', ' ', $address));
    if ($address === '') {
        return null;
    }
    $key = 'mcr_geo_' . md5(strtolower($address));
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached['lat'] === null ? null : $cached;
    }
    $lat0 = (float) mcr_setting('store', 'lat');
    $lon0 = (float) mcr_setting('store', 'lon');
    $found = null;
    $answered = false;

    $d = mcr_http_json('https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format' => 'jsonv2',
        'limit' => 1,
        'countrycodes' => 'ca',
        'accept-language' => 'fr',
        'viewbox' => ($lon0 - 1.2) . ',' . ($lat0 + 0.8) . ',' . ($lon0 + 1.2) . ',' . ($lat0 - 0.8),
        'q' => $address,
    ]));
    if (is_array($d)) {
        $answered = true;
        if (!empty($d[0]['lat'])) {
            $found = ['lat' => (float) $d[0]['lat'], 'lon' => (float) $d[0]['lon']];
        }
    }
    if (!$found) {
        $d = mcr_http_json('https://photon.komoot.io/api/?' . http_build_query(['q' => $address, 'limit' => 1, 'lat' => $lat0, 'lon' => $lon0]));
        if (is_array($d)) {
            $answered = true;
            $f = $d['features'][0] ?? null;
            if ($f && ($f['properties']['countrycode'] ?? 'CA') === 'CA') {
                $found = ['lat' => (float) $f['geometry']['coordinates'][1], 'lon' => (float) $f['geometry']['coordinates'][0]];
            }
        }
    }
    if ($found) {
        set_transient($key, $found, 30 * DAY_IN_SECONDS);
    } elseif ($answered) {
        // A clean "not found": remember it for a short while only.
        set_transient($key, ['lat' => null, 'lon' => null], HOUR_IN_SECONDS);
    }
    return $found;
}

function mcr_straight_km(float $lat, float $lon): float
{
    $lat0 = deg2rad((float) mcr_setting('store', 'lat'));
    $lon0 = deg2rad((float) mcr_setting('store', 'lon'));
    $lat1 = deg2rad($lat);
    $lon1 = deg2rad($lon);
    $a = sin(($lat1 - $lat0) / 2) ** 2 + cos($lat0) * cos($lat1) * sin(($lon1 - $lon0) / 2) ** 2;
    return 6371 * 2 * asin(min(1, sqrt($a)));
}

/** Distance from the store, in km, using the method chosen in the settings. Cached 30 days. */
function mcr_distance_km(float $lat, float $lon): float
{
    $d = mcr_setting('delivery');
    $straight = mcr_straight_km($lat, $lon);
    if (($d['distance_method'] ?? 'road') !== 'road') {
        return $straight * (float) ($d['straight_line_factor'] ?: 1);
    }
    $key = 'mcr_road_' . md5(round($lat, 5) . ',' . round($lon, 5) . '|' . mcr_setting('store', 'lat') . ',' . mcr_setting('store', 'lon'));
    $cached = get_transient($key);
    if ($cached !== false) {
        return (float) $cached;
    }
    $r = mcr_http_json(sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=false',
        (float) mcr_setting('store', 'lon'),
        (float) mcr_setting('store', 'lat'),
        $lon,
        $lat
    ), 8);
    if (isset($r['routes'][0]['distance'])) {
        $km = $r['routes'][0]['distance'] / 1000;
        set_transient($key, $km, 30 * DAY_IN_SECONDS);
        return $km;
    }
    return $straight * (float) ($d['straight_line_factor'] ?: 1.3);
}

/** Key used to match coordinates picked in the address suggestions to the address sent at checkout. */
function mcr_address_key(string $street, string $city): string
{
    $s = remove_accents(strtolower($street . '|' . $city));
    return preg_replace('/[^a-z0-9|]+/', '', $s);
}

/** Coordinates of a shipping destination: the suggestion the customer picked, else a lookup. */
function mcr_destination_coords(array $dest): ?array
{
    $street = trim((string) ($dest['address_1'] ?? $dest['address'] ?? ''));
    $city = trim((string) ($dest['city'] ?? ''));
    $postcode = trim((string) ($dest['postcode'] ?? ''));
    if ($street === '' && $postcode === '') {
        return null;
    }
    $picked = WC()->session ? WC()->session->get('mcr_picked_place') : null;
    if (is_array($picked) && ($picked['key'] ?? '') === mcr_address_key($street, $city)) {
        return ['lat' => (float) $picked['lat'], 'lon' => (float) $picked['lon']];
    }
    $state = (string) ($dest['state'] ?? 'QC');
    $full = implode(', ', array_filter([$street, $city, $state, $postcode, 'Canada']));
    $found = mcr_geocode($full);
    if (!$found && $postcode !== '' && $street !== '') {
        // Postal codes are sometimes missing from OpenStreetMap: retry without it.
        $found = mcr_geocode(implode(', ', array_filter([$street, $city, $state, 'Canada'])));
    }
    return $found;
}

// The checkout sends the place picked in the address suggestions; keep it in the session.
add_action('woocommerce_checkout_update_order_review', function ($post_data) {
    parse_str((string) $post_data, $data);
    mcr_remember_picked_place($data);
});
add_action('woocommerce_checkout_process', function () {
    mcr_remember_picked_place($_POST); // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the checkout nonce.
});
function mcr_remember_picked_place(array $data): void
{
    if (!WC()->session || empty($data['mcr_lat']) || empty($data['mcr_lon']) || empty($data['mcr_place_key'])) {
        return;
    }
    $lat = (float) $data['mcr_lat'];
    $lon = (float) $data['mcr_lon'];
    if ($lat < 40 || $lat > 63 || $lon < -80 || $lon > -55) {
        return; // Not in Quebec: ignore.
    }
    WC()->session->set('mcr_picked_place', ['lat' => $lat, 'lon' => $lon, 'key' => sanitize_text_field(wp_unslash($data['mcr_place_key']))]);
}

// Make the shipping package change when the picked place changes, so prices are recalculated.
add_filter('woocommerce_cart_shipping_packages', function ($packages) {
    $picked = WC()->session ? WC()->session->get('mcr_picked_place') : null;
    foreach ($packages as &$p) {
        $p['mcr_place'] = $picked ? $picked['key'] . '@' . $picked['lat'] . ',' . $picked['lon'] : '';
    }
    return $packages;
});

// ---------------------------------------------------------------- Shipping methods

add_action('woocommerce_shipping_init', function () {
    class MCR_Shipping_Delivery extends WC_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id = 'mcr_delivery';
            $this->instance_id = absint($instance_id);
            $this->method_title = 'Livraison MCR (selon la distance)';
            $this->method_description = 'Prix selon la distance en voiture depuis le magasin. Les paliers se règlent dans MCR Gestion > Paramètres > Livraison.';
            $this->supports = ['shipping-zones', 'instance-settings'];
            $this->instance_form_fields = [];
            $this->init_settings();
            $this->title = mcr_is_en() ? 'Delivery' : 'Livraison';
        }

        public function is_available($package)
        {
            return (bool) mcr_setting('delivery', 'enabled') && parent::is_available($package);
        }

        public function calculate_shipping($package = [])
        {
            $en = mcr_is_en();
            $subtotal = 0.0;
            foreach ($package['contents'] ?? [] as $item) {
                $subtotal += (float) ($item['line_total'] ?? 0); // after discounts, before taxes
            }
            $coords = mcr_destination_coords($package['destination'] ?? []);
            if (!$coords) {
                if (trim((string) ($package['destination']['address_1'] ?? '')) === '') {
                    mcr_set_delivery_status(null);
                    return; // No street yet: the price appears once the address is entered.
                }
                // Address not found: charge the highest tier, the team confirms the fee by phone.
                mcr_set_delivery_status(['state' => 'unknown']);
                $this->add_rate([
                    'id' => $this->get_rate_id(),
                    'label' => $en ? 'Delivery (fee to be confirmed)' : 'Livraison (frais à confirmer)',
                    'cost' => function_exists('mcr_rw_cart_free_delivery') && mcr_rw_cart_free_delivery() ? 0 : mcr_max_delivery_fee(),
                    'package' => $package,
                    'meta_data' => [$en ? 'Distance' : 'Distance' => $en ? 'address not found, to be confirmed' : 'adresse non trouvée, à confirmer'],
                ]);
                return;
            }
            $km = mcr_distance_km($coords['lat'], $coords['lon']);
            $q = mcr_delivery_quote($km, $subtotal);
            mcr_set_delivery_status(['state' => $q['ok'] ? 'ok' : 'too_far', 'km' => $q['km']]);
            if (!$q['ok']) {
                return;
            }
            $gift = function_exists('mcr_rw_cart_free_delivery') && mcr_rw_cart_free_delivery(); // welcome-wheel prize
            if ($gift) {
                $q['fee'] = 0;
            }
            $label = $q['free'] || $gift
                ? ($en ? 'Free delivery' : 'Livraison gratuite') . ($gift && !$q['free'] ? ($en ? ' (gift)' : ' (cadeau)') : '')
                : ($en ? 'Delivery' : 'Livraison');
            $this->add_rate([
                'id' => $this->get_rate_id(),
                'label' => $label . ' (' . mcr_format_km($q['km']) . ')',
                'cost' => $q['fee'],
                'package' => $package,
                'meta_data' => ['Distance' => mcr_format_km($q['km'])],
            ]);
        }
    }

    class MCR_Shipping_Pickup extends WC_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id = 'mcr_pickup';
            $this->instance_id = absint($instance_id);
            $this->method_title = 'Retrait en magasin MCR';
            $this->method_description = 'Gratuit. Les délais et instructions se règlent dans MCR Gestion > Paramètres > Retrait.';
            $this->supports = ['shipping-zones', 'instance-settings'];
            $this->instance_form_fields = [];
            $this->init_settings();
            $this->title = mcr_is_en() ? 'In-store pickup' : 'Retrait en magasin';
        }

        public function is_available($package)
        {
            return (bool) mcr_setting('pickup', 'enabled') && parent::is_available($package);
        }

        public function calculate_shipping($package = [])
        {
            $this->add_rate([
                'id' => $this->get_rate_id(),
                'label' => (mcr_is_en() ? 'In-store pickup' : 'Retrait en magasin') . ' – ' . mcr_setting('store', 'street'),
                'cost' => 0,
                'package' => $package,
            ]);
        }
    }
});

add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['mcr_delivery'] = 'MCR_Shipping_Delivery';
    $methods['mcr_pickup'] = 'MCR_Shipping_Pickup';
    return $methods;
});

// Last distance result, kept in the session (shipping prices are cached between page loads).
function mcr_set_delivery_status(?array $status): void
{
    if (WC()->session) {
        WC()->session->set('mcr_delivery_status', $status);
    }
}

// Explain why delivery is missing (too far) under the shipping choices.
function mcr_delivery_notice_html(): string
{
    $s = WC()->session ? WC()->session->get('mcr_delivery_status') : null;
    if (!$s || $s['state'] !== 'too_far') {
        return '';
    }
    $max = mcr_format_km((float) mcr_setting('delivery', 'max_km'));
    $msg = mcr_is_en()
        ? sprintf('Your address is %s from the store: we deliver up to %s. You can pick up your order in store.', mcr_format_km($s['km']), $max)
        : sprintf('Votre adresse est à %s du magasin : nous livrons jusqu’à %s. Vous pouvez retirer votre commande en magasin.', mcr_format_km($s['km']), $max);
    return '<p class="mcr-ship-note">' . esc_html($msg) . '</p>';
}
add_action('woocommerce_after_shipping_rate', function ($rate) {
    if ($rate->get_method_id() === 'mcr_pickup') {
        echo mcr_delivery_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
    }
});
foreach (['woocommerce_no_shipping_available_html', 'woocommerce_cart_no_shipping_available_html'] as $hook) {
    add_filter($hook, function ($html) {
        $note = mcr_delivery_notice_html();
        return $note !== '' ? $note : $html;
    });
}

// Show delivery or pickup details under the shipping choice.
add_action('woocommerce_after_shipping_rate', function ($rate) {
    $en = mcr_is_en();
    if ($rate->get_method_id() === 'mcr_delivery') {
        $txt = (string) mcr_setting('delivery', $en ? 'eta_en' : 'eta_fr');
    } elseif ($rate->get_method_id() === 'mcr_pickup') {
        $mins = (int) mcr_setting('pickup', 'prep_minutes');
        $txt = $en ? sprintf('Ready in about %d minutes during store hours.', $mins) : sprintf('Prête en environ %d minutes pendant les heures d’ouverture.', $mins);
    } else {
        return;
    }
    if ($txt !== '') {
        echo '<small class="mcr-ship-hint">' . esc_html($txt) . '</small>';
    }
}, 5);

// ---------------------------------------------------------------- Checkout address suggestions

add_action('woocommerce_after_checkout_billing_form', function () {
    echo '<input type="hidden" name="mcr_lat" id="mcr_lat" value=""><input type="hidden" name="mcr_lon" id="mcr_lon" value=""><input type="hidden" name="mcr_place_key" id="mcr_place_key" value="">';
});

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
        return;
    }
    wp_enqueue_script('mcr-checkout', MCR_URL . 'assets/checkout.js', ['jquery', 'wc-checkout'], MCR_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    wp_localize_script('mcr-checkout', 'MCR_CHECKOUT', [
        'lat' => (float) mcr_setting('store', 'lat'),
        'lon' => (float) mcr_setting('store', 'lon'),
        'lang' => mcr_lang(),
        'state' => 'QC',
        'searching' => mcr_is_en() ? 'Searching…' : 'Recherche…',
        'none' => mcr_is_en() ? 'No address found. Keep typing or enter it in full.' : 'Aucune adresse trouvée. Continuez à écrire ou entrez-la au complet.',
    ]);
});

// ---------------------------------------------------------------- Order details

add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    $mode = '';
    foreach ($order->get_shipping_methods() as $m) {
        if ($m->get_method_id() === 'mcr_delivery') {
            $mode = 'delivery';
        } elseif ($m->get_method_id() === 'mcr_pickup' || $m->get_method_id() === 'local_pickup') {
            $mode = 'pickup';
        }
    }
    if ($mode) {
        $order->update_meta_data('_mcr_fulfilment', $mode);
    }
    $picked = WC()->session ? WC()->session->get('mcr_picked_place') : null;
    if ($mode === 'delivery' && is_array($picked)) {
        $order->update_meta_data('_mcr_delivery_coords', $picked['lat'] . ',' . $picked['lon']);
    }
}, 20);

function mcr_order_fulfilment(WC_Order $order): string
{
    $mode = (string) $order->get_meta('_mcr_fulfilment');
    if ($mode) {
        return $mode;
    }
    foreach ($order->get_shipping_methods() as $m) {
        if (in_array($m->get_method_id(), ['mcr_pickup', 'local_pickup'], true)) {
            return 'pickup';
        }
    }
    return $order->get_shipping_methods() ? 'delivery' : '';
}

// ---------------------------------------------------------------- One-time setup

/**
 * Put the two methods in a "Québec" zone (and pickup everywhere else), once.
 * Existing zones and methods are left as they are; review them in
 * WooCommerce > Réglages > Expédition.
 */
add_action('admin_init', function () {
    if (get_option('mcr_shipping_setup_v1') || !current_user_can('manage_woocommerce') || !class_exists('WC_Shipping_Zones')) {
        return;
    }
    $has = fn(WC_Shipping_Zone $z, string $id) => (bool) array_filter($z->get_shipping_methods(), fn($m) => $m->id === $id);
    $quebec = null;
    foreach (WC_Shipping_Zones::get_zones('admin') as $data) {
        $zone = new WC_Shipping_Zone($data['id']);
        foreach ($zone->get_zone_locations() as $loc) {
            if (($loc->type === 'state' && $loc->code === 'CA:QC') || ($loc->type === 'country' && $loc->code === 'CA')) {
                $quebec = $zone;
                break 2;
            }
        }
    }
    if (!$quebec) {
        $quebec = new WC_Shipping_Zone();
        $quebec->set_zone_name('Québec');
        $quebec->set_zone_order(0);
        $quebec->add_location('CA:QC', 'state');
        $quebec->save();
    }
    if (!$has($quebec, 'mcr_delivery')) {
        $quebec->add_shipping_method('mcr_delivery');
    }
    if (!$has($quebec, 'mcr_pickup')) {
        $quebec->add_shipping_method('mcr_pickup');
    }
    $rest = new WC_Shipping_Zone(0);
    if (!$has($rest, 'mcr_pickup')) {
        $rest->add_shipping_method('mcr_pickup');
    }
    update_option('mcr_shipping_setup_v1', time(), false);
});

// A few newer WooCommerce labels have no French translation yet.
add_filter('gettext_woocommerce', function ($translation, $text) {
    static $fr = ['Shipment' => 'Mode de réception', 'Shipment %d' => 'Envoi %d', 'Shipping' => 'Livraison'];
    if (is_admin() || mcr_is_en()) {
        return $translation === 'Shipment' ? 'Delivery or pickup' : $translation;
    }
    return ($translation === $text && isset($fr[$text])) ? $fr[$text] : $translation;
}, 10, 2);

// ---------------------------------------------------------------- Address check (delivery page)

/**
 * Public price check for the delivery page: address in, distance and fee out.
 * Limited to 20 checks per visitor every 10 minutes; results are cached by mcr_geocode().
 */
function mcr_ajax_delivery_quote(): void
{
    $ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $key = 'mcr_dq_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= 20) {
        wp_send_json_error(['message' => 'limit'], 429);
    }
    set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
    $lat = isset($_REQUEST['lat']) ? (float) $_REQUEST['lat'] : 0.0; // phpcs:ignore WordPress.Security.NonceVerification -- public, read-only.
    $lon = isset($_REQUEST['lon']) ? (float) $_REQUEST['lon'] : 0.0; // phpcs:ignore WordPress.Security.NonceVerification
    if (!$lat || !$lon) {
        $address = sanitize_text_field(wp_unslash($_REQUEST['address'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if (mb_strlen($address) < 5) {
            wp_send_json_error(['message' => 'short']);
        }
        $found = mcr_geocode($address . (stripos($address, 'qc') === false && stripos($address, 'québec') === false ? ', QC, Canada' : ''));
        if (!$found) {
            wp_send_json_error(['message' => 'notfound']);
        }
        [$lat, $lon] = [$found['lat'], $found['lon']];
    }
    if ($lat < 40 || $lat > 63 || $lon < -80 || $lon > -55) {
        wp_send_json_error(['message' => 'notfound']);
    }
    $km = mcr_distance_km($lat, $lon);
    $q = mcr_delivery_quote($km, 0);
    $free = (float) mcr_setting('delivery', 'free_min_subtotal');
    wp_send_json_success([
        'km' => $q['km'],
        'km_label' => mcr_format_km($q['km']),
        'ok' => $q['ok'],
        'fee' => $q['ok'] ? round($q['fee'], 2) : null,
        'free_from' => $free,
        'free_eligible' => $q['ok'] && $q['km'] <= (float) mcr_setting('delivery', 'free_radius_km'),
    ]);
}
add_action('wp_ajax_mcr_delivery_quote', 'mcr_ajax_delivery_quote');
add_action('wp_ajax_nopriv_mcr_delivery_quote', 'mcr_ajax_delivery_quote');

// In-store pickup is taxed at the store's address (Québec), like WooCommerce's own local pickup.
add_filter('woocommerce_local_pickup_methods', function ($methods) {
    $methods[] = 'mcr_pickup';
    return $methods;
});
