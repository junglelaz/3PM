<?php
/**
 * My Account → Plans + Menu Icons (SVG), bullets removed, icon rings, "My Plan" (crown)
 * - Plans page lives at /plan-select
 * - My Account item renamed to "My Plan"
 * - Menu icons are neon-green SVG masks with neon-green circular ring
 * - Removes bullets in My Account nav (strong overrides)
 * - Plan tiles: full-width buttons, hover text black; footer button hover text white
 * - Equal height plan cards (JS)
 */

/* =========================================================
 * $3PM helpers (LOCAL, non-colliding)  ⬅️ UPDATED: unique names + reads 3PM Suite peg
 * ========================================================= */
if ( ! function_exists('_threepm_local_usd_per_coin') ) {
    function _threepm_local_usd_per_coin(){
        // 1) Preferred: public helper from the 3PM Suite plugin
        if ( function_exists('threepm_rate_coin_to_usd') ) {
            $v = threepm_rate_coin_to_usd(); // USD per 1 coin
            if ( is_numeric($v) && $v > 0 ) return (float) $v;
        }
        // 2) Read saved settings directly (works even if class not loaded yet)
        if ( function_exists('get_option') ) {
            $opt = get_option('threepm_rate_settings');
            if ( is_array($opt) ) {
                // Live mode cached value
                if ( !empty($opt['mode']) && $opt['mode'] === 'live' && function_exists('get_transient') ) {
                    $cached = get_transient('threepm_rate_cache');
                    if ( is_array($cached) && !empty($cached['usd_per_coin']) && $cached['usd_per_coin'] > 0 ) {
                        return (float) $cached['usd_per_coin'];
                    }
                }
                // Manual price fallback
                if ( !empty($opt['manual_price']) ) {
                    $raw = is_string($opt['manual_price']) ? str_replace(',', '.', $opt['manual_price']) : $opt['manual_price'];
                    if ( is_numeric($raw) && $raw > 0 ) return (float) $raw;
                }
            }
        }
        // 3) Final fallback so page always renders
        return 0.05; // $0.05 per coin (i.e., $1 = 20 $3PM)
    }
}
if ( ! function_exists('_threepm_local_usd_to_coins') ) {
    function _threepm_local_usd_to_coins($usd, $decimals = 0){
        $usd_per_coin  = _threepm_local_usd_per_coin();          // USD per 1 coin
        $coins_per_usd = $usd_per_coin > 0 ? (1 / $usd_per_coin) : 20;
        $coins = floatval($usd) * $coins_per_usd;
        if ($decimals === 0) $coins = round($coins);
        return number_format_i18n($coins, $decimals);
    }
}
if ( ! function_exists('_threepm_local_format_dual') ) {
    function _threepm_local_format_dual($usd, $primary = '3pm', $coin_decimals = 0){
        $usd_amount = function_exists('wc_price')
            ? wc_price( floatval($usd), array('currency' => 'USD') ) . ' USD'
            : '$' . number_format_i18n(floatval($usd), 2) . ' USD';
        $coins_amount = _threepm_local_usd_to_coins( $usd, $coin_decimals ) . ' $3PM';
        return $primary === 'usd'
            ? sprintf('%s <span class="price-alt">(~%s)</span>', $usd_amount, $coins_amount)
            : sprintf('%s <span class="price-alt">(~%s)</span>', $coins_amount, $usd_amount);
    }
}

/* =========================================================
 * Emoji names (for plan tiles)
 * ========================================================= */
if ( ! function_exists('_3pm_level_name_with_emoji') ) {
    function _3pm_level_name_with_emoji($name){
        $map = [
            'Silver Comrade'  => '⚪ Silver Comrade',
            'Gold Comrade'    => '🟡 Gold Comrade',
            'Diamond Comrade' => '💎 Diamond Comrade',
            'Comrade'         => '✊🏽 Comrade',
        ];
        return $map[$name] ?? $name;
    }
}

/* =========================================================
 * Helpers for Plans rendering
 * ========================================================= */
