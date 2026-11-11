<?php
add_filter('woocommerce_available_payment_gateways', function($gateways){
    if ( is_admin() && !is_ajax() ) return $gateways;
    if ( empty($gateways) || !WC()->cart ) return $gateways;

    $cart_has_topup = false;
    $cart_has_other = false;

    foreach ( WC()->cart->get_cart() as $item ) {
        if ( has_term('wallet-topup', 'product_cat', $item['product_id']) ) {
            $cart_has_topup = true;
        } else {
            $cart_has_other = true;
        }
    }

    // Your gateway IDs (adjust if different):
    // TeraWallet = 'woo_wallet'  |  PayFast = 'payfast'  |  Stripe = 'stripe'
    if ( $cart_has_topup && !$cart_has_other ) {
        // Top-up only → allow PayFast/Stripe only, hide Wallet
        foreach (['wallet','woo_wallet','terawallet'] as $wallet_id) unset($gateways[$wallet_id]);
        // keep payfast/stripe; remove anything else just in case:
        foreach ($gateways as $id => $gw) {
            if (!in_array($id, array('payfast','stripe'))) unset($gateways[$id]);
        }
    } elseif ( $cart_has_other && !$cart_has_topup ) {
        // Normal products → allow Wallet only
        foreach ($gateways as $id => $gw) {
            if (!in_array($id, ['wallet','woo_wallet','terawallet'], true)) unset($gateways[$id]);
        }
    }

    return $gateways;
}, 20);
