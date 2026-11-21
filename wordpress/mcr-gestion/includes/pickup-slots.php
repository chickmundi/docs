<?php
/**
 * Pickup time slots, built from the store's opening hours
 * (MCR Gestion > Paramètres > Heures du magasin / Retrait en magasin).
 * - The first slot starts after the preparation time (rounded up to the slot length).
 * - The last slot ends at closing time. Closed days have no slots.
 * - Optional maximum number of orders per slot.
 * The chosen slot is saved on the order (_mcr_pickup_slot = "Y-m-d H:i") and shown
 * in the confirmation, emails, admin and the MCR Commandes app.
 */
defined('ABSPATH') || exit;

/** Store time zone: WordPress's own when it is a named zone, else Quebec time. */
function mcr_store_tz(): DateTimeZone
{
    $tz = wp_timezone_string();
    return new DateTimeZone($tz && strpos($tz, '/') !== false ? $tz : 'America/Toronto');
}

function mcr_slots_enabled(): bool
{
    return (bool) mcr_setting('pickup', 'enabled') && (bool) mcr_setting('pickup', 'slots_enabled');
}

/** Orders already booked per slot ("Y-m-d H:i" => count), for the coming days. */
function mcr_slot_bookings(): array
{
    $cap = (int) mcr_setting('pickup', 'slot_capacity');
    if ($cap <= 0) {
        return [];
    }
    $orders = wc_get_orders([
        'limit' => -1,
        'return' => 'ids',
        'status' => ['mcr-whatsapp', 'processing', 'on-hold', 'pending', 'mcr-preparing', 'mcr-ready', 'mcr-out', 'completed'],
        'meta_key' => '_mcr_pickup_slot',
        'meta_compare' => '>=',
        'meta_value' => (new DateTimeImmutable('now', mcr_store_tz()))->format('Y-m-d'),
        'date_created' => '>' . (time() - 10 * DAY_IN_SECONDS),
    ]);
    $count = [];
    foreach ($orders as $id) {
        $slot = (string) wc_get_order($id)->get_meta('_mcr_pickup_slot');
        if ($slot) {
            $count[$slot] = ($count[$slot] ?? 0) + 1;
        }
    }
    return $count;
}

/**
 * Available slots, grouped by day:
 * [ ['date' => 'Y-m-d', 'label' => 'Aujourd’hui', 'slots' => [['value' => 'Y-m-d H:i', 'label' => '14 h – 15 h', 'full' => false], …]], … ]
 */
function mcr_pickup_slots(?int $now = null): array
{
    $tz = mcr_store_tz();
    $now = $now ?? time();
    $len = max(15, (int) (mcr_setting('pickup', 'slot_minutes') ?: 60));
    $prep = max(0, (int) mcr_setting('pickup', 'prep_minutes'));
    $ahead = max(1, min(14, (int) (mcr_setting('pickup', 'days_ahead') ?: 4)));
    $cap = (int) mcr_setting('pickup', 'slot_capacity');
    $hours = (array) mcr_setting('hours');
    $booked = mcr_slot_bookings();
    $earliest = $now + $prep * 60;
    $en = mcr_is_en();
    $days = [];
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    for ($i = 0, $found = 0; $i < 21 && $found < $ahead; $i++) {
        $day = $today->modify("+{$i} day");
        $h = $hours[(int) $day->format('N')] ?? null;
        if (!$h || !empty($h['closed']) || empty($h['open']) || empty($h['close'])) {
            continue;
        }
        [$oh, $om] = array_map('intval', explode(':', $h['open']));
        [$ch, $cm] = array_map('intval', explode(':', $h['close']));
        $open = $day->setTime($oh, $om);
        $close = $day->setTime($ch, $cm);
        if ($close <= $open) {
            $close = $close->modify('+1 day');
        }
        $slots = [];
        for ($s = $open; $s < $close; $s = $s->modify("+{$len} minutes")) {
            $end = min($s->modify("+{$len} minutes"), $close);
            // A shorter last slot is kept if it lasts at least 30 minutes (e.g. 21 h 30 – 22 h).
            if ($s->getTimestamp() < $earliest || ($end->getTimestamp() - $s->getTimestamp()) < min(30, $len) * 60) {
                continue;
            }
            $value = $s->format('Y-m-d H:i');
            $slots[] = [
                'value' => $value,
                'label' => mcr_slot_time($s, $en) . ' – ' . mcr_slot_time($end, $en),
                'full' => $cap > 0 && ($booked[$value] ?? 0) >= $cap,
            ];
        }
        if (!$slots) {
            continue;
        }
        $found++;
        $days[] = ['date' => $day->format('Y-m-d'), 'label' => mcr_slot_day_label($day, $today, $en), 'slots' => $slots];
    }
    return $days;
}

