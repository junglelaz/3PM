<?php
/**
 * 3PM – WooCommerce My Account menu tweaks
 * (Aligned icons, no circles/glow; final Security alignment fix)
 */

/* ---------------- 1) Your existing menu logic (unchanged) ---------------- */

add_filter('woocommerce_account_menu_items', function ($items) {

    // Force label to "Dashboard"
    if (isset($items['dashboard'])) {
        $items['dashboard'] = __('Dashboard', 'woocommerce');
    } else {
        foreach ($items as $key => $label) {
            if (stripos($label, 'dashboard') !== false) {
                $items[$key] = __('Dashboard', 'woocommerce');
                break;
            }
        }
    }

    // Remove WPStream items if present
    foreach ($items as $key => $label) {
        if (trim($label) === 'My Live Streams' || trim($label) === 'My Videos') {
            unset($items[$key]);
        }
    }

    // Ensure "My Wallet" exists (TeraWallet)
    $needs_wallet = true;
    foreach ($items as $k => $v) {
        if ($k === 'woo-wallet' || str_ireplace(' ', '', $v) === 'MyWallet') {
            $needs_wallet = false; break;
        }
    }
    if ($needs_wallet) {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'orders') {
                $new['woo-wallet'] = __('My Wallet', 'woocommerce');
            }
        }
        if (!isset($new['woo-wallet'])) {
            $new['woo-wallet'] = __('My Wallet', 'woocommerce');
        }
        $items = $new;
    } else {
        $items['woo-wallet'] = __('My Wallet', 'woocommerce');
    }

    return $items;
}, 999);

add_action('init', function () {
    add_rewrite_endpoint('woo-wallet', EP_ROOT | EP_PAGES);
});

add_action('woocommerce_account_woo-wallet_endpoint', function () {
    if (shortcode_exists('woo_wallet')) {
        echo do_shortcode('[woo_wallet]');
    } else {
        echo '<p>Your wallet is currently unavailable.</p>';
    }
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'woo-wallet';
    return $vars;
});

add_filter('body_class', function ($classes) {
    $classes[] = 'myaccount-tweaks-3pm';
    return $classes;
});


/* ---------------- 2) CSS: align all icons; force Security to identical slot ---------------- */

add_action('wp_head', function () { ?>
  <style id="threepm-myaccount-alignment-final">
    /* Reserve identical left gutter for ALL items */
    .woocommerce-MyAccount-navigation li > a {
      position: relative !important;
      display: block !important;
      padding-left: 30px !important;      /* 20px box + 5px inset + 5px label gap */
      white-space: nowrap !important;
      line-height: 1.6 !important;
      text-shadow: none !important;
      filter: none !important;
      box-shadow: none !important;
    }

    /* Normal items: theme draws icon via ::before */
    .woocommerce-MyAccount-navigation li:not(.woocommerce-MyAccount-navigation-link--security) > a::before {
      content: "" !important;
      position: absolute !important;
      left: 5px !important;
      top: 50% !important;
      width: 20px !important;
      height: 20px !important;
      transform: translateY(-50%) !important;
      border: none !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      background-repeat: no-repeat !important;
      background-position: center !important;
      background-size: 16px 16px !important;
    }
    /* Hide any stray inline icons on normal items */
    .woocommerce-MyAccount-navigation li:not(.woocommerce-MyAccount-navigation-link--security) > a > svg,
    .woocommerce-MyAccount-navigation li:not(.woocommerce-MyAccount-navigation-link--security) > a > i,
    .woocommerce-MyAccount-navigation li:not(.woocommerce-MyAccount-navigation-link--security) > a > .icon,
    .woocommerce-MyAccount-navigation li:not(.woocommerce-MyAccount-navigation-link--security) > a > [class*="icon"],
    .woocommerce-MyAccount-navigation li:not(.woocommerce-MyAccount-navigation-link--security) > a > img {
      display: none !important;
    }

    /* SECURITY: disable pseudo; position the REAL icon identically to others */
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a::before {
      content: none !important;
    }
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a svg,
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a i,
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a .icon,
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a [class*="icon"],
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a img {
      position: absolute !important;
      left: 5px !important;                /* same horizontal origin */
      top: 50% !important;
      width: 20px !important;              /* same box size */
      height: 20px !important;
      transform: translateY(-50%) !important;
      margin: 0 !important;
      display: block !important;
      text-shadow: none !important;
      filter: none !important;
      box-shadow: none !important;
      object-fit: contain !important;      /* in case it's an <img> */
    }
    /* If it's an SVG, size the glyph to 16×16 inside the 20×20 box */
    .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--security > a svg {
      width: 16px !important;
      height: 16px !important;
      position: absolute !important;       /* keep centered within the 20×20 box */
      left: 5px !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
    }

    /* Clean up any helper elements from older attempts */
    .woocommerce-MyAccount-navigation .threepm-ring { display: none !important; }
  </style>
<?php });
