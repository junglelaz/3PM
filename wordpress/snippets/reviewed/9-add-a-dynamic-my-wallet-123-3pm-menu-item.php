<?php
/**
 * Append a dynamic "My Wallet (BALANCE)" item to ALL menus (horizontal + vertical).
 * - Shows only for logged-in users
 * - Uses TeraWallet balance (in coins)
 * - Works regardless of theme locations
 * - Add your own icon HTML if desired
 */
add_filter('wp_nav_menu_items', function($items, $args){
    if ( ! is_user_logged_in() || ! function_exists('woo_wallet') ) {
        return $items;
    }

    // Get $3PM balance (TeraWallet stores/returns wallet unit amount)
    $user_id     = get_current_user_id();
    $balance_raw = woo_wallet()->wallet->get_wallet_balance($user_id, 'edit', '3PM');
    $balance_3pm = number_format_i18n( floatval($balance_raw), 0 );

    // OPTIONAL: also show USD equivalent (1 $3PM = $0.05 USD)
    $show_usd_equiv = false; // set to true to enable
    $usd_equiv_html = '';
    if ( $show_usd_equiv ) {
        $usd = floatval($balance_raw) * threepm_rate_coin_to_usd();
        // Force USD suffix for clarity
        $usd_equiv_html = ' <span class="wallet-usd">(~' . wc_price($usd, ['currency' => 'USD']) . ' USD)</span>';
    }

    // Link to the wallet page
    $wallet_url = wc_get_account_endpoint_url('my-wallet');

    // Build menu item; class name lets you style it
    $label = 'My Wallet (' . esc_html($balance_3pm) . ' $3PM)';
    $new   = '<li class="menu-item menu-item-my-wallet">'
           . '<a href="' . esc_url($wallet_url) . '">' . $label . $usd_equiv_html . '</a>'
           . '</li>';

    // Append to the end of each menu rendered
    return $items . $new;
}, 10, 2);