function mcr_slot_time(DateTimeImmutable $d, bool $en): string
{
    $h = (int) $d->format('G');
    $m = (int) $d->format('i');
    return $en ? $d->format($m ? 'g:i a' : 'g a') : ($m ? sprintf('%d h %02d', $h, $m) : $h . ' h');
}

function mcr_slot_day_label(DateTimeImmutable $day, DateTimeImmutable $today, bool $en): string
{
    $diff = (int) $today->diff($day)->format('%a');
    if ($diff === 0) {
        return $en ? 'Today' : 'Aujourd’hui';
    }
    if ($diff === 1) {
        return $en ? 'Tomorrow' : 'Demain';
    }
    $d = $en ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] : ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    $mo = $en ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] : ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $d[(int) $day->format('N') - 1] . ' ' . $day->format('j') . ' ' . $mo[(int) $day->format('n') - 1];
}

/** "Samedi 22 novembre, 14 h – 15 h" for a saved slot. */
function mcr_slot_label(string $value, ?bool $en = null): string
{
    $en = $en ?? mcr_is_en();
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, mcr_store_tz());
    if (!$d) {
        return $value;
    }
    $len = max(15, (int) (mcr_setting('pickup', 'slot_minutes') ?: 60));
    $days = $en ? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] : ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $months = $en ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] : ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $day = $days[(int) $d->format('N') - 1];
    $date = $en ? $day . ', ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('j') : ucfirst($day) . ' ' . $d->format('j') . ' ' . $months[(int) $d->format('n') - 1];
    $end = $d->modify("+{$len} minutes");
    $h = (array) (mcr_setting('hours')[(int) $d->format('N')] ?? []);
    if (!empty($h['close'])) {
        [$ch, $cm] = array_map('intval', explode(':', $h['close']));
        $end = min($end, $d->setTime($ch, $cm) > $d ? $d->setTime($ch, $cm) : $end);
    }
    return $date . ', ' . mcr_slot_time($d, $en) . ' – ' . mcr_slot_time($end, $en);
}

/** Is this value one of the slots a customer can choose right now? */
function mcr_slot_is_valid(string $value): bool
{
    foreach (mcr_pickup_slots() as $day) {
        foreach ($day['slots'] as $s) {
            if ($s['value'] === $value && !$s['full']) {
                return true;
            }
        }
    }
    return false;
}

// ---------------------------------------------------------------- Checkout

