<?php
/**
 * Plugin Name: 3PM Suite (Pricing + Wallet + Rate Settings)
 * Description: Single source of truth for $3PM: admin peg (manual or live), Woo dual-display, TeraWallet accounting in coins, wallet UI in $3PM, PMPro plans dual display (using your working snippet pattern), and auto wallet template override.
 * Version:     1.5.1
 * Author:      3PM
 */

if (!defined('ABSPATH')) exit;

final class ThreePM_Suite {
    const OPT_KEY   = 'threepm_rate_settings';
    const TRANS_KEY = 'threepm_rate_cache';

    /* runtime defaults (overridden by settings) */
    const DEF_COIN_CODE   = '3PM';
    const DEF_COIN_SYMBOL = '$3PM';
    const DEF_USD_CODE    = 'USD';
    const DEF_TOPUP_SLUG  = 'wallet-topup';

    public static function boot(){
        // Settings page
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'snapshot_order_rate']);
        add_action('woocommerce_store_api_checkout_update_order_meta', [__CLASS__, 'snapshot_order_rate']);

        // Pricing + wallet hooks
        self::hooks_dual_display();
        self::hooks_wallet_ui();
        self::hooks_wallet_accounting();

        // PMPro hooks (normal)
        add_action('plugins_loaded', [__CLASS__, 'hooks_pmpro'], 20);

        // PMPro “working snippet” bridge — runs LAST to ensure it wins
        add_action('plugins_loaded', [__CLASS__, 'hooks_pmpro_snippet_bridge'], 9999);

        // Activation: try to install wallet template override into theme
        register_activation_hook(__FILE__, [__CLASS__, 'install_wallet_template']);

        // Admin notice if template missing
        add_action('admin_notices', [__CLASS__, 'maybe_notice_template']);
    }

    /* =====================
     * SETTINGS (admin)
     * ===================== */
    public static function menu(){
        add_submenu_page(
            'woocommerce',
            '3PM Coin Rate',
            '3PM Coin Rate',
            'manage_woocommerce',
            'threepm-coin-rate',
            [__CLASS__, 'screen']
        );
    }

    public static function defaults(){
        return [
            // pricing mode
            'mode'         => 'manual',        // manual | live
            'manual_price' => '0.05',          // USD per 1 coin

            // live source
            'live_source'  => [
                'type'         => 'coingecko', // coingecko | custom
                'coingecko_id' => '3pm',
                'custom_url'   => '',
                'path'         => '',          // dot path to USD (optional)
            ],
            'cache_ttl'    => 5,               // minutes

            // display toggles
            'coin_symbol'       => self::DEF_COIN_SYMBOL,
            'coin_code'         => self::DEF_COIN_CODE,
            'usd_code'          => self::DEF_USD_CODE,
            'topup_slug'        => self::DEF_TOPUP_SLUG,
            'coin_decimals'     => 0,          // 0 or 2
            'show_usd_hint_shop'=> 1,          // bool (catalog/cart/checkout & PMPro)
            'show_usd_hint_wallet'=> 1,        // bool
            'force_wallet_3pm'  => 1,          // bool
        ];
    }

    public static function get(){
        $v = get_option(self::OPT_KEY, []);
        $d = self::defaults();
        $v = is_array($v) ? $v : [];
        $out = wp_parse_args($v, $d);
        $out['live_source'] = wp_parse_args(is_array($out['live_source']) ? $out['live_source'] : [], $d['live_source']);
        return $out;
    }

    public static function register_settings(){
        register_setting(self::OPT_KEY, self::OPT_KEY, [__CLASS__,'sanitize']);

        add_settings_section('threepm_main', 'Peg Settings', '__return_false', self::OPT_KEY);
        add_settings_field('mode', 'Mode', [__CLASS__, 'field_mode'], self::OPT_KEY, 'threepm_main');
        add_settings_field('manual_price', 'Manual price (USD per 1 $3PM)', [__CLASS__, 'field_manual'], self::OPT_KEY, 'threepm_main');
        add_settings_field('live_source', 'Live source', [__CLASS__, 'field_live'], self::OPT_KEY, 'threepm_main');
        add_settings_field('cache_ttl', 'Cache duration (minutes)', [__CLASS__, 'field_cache'], self::OPT_KEY, 'threepm_main');

        add_settings_section('threepm_disp', 'Display & Behaviour', '__return_false', self::OPT_KEY);
        add_settings_field('coin_symbol', '$3PM symbol', [__CLASS__, 'field_coin_symbol'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('coin_code', 'Coin code', [__CLASS__, 'field_coin_code'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('usd_code', 'Fiat code', [__CLASS__, 'field_usd_code'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('topup_slug', 'Top-up category slug', [__CLASS__, 'field_topup_slug'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('coin_decimals', 'Coin decimals', [__CLASS__, 'field_coin_decimals'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('show_usd_hint_shop', 'Show ≈ USD in catalog/cart/checkout & PMPro pages', [__CLASS__, 'field_hint_shop'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('show_usd_hint_wallet', 'Show ≈ USD in wallet', [__CLASS__, 'field_hint_wallet'], self::OPT_KEY, 'threepm_disp');
        add_settings_field('force_wallet_3pm', 'Force $3PM currency on wallet page', [__CLASS__, 'field_force_wallet'], self::OPT_KEY, 'threepm_disp');
    }

    public static function sanitize($in){
        $d = self::defaults();
        $in = is_array($in) ? $in : [];
        $previous = self::get();
        $manual = $in['manual_price'] ?? '';
        if (!self::valid_price($manual)) {
            $manual = self::valid_price($previous['manual_price']) ? $previous['manual_price'] : $d['manual_price'];
            add_settings_error(self::OPT_KEY, 'invalid_rate', 'Coin price must be a finite number greater than zero. The previous valid price was retained.');
        }
        $out = [
            'mode'         => in_array($in['mode'] ?? 'manual', ['manual','live'], true) ? $in['mode'] : 'manual',
            'manual_price' => (string) $manual,
            'cache_ttl'    => max(1, intval($in['cache_ttl'] ?? $d['cache_ttl'])),
            'live_source'  => [
                'type'         => in_array($in['live_source']['type'] ?? 'coingecko', ['coingecko','custom'], true) ? $in['live_source']['type'] : 'coingecko',
                'coingecko_id' => sanitize_text_field($in['live_source']['coingecko_id'] ?? $d['live_source']['coingecko_id']),
                'custom_url'   => esc_url_raw($in['live_source']['custom_url'] ?? ''),
                'path'         => sanitize_text_field($in['live_source']['path'] ?? ''),
            ],
            'coin_symbol'        => sanitize_text_field($in['coin_symbol'] ?? $d['coin_symbol']),
            'coin_code'          => preg_replace('/[^A-Z0-9]/','', strtoupper($in['coin_code'] ?? $d['coin_code'])),
            'usd_code'           => preg_replace('/[^A-Z0-9]/','', strtoupper($in['usd_code'] ?? $d['usd_code'])),
            'topup_slug'         => sanitize_title($in['topup_slug'] ?? $d['topup_slug']),
            'coin_decimals'      => in_array(intval($in['coin_decimals'] ?? $d['coin_decimals']), [0,2], true) ? intval($in['coin_decimals']) : 0,
            'show_usd_hint_shop' => empty($in['show_usd_hint_shop']) ? 0 : 1,
            'show_usd_hint_wallet'=> empty($in['show_usd_hint_wallet']) ? 0 : 1,
            'force_wallet_3pm'   => empty($in['force_wallet_3pm']) ? 0 : 1,
        ];
        delete_transient(self::TRANS_KEY);
        return $out;
    }

    public static function screen(){
        if (!current_user_can('manage_woocommerce')) return;
        if (isset($_POST['threepm_refresh'])) {
            check_admin_referer('threepm_refresh_rate');
            delete_transient(self::TRANS_KEY);
            echo '<div class="updated notice"><p>Live rate cache cleared.</p></div>';
        }
        $o = self::get();
        ?>
        <div class="wrap">
            <h1>3PM Coin Rate</h1>
            <p><strong>To change the pre-launch rate:</strong> choose Manual, enter the USD value of one $3PM coin, then click Save Settings. This is the shared rate used by the site.</p>
            <p>Current example: a US$100 eligible purchase earns <strong><?php echo esc_html(number_format_i18n(self::cashback_quote(100)['coins'], 2)); ?> $3PM</strong> at 10% cashback. Cashback belongs in HODL and is locked from withdrawal until the off-site launch.</p>
            <p>New checkout orders record their conversion rate. Changing this setting does not rewrite that saved rate or existing coin balances.</p>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPT_KEY);
                do_settings_sections(self::OPT_KEY);
                submit_button('Save Settings');
                ?>
            </form>

            <hr>
            <p><strong>Current effective rates</strong></p>
            <ul>
                <li>USD per 1 <?php echo esc_html($o['coin_symbol']); ?>: <code><?php echo esc_html( number_format_i18n(self::usd_per_coin(), 6) ); ?></code></li>
                <li><?php echo esc_html($o['coin_code']); ?> per 1 <?php echo esc_html($o['usd_code']); ?>: <code><?php echo esc_html( number_format_i18n(self::usd_to_coin(), 6) ); ?></code></li>
            </ul>
            <form method="post">
                <?php wp_nonce_field('threepm_refresh_rate'); submit_button('Refresh Live Cache Now', 'secondary', 'threepm_refresh', false); ?>
            </form>
            <hr>
            <p class="description">Top-up category slug currently: <code><?php echo esc_html($o['topup_slug']); ?></code></p>
        </div>
        <?php
    }

    /* ---- field renderers ---- */
    public static function field_mode(){
        $o = self::get(); ?>
        <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[mode]" value="manual" <?php checked($o['mode'],'manual'); ?>> Manual</label>
        &nbsp;&nbsp;
        <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[mode]" value="live" <?php checked($o['mode'],'live'); ?>> Live (API)</label>
        <?php
    }
    public static function field_manual(){
        $o = self::get(); ?>
        <input type="number" step="0.000001" min="0" name="<?php echo esc_attr(self::OPT_KEY); ?>[manual_price]" value="<?php echo esc_attr($o['manual_price']); ?>">
        <p class="description">Example: <code>0.10</code> means 1 coin = $0.10 ⇒ 10 coins per $1.</p>
        <?php
    }
    public static function field_live(){
        $o = self::get(); $l = $o['live_source']; ?>
        <p>
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[live_source][type]" value="coingecko" <?php checked($l['type'],'coingecko'); ?>> CoinGecko</label>
            &nbsp;&nbsp;
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[live_source][type]" value="custom" <?php checked($l['type'],'custom'); ?>> Custom URL</label>
        </p>
        <p>
            <label>CoinGecko ID:
                <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[live_source][coingecko_id]" value="<?php echo esc_attr($l['coingecko_id']); ?>" placeholder="e.g. 3pm">
            </label>
        </p>
        <p>
            <label>Custom URL (returns USD/coin):
                <input style="width:480px" type="url" name="<?php echo esc_attr(self::OPT_KEY); ?>[live_source][custom_url]" value="<?php echo esc_attr($l['custom_url']); ?>" placeholder="https://api.example.com/3pm-price-usd">
            </label>
        </p>
        <p>
            <label>JSON Path (optional):
                <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[live_source][path]" value="<?php echo esc_attr($l['path']); ?>" placeholder="market_data.current_price.usd">
            </label>
        </p>
        <p class="description">CoinGecko endpoint: <code>/api/v3/simple/price?ids=&lt;id&gt;&amp;vs_currencies=usd</code></p>
        <?php
    }
    public static function field_cache(){
        $o = self::get(); ?>
        <input type="number" step="1" min="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[cache_ttl]" value="<?php echo esc_attr($o['cache_ttl']); ?>">
        <span class="description">Minutes to cache live API results.</span>
        <?php
    }
    public static function field_coin_symbol(){
        $o = self::get(); ?>
        <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[coin_symbol]" value="<?php echo esc_attr($o['coin_symbol']); ?>" />
        <span class="description">Default: <code><?php echo esc_html(self::DEF_COIN_SYMBOL); ?></code></span>
        <?php
    }
    public static function field_coin_code(){
        $o = self::get(); ?>
        <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[coin_code]" value="<?php echo esc_attr($o['coin_code']); ?>" />
        <span class="description">Shown next to amounts in some places. Default: <code><?php echo esc_html(self::DEF_COIN_CODE); ?></code></span>
        <?php
    }
    public static function field_usd_code(){
        $o = self::get(); ?>
        <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[usd_code]" value="<?php echo esc_attr($o['usd_code']); ?>" />
        <span class="description">Fiat code label. Default: <code>USD</code></span>
        <?php
    }
    public static function field_topup_slug(){
        $o = self::get(); ?>
        <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[topup_slug]" value="<?php echo esc_attr($o['topup_slug']); ?>" />
        <span class="description">Product category slug for Wallet Top-ups.</span>
        <?php
    }
    public static function field_coin_decimals(){
        $o = self::get(); ?>
        <select name="<?php echo esc_attr(self::OPT_KEY); ?>[coin_decimals]">
            <option value="0" <?php selected($o['coin_decimals'],0); ?>>0 (whole coins)</option>
            <option value="2" <?php selected($o['coin_decimals'],2); ?>>2 (allow fractions)</option>
        </select>
        <?php
    }
    public static function field_hint_shop(){
        $o = self::get(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[show_usd_hint_shop]" value="1" <?php checked($o['show_usd_hint_shop'],1); ?>> Show “≈ USD” hints on shop/cart/checkout & PMPro pages</label>
        <?php
    }
    public static function field_hint_wallet(){
        $o = self::get(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[show_usd_hint_wallet]" value="1" <?php checked($o['show_usd_hint_wallet'],1); ?>> Show “≈ USD” hints on wallet page</label>
        <?php
    }
    public static function field_force_wallet(){
        $o = self::get(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[force_wallet_3pm]" value="1" <?php checked($o['force_wallet_3pm'],1); ?>> Force $3PM currency on wallet endpoint</label>
        <?php
    }

    /* ===== effective rate helpers ===== */
    /** Shared quote only: issuing rewards is the HODL ledger's responsibility. */
    public static function cashback_quote($eligible_usd, $usd_per_coin = null){
        $rate = $usd_per_coin === null ? self::usd_per_coin() : $usd_per_coin;
        if (!is_numeric($eligible_usd) || !is_finite((float)$eligible_usd) || (float)$eligible_usd < 0 || !self::valid_price($rate)) {
            throw new InvalidArgumentException('A valid USD amount and coin rate are required.');
        }
        $coins = ((float)$eligible_usd * 0.10) / (float)$rate;
        if (!is_finite($coins)) throw new InvalidArgumentException('Reward amount exceeds supported limits.');
        return ['eligible_usd'=>(string)$eligible_usd, 'cashback_basis_points'=>1000,
            'usd_per_coin'=>(string)$rate, 'coins'=>round($coins, 8),
            'destination'=>'hodl', 'withdrawal_locked'=>true];
    }

    public static function snapshot_order_rate($order){
        if (!$order instanceof WC_Order || $order->get_meta('_threepm_usd_per_coin', true) !== '') return;
        if ($order->get_currency('edit') !== 'USD') return;
        $order->update_meta_data('_threepm_usd_per_coin', (string)self::usd_per_coin());
        $order->update_meta_data('_threepm_rate_recorded_at', gmdate('c'));
        // Classic checkout saves the object after this hook. Store API orders already exist.
        if ($order->get_id()) $order->save_meta_data();
    }

    private static function valid_price($price){
        return is_numeric($price) && is_finite((float) $price) && (float) $price > 0 && is_finite(1 / (float) $price);
    }
    public static function usd_per_coin(){
        $o = self::get();

        $manual = self::valid_price($o['manual_price']) ? (float) $o['manual_price'] : (float) self::defaults()['manual_price'];
        if ($o['mode'] !== 'live') {
            return $manual;
        }
        $cached = get_transient(self::TRANS_KEY);
        if (is_array($cached) && isset($cached['usd_per_coin']) && self::valid_price($cached['usd_per_coin'])) {
            return floatval($cached['usd_per_coin']);
        }
        $price = null;
        if ($o['live_source']['type'] === 'coingecko' && !empty($o['live_source']['coingecko_id'])) {
            $url = add_query_arg(['ids'=>$o['live_source']['coingecko_id'],'vs_currencies'=>'usd'],'https://api.coingecko.com/api/v3/simple/price');
            $price = self::fetch_number($url, $o['live_source']['path'] ?: $o['live_source']['coingecko_id'].'.usd');
        } elseif ($o['live_source']['type'] === 'custom' && !empty($o['live_source']['custom_url'])) {
            $price = self::fetch_number($o['live_source']['custom_url'], $o['live_source']['path']);
        }
        $usd = self::valid_price($price) ? (float) $price : $manual;
        set_transient(self::TRANS_KEY, ['usd_per_coin'=>$usd], max(60, intval($o['cache_ttl'])*MINUTE_IN_SECONDS));
        return $usd;
    }
    public static function usd_to_coin(){
        $p = self::usd_per_coin();
        return $p>0 ? 1/$p : 0.0;
    }
    private static function fetch_number($url, $path=''){
        $r = wp_remote_get($url, ['timeout'=>12]);
        if (is_wp_error($r)) return null;
        $code = wp_remote_retrieve_response_code($r);
        if ($code<200 || $code>=300) return null;
        $body = wp_remote_retrieve_body($r);
        if ($body==='') return null;
        $json = json_decode($body, true);
        if ($json===null) { return is_numeric($body) ? floatval($body) : null; }
        if (!$path) { return self::find_first_numeric($json); }
        $val = self::dig_path($json,$path);
        return is_numeric($val) ? floatval($val) : null;
    }
    private static function dig_path($arr,$path){
        $keys = array_filter(explode('.', (string)$path));
        $cur = $arr;
        foreach ($keys as $k){
            if (!is_array($cur) || !array_key_exists($k,$cur)) return null;
            $cur = $cur[$k];
        }
        return $cur;
    }
    private static function find_first_numeric($arr){
        if (is_numeric($arr)) return (float) $arr;
        if (!is_array($arr)) return null;
        foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($arr)) as $v){
            if (is_numeric($v)) return floatval($v);
        }
        return null;
    }

    /* =====================
     * PRICING (dual display)
     * ===================== */
    protected static function hooks_dual_display(){
        // Product/loop price
        add_filter('woocommerce_get_price_html', function($price_html, $product){
            if (is_admin() && !wp_doing_ajax()) return $price_html;
            $usd = wc_get_price_to_display($product, ['price'=>$product->get_price()]);
            return self::is_topup($product) ? self::fmt_dual($usd,'usd') : self::fmt_dual($usd,'3pm');
        },10,2);

        // Cart item price
        add_filter('woocommerce_cart_item_price', function($price_html, $cart_item){
            if (is_admin() && !wp_doing_ajax()) return $price_html;
            $product  = $cart_item['data'];
            $usd_unit = floatval($product->get_price());
            return self::is_topup($product) ? self::fmt_dual($usd_unit,'usd') : self::fmt_dual($usd_unit,'3pm');
        },10,2);

        // Cart item subtotal
        add_filter('woocommerce_cart_item_subtotal', function($subtotal_html, $cart_item){
            if (is_admin() && !wp_doing_ajax()) return $subtotal_html;
            $usd_line = floatval($cart_item['line_total']);
            return has_term(self::opt('topup_slug'),'product_cat',$cart_item['product_id'])
                ? self::fmt_dual($usd_line,'usd') : self::fmt_dual($usd_line,'3pm');
        },10,2);

        // Totals
        add_filter('woocommerce_cart_totals_subtotal_html', function($html){
            if (is_admin() && !wp_doing_ajax()) return $html;
            $usd = WC()->cart ? WC()->cart->get_subtotal() : 0;
            $primary = self::cart_is_topup_only() ? 'usd' : '3pm';
            return self::fmt_dual($usd,$primary);
        },10,1);

        add_filter('woocommerce_cart_totals_order_total_html', function($html){
            if (is_admin() && !wp_doing_ajax()) return $html;
            $usd = WC()->cart ? WC()->cart->get_total('edit') : 0;
            $primary = self::cart_is_topup_only() ? 'usd' : '3pm';
            return '<strong>'.self::fmt_dual($usd,$primary).'</strong>';
        },10,1);

        add_filter('woocommerce_widget_cart_total', function($value){
            if (is_admin() && !wp_doing_ajax()) return $value;
            $usd = WC()->cart ? WC()->cart->get_total('edit') : 0;
            $primary = self::cart_is_topup_only() ? 'usd' : '3pm';
            return '<strong>'.self::fmt_dual($usd,$primary).'</strong>';
        },10,1);

        // Checkout + emails
        add_filter('woocommerce_get_formatted_order_total', function($total,$order){
            if (is_admin() && !wp_doing_ajax()) return $total;
            $primary='3pm';
            foreach($order->get_items() as $item){
                if (has_term(self::opt('topup_slug'),'product_cat',$item->get_product_id())) { $primary='usd'; break; }
            }
            $usd=floatval($order->get_total());
            return '<strong>'.self::fmt_dual($usd,$primary).'</strong>';
        },10,2);

        add_filter('woocommerce_order_formatted_line_subtotal', function($subtotal,$item,$order){
            if (is_admin() && !wp_doing_ajax()) return $subtotal;
            $usd = floatval($order->get_line_subtotal($item,false,false));
            $primary = has_term(self::opt('topup_slug'),'product_cat',$item->get_product_id()) ? 'usd' : '3pm';
            return self::fmt_dual($usd,$primary);
        },10,3);
    }

    protected static function is_topup($product){
        if(!$product) return false;
        $pid = $product instanceof WC_Product ? $product->get_id() : absint($product);
        return has_term(self::opt('topup_slug'),'product_cat',$pid);
    }

    protected static function cart_is_topup_only(){
        if (!WC()->cart) return false;
        $has_top=false; $has_other=false;
        foreach(WC()->cart->get_cart() as $item){
            if (has_term(self::opt('topup_slug'),'product_cat',$item['product_id'])) $has_top=true;
            else $has_other=true;
        }
        return $has_top && !$has_other;
    }

    protected static function fmt_dual($usd, $primary='3pm'){
        $o = self::get();
        $coin_dec = intval($o['coin_decimals']);
        $usd_html   = wc_price(floatval($usd), ['currency'=>$o['usd_code']]).' '.$o['usd_code'];
        $coins_num  = floatval($usd) * self::usd_to_coin();
        $coins_html = number_format_i18n($coins_num, $coin_dec).' '.$o['coin_symbol'];

        if (!self::is_wallet_endpoint() && !empty($o['show_usd_hint_shop'])) {
            if ($primary==='usd') return sprintf('%s <span class="price-alt">(~%s)</span>', $usd_html, $coins_html);
            return sprintf('%s <span class="price-alt">(~%s)</span>', $coins_html, $usd_html);
        }
        return ($primary==='usd') ? $usd_html : $coins_html;
    }

    /* =====================
     * WALLET — UI + ACCOUNTING
     * ===================== */
    protected static function hooks_wallet_ui(){
        // Register coin as Woo currency so wc_price() can work if needed
        add_filter('woocommerce_currencies', function($curr){
            $curr[self::opt('coin_code')] = self::opt('coin_symbol').' Coins';
            return $curr;
        });
        add_filter('woocommerce_currency_symbol', function($symbol,$currency){
            return $currency===self::opt('coin_code') ? self::opt('coin_symbol') : $symbol;
        },10,2);

        // Force wallet endpoint to show 3PM (toggle)
        add_filter('woocommerce_currency', function($currency){
            return (self::is_wallet_endpoint() && self::opt('force_wallet_3pm')) ? self::opt('coin_code') : $currency;
        });
        add_filter('woo_wallet_currency', function($currency){
            return (self::is_wallet_endpoint() && self::opt('force_wallet_3pm')) ? self::opt('coin_code') : $currency;
        });

        // Append ≈ USD hint under formatted wallet amounts (toggle)
        add_filter('woo_wallet_formatted_price', function($html,$amount){
            if (!self::is_wallet_endpoint()) return $html;
            if (!self::opt('show_usd_hint_wallet')) return $html;

            $usd = floatval($amount) * self::usd_per_coin();
            $html .= sprintf(
                '<div class="wallet-usd-hint" style="font-size:12px;opacity:.8;line-height:1.2">≈ %s %s</div>',
                get_woocommerce_currency_symbol(self::opt('usd_code')),
                number_format_i18n($usd,2)
            );
            return $html;
        },10,2);
    }

    protected static function hooks_wallet_accounting(){
        // TeraWallet 1.6+ normalizes credits/debits through its currency manager.
        // The ledger unit is a coin; WooCommerce products/orders remain in USD.
        add_action('woo_wallet_register_currency_providers', [__CLASS__, 'register_wallet_provider']);
        add_action('woocommerce_init', [__CLASS__, 'configure_wallet_adapter'], 30);
        add_filter('woocommerce_payment_gateways', [__CLASS__, 'wallet_refund_gateway'], 999);
    }

    public static function wallet_active_currency(){
        return (is_admin() || self::is_wallet_endpoint()) ? self::opt('coin_code') : self::opt('usd_code');
    }

    public static function wallet_convert($amount, $from, $to){
        $from = strtoupper((string) $from);
        $to = strtoupper((string) $to);
        $coin = self::opt('coin_code');
        $usd = self::opt('usd_code');
        if (!is_numeric($amount) || !is_finite((float) $amount)) return null;
        if ($from === $to && in_array($from, [$coin, $usd], true)) return (float) $amount;
        $converted = null;
        if ($from === $usd && $to === $coin) $converted = (float) $amount * self::usd_to_coin();
        if ($from === $coin && $to === $usd) $converted = (float) $amount * self::usd_per_coin();
        return $converted !== null && is_finite($converted) ? $converted : null;
    }

    public static function register_wallet_provider($manager){
        if (!interface_exists('Woo_Wallet_Currency_Provider')) return;
        // Never reinterpret a pre-existing fiat ledger as coins.
        global $wpdb;
        $foreign = $wpdb->get_var($wpdb->prepare("SELECT transaction_id FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE deleted=0 AND currency<>%s LIMIT 1", self::opt('coin_code')));
        if ($foreign || $wpdb->last_error || get_option('woocommerce_currency') !== self::opt('usd_code')) {
            add_action('admin_notices', function () {
                if (current_user_can('manage_woocommerce')) echo '<div class="notice notice-error"><p>3PM wallet conversion needs review: the shop currency or existing ledger differs from the coin model. Existing entries have not been changed.</p></div>';
            });
            return;
        }
        $provider = new class implements Woo_Wallet_Currency_Provider {
                public function get_id(){ return 'threepm-coins'; }
                public function get_label(){ return '3PM fixed coin balances'; }
                public function is_available(){ return true; }
                public function get_active_currency(){ return ThreePM_Suite::wallet_active_currency(); }
                public function get_base_currency(){ return ThreePM_Suite::opt('coin_code'); }
                public function get_supported_currencies(){ return [ThreePM_Suite::opt('coin_code'), ThreePM_Suite::opt('usd_code')]; }
                public function convert($amount, $from, $to){ return ThreePM_Suite::wallet_convert($amount, $from, $to); }
                public function get_rate($from, $to){ return $this->convert(1, $from, $to); }
        };
        $manager->register_provider($provider, 1);
    }

    public static function configure_wallet_adapter(){
        static $configured = false;
        if ($configured) return;
        if (!class_exists('Woo_Wallet_Currency_Manager') || Woo_Wallet_Currency_Manager::instance()->get_active_provider_id() !== 'threepm-coins') return;
        $configured = true;
        // The vendor's storefront shim assumes wallet base = shop base. Our
        // coin ledger deliberately differs, so replace only that shim's hooks.
        global $wp_filter;
        $hooks = ['woo_wallet_amount','woo_wallet_rechargeable_amount','woo_wallet_current_balance',
            'woo_wallet_get_option__wallet_settings_general_max_topup_amount',
            'woo_wallet_get_option__wallet_settings_general_min_topup_amount',
            'woo_wallet_get_option__wallet_settings_general_min_transfer_amount',
            'woo_wallet_form_cart_cashback_amount','woo_wallet_form_order_cashback_amount'];
        foreach ($hooks as $hook) {
            if (empty($wp_filter[$hook]->callbacks)) continue;
            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $entry) {
                    $fn = $entry['function'];
                    if (is_array($fn) && is_object($fn[0]) && is_a($fn[0], 'Woo_Wallet_Multicurrency_Integration')) remove_filter($hook, $fn, $priority);
                }
            }
        }
        add_filter('woo_wallet_amount', [__CLASS__, 'wallet_display_amount'], 10, 3);
        add_filter('woo_wallet_current_balance', [__CLASS__, 'wallet_display_balance'], 10, 3);
        add_filter('woo_wallet_rechargeable_amount', [__CLASS__, 'wallet_topup_shop_amount']);
        foreach (['max_topup_amount','min_topup_amount'] as $setting) {
            add_filter('woo_wallet_get_option__wallet_settings_general_'.$setting, [__CLASS__, 'wallet_topup_limit']);
        }
    }

    public static function wallet_refund_coins($refund, $paid_coins, $paid_fiat){
        foreach ([$refund, $paid_coins, $paid_fiat] as $value) {
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) return null;
        }
        if ((float) $refund > (float) $paid_fiat) return null;
        $coins = (float) $paid_coins * ((float) $refund / (float) $paid_fiat);
        return is_finite($coins) ? $coins : null;
    }

    public static function wallet_refund_gateway($gateways){
        if (!class_exists('Woo_Wallet_Currency_Manager') || Woo_Wallet_Currency_Manager::instance()->get_active_provider_id() !== 'threepm-coins') return $gateways;
        foreach ($gateways as $key => $gateway) {
            if ($gateway !== 'Woo_Gateway_Wallet_payment') continue;
            $gateways[$key] = new class extends Woo_Gateway_Wallet_payment {
                public function process_refund($order_id, $amount = null, $reason = ''){
                    $order = wc_get_order($order_id);
                    if (!$order) return new WP_Error('threepm_refund_order', 'Refund order not found.');
                    global $wpdb;
                    $paid = $wpdb->get_row($wpdb->prepare(
                        "SELECT amount, original_amount, original_currency FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE transaction_id=%d AND user_id=%d AND type='debit' AND deleted=0 AND currency=%s",
                        absint($order->get_transaction_id()), $order->get_customer_id(), ThreePM_Suite::opt('coin_code')
                    ));
                    $coins = $paid && $paid->original_currency === $order->get_currency('edit')
                        ? ThreePM_Suite::wallet_refund_coins($amount, $paid->amount, $paid->original_amount) : null;
                    if ($coins === null) return new WP_Error('threepm_refund_rate', 'Original coin payment could not be verified. Review this refund manually.');
                    $transaction_id = woo_wallet()->wallet->credit($order->get_customer_id(), $coins,
                        $reason ?: 'Wallet refund #'.$order->get_order_number(),
                        ['currency'=>ThreePM_Suite::opt('coin_code'), 'category'=>'refund', 'order_id'=>$order->get_order_number()]);
                    if (!$transaction_id) return new WP_Error('threepm_refund_failed', 'Refund was not credited to the wallet.');
                    do_action('woo_wallet_order_refunded', $order, $amount, $transaction_id);
                    return true;
                }
            };
        }
        return $gateways;
    }

    public static function wallet_display_amount($amount, $currency, $user_id = null){
        $converted = self::wallet_convert($amount, $currency, self::wallet_active_currency());
        return $converted === null ? $amount : $converted;
    }
    public static function wallet_display_balance($amount, $user_id, $currency = ''){
        $currency = $currency ?: self::wallet_active_currency();
        $converted = self::wallet_convert($amount, self::opt('coin_code'), $currency);
        return $converted === null ? $amount : $converted;
    }
    public static function wallet_topup_shop_amount($amount){
        return self::wallet_convert($amount, self::wallet_active_currency(), self::opt('usd_code'));
    }
    public static function wallet_topup_limit($amount){
        if (is_admin() || !is_numeric($amount)) return $amount;
        $converted = self::wallet_convert($amount, self::opt('usd_code'), self::wallet_active_currency());
        if ($converted === null) return $amount;
        $unit = 10 ** wc_get_price_decimals();
        $scaled = round($converted * $unit, 6);
        return strpos(current_filter(), '_max_') !== false ? floor($scaled) / $unit : ceil($scaled) / $unit;
    }

    /* =====================
     * PMPro (standard hooks) — baseline coverage
     * ===================== */
    public static function hooks_pmpro(){
        if (!function_exists('pmpro_getAllLevels')) return; // PMPro not active

        // Core level cost text (classic/block templates)
        add_filter('pmpro_level_cost_text', function($text, $level, $tags = null, $short = false, $price = false){
            // Provide a simple dual line; final override comes from snippet bridge
            $o = self::get();
            $usd = 0.0;
            if (!empty($level->billing_amount)) $usd = floatval($level->billing_amount);
            elseif (isset($level->initial_payment)) $usd = floatval($level->initial_payment);
            if ($usd <= 0) return esc_html__('Free', 'pmpro');

            $coin_dec   = intval($o['coin_decimals']);
            $coins_num  = $usd * self::usd_to_coin();
            $coins_html = number_format_i18n($coins_num, $coin_dec).' '.$o['coin_symbol'];
            $usd_html   = wc_price($usd, ['currency'=>$o['usd_code']]).' '.$o['usd_code'];

            $period = '';
            if (!empty($level->cycle_period)) {
                $period = ' '.esc_html__('per','pmpro').' '.esc_html(strtolower($level->cycle_period));
                if (!empty($level->cycle_number) && intval($level->cycle_number)>1) $period .= 's';
            }
            $hint = !empty($o['show_usd_hint_shop']) ? ' <span class="price-alt">(~'.$usd_html.')</span>' : '';
            return $coins_html.$hint.$period;
        }, 20, 5);

        // Advanced Levels add-on (if installed)
        add_filter('pmproal_the_level_cost', function($text, $level){
            return apply_filters('pmpro_level_cost_text','',$level);
        }, 20, 2);
        add_filter('pmproal_level_cost_text', function($text, $level){
            return apply_filters('pmpro_level_cost_text','',$level);
        }, 20, 2);

        // Generic fallback for stray PMPro prices
        add_filter('pmpro_format_price', function($price, $pmpro_currency_symbol = ''){
            $o   = self::get();
            $usd = floatval(preg_replace('/[^0-9.\-]/', '', (string)$price));
            if ($usd <= 0) return $price;

            $coin_dec   = intval($o['coin_decimals']);
            $coins_num  = $usd * self::usd_to_coin();
            $coins_html = number_format_i18n($coins_num, $coin_dec) . ' ' . $o['coin_symbol'];
            $usd_html   = wc_price($usd, ['currency' => $o['usd_code']]) . ' ' . $o['usd_code'];

            return $coins_html . (!empty($o['show_usd_hint_shop']) ? ' <span class="price-alt">(~' . $usd_html . ')</span>' : '');
        }, 20, 2);
    }

    /* =====================
     * PMPro “working snippet” bridge — your logic, powered by Suite peg
     * Runs at very high priority so it wins on Levels/Checkout/Account/Invoices.
     * ===================== */
    public static function hooks_pmpro_snippet_bridge(){
        if (!function_exists('pmpro_getAllLevels')) return;

        // Levels page cost text (Memberships → Levels)
        add_filter('pmpro_level_cost_text', function ($text, $level, $tags = null, $short = false) {
            if ( is_admin() && ! wp_doing_ajax() ) return $text;
            return ThreePM_Suite::pmpro_snippet_cost_text($level);
        }, 9999, 4);

        // Checkout price line(s)
        add_filter('pmpro_subscription_price', function($price, $level) {
            if ( is_admin() && ! wp_doing_ajax() ) return $price;

            $out = [];

            $init = isset($level->initial_payment) ? floatval($level->initial_payment) : 0.0;
            if ($init > 0) {
                $out[] = 'Today: ' . ThreePM_Suite::fmt_dual($init, '3pm');
            }

            $bill = isset($level->billing_amount) ? floatval($level->billing_amount) : 0.0;
            if ($bill > 0) {
                $cycle_number = isset($level->cycle_number) ? intval($level->cycle_number) : 1;
                $cycle_period = isset($level->cycle_period) ? $level->cycle_period : 'Month';
                $limit = isset($level->billing_limit) ? intval($level->billing_limit) : 0;

                $line = 'Then: ' . ThreePM_Suite::fmt_dual($bill, '3pm') . ' per ' . $cycle_number . ' ' . $cycle_period;
                if ($limit > 0) $line .= ' for ' . $limit . ' installments';
                $out[] = $line;
            }

            return $out ? implode(' • ', $out) : $price;
        }, 9999, 2);

        // Fallback pass: confirmation, invoices, account
        add_filter('pmpro_confirmation_message', function($msg) {
            return ThreePM_Suite::pmpro_snippet_enhance_text($msg);
        }, 9999, 1);

        add_filter('pmpro_invoice_bullets', function($bullets, $invoice) {
            if ( is_admin() && ! wp_doing_ajax() ) return $bullets;
            foreach ($bullets as &$b) {
                if (is_string($b)) $b = ThreePM_Suite::pmpro_snippet_enhance_text($b);
            }
            return $bullets;
        }, 9999, 2);

        add_filter('pmpro_account_membership_levels_rows', function($rows, $r) {
            if ( is_admin() && ! wp_doing_ajax() ) return $rows;
            foreach ($rows as &$row) {
                foreach ($row as &$cell) {
                    if (is_string($cell)) $cell = ThreePM_Suite::pmpro_snippet_enhance_text($cell);
                }
            }
            return $rows;
        }, 9999, 2);

        // Tiny CSS polish for (~USD)
        add_action('wp_head', function(){
            echo '<style>.price-alt{opacity:.7;font-size:.95em}</style>';
        });
    }

    private static function pmpro_snippet_cost_text($level){
        $parts = [];

        $init = isset($level->initial_payment) ? floatval($level->initial_payment) : 0.0;
        if ($init > 0) {
            $parts[] = 'Join for ' . ThreePM_Suite::fmt_dual($init, '3pm') . ' now';
        }

        $bill = isset($level->billing_amount) ? floatval($level->billing_amount) : 0.0;
        if ($bill > 0) {
            $num   = isset($level->cycle_number) ? intval($level->cycle_number) : 1;
            $per   = isset($level->cycle_period) ? $level->cycle_period : 'Month';
            $limit = isset($level->billing_limit) ? intval($level->billing_limit) : 0;

            $line = 'then ' . ThreePM_Suite::fmt_dual($bill, '3pm') . ' per ' . trim($num . ' ' . $per);
            if ($limit > 0) $line .= ' for ' . $limit . ' installments';
            $parts[] = $line;
        }

        if (!$parts) return 'Free';

        // Trial (if applicable)
        $trial_amount = isset($level->trial_amount) ? floatval($level->trial_amount) : 0.0;
        $trial_limit  = isset($level->trial_limit) ? intval($level->trial_limit) : 0;
        if ($trial_limit > 0) {
            $period = isset($level->cycle_period) ? strtolower($level->cycle_period) : 'month';
            $parts[] = 'includes a trial of ' . $trial_limit . ' ' . $period .
                       ' at ' . ThreePM_Suite::fmt_dual($trial_amount, '3pm') . ' total';
        }

        return implode(' and ', $parts);
    }

    private static function pmpro_snippet_enhance_text($text){
        if ( is_admin() && ! wp_doing_ajax() ) return $text;
        if (strpos($text, '$') === false) return $text;

        // Convert $12.34 (that isn't already followed by a closing span)
        return preg_replace_callback('/\$(\d+(?:\.\d{1,2})?)(?![^<]*<\/span>)/',
            function($m){
                $usd = floatval($m[1]);
                return ThreePM_Suite::fmt_dual($usd, '3pm');
            },
            $text
        );
    }

    protected static function is_wallet_endpoint(){
        return is_account_page()
            && function_exists('is_wc_endpoint_url')
            && ( is_wc_endpoint_url('woo-wallet') || is_wc_endpoint_url('wallet') || is_wc_endpoint_url('woo-wallet-transactions') || is_wc_endpoint_url('wallet-transactions') );
    }

    /* =====================
     * TEMPLATE OVERRIDE (Wallet auto-install into theme)
     * ===================== */
    public static function install_wallet_template(){
        $theme = get_stylesheet_directory();
        $dir   = trailingslashit($theme).'woo-wallet';
        $file  = trailingslashit($dir).'wc-endpoint-wallet.php';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        if (!is_writable($dir)) return;

        if (!file_exists($file)) {
            $content = self::wallet_template_contents();
            @file_put_contents($file, $content);
        }
    }

    public static function wallet_template_contents(){
        $o = self::get();
        $coin_symbol = $o['coin_symbol'];
        $usd_code    = $o['usd_code'];
        $coin_dec    = intval($o['coin_decimals']);

        return <<<PHP
<?php
defined('ABSPATH') || exit;
global \$wp;

/* helpers from plugin */
if (!function_exists('threepm_rate_coin_to_usd')) { function threepm_rate_coin_to_usd(){ return \\ThreePM_Suite::usd_per_coin(); } }
if (!function_exists('threepm_rate_usd_to_coin')) { function threepm_rate_usd_to_coin(){ return \\ThreePM_Suite::usd_to_coin(); } }
if (!function_exists('threepm_coin_symbol'))      { function threepm_coin_symbol(){ return '{$coin_symbol}'; } }
if (!function_exists('threepm_usd_symbol'))       { function threepm_usd_symbol(){ return get_woocommerce_currency_symbol('{$usd_code}'); } }
if (!function_exists('threepm_format_coins'))     { function threepm_format_coins(\$n,\$d={$coin_dec}){ return number_format_i18n(floatval(\$n),\$d); } }
if (!function_exists('threepm_show_wallet_hint')) { function threepm_show_wallet_hint(){ \$o = get_option('threepm_rate_settings', []); return !empty(\$o['show_usd_hint_wallet']); } }

do_action( 'woo_wallet_before_my_wallet_content' );
\$is_rendred_from_myaccount = wc_post_content_has_shortcode( 'woo-wallet' ) ? false : is_account_page();
\$menu_items = apply_filters( 'woo_wallet_nav_menu_items', array(
    'top_up'=>array(
        'title'=>apply_filters('woo_wallet_account_topup_menu_title',__('Wallet topup','woo-wallet')),
        'url'=>\$is_rendred_from_myaccount ? esc_url( wc_get_endpoint_url( get_option( 'woocommerce_woo_wallet_endpoint','my-wallet'),'add', wc_get_page_permalink('myaccount') ) ) : add_query_arg('wallet_action','add'),
        'icon'=>'dashicons dashicons-plus-alt',
    ),
    'transfer'=>array(
        'title'=>apply_filters('woo_wallet_account_transfer_amount_menu_title',__('Wallet transfer','woo-wallet')),
        'url'=>\$is_rendred_from_myaccount ? esc_url( wc_get_endpoint_url( get_option( 'woocommerce_woo_wallet_endpoint','my-wallet'),'transfer', wc_get_page_permalink('myaccount') ) ) : add_query_arg('wallet_action','transfer'),
        'icon'=>'dashicons dashicons-randomize',
    ),
    'transaction_details'=>array(
        'title'=>apply_filters('woo_wallet_account_transaction_menu_title',__('Transactions','woo-wallet')),
        'url'=>\$is_rendred_from_myaccount ? esc_url( wc_get_account_endpoint_url( get_option( 'woocommerce_woo_wallet_transactions_endpoint','wallet-transactions') ) ) : add_query_arg('wallet_action','view_transactions'),
        'icon'=>'dashicons dashicons-list-view',
    ),
), \$is_rendred_from_myaccount );

\$user_id = get_current_user_id();
\$coin_balance = 0.0;
if ( function_exists('woo_wallet') && woo_wallet() && isset( woo_wallet()->wallet ) ) {
    \$coin_balance = floatval( woo_wallet()->wallet->get_wallet_balance( \$user_id, 'edit', '3PM' ) );
}
\$coins_display = threepm_format_coins(\$coin_balance);
\$usd_display   = number_format_i18n( \$coin_balance * threepm_rate_coin_to_usd(), 2 );
?>
<div class="woo-wallet-my-wallet-container">
    <div class="woo-wallet-sidebar">
        <h3 class="woo-wallet-sidebar-heading">
            <a href="<?php echo \$is_rendred_from_myaccount ? esc_url( wc_get_account_endpoint_url( get_option( 'woocommerce_woo_wallet_endpoint','my-wallet') ) ) : esc_url( get_permalink() ); ?>">
                <?php echo apply_filters( 'woo_wallet_account_menu_title', __( 'My Wallet', 'woo-wallet' ) ); ?>
            </a>
        </h3>
        <ul>
            <?php foreach ( \$menu_items as \$item => \$menu_item ) : ?>
                <?php if ( apply_filters( 'woo_wallet_is_enable_' . \$item, true ) ) : ?>
                    <li class="card"><a href="<?php echo esc_url( \$menu_item['url'] ); ?>"><span class="<?php echo esc_attr( \$menu_item['icon'] ); ?>"></span><p><?php echo esc_html( \$menu_item['title'] ); ?></p></a></li>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php do_action( 'woo_wallet_menu_items' ); ?>
        </ul>
    </div>
    <div class="woo-wallet-content">
        <div class="woo-wallet-content-heading">
            <h3 class="woo-wallet-content-h3"><?php esc_html_e('Balance','woo-wallet'); ?></h3>
            <p class="woo-wallet-price" style="margin:0%;">
                <span class="woocommerce-Price-amount amount" style="font-weight:700;">
                    <?php echo esc_html( \$coins_display . ' ' . threepm_coin_symbol() ); ?>
                </span>
                <?php if ( threepm_show_wallet_hint() ) : ?>
                <span class="wallet-usd-hint" style="display:block;font-size:12px;opacity:.8;line-height:1.2;">
                    <?php printf( '≈ %s %s', esc_html( threepm_usd_symbol() ), esc_html( \$usd_display ) ); ?>
                </span>
                <?php endif; ?>
            </p>
        </div>
        <div style="clear:both"></div>
        <hr/>
        <?php global \$wp;
        if ( ( isset( \$wp->query_vars['woo-wallet'] ) && ! empty( \$wp->query_vars['woo-wallet'] ) ) || isset( \$_GET['wallet_action'] ) ) { ?>
            <?php if ( apply_filters( 'woo_wallet_is_enable_top_up', true ) && ( ( isset( \$wp->query_vars['woo-wallet'] ) && 'add' === \$wp->query_vars['woo-wallet'] ) || ( isset( \$_GET['wallet_action'] ) && 'add' === \$_GET['wallet_action'] ) ) ) { ?>
                <form method="post" action="">
                    <div class="woo-wallet-add-amount">
                        <label for="woo_wallet_balance_to_add"><?php esc_html_e( 'Enter amount', 'woo-wallet' ); ?></label>
                        <?php
                        \$min_amount = woo_wallet()->settings_api->get_option( 'min_topup_amount', '_wallet_settings_general', 0 );
                        \$max_amount = woo_wallet()->settings_api->get_option( 'max_topup_amount', '_wallet_settings_general', '' );
                        ?>
                        <input type="number" step="0.01" min="<?php echo esc_attr( \$min_amount ); ?>" max="<?php echo esc_attr( \$max_amount ); ?>" name="woo_wallet_balance_to_add" id="woo_wallet_balance_to_add" class="woo-wallet-balance-to-add" required />
                        <?php wp_nonce_field( 'woo_wallet_topup', 'woo_wallet_topup' ); ?>
                        <input type="submit" name="woo_add_to_wallet" class="woo-add-to-wallet" value="<?php esc_attr_e( 'Add', 'woo-wallet' ); ?>" />
                    </div>
                </form>
            <?php } elseif ( apply_filters( 'woo_wallet_is_enable_transfer', 'on' === woo_wallet()->settings_api->get_option( 'is_enable_wallet_transfer', '_wallet_settings_general', 'on' ) ) && ( ( isset( \$wp->query_vars['woo-wallet'] ) && 'transfer' === \$wp->query_vars['woo-wallet'] ) || ( isset( \$_GET['wallet_action'] ) && 'transfer' === \$_GET['wallet_action'] ) ) ) { ?> 
                <form method="post" action="" id="woo_wallet_transfer_form">
                    <p class="woo-wallet-field-container form-row form-row-wide">
                        <label for="woo_wallet_transfer_user_id"><?php esc_html_e( 'Select whom to transfer', 'woo-wallet' ); ?> <?php if ( apply_filters( 'woo_wallet_user_search_exact_match', true ) ) { esc_html_e( '(Email)', 'woo-wallet' ); } ?></label>
                        <select name="woo_wallet_transfer_user_id" class="woo-wallet-select2" required></select>
                    </p>
                    <p class="woo-wallet-field-container form-row form-row-wide">
                        <label for="woo_wallet_transfer_amount"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></label>
                        <input type="number" step="0.01" min="<?php echo woo_wallet()->settings_api->get_option( 'min_transfer_amount', '_wallet_settings_general', 0 ); ?>" name="woo_wallet_transfer_amount" required />
                    </p>
                    <p class="woo-wallet-field-container form-row form-row-wide">
                        <label for="woo_wallet_transfer_note"><?php esc_html_e( 'What\'s this for', 'woo-wallet' ); ?></label>
                        <textarea name="woo_wallet_transfer_note"></textarea>
                    </p>
                    <p class="woo-wallet-field_container form-row">
                        <?php wp_nonce_field( 'woo_wallet_transfer', 'woo_wallet_transfer' ); ?>
                        <input type="submit" class="button" name="woo_wallet_transfer_fund" value="<?php esc_attr_e( 'Proceed to transfer', 'woo-wallet' ); ?>" />
                    </p>
                </form>
            <?php } ?>
            <?php do_action( 'woo_wallet_menu_content' ); ?>
        <?php } elseif ( apply_filters( 'woo_wallet_is_enable_transaction_details', true ) ) { ?>
            <?php \$transactions = get_wallet_transactions( array( 'limit' => apply_filters( 'woo_wallet_transactions_count', 10 ) ) ); ?>
            <?php if ( ! empty( \$transactions ) ) { ?>
                <ul class="woo-wallet-transactions-items">
                    <?php foreach ( \$transactions as \$transaction ) : 
                        \$t_amount_coins = floatval( \$transaction->amount );
                        \$t_sign         = ( 'credit' === \$transaction->type ) ? '+' : '-';
                        \$t_coins_disp   = number_format_i18n( \$t_amount_coins, {$coin_dec} );
                        \$t_usd_disp     = number_format_i18n( \$t_amount_coins * threepm_rate_coin_to_usd(), 2 );
                    ?>
                        <li>
                            <div>
                                <p><?php echo wp_kses_post( \$transaction->details ); ?></p>
                                <small><?php echo esc_html( wc_string_to_datetime( \$transaction->date )->date_i18n( wc_date_format() ) ); ?></small>
                            </div>
                            <div class="woo-wallet-transaction-type-<?php echo esc_attr( \$transaction->type ); ?>">
                                <span class="woocommerce-Price-amount amount"><?php echo esc_html( \$t_sign.' '.\$t_coins_disp.' '.threepm_coin_symbol() ); ?></span>
                                <?php if ( threepm_show_wallet_hint() ) : ?>
                                <div class="wallet-usd-hint" style="font-size:11px;opacity:.75;line-height:1.2"><?php printf('≈ %s %s', esc_html( threepm_usd_symbol() ), esc_html( \$t_usd_disp ) ); ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php } else { esc_html_e( 'No transactions found', 'woo-wallet' ); } ?>
        <?php } ?>
    </div>
</div>
<style>.woo-wallet-balance .woocommerce-Price-currencySymbol:before{content:'{$coin_symbol}';}</style>
PHP;
    }

    public static function maybe_notice_template(){
        if (!current_user_can('manage_woocommerce')) return;
        $file = trailingslashit(get_stylesheet_directory()).'woo-wallet/wc-endpoint-wallet.php';
        if (!file_exists($file)) {
            echo '<div class="notice notice-warning"><p><strong>3PM Suite:</strong> Wallet template override not found in your theme. The plugin tried to install it on activation. If you still see USD on the wallet page, ensure this file exists: <code>'.esc_html($file).'</code>.</p></div>';
        }
    }

    /* ===== util ===== */
    public static function opt($key){
        $o = self::get();
        return $o[$key] ?? null;
    }
}

ThreePM_Suite::boot();

/* Public helpers (stable names for external snippets, if any) */
if (!function_exists('threepm_rate_usd_to_coin')) {
    function threepm_rate_usd_to_coin(){ return ThreePM_Suite::usd_to_coin(); } // coins per $1
}
if (!function_exists('threepm_rate_coin_to_usd')) {
    function threepm_rate_coin_to_usd(){ return ThreePM_Suite::usd_per_coin(); } // $ per 1 coin
}
