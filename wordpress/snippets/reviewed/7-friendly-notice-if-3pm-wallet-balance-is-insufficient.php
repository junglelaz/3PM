<?php
add_action('woocommerce_before_checkout_form', function(){
    if ( !WC()->cart ) return;

    $cart_has_other = false;
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( !has_term('wallet-topup', 'product_cat', $item['product_id']) ) {
            $cart_has_other = true;
            break;
        }
    }
    if ( !$cart_has_other ) return;

    if ( function_exists('woo_wallet') ) {
        $balance = woo_wallet()->wallet->get_wallet_balance(get_current_user_id(), 0);
        $total   = WC()->cart->get_total('edit'); // numeric total

        if ( floatval($balance) < floatval($total) ) {
            $topup_link = get_term_link('wallet-topup', 'product_cat');
            wc_print_notice(
                sprintf(
                    __('Your $3PM wallet balance is insufficient to complete this order. Please <a href="%s">top up your $3PM Coins</a> first.', 'your-textdomain'),
                    esc_url($topup_link)
                ),
                'notice'
            );
        }
    }
});