/** Slot picker HTML, shown under the "Retrait en magasin" card when pickup is chosen. */
function mcr_slot_picker_html(): string
{
    if (!mcr_slots_enabled()) {
        return '';
    }
    $en = mcr_is_en();
    $days = mcr_pickup_slots();
    // Earliest free slot, offered as a one-tap choice.
    $first = null;
    foreach ($days as $d) {
        foreach ($d['slots'] as $s) {
            if (!$s['full']) {
                $first = ['value' => $s['value'], 'label' => $d['label'] . ', ' . $s['label']];
                break 2;
            }
        }
    }
    $periods = [
        'am' => [$en ? 'Morning' : 'Matin', 0, 12],
        'pm' => [$en ? 'Afternoon' : 'Après-midi', 12, 17],
        'ev' => [$en ? 'Evening' : 'Soir', 17, 24],
    ];
    ob_start();
    ?>
    <div class="mcr-slots" data-mcr-slots hidden>
      <input type="hidden" name="mcr_pickup_slot" value="" data-slot-input>
      <?php if (!$days || !$first) : ?>
        <p class="mcr-slots__none"><?php echo esc_html($en ? 'No pickup time is available right now. Please call us.' : 'Aucune plage de retrait n’est disponible pour le moment. Appelez-nous.'); ?></p>
      <?php else : ?>
        <div class="mcr-slots__summary" data-slot-summary hidden>
          <span class="mcr-slots__summary-ic" aria-hidden="true">🕒</span>
          <span><small><?php echo esc_html($en ? 'Pickup time' : 'Heure de retrait'); ?></small><strong data-slot-chosen></strong></span>
          <button type="button" class="mcr-slots__edit" data-slot-edit><?php echo esc_html($en ? 'Change' : 'Modifier'); ?></button>
        </div>
        <div class="mcr-slots__body" data-slot-body>
          <p class="mcr-slots__title"><?php echo esc_html($en ? 'Pickup time' : 'Heure de retrait'); ?> <abbr class="required" title="<?php echo esc_attr($en ? 'required' : 'obligatoire'); ?>">*</abbr></p>
          <button type="button" class="mcr-slots__asap" data-slot="<?php echo esc_attr($first['value']); ?>" data-slot-label="<?php echo esc_attr($first['label']); ?>">
            <span>⚡</span><span><strong><?php echo esc_html($en ? 'As soon as possible' : 'Le plus tôt possible'); ?></strong><small><?php echo esc_html($first['label']); ?></small></span>
          </button>
          <details class="mcr-slots__more">
            <summary><?php echo esc_html($en ? 'Choose another time' : 'Choisir une autre heure'); ?></summary>
            <div class="mcr-slots__days" role="tablist" aria-label="<?php echo esc_attr($en ? 'Day' : 'Jour'); ?>">
              <?php foreach ($days as $i => $d) : ?>
                <button type="button" role="tab" class="mcr-slots__day" data-day="<?php echo esc_attr($d['date']); ?>" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"><?php echo esc_html($d['label']); ?></button>
              <?php endforeach; ?>
            </div>
            <?php foreach ($days as $i => $d) :
                $groups = [];
                foreach ($d['slots'] as $s) {
                    $h = (int) substr($s['value'], 11, 2);
                    foreach ($periods as $key => [, $from, $to]) {
                        if ($h >= $from && $h < $to) {
                            $groups[$key][] = $s;
                        }
                    }
                }
                $firstKey = array_key_first($groups);
                ?>
              <div class="mcr-slots__panel" data-day-panel="<?php echo esc_attr($d['date']); ?>" <?php echo $i === 0 ? '' : 'hidden'; ?>>
                <div class="mcr-slots__periods">
                  <?php foreach ($groups as $key => $list) : ?>
                    <button type="button" class="mcr-slots__period" data-period="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $key === $firstKey ? 'true' : 'false'; ?>"><?php echo esc_html($periods[$key][0]); ?></button>
                  <?php endforeach; ?>
                </div>
                <?php foreach ($groups as $key => $list) : ?>
                  <div class="mcr-slots__times" data-period-panel="<?php echo esc_attr($key); ?>" <?php echo $key === $firstKey ? '' : 'hidden'; ?>>
                    <?php foreach ($list as $s) : $start = trim(explode('–', $s['label'])[0]); ?>
                      <button type="button" class="mcr-slots__slot" data-slot="<?php echo esc_attr($s['value']); ?>" data-slot-label="<?php echo esc_attr($d['label'] . ', ' . $s['label']); ?>" aria-label="<?php echo esc_attr($s['label']); ?>" aria-pressed="false" <?php disabled($s['full']); ?>><?php echo esc_html($start); ?></button>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </details>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

// Put the picker right under the delivery / pickup cards.
add_filter('woocommerce_form_field_mcr_mode', function ($html) {
    $picker = mcr_slot_picker_html();
    if ($picker === '') {
        return $html;
    }
    $pos = strrpos($html, '</div>');
    return $pos === false ? $html . $picker : substr($html, 0, $pos) . $picker . substr($html, $pos);
}, 20);

add_action('woocommerce_after_checkout_validation', function ($data, WP_Error $errors) {
    if (!mcr_slots_enabled() || !function_exists('mcr_posted_pickup') || !mcr_posted_pickup()) {
        return;
    }
    $slot = sanitize_text_field(wp_unslash($_POST['mcr_pickup_slot'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the checkout nonce.
    $en = mcr_is_en();
    if ($slot === '') {
        $errors->add('mcr_pickup_slot_required', $en ? 'Please choose a pickup time.' : 'Choisissez une heure de retrait.');
    } elseif (!mcr_slot_is_valid($slot)) {
        $errors->add('mcr_pickup_slot_invalid', $en ? 'This pickup time is no longer available. Please choose another one.' : 'Cette plage de retrait n’est plus disponible. Choisissez-en une autre.');
    }
}, 30, 2);

add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    if (!mcr_slots_enabled() || !function_exists('mcr_posted_pickup') || !mcr_posted_pickup()) {
        return;
    }
    $slot = sanitize_text_field(wp_unslash($_POST['mcr_pickup_slot'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    if ($slot !== '') {
        $order->update_meta_data('_mcr_pickup_slot', $slot);
    }
}, 25);

// ---------------------------------------------------------------- Display

/** Slot text for an order, in the order's language when known. */
function mcr_order_slot_text(WC_Order $order, ?bool $en = null): string
{
    $slot = (string) $order->get_meta('_mcr_pickup_slot');
    if ($slot === '') {
        return '';
    }
    $en = $en ?? ($order->get_meta('_mcr_lang') === 'en');
    return mcr_slot_label($slot, $en);
}

// Thank-you page and "My account" order view.
add_action('woocommerce_order_details_after_order_table', function ($order) {
    if ($order instanceof WC_Order && ($t = mcr_order_slot_text($order, mcr_is_en()))) {
        printf('<p class="mcr-slot-note"><strong>%s</strong> %s<br><small>%s</small></p>', esc_html(mcr_is_en() ? 'Pickup time:' : 'Heure de retrait :'), esc_html($t), esc_html(mcr_setting('store', 'street') . ', ' . mcr_setting('store', 'city')));
    }
});
add_action('woocommerce_thankyou', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order && ($t = mcr_order_slot_text($order, mcr_is_en()))) {
        printf('<div class="mcr-slot-banner"><strong>%s</strong><span>%s</span></div>', esc_html(mcr_is_en() ? 'Your pickup time' : 'Votre heure de retrait'), esc_html($t));
    }
}, 5);

// Emails (customer and store).
add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin, $plain_text) {
    if (!$order instanceof WC_Order || !($t = mcr_order_slot_text($order, $sent_to_admin ? false : null))) {
        return;
    }
    $label = ($order->get_meta('_mcr_lang') === 'en' && !$sent_to_admin) ? 'Pickup time' : 'Heure de retrait';
    echo $plain_text ? "\n{$label} : {$t}\n" : '<p style="margin:0 0 16px;padding:12px 14px;background:#e7eee5;border-radius:8px"><strong>' . esc_html($label) . ' :</strong> ' . esc_html($t) . '</p>'; // phpcs:ignore
}, 5, 3);

// Admin order screen.
add_action('woocommerce_admin_order_data_after_shipping_address', function ($order) {
    if ($order instanceof WC_Order && ($t = mcr_order_slot_text($order, false))) {
        printf('<p style="padding:10px;background:#e7eee5;border-radius:6px"><strong>Heure de retrait :</strong><br>%s</p>', esc_html($t));
    }
});

// "Prête au retrait" email mentions the slot.
add_filter('mcr_ready_email_lines', function ($lines, WC_Order $order, bool $en) {
    if ($t = mcr_order_slot_text($order, $en)) {
        array_splice($lines, 2, 0, [($en ? 'Your pickup time: ' : 'Votre heure de retrait : ') . $t]);
    }
    return $lines;
}, 10, 3);

// One time: switch slots on (the store asked for them), 4 days ahead, no limit per slot.
add_action('init', function () {
    if (get_option('mcr_slots_v1')) {
        return;
    }
    $p = get_option('mcr_pickup', []);
    $p = is_array($p) ? $p : [];
    $p['slots_enabled'] = true;
    $p['slot_minutes'] = (int) ($p['slot_minutes'] ?? 60) ?: 60;
    $p['days_ahead'] = (int) ($p['days_ahead'] ?? 4) ?: 4;
    $p['slot_capacity'] = (int) ($p['slot_capacity'] ?? 0);
    update_option('mcr_pickup', $p);
    update_option('mcr_slots_v1', 1, false);
});