if ( ! function_exists('_3pm_cycle_label_br') ) {
    function _3pm_cycle_label_br($cycle_number, $cycle_period){
        $period = strtolower($cycle_period ?: 'month');
        $n = intval($cycle_number) ?: 1;
        return $n === 1 ? '<br>per ' . esc_html($period) : '<br>per ' . $n . ' ' . esc_html($period);
    }
}
if ( ! function_exists('_3pm_format_level_price_line') ) {
    function _3pm_format_level_price_line($level){
        $bill  = isset($level->billing_amount) ? floatval($level->billing_amount) : 0.0;
        $init  = isset($level->initial_payment) ? floatval($level->initial_payment) : 0.0;
        $num   = isset($level->cycle_number) ? intval($level->cycle_number) : 1;
        $per   = isset($level->cycle_period) ? $level->cycle_period : 'Month';
        if ($bill > 0) return _threepm_local_format_dual($bill, '3pm', 0) . ' ' . _3pm_cycle_label_br($num, $per);
        if ($init > 0) return _threepm_local_format_dual($init, '3pm', 0);
        return 'Free';
    }
}
if ( ! function_exists('_3pm_level_monthly_usd') ) {
    function _3pm_level_monthly_usd($level){
        $bill  = isset($level->billing_amount) ? floatval($level->billing_amount) : 0.0;
        $num   = max(1, intval($level->cycle_number ?? 1));
        $per   = strtolower($level->cycle_period ?? 'month');
        if ($bill <= 0) return 0.0;
        switch ($per) {
            case 'day':   $months = ($num / 30.437); break;
            case 'week':  $months = ($num / 4.34524); break;
            case 'month': $months = $num; break;
            case 'year':  $months = ($num * 12); break;
            default:      $months = $num; break;
        }
        if ($months <= 0) $months = 1;
        return $bill / $months;
    }
}
if ( ! function_exists('_3pm_pmpro_checkout_url') ) {
    function _3pm_pmpro_checkout_url($level_id){
        if ( function_exists('pmpro_url') ) return pmpro_url('checkout', '?level=' . intval($level_id));
        return home_url('/membership-checkout/?level=' . intval($level_id));
    }
}

/* =========================================================
 * Shortcode: [threepm_plans] (Plans UI)
 * ========================================================= */
