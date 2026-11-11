<?php
/** Coin-pack fulfilment. Native TeraWallet top-ups remain owned by TeraWallet. */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values, $order) {
    $product = $item->get_product();
    if (!$product || !class_exists('ThreePM_Suite')) return;
    $id = $product->get_parent_id() ?: $product->get_id();
    if (has_term(ThreePM_Suite::opt('topup_slug'), 'product_cat', $id)) {
        $item->add_meta_data('_3pm_topup_coins_per_usd', ThreePM_Suite::usd_to_coin(), true);
    }
}, 20, 4);

add_action('woocommerce_order_status_completed', function ($order_id) {
    if (!function_exists('woo_wallet') || !class_exists('ThreePM_Suite') || !class_exists('Woo_Wallet_Currency_Manager')) return;
    if (Woo_Wallet_Currency_Manager::instance()->get_active_provider_id() !== 'threepm-coins') return;
    $order = wc_get_order($order_id);
    if (!$order || !$order->is_paid() || !$order->get_customer_id()) return;
    if (function_exists('is_wallet_rechargeable_order') && is_wallet_rechargeable_order($order)) return;
    global $wpdb;
    $lock = 'threepm_coinpack_' . absint($order_id);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== '1') return;
    try {
        $order = class_exists('WOO_Wallet_Helper') ? WOO_Wallet_Helper::get_order_for_update($order_id) : wc_get_order($order_id);
        if (!$order || !$order->is_paid() || $order->get_meta('_3pm_topup_credited') === 'yes') return;
        if ($order->get_meta('_3pm_topup_credit_pending')) return; // Uncertain prior attempt requires review.
        $coins = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $id = $product->get_parent_id() ?: $product->get_id();
            if (!has_term(ThreePM_Suite::opt('topup_slug'), 'product_cat', $id)) continue;
            $rate = $item->get_meta('_3pm_topup_coins_per_usd', true);
            if (!is_numeric($rate) || !is_finite((float) $rate) || $rate <= 0) $rate = ThreePM_Suite::usd_to_coin();
            $coins += ((float) $item->get_total() + (float) $item->get_total_tax()) * (float) $rate;
        }
        $coins = round($coins, 2);
        if ($coins <= 0 || !is_finite($coins)) return;
        $order->update_meta_data('_3pm_topup_credit_pending', 'yes');
        $order->save();
        $transaction = woo_wallet()->wallet->credit($order->get_customer_id(), $coins,
            '3PM coin pack from order #'.$order->get_order_number(),
            ['currency'=>ThreePM_Suite::opt('coin_code'), 'category'=>'topup', 'for'=>'threepm_coinpack']);
        if (!$transaction) {
            $order->delete_meta_data('_3pm_topup_credit_pending');
            $order->add_order_note('3PM coin credit failed; no successful credit was returned. Review before retrying.');
            $order->save();
            return;
        }
        $order->update_meta_data('_3pm_topup_credited', 'yes');
        $order->update_meta_data('_3pm_topup_coins_credited', $coins);
        $order->update_meta_data('_3pm_topup_transaction_id', $transaction);
        $order->delete_meta_data('_3pm_topup_credit_pending');
        $order->save();
    } catch (Throwable $e) {
        // Keep the pending flag when a committed credit cannot be ruled out.
        if (function_exists('wc_get_logger')) wc_get_logger()->error('Coin-pack fulfilment needs review for order '.absint($order_id), ['source'=>'threepm-wallet']);
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}, 20);

// Reverse the original credited coins proportionally when a coin-pack is refunded.
$threepm_coinpack_reverse = function ($order_id) {
    if (!function_exists('woo_wallet') || !class_exists('ThreePM_Suite')) return;
    global $wpdb;
    $lock = 'threepm_coinpack_' . absint($order_id);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== '1') return;
    try {
        $order = class_exists('WOO_Wallet_Helper') ? WOO_Wallet_Helper::get_order_for_update($order_id) : wc_get_order($order_id);
        if (!$order || $order->get_meta('_3pm_topup_credited') !== 'yes' || $order->get_meta('_3pm_topup_reversal_pending')) return;
        $credited = (float) $order->get_meta('_3pm_topup_coins_credited');
        $gross = (float) $order->get_total('edit');
        if ($credited <= 0 || $gross <= 0) return;
        $ratio = in_array($order->get_status(), ['cancelled','refunded'], true) ? 1 : min(1, max(0, (float) $order->get_total_refunded() / $gross));
        $already = (float) $order->get_meta('_3pm_topup_coins_reversed');
        $due = round($credited * $ratio - $already, 2);
        if ($due <= 0) return;
        $order->update_meta_data('_3pm_topup_reversal_pending', 'yes');
        $order->save();
        $transaction = woo_wallet()->wallet->debit($order->get_customer_id(), $due,
            '3PM coin-pack reversal for order #'.$order->get_order_number(),
            ['currency'=>ThreePM_Suite::opt('coin_code'), 'category'=>'refund', 'for'=>'threepm_coinpack_reversal']);
        if (!$transaction) {
            $order->delete_meta_data('_3pm_topup_reversal_pending');
            $order->update_meta_data('_3pm_topup_reversal_shortfall', $due);
            $order->add_order_note('3PM coin-pack reversal could not be debited. Wallet balance and card refund require manual reconciliation.');
            $order->save();
            return;
        }
        $order->update_meta_data('_3pm_topup_coins_reversed', $already + $due);
        $order->update_meta_data('_3pm_topup_reversal_transaction_id', $transaction);
        $order->delete_meta_data('_3pm_topup_reversal_pending');
        $order->delete_meta_data('_3pm_topup_reversal_shortfall');
        $order->save();
    } catch (Throwable $e) {
        if (function_exists('wc_get_logger')) wc_get_logger()->error('Coin-pack reversal needs review for order '.absint($order_id), ['source'=>'threepm-wallet']);
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
};
add_action('woocommerce_order_refunded', $threepm_coinpack_reverse, 20);
add_action('woocommerce_order_status_cancelled', $threepm_coinpack_reverse, 20);
add_action('woocommerce_order_status_refunded', $threepm_coinpack_reverse, 20);
