<?php
/**
 * Order steps for the store team. Payment status stays WooCommerce's own
 * (Paiement en attente, En cours = payée, Remboursée…); these steps follow
 * the order after payment:
 *   En cours → En préparation → Prête au retrait / En livraison → Terminée.
 * The customer receives a short email (in their language) at "Prête au
 * retrait" and "En livraison".
 */
defined('ABSPATH') || exit;

function mcr_order_steps(): array
{
    return [
        'wc-mcr-preparing' => ['fr' => 'En préparation', 'en' => 'Being prepared'],
        'wc-mcr-ready' => ['fr' => 'Prête au retrait', 'en' => 'Ready for pickup'],
        'wc-mcr-out' => ['fr' => 'En livraison', 'en' => 'Out for delivery'],
    ];
}

add_action('init', function () {
    foreach (mcr_order_steps() as $key => $label) {
        register_post_status($key, [
            'label' => $label['fr'],
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count' => _n_noop($label['fr'] . ' <span class="count">(%s)</span>', $label['fr'] . ' <span class="count">(%s)</span>'),
        ]);
    }
});

// Insert the steps right after "En cours" (processing).
add_filter('wc_order_statuses', function ($statuses) {
    $en = !is_admin() && mcr_is_en();
    $out = [];
    foreach ($statuses as $k => $v) {
        $out[$k] = $v;
        if ($k === 'wc-processing') {
            foreach (mcr_order_steps() as $key => $label) {
                $out[$key] = $en ? $label['en'] : $label['fr'];
            }
        }
    }
    return $out;
});

// The steps come after payment: count them as paid (reports, stock, downloads).
add_filter('woocommerce_order_is_paid_statuses', fn($s) => array_merge($s, ['mcr-preparing', 'mcr-ready', 'mcr-out']));
add_filter('woocommerce_reports_order_statuses', function ($s) {
    return is_array($s) && in_array('processing', $s, true) ? array_merge($s, ['mcr-preparing', 'mcr-ready', 'mcr-out']) : $s;
});
add_filter('woocommerce_analytics_actionable_order_statuses', fn($s) => array_merge((array) $s, ['mcr-preparing']));
add_filter('woocommerce_valid_order_statuses_for_order_again', fn($s) => array_merge((array) $s, ['mcr-ready', 'mcr-out']));

// Bulk actions in the orders list ("Changer l'état : …").
foreach (['bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders'] as $hook) {
    add_filter($hook, function ($actions) {
        foreach (mcr_order_steps() as $key => $label) {
            $actions['mark_' . substr($key, 3)] = 'Changer l’état : ' . $label['fr'];
        }
        return $actions;
    }, 20);
}

// Quick buttons in the orders list, for the next step only.
add_filter('woocommerce_admin_order_actions', function ($actions, WC_Order $order) {
    $mode = function_exists('mcr_order_fulfilment') ? mcr_order_fulfilment($order) : '';
    $next = [];
    if ($order->has_status(['processing', 'on-hold'])) {
        $next[] = 'mcr-preparing';
    }
    if ($order->has_status(['processing', 'mcr-preparing'])) {
        $next[] = $mode === 'pickup' ? 'mcr-ready' : 'mcr-out';
    }
    if ($order->has_status(['mcr-ready', 'mcr-out'])) {
        $next[] = 'completed';
    }
    if (!$next) {
        return $actions;
    }
    unset($actions['processing'], $actions['complete']);
    $labels = ['mcr-preparing' => 'En préparation', 'mcr-ready' => 'Prête au retrait', 'mcr-out' => 'En livraison', 'completed' => $mode === 'pickup' ? 'Récupérée (terminée)' : 'Livrée (terminée)'];
    foreach ($next as $st) {
        $actions[$st] = [
            'url' => wp_nonce_url(admin_url('admin-ajax.php?action=woocommerce_mark_order_status&status=' . $st . '&order_id=' . $order->get_id()), 'woocommerce-mark-order-status'),
            'name' => $labels[$st],
            'action' => $st === 'completed' ? 'complete' : $st,
        ];
    }
    return $actions;
}, 10, 2);

