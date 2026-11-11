<?php
/**
 * PMPro — Short recurring-only price display everywhere
 * Format:
 *   100 $3PM (~$5,00 USD)
 *   per month
 *
 * Peg: 1 $3PM = $0.05 USD  (=> $1 = 20 $3PM)
 * Frontend only. Admin remains USD.
 */

/* --------------------------------------------------------------------------
   Helpers (reused from your WooCommerce setup)
-------------------------------------------------------------------------- */

if ( ! function_exists('_3pm_usd_to_coins') ) {
    function _3pm_usd_to_coins($usd, $decimals = 0){
        // $1 USD = 20 $3PM
        $coins = floatval($usd) * 20;
        if ($decimals === 0) $coins = round($coins);
        return number_format_i18n($coins, $decimals);
    }
}

if ( ! function_exists('_3pm_format_dual') ) {
    function _3pm_format_dual($usd, $primary = '3pm', $coin_decimals = 0){
        if ( function_exists('wc_price') ) {
            $usd_amount = wc_price( floatval($usd), array('currency' => 'USD') ) . ' USD';
        } else {
            $usd_amount = '$' . number_format_i18n(floatval($usd), 2) . ' USD';
        }
        $coins_amount = _3pm_usd_to_coins( $usd, $coin_decimals ) . ' $3PM';

        if ($primary === 'usd') {
            return sprintf('%s <span class="price-alt">(~%s)</span>', $usd_amount, $coins_amount);
        }
        return sprintf('%s <span class="price-alt">(~%s)</span>', $coins_amount, $usd_amount);
    }
}

/* --------------------------------------------------------------------------
   Helper: add <br> before cycle label
-------------------------------------------------------------------------- */
if ( ! function_exists('_3pm_cycle_label') ) {
    function _3pm_cycle_label($cycle_number, $cycle_period){
        $period = strtolower($cycle_period ?: 'month');
        if (intval($cycle_number) === 1) {
            return '<br>per ' . $period;
        }
        return '<br>per ' . intval($cycle_number) . ' ' . $period;
    }
}

/* --------------------------------------------------------------------------
   1) Levels page
-------------------------------------------------------------------------- */
add_filter('pmpro_level_cost_text', function ($text, $level, $tags = null, $short = false) {
    if ( is_admin() && ! wp_doing_ajax() ) return $text;

    $bill  = isset($level->billing_amount) ? floatval($level->billing_amount) : 0.0;
    $init  = isset($level->initial_payment) ? floatval($level->initial_payment) : 0.0;
    $num   = isset($level->cycle_number) ? intval($level->cycle_number) : 1;
    $per   = isset($level->cycle_period) ? $level->cycle_period : 'Month';

    if ($bill > 0) {
        return _3pm_format_dual($bill, '3pm', 0) . ' ' . _3pm_cycle_label($num, $per);
    }
    if ($init > 0) {
        return _3pm_format_dual($init, '3pm', 0);
    }
    return 'Free';
}, 10, 4);

/* --------------------------------------------------------------------------
   2) Checkout page
-------------------------------------------------------------------------- */
add_filter('pmpro_subscription_price', function($price, $level) {
    if ( is_admin() && ! wp_doing_ajax() ) return $price;

    $bill  = isset($level->billing_amount) ? floatval($level->billing_amount) : 0.0;
    $init  = isset($level->initial_payment) ? floatval($level->initial_payment) : 0.0;
    $num   = isset($level->cycle_number) ? intval($level->cycle_number) : 1;
    $per   = isset($level->cycle_period) ? $level->cycle_period : 'Month';

    if ($bill > 0) {
        return _3pm_format_dual($bill, '3pm', 0) . ' ' . _3pm_cycle_label($num, $per);
    }
    if ($init > 0) {
        return _3pm_format_dual($init, '3pm', 0);
    }
    return 'Free';
}, 10, 2);

/* --------------------------------------------------------------------------
   3) Confirmation, Invoices, Account
-------------------------------------------------------------------------- */
function _3pm_pmpro_compact_text($text){
    if ( is_admin() && ! wp_doing_ajax() ) return $text;

    // Replace raw $ amounts with dual
    $text = preg_replace_callback('/\$(\d+(?:\.\d{1,2})?)/', function($m){
        return _3pm_format_dual(floatval($m[1]), '3pm', 0);
    }, $text);

    // Normalize "per 1 Month" → "<br>per month"
    $text = preg_replace('/per\s+1\s+(Month|Week|Day|Year)/i', '<br>per ' . strtolower('$1'), $text);

    // Replace " per Month" → "<br>per month"
    $text = preg_replace('/ per (Month|Week|Day|Year)/i', '<br>per ' . strtolower('$1'), $text);

    return $text;
}

add_filter('pmpro_confirmation_message', function($msg){
    return _3pm_pmpro_compact_text($msg);
}, 10, 1);

add_filter('pmpro_invoice_bullets', function($bullets, $invoice){
    if ( is_admin() && ! wp_doing_ajax() ) return $bullets;
    foreach ($bullets as &$b) {
        if (is_string($b)) $b = _3pm_pmpro_compact_text($b);
    }
    return $bullets;
}, 10, 2);

add_filter('pmpro_account_membership_levels_rows', function($rows, $r){
    if ( is_admin() && ! wp_doing_ajax() ) return $rows;
    foreach ($rows as &$row) {
        foreach ($row as &$cell) {
            if (is_string($cell)) $cell = _3pm_pmpro_compact_text($cell);
        }
    }
    return $rows;
}, 10, 2);

/* --------------------------------------------------------------------------
   Optional styling: make (~USD) lighter, and add spacing after <br>
-------------------------------------------------------------------------- */
add_action('wp_head', function(){
    ?>
    <style>
        .price-alt { opacity: .7; font-size: .95em; }
        td.pmpro_level-price br { content:""; display:block; margin-top:2px; }
    </style>
    <?php
});
