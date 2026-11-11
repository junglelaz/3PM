<?php
/**
 * Add emojis in front of membership tier names (PMPro + WooCommerce)
 * and style them for better alignment.
 *
 * Emojis:
 * - Silver Comrade  => ⚪
 * - Gold Comrade    => 🟡
 * - Diamond Comrade => 💎
 * - Comrade (Free)  => ✊🏽
 */

/* ---- Shared emoji mapper ---- */
if ( ! function_exists('_3pm_level_name_with_emoji') ) {
    function _3pm_level_name_with_emoji($name){
        $map = [
            'Silver Comrade'  => '⚪ Silver Comrade',
            'Gold Comrade'    => '🟡 Gold Comrade',
            'Diamond Comrade' => '💎 Diamond Comrade',
            'Comrade'         => '✊🏽 Comrade', // free tier
        ];
        return $map[$name] ?? $name;
    }
}

/* --------------------------------------------------------------------------
   PMPro: Membership Levels page
-------------------------------------------------------------------------- */
add_filter('pmpro_levels_array', function($levels){
    if ( is_admin() && ! wp_doing_ajax() ) return $levels;
    foreach ($levels as &$level) {
        if ( isset($level->name) ) {
            $level->name = _3pm_level_name_with_emoji($level->name);
        }
    }
    return $levels;
}, 10);

/* --------------------------------------------------------------------------
   PMPro: general name filter (checkout, invoices, confirmation, etc.)
-------------------------------------------------------------------------- */
add_filter('pmpro_level_name', function($name, $level){
    if ( is_admin() && ! wp_doing_ajax() ) return $name;
    return _3pm_level_name_with_emoji($name);
}, 10, 2);

/* --------------------------------------------------------------------------
   PMPro: Account table fallback
-------------------------------------------------------------------------- */
add_filter('pmpro_account_membership_levels_rows', function($rows, $r){
    foreach ($rows as &$row) {
        foreach ($row as &$cell) {
            if (is_string($cell)) {
                $cell = str_replace(
                    ['Silver Comrade','Gold Comrade','Diamond Comrade','Comrade'],
                    ['⚪ Silver Comrade','🟡 Gold Comrade','💎 Diamond Comrade','✊🏽 Comrade'],
                    $cell
                );
            }
        }
    }
    return $rows;
}, 20, 2);

/* --------------------------------------------------------------------------
   WooCommerce: product titles + cart/checkout/order names
-------------------------------------------------------------------------- */
add_filter('the_title', function($title, $id){
    if ( is_admin() ) return $title;
    if ( get_post_type($id) === 'product' ) {
        $title = _3pm_level_name_with_emoji($title);
    }
    return $title;
}, 10, 2);

add_filter('woocommerce_cart_item_name', function($name, $cart_item, $key){
    return _3pm_level_name_with_emoji($name);
}, 10, 3);

add_filter('woocommerce_order_item_name', function($name, $item, $is_visible){
    return _3pm_level_name_with_emoji($name);
}, 10, 3);

/* --------------------------------------------------------------------------
   CSS: style emojis smaller and aligned with text
-------------------------------------------------------------------------- */
add_action('wp_head', function(){
    ?>
    <style>
        /* Target emojis at the start of level/product names */
        .pmpro_levels-table td.pmpro_level-name,
        .woocommerce ul.products li.product h2.woocommerce-loop-product__title,
        .woocommerce-cart .product-name,
        .woocommerce-checkout .product-name,
        .woocommerce-order .product-name {
            font-size: inherit;
        }
        .pmpro_levels-table td.pmpro_level-name::first-letter,
        .woocommerce ul.products li.product h2.woocommerce-loop-product__title::first-letter,
        .woocommerce-cart .product-name::first-letter,
        .woocommerce-checkout .product-name::first-letter,
        .woocommerce-order .product-name::first-letter {
            font-size: 0.9em;
            vertical-align: middle;
        }
        /* Generic span wrapper if you ever wrap emojis */
        .emoji-prefix {
            font-size: 0.9em;
            vertical-align: middle;
            margin-right: 4px;
        }
    </style>
    <?php
});

