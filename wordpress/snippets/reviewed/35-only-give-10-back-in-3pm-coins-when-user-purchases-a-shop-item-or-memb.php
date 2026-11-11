<?php
/** Apply the configured wallet cashback only to wallet-funded purchases. */
$threepm_wallet_reward_gate = function ($allowed, $order) {
    if (!$allowed || !$order instanceof WC_Order) return false;
    if (!in_array($order->get_payment_method(), ['wallet','woo_wallet','terawallet'], true)) return false;
    foreach ($order->get_items('line_item') as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $id = $product->get_parent_id() ?: $product->get_id();
        $slug = class_exists('ThreePM_Suite') ? ThreePM_Suite::opt('topup_slug') : 'wallet-topup';
        if (has_term($slug, 'product_cat', $id)) return false;
    }
    return true;
};
add_filter('process_woo_wallet_general_cashback', $threepm_wallet_reward_gate, 10, 2);
add_filter('process_woo_wallet_coupon_cashback', $threepm_wallet_reward_gate, 10, 2);