// Colors for the steps in the orders list, and icons for the quick buttons.
add_action('admin_head', function () {
    echo '<style>
.order-status.status-mcr-preparing{background:#fde9c8;color:#7a4d00}
.order-status.status-mcr-ready{background:#d8f0dc;color:#1d5c2a}
.order-status.status-mcr-out{background:#dbe4ff;color:#26368f}
.wc-action-button-mcr-preparing::after{content:"\f464"!important;font-family:dashicons!important}
.wc-action-button-mcr-ready::after{content:"\f312"!important;font-family:dashicons!important}
.wc-action-button-mcr-out::after{content:"\f17c"!important;font-family:dashicons!important}
.mcr-fulfil{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;font-size:11px;background:#eef;color:#334}
.mcr-fulfil--pickup{background:#e7f5ea;color:#1d5c2a}
</style>';
});

// "Livraison" / "Retrait" badge in the orders list, after the order number.
foreach (['manage_shop_order_posts_custom_column' => 2, 'manage_woocommerce_page_wc-orders_custom_column' => 2] as $hook => $args) {
    add_action($hook, function ($column, $order) {
        if ($column !== 'order_number') {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order || !function_exists('mcr_order_fulfilment')) {
            return;
        }
        $mode = mcr_order_fulfilment($order);
        if ($mode === 'pickup') {
            echo '<span class="mcr-fulfil mcr-fulfil--pickup">Retrait</span>';
        } elseif ($mode === 'delivery') {
            echo '<span class="mcr-fulfil">Livraison</span>';
        }
    }, 20, $args);
}

// ---------------------------------------------------------------- Customer emails

add_action('woocommerce_order_status_changed', function ($order_id, $from, $to, $order) {
    if (!in_array($to, ['mcr-ready', 'mcr-out'], true) || !$order instanceof WC_Order) {
        return;
    }
    $email = $order->get_billing_email();
    if (!$email || $order->get_meta('_mcr_notified_' . $to)) {
        return;
    }
    $en = $order->get_meta('_mcr_lang') === 'en';
    $store = mcr_setting('store');
    $num = $order->get_order_number();
    $name = $order->get_billing_first_name();
    if ($to === 'mcr-ready') {
        $subject = $en ? "Your order #{$num} is ready for pickup" : "Votre commande n° {$num} est prête au retrait";
        $lines = $en
            ? ["Hello {$name},", "Your order #{$num} is ready. You can pick it up at {$store['street']}, {$store['city']}.", (string) mcr_setting('pickup', 'instructions_en')]
            : ["Bonjour {$name},", "Votre commande n° {$num} est prête. Vous pouvez la récupérer au {$store['street']}, {$store['city']}.", (string) mcr_setting('pickup', 'instructions_fr')];
    } else {
        $subject = $en ? "Your order #{$num} is on its way" : "Votre commande n° {$num} est en route";
        $lines = $en
            ? ["Hello {$name},", "Your order #{$num} has left the store and is on its way to you.", (string) mcr_setting('delivery', 'instructions_en')]
            : ["Bonjour {$name},", "Votre commande n° {$num} a quitté le magasin et est en route vers vous.", (string) mcr_setting('delivery', 'instructions_fr')];
    }
    $lines[] = $en ? "Questions? Call us at {$store['phone']} or write on WhatsApp." : "Une question ? Appelez-nous au {$store['phone']} ou écrivez-nous sur WhatsApp.";
    $lines[] = $store['name'];
    $mailer = WC()->mailer();
    $body = $mailer->wrap_message($subject, wpautop(esc_html(implode("\n\n", array_filter($lines)))));
    $mailer->send($email, $subject, $body);
    $order->update_meta_data('_mcr_notified_' . $to, time());
    $order->add_order_note(($to === 'mcr-ready' ? 'Courriel « prête au retrait »' : 'Courriel « en livraison »') . ' envoyé au client.');
    $order->save();
}, 10, 4);

// Show the "Actions" column (quick step buttons) in the orders list by default.
add_filter('default_hidden_columns', function ($hidden, $screen) {
    if ($screen && in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true)) {
        $hidden = array_values(array_diff((array) $hidden, ['wc_actions']));
    }
    return $hidden;
}, 20, 2);
