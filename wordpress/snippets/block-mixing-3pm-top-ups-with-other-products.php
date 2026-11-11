<?php
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id){
    if ( is_admin() ) return $passed;

    $is_topup = has_term('wallet-topup', 'product_cat', $product_id);
    $cart_has_topup = false;
    $cart_has_other = false;

    foreach ( WC()->cart->get_cart() as $item ) {
        if ( has_term('wallet-topup', 'product_cat', $item['product_id']) ) {
            $cart_has_topup = true;
        } else {
            $cart_has_other = true;
        }
    }

    // Disallow mixing
    if ( ($is_topup && $cart_has_other) || (!$is_topup && $cart_has_topup) ) {
        wc_add_notice(__('You can’t purchase $3PM coin top-ups together with other items. Please checkout top-ups separately.', 'your-textdomain'), 'error');
        return false;
    }
    return $passed;
}, 10, 2);