add_shortcode('threepm_plans', function(){
    if ( ! function_exists('pmpro_getAllLevels') ) return '<p>No membership plugin found.</p>';

    $levels = pmpro_getAllLevels(true, true);
    if ( empty($levels) ) {
        $levels_url = home_url('/membership-levels/');
        return '<p>No membership plans could be displayed here.</p><p><a class="button" href="'.esc_url($levels_url).'">View Plans</a></p>';
    }

    $joinable = array_filter($levels, fn($lvl) => isset($lvl->allow_signups) ? (bool)$lvl->allow_signups : true);

    $current_level_id = 0; $current_monthly = 0.0;
    if ( function_exists('pmpro_getMembershipLevelForUser') ) {
        $u = pmpro_getMembershipLevelForUser(get_current_user_id());
        if ($u && !empty($u->ID)) {
            $current_level_id = (int)$u->ID;
            if ( function_exists('pmpro_getLevel') ) {
                $full = pmpro_getLevel($current_level_id);
                if ($full) $current_monthly = _3pm_level_monthly_usd($full);
            }
        }
    }

    ob_start();
    echo '<div class="threepm-plans-grid">';
    foreach ($joinable as $level) {
        $name   = _3pm_level_name_with_emoji($level->name);
        $price  = _3pm_format_level_price_line($level);
        $is_current = ((int)$level->id === $current_level_id);

        $btn = 'Select';
        if ($is_current) {
            $btn = 'Your plan';
        } else {
            $target = _3pm_level_monthly_usd($level);
            if ($current_monthly > 0 && $target > 0) {
                if ($target > $current_monthly) $btn = 'Upgrade';
                elseif ($target < $current_monthly) $btn = 'Downgrade';
            }
        }

        echo '<div class="threepm-plan-card'.($is_current?' threepm-plan-current':'').'">';
        echo   '<div class="threepm-plan-header"><h3 class="threepm-plan-title">'.esc_html($name).'</h3></div>';
        if ($price) echo '<div class="threepm-plan-price">'.$price.'</div>';
        $url = $is_current ? '#' : esc_url(_3pm_pmpro_checkout_url($level->id));
        $disabled = $is_current ? ' disabled' : '';
        echo '<a class="button'.$disabled.'" href="'.$url.'" '.($is_current?'onclick="return false;" aria-disabled="true"':'').'>'.$btn.'</a>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="threepm-plans-footer"><a class="threepm-plans-more" href="/plans">View Plan Details &amp; Info</a></div>';

    return ob_get_clean();
});

/* =========================================================
 * Ensure the Plans page exists at /plan-select
 * ========================================================= */
add_action('admin_init', function () {
    if ( ! defined('THREEPM_PLANS_MENU_KEY') ) define('THREEPM_PLANS_MENU_KEY', 'threepm_plans_link');

    $target_slug = 'plan-select';
    $target_title = 'Membership Plans';
    $target_content = '[threepm_plans]';

    $existing = get_page_by_path($target_slug, OBJECT, 'page');
    if ($existing instanceof WP_Post) {
        if ($existing->post_status !== 'publish') {
            wp_update_post(['ID'=>$existing->ID,'post_status'=>'publish']);
        }
        if (strpos($existing->post_content,$target_content)===false) {
            wp_update_post(['ID'=>$existing->ID,'post_content'=>$existing->post_content."\n\n".$target_content]);
        }
        update_option('threepm_plans_page_id',$existing->ID);
        return;
    }

    $stored_id = (int)get_option('threepm_plans_page_id');
    if ($stored_id) {
        $stored = get_post($stored_id);
        if ($stored instanceof WP_Post && $stored->post_type==='page') {
            $ok = wp_update_post(['ID'=>$stored->ID,'post_name'=>$target_slug,'post_status'=>'publish'],true);
            if (!is_wp_error($ok)) {
                if (strpos($stored->post_content,$target_content)===false) {
                    wp_update_post(['ID'=>$stored->ID,'post_content'=>$stored->post_content."\n\n".$target_content]);
                }
                update_option('threepm_plans_page_id',$stored->ID);
                return;
            }
        }
    }

    $cands = get_posts(['post_type'=>'page','post_status'=>'any','posts_per_page'=>50,'s'=>'threepm_plans']);
    foreach ($cands as $p) {
        if (strpos($p->post_content??'',$target_content)!==false) {
            $ok = wp_update_post(['ID'=>$p->ID,'post_name'=>$target_slug,'post_title'=>$p->post_title?:$target_title,'post_status'=>'publish'],true);
            if (!is_wp_error($ok)) { update_option('threepm_plans_page_id',$p->ID); return; }
        }
    }

    $new_id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$target_title,'post_name'=>$target_slug,'post_content'=>$target_content],true);
    if (!is_wp_error($new_id)) update_option('threepm_plans_page_id',$new_id);
});

/* =========================================================
 * Insert "My Plan" into Woo My Account menu (remove Memberships)
 * ========================================================= */
if ( ! defined('THREEPM_PLANS_MENU_KEY') ) define('THREEPM_PLANS_MENU_KEY', 'threepm_plans_link');

add_filter('woocommerce_account_menu_items', function($items){
    unset($items['memberships']);
    $label = __('My Plan','threepm');
    $new = []; $inserted=false;
    foreach($items as $key=>$val){
        $new[$key]=$val;
        if($key==='dashboard' && !$inserted){
            $new[THREEPM_PLANS_MENU_KEY]=$label;
            $inserted=true;
        }
    }
    if(!isset($new[THREEPM_PLANS_MENU_KEY])) $new[THREEPM_PLANS_MENU_KEY]=$label;
    return $new;
},20);

add_filter('woocommerce_get_endpoint_url', function($url,$endpoint,$value,$permalink){
    if($endpoint===THREEPM_PLANS_MENU_KEY){
        $plans_id=(int)get_option('threepm_plans_page_id');
        if($plans_id) return get_permalink($plans_id);
    }
    return $url;
},10,4);

