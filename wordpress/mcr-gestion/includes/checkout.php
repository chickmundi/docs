<?php
/**
 * Simpler cart and checkout:
 * - no coupons (switch in MCR Gestion > Paramètres > Commerce)
 * - one address only (billing = delivery address)
 * - fields in a short, natural order: name, phone, email, then
 *   "Livraison ou retrait"; the address is asked only for delivery
 * - no shipping calculator on the cart page (the price comes at checkout)
 */
defined('ABSPATH') || exit;

// ---------------------------------------------------------------- Coupons

add_filter('woocommerce_coupons_enabled', fn() => (bool) mcr_setting('store', 'coupons_enabled'));

// ---------------------------------------------------------------- Cart and checkout options

add_filter('pre_option_woocommerce_ship_to_destination', fn() => 'billing_only');
add_filter('pre_option_woocommerce_enable_shipping_calc', fn() => 'no');

/** Is in-store pickup the shipping choice sent with the checkout form? */
function mcr_posted_pickup(): bool
{
    $methods = (array) ($_POST['shipping_method'] ?? []); // phpcs:ignore WordPress.Security.NonceVerification -- read during WooCommerce's own checkout.
    foreach ($methods as $m) {
        if (str_starts_with((string) $m, 'mcr_pickup') || str_starts_with((string) $m, 'local_pickup')) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------- Fields

add_filter('woocommerce_checkout_fields', function ($fields) {
    $en = mcr_is_en();
    $b = &$fields['billing'];
    unset($b['billing_company']);

    $set = function (string $key, int $prio, string $class, array $extra = []) use (&$b) {
        if (!isset($b[$key])) {
            return;
        }
        $b[$key]['priority'] = $prio;
        $b[$key]['class'] = array_merge(array_diff((array) ($b[$key]['class'] ?? []), ['form-row-first', 'form-row-last', 'form-row-wide']), [$class]);
        $b[$key] = array_merge($b[$key], $extra);
    };

    $b['billing_mcr_step1'] = ['type' => 'mcr_heading', 'label' => $en ? 'Your details' : 'Vos coordonnées', 'priority' => 1, 'step' => 1, 'required' => false];
    $set('billing_first_name', 10, 'form-row-first', ['autocomplete' => 'given-name']);
    $set('billing_last_name', 20, 'form-row-last', ['autocomplete' => 'family-name']);
    $set('billing_phone', 30, 'form-row-first', [
        'label' => $en ? 'Phone' : 'Téléphone',
        'placeholder' => '819 555-0123',
        'required' => true,
        'description' => '',
    ]);
    $set('billing_email', 40, 'form-row-last', ['label' => $en ? 'Email' : 'Courriel', 'placeholder' => $en ? 'you@example.com' : 'vous@exemple.com']);

    $b['billing_mcr_mode'] = ['type' => 'mcr_mode', 'priority' => 45, 'required' => false, 'class' => ['form-row-wide']];

    $set('billing_address_1', 50, 'form-row-wide', [
        'label' => $en ? 'Delivery address' : 'Adresse de livraison',
        'placeholder' => $en ? 'Start typing your address…' : 'Commencez à écrire votre adresse…',
    ]);
    $set('billing_address_2', 55, 'form-row-first', [
        'label' => $en ? 'Apt., unit' : 'App., bureau',
        'label_class' => [],
        'placeholder' => '',
    ]);
    $set('billing_city', 60, 'form-row-last');
    $set('billing_postcode', 65, 'form-row-first', ['label' => $en ? 'Postal code' : 'Code postal']);
    $set('billing_state', 70, 'form-row-last');
    $set('billing_country', 80, 'form-row-wide');
    foreach (['billing_address_1', 'billing_address_2', 'billing_city', 'billing_postcode', 'billing_state', 'billing_country'] as $k) {
        if (isset($b[$k])) {
            $b[$k]['class'][] = 'mcr-addr';
        }
    }
    // One province and one country: kept for the address, not shown.
    foreach (['billing_state', 'billing_country'] as $k) {
        if (isset($b[$k])) {
            $b[$k]['class'][] = 'mcr-hide';
        }
    }

    if (isset($fields['order']['order_comments'])) {
        $fields['order']['order_comments']['label'] = $en ? 'Note for your order' : 'Note pour votre commande';
        $fields['order']['order_comments']['placeholder'] = $en ? 'Door code, buzzer, a substitute you accept…' : 'Code de porte, sonnette, produit de remplacement accepté…';
        $fields['order']['order_comments']['custom_attributes'] = ['rows' => 2];
    }
    return $fields;
}, 20);

// Province and country are fixed (Quebec, Canada) for this store.
add_filter('default_checkout_billing_state', fn($v) => $v ?: 'QC');
add_filter('default_checkout_billing_country', fn($v) => $v ?: 'CA');

add_filter('woocommerce_form_field_mcr_heading', function ($field, $key, $args) {
    return sprintf('<h3 class="mcr-step"><span>%d</span>%s</h3>', (int) ($args['step'] ?? 1), esc_html($args['label'] ?? ''));
}, 10, 3);

add_filter('woocommerce_form_field_mcr_mode', function ($field, $key, $args) {
    $en = mcr_is_en();
    $store = mcr_setting('store');
    $free_min = (float) mcr_setting('delivery', 'free_min_subtotal');
    $delivery = mcr_setting('delivery', 'enabled');
    $pickup = mcr_setting('pickup', 'enabled');
    ob_start();
    ?>
    <div class="form-row form-row-wide mcr-mode" id="billing_mcr_mode_field" data-mcr-mode>
      <h3 class="mcr-step"><span>2</span><?php echo esc_html($en ? 'Delivery or pickup' : 'Livraison ou retrait'); ?></h3>
      <input type="hidden" name="billing_mcr_mode" value="" data-mode-input>
      <div class="mcr-mode__opts" role="radiogroup" aria-label="<?php echo esc_attr($en ? 'How do you want to receive your order?' : 'Comment voulez-vous recevoir votre commande ?'); ?>">
        <?php if ($delivery) : ?>
        <button type="button" class="mcr-mode__opt" role="radio" aria-checked="false" data-mode="delivery">
          <span class="mcr-mode__ic"><?php echo function_exists('mcr_icon') ? mcr_icon('truck', 22) : ''; ?></span>
          <span class="mcr-mode__txt"><strong><?php echo esc_html($en ? 'Delivery' : 'Livraison'); ?></strong>
          <small data-mode-price><?php echo esc_html($en ? sprintf('From %s · free from %s', wp_strip_all_tags(wc_price(mcr_min_delivery_fee())), wp_strip_all_tags(wc_price($free_min))) : sprintf('Dès %s · gratuite dès %s', wp_strip_all_tags(wc_price(mcr_min_delivery_fee())), wp_strip_all_tags(wc_price($free_min)))); ?></small></span>
        </button>
        <?php endif; ?>
        <?php if ($pickup) : ?>
        <button type="button" class="mcr-mode__opt" role="radio" aria-checked="false" data-mode="pickup">
          <span class="mcr-mode__ic"><?php echo function_exists('mcr_icon') ? mcr_icon('store', 22) : ''; ?></span>
          <span class="mcr-mode__txt"><strong><?php echo esc_html($en ? 'In-store pickup' : 'Retrait en magasin'); ?></strong>
          <small><?php echo esc_html(($en ? 'Free · ' : 'Gratuit · ') . $store['street']); ?></small></span>
        </button>
        <?php endif; ?>
      </div>
      <div class="mcr-mode__note" data-mode-note hidden></div>
    </div>
    <?php
    return (string) ob_get_clean();
}, 10, 3);

function mcr_min_delivery_fee(): float
{
    $fees = array_map(fn($t) => (float) ($t['fee'] ?? 0), (array) mcr_setting('delivery', 'tiers'));
    return $fees ? min($fees) : 0.0;
}

// The two helper "fields" are not real data.
add_filter('woocommerce_checkout_posted_data', function ($data) {
    unset($data['billing_mcr_step1']);
    if (isset($data['billing_mcr_mode']) && !in_array($data['billing_mcr_mode'], ['delivery', 'pickup'], true)) {
        unset($data['billing_mcr_mode']);
    }
    return $data;
});

// Pickup: the address is not needed.
add_action('woocommerce_after_checkout_validation', function ($data, WP_Error $errors) {
    if (!mcr_posted_pickup()) {
        return;
    }
    foreach (['billing_address_1', 'billing_address_2', 'billing_city', 'billing_postcode', 'billing_state', 'billing_country', 'shipping_address_1', 'shipping_city', 'shipping_postcode', 'shipping_state', 'shipping_country'] as $key) {
        foreach (['_required', '_validation'] as $suffix) {
            if ($errors->get_error_message($key . $suffix)) {
                $errors->remove($key . $suffix);
            }
        }
    }
    // Messages without a field code (older WooCommerce): drop the address ones.
    foreach ($errors->get_error_codes() as $code) {
        if (preg_match('/^(billing|shipping)_(address_1|city|postcode|state)/', (string) $code)) {
            $errors->remove($code);
        }
    }
}, 20, 2);

// Section headings for the order summary and payment.
add_action('woocommerce_review_order_before_payment', function () {
    echo '<h3 class="mcr-step mcr-step--pay"><span>3</span>' . esc_html(mcr_is_en() ? 'Payment' : 'Paiement') . '</h3>';
});
add_action('woocommerce_review_order_after_submit', function () {
    echo '<p class="mcr-secure">' . (function_exists('mcr_icon') ? mcr_icon('lock', 14) : '') . ' ' . esc_html(mcr_is_en() ? 'Secure checkout. Your details stay private.' : 'Paiement sécurisé. Vos données restent confidentielles.') . '</p>';
});

// Shorter button and headings.
add_filter('woocommerce_order_button_text', fn() => mcr_is_en() ? 'Place my order' : 'Passer ma commande');
add_filter('gettext_woocommerce', function ($translation, $text) {
    if (is_admin()) {
        return $translation;
    }
    $en = mcr_is_en();
    $map = [
        'Your order' => $en ? 'Order summary' : 'Résumé de la commande',
        'Proceed to checkout' => $en ? 'Checkout' : 'Passer à la caisse',
    ];
    return $map[$text] ?? $translation;
}, 20, 2);

// WooCommerce's address script re-applies labels, order and "required" from the
// country settings: give it the same choices.
function mcr_locale_overrides(): array
{
    $en = mcr_is_en();
    return [
        'phone' => ['priority' => 30, 'required' => true, 'hidden' => false, 'label' => $en ? 'Phone' : 'Téléphone'],
        'address_1' => ['priority' => 50, 'label' => $en ? 'Delivery address' : 'Adresse de livraison', 'placeholder' => $en ? 'Start typing your address…' : 'Commencez à écrire votre adresse…'],
        'address_2' => ['priority' => 55, 'label' => $en ? 'Apt., unit' : 'App., bureau', 'placeholder' => '', 'label_class' => [], 'required' => false],
        'city' => ['priority' => 60],
        'postcode' => ['priority' => 65, 'label' => $en ? 'Postal code' : 'Code postal'],
        'state' => ['priority' => 70],
    ];
}
add_filter('woocommerce_get_country_locale_default', function ($locale) {
    foreach (mcr_locale_overrides() as $k => $v) {
        $locale[$k] = array_merge((array) ($locale[$k] ?? []), $v);
    }
    return $locale;
}, 20);
add_filter('woocommerce_get_country_locale', function ($locales) {
    if (isset($locales['CA'])) {
        foreach (mcr_locale_overrides() as $k => $v) {
            $locales['CA'][$k] = array_merge((array) ($locales['CA'][$k] ?? []), $v);
        }
    }
    return $locales;
}, 20);
add_filter('pre_option_woocommerce_checkout_phone_field', fn() => 'required');
add_filter('woocommerce_billing_fields', function ($fields) {
    if (isset($fields['billing_phone'])) {
        $fields['billing_phone']['priority'] = 30;
        $fields['billing_phone']['required'] = true;
    }
    return $fields;
}, 1000);

// "Shipment" row name in the summary.
add_filter('woocommerce_shipping_package_name', fn() => mcr_is_en() ? 'Delivery or pickup' : 'Livraison ou retrait', 20);

// Short privacy note at checkout, in the visitor's language.
add_filter('woocommerce_get_privacy_policy_text', function ($text, $type) {
    if ($type !== 'checkout') {
        return $text;
    }
    return mcr_is_en()
        ? 'Your details are used only to process your order. See our [privacy_policy].'
        : 'Vos données servent uniquement à traiter votre commande. Voir notre [privacy_policy].';
}, 10, 2);

// The cart updates by itself: no "Cart updated." message each time.
add_filter('woocommerce_add_success', function ($message) {
    return in_array($message, [__('Cart updated.', 'woocommerce'), 'Cart updated.'], true) ? '' : $message;
});
