<?php
/**
 * Auto-credit TeraWallet using $3PM plugin settings.
 * - Store currency = USD
 * - Uses get_option('3pm_coins_per_usd') for coins per 1 USD
 * - Fallback: _3pm_usd_to_coins(1) if available
 * - Category gate via get_option('3pm_topup_category_slug') (default: wallet-topup)
 */

add_action('woocommerce_order_status_completed', function($order_id){
    if ( ! function_exists('woo_wallet') ) return; // TeraWallet required
    $order = wc_get_order($order_id);
    if ( ! $order ) return;

    // Avoid double-crediting
    if ( 'yes' === $order->get_meta('_3pm_topup_credited') ) return;

    // ---- SETTINGS ----
    $coins_per_usd = floatval( get_option('3pm_coins_per_usd', 0) );
    if ( $coins_per_usd <= 0 && function_exists('_3pm_usd_to_coins') ) {
        $coins_per_usd = floatval( _3pm_usd_to_coins(1, 2) ); // fallback
    }
    if ( $coins_per_usd <= 0 ) {
        $order->add_order_note('3PM credit skipped: coins_per_usd not configured.');
        return;
    }

    $topup_cat_slug = get_option('3pm_topup_category_slug', 'wallet-topup');

    // ---- DETECT TOP-UP ITEMS ----
    $is_topup = false;
    $topup_total_usd = 0.0;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;

        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ( $terms && ! is_wp_error($terms) ) {
            foreach ($terms as $t) {
                if ( $t->slug === $topup_cat_slug ) {
                    $is_topup = true;
                    $topup_total_usd += (float) $item->get_total();
                    $topup_total_usd += (float) $item->get_total_tax();
                    break 2;
                }
            }
        }
    }

    if ( ! $is_topup ) return;

    // ---- USD → 3PM CREDITS ----
    $credits_to_add = round( $topup_total_usd * $coins_per_usd, 2 );

    // ---- CREDIT THE USER ----
    $user_id = $order->get_user_id();
    if ( $user_id && $credits_to_add > 0 ) {
        $note = sprintf(
            'Top-up from Order #%d → %s 3PM Credits ($%.2f × %.2f coins/USD)',
            $order_id,
            number_format_i18n($credits_to_add, 2),
            $topup_total_usd,
            $coins_per_usd
        );
        woo_wallet()->wallet->credit($user_id, $credits_to_add, $note);
        $order->update_meta_data('_3pm_topup_credited', 'yes');
        $order->save();
    }
}, 20);