/* =========================================================
 * CSS (SVG icons + neon ring, remove bullets, tiles/buttons)
 * ========================================================= */
add_action('wp_head', function(){
    ?>
    <style>
      :root { --threepm-accent:#ccff00; }

      /* Remove bullets / markers from My Account nav (strong overrides) */
      .woocommerce-MyAccount-navigation ul,
      .woocommerce-MyAccount-navigation ul li {
        list-style: none !important;
      }
      .woocommerce-MyAccount-navigation ul li::marker { content: "" !important; }
      .woocommerce-MyAccount-navigation ul li:before { content: none !important; }

      /* Base link so our absolute icons/rings position nicely */
      .woocommerce-MyAccount-navigation li a {
        position: relative;
        padding-left: 2.6em; /* increased for bigger ring + inner spacing */
        display: inline-block;
      }

      /* Neon ring behind icon (bigger for breathing room) */
      .woocommerce-MyAccount-navigation li a::before {
        content:"";
        position:absolute;
        left:0;
        top:50%;
        transform:translateY(-50%);
        width:1.9em;                 /* bigger ring */
        height:1.9em;
        border:2px solid var(--threepm-accent);
        border-radius:50%;
        box-sizing:border-box;
      }

      /* Icon inside ring (smaller so it doesn't feel cramped) */
      .woocommerce-MyAccount-navigation li a::after {
        content:"";
        position:absolute;
        left:0.45em;                 /* centered within larger ring */
        top:50%;
        transform:translateY(-50%);
        width:0.98em;                /* smaller than before */
        height:0.98em;
        background-color:var(--threepm-accent);
        -webkit-mask:var(--icon) no-repeat center / contain;
                mask:var(--icon) no-repeat center / contain;
      }

      /* ---------- Icon data (inline SVGs) ---------- */
      /* Dashboard = home */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--dashboard a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 3.172 3 10v10a1 1 0 0 0 1 1h6v-6h4v6h6a1 1 0 0 0 1-1V10l-9-6.828Z"/></svg>');
      }

      /* My Plan = crown */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--<?php echo esc_html(THREEPM_PLANS_MENU_KEY); ?> a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3 7.5 7.5 11l2.5-5 3 6 3.5-4 4.5 3V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7.5zM5 18h14v-4.5l-2.7-1.8-3.1 3.5-2.8-5.6L8.7 13 5 10.4V18z"/></svg>');
      }

      /* Orders = receipt */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--orders a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6 2h12a1 1 0 0 1 1 1v18l-3-2-3 2-3-2-3 2V3a1 1 0 0 1 1-1Zm2 5h8v2H8V7Zm0 4h8v2H8v-2Zm0 4h6v2H8v-2Z"/></svg>');
      }

      /* Downloads = thick downward arrow */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--downloads a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11 3h2v9l3.5-3.5L18 10l-6 6-6-6 1.5-1.5L11 12V3Zm-8 14h18v4H3v-4Z"/></svg>');
      }

      /* Addresses = pin */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--edit-address a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5Z"/></svg>');
      }

      /* My Wallet = bills */
      .woocommerce-MyAccount-navigation li[class*="wallet"] a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3 6h15a3 3 0 0 1 3 3v1h-5a3 3 0 0 0-3 3v1a3 3 0 0 0 3 3h5v1a3 3 0 0 1-3 3H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm13 7a1 1 0 0 1 1-1h6v4h-6a1 1 0 0 1-1-1v-2Z"/></svg>');
      }

      /* Account details = gear */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--edit-account a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19.14 12.936a7.96 7.96 0 0 0 .06-.936 7.96 7.96 0 0 0-.06-.936l2.037-1.59a.5.5 0 0 0 .12-.642l-1.93-3.342a.5.5 0 0 0-.607-.22l-2.4.966a7.994 7.994 0 0 0-1.62-.942l-.363-2.54A.5.5 0 0 0 13.87 1h-3.74a.5.5 0 0 0-.495.42l-.363 2.54a7.994 7.994 0 0 0-1.62.942l-2.4-.966a.5.5 0 0 0-.607.22L.716 7.02a.5.5 0 0 0 .12.642l2.037 1.59A7.96 7.96 0 0 0 2.814 12c0 .318.021.63.059.936l-2.037 1.59a.5.5 0 0 0-.12.642l1.93 3.342a.5.5 0 0 0 .607.22l2.4-.966c.5.384 1.045.704 1.62.942l.363 2.54a.5.5 0 0 0 .495.42h3.74a.5.5 0 0 0 .495-.42l.363-2.54c.575-.238 1.12-.558 1.62-.942l2.4.966a.5.5 0 0 0 .607-.22l1.93-3.342a.5.5 0 0 0-.12-.642l-2.037-1.59ZM12 15a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/></svg>');
      }

      /* Payment methods (if enabled) = card */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--payment-methods a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3 5h18a2 2 0 0 1 2 2v2H1V7a2 2 0 0 1 2-2Zm-2 6h22v6a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-6Zm4 4h6v2H8v-2Z"/></svg>');
      }

      /* Logout = door/arrow */
      .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link--customer-logout a {
        --icon:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10 3h8a2 2 0 0 1 2 2v5h-2V5h-8v14h8v-5h2v5a2 2 0 0 1-2 2h-8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm3.293 6.293 1.414-1.414L18.414 11l-3.707 3.121-1.414-1.414L15 12H7v-2h8l-1.707-1.707Z"/></svg>');
      }

      /* ---------- Plans grid & cards ---------- */
      .threepm-plans-grid {
        display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
        gap:16px; margin-top:12px;
      }
      .threepm-plan-card{
        background:#fff; border:1px solid rgba(0,0,0,.08); border-radius:10px;
        padding:16px; color:#111; display:flex; flex-direction:column;
      }
      .threepm-plan-title{margin:0; color:#111; font-size:1.1rem;}
      .threepm-plan-price{margin:10px 0 16px; color:#111; font-weight:600; line-height:1.35;}
      .threepm-plan-price .price-alt{opacity:.75; font-size:.95em;}
      .threepm-plan-price br{content:""; display:block; margin-top:2px;}
      .threepm-plan-header{display:flex; align-items:center; gap:.5rem; justify-content:space-between;}

      /* Plan tile button */
      .threepm-plan-card .button{
        display:block; width:100%; text-align:center; background:#111;
        color:var(--threepm-accent); border:2px solid transparent; padding:10px 0;
        border-radius:6px; font-weight:600; text-decoration:none;
        transition:color .2s,background .2s,border-color .2s; margin-top:auto;
      }
      .threepm-plan-card .button:hover{background:transparent; border-color:#111; color:#111;}
      .threepm-plan-card .button.disabled{pointer-events:none; opacity:.85;}

      /* Footer button under grid */
      .threepm-plans-footer{margin-top:28px; text-align:center;}
      .threepm-plans-more{
        display:inline-block; padding:10px 14px; border-radius:6px; border:2px solid var(--threepm-accent);
        background:transparent; color:var(--threepm-accent); text-decoration:none; font-weight:700;
        transition:color .2s,border-color .2s,background .2s;
      }
      .threepm-plans-more:hover{color:#fff; border-color:#fff; background:transparent;}
    </style>
    <?php
});

/* =========================================================
 * JS Equalizer (equal height cards)
 * ========================================================= */
add_action('wp_footer', function(){
    ?>
    <script>
      (function(){
        function equalizeThreepmCards(){
          const cards=document.querySelectorAll('.threepm-plan-card');
          if(!cards.length) return;
          cards.forEach(c=>c.style.height='auto');
          let maxH=0; cards.forEach(c=>{ if(c.offsetHeight>maxH) maxH=c.offsetHeight; });
          cards.forEach(c=>c.style.height=maxH+'px');
        }
        document.addEventListener('DOMContentLoaded',function(){
          equalizeThreepmCards();
          setTimeout(equalizeThreepmCards,200);
          setTimeout(equalizeThreepmCards,600);
        });
        window.addEventListener('load',equalizeThreepmCards);
        window.addEventListener('resize',equalizeThreepmCards);
      })();
    </script>
    <?php
});

