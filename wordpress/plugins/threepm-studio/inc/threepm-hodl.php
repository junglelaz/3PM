<?php
defined('ABSPATH') || exit;

/** Locked, off-chain purchase rewards. This ledger has no transfer or withdrawal operation. */
final class ThreePM_Hodl {
    const VERSION = '1';
    static function table(){ global $wpdb; return $wpdb->prefix.'threepm_hodl_rewards'; }
    static function boot(){
        add_action('admin_init',[__CLASS__,'install']);
        add_action('init',function(){ add_rewrite_endpoint('hodl-wallet',EP_PAGES); });
        add_filter('woocommerce_account_menu_items',function($items){
            unset($items['hodl-wallet']);return $items;
        },90);
        add_action('template_redirect',function(){
            if(function_exists('is_account_page')&&is_account_page()&&get_query_var('hodl-wallet',null)!==null){wp_safe_redirect(wc_get_account_endpoint_url(get_option('woocommerce_woo_wallet_endpoint','my-wallet')));exit;}
        },5);
        add_action('woocommerce_account_content',function(){
            if(is_wc_endpoint_url('woo-wallet')||is_wc_endpoint_url('my-wallet')||is_wc_endpoint_url('wallet'))self::screen();
        },90);
        add_action('woocommerce_account_woo-wallet_endpoint',function(){ob_start(function($html){$html=preg_replace_callback('~<h3([^>]*class=[\"\'][^\"\']*woo-wallet-content-h3[^\"\']*[\"\'][^>]*)>.*?</h3>~s',fn()=>'<div class="threepm-wallet-title"><span class="threepm-wallet-symbol">$3PM</span><h2>Spend Wallet</h2></div>',$html,1);if(function_exists('woo_wallet')&&isset(woo_wallet()->wallet)){$balance=(float)woo_wallet()->wallet->get_wallet_balance(get_current_user_id(),'edit');$html=preg_replace_callback('~(<span[^>]*class="woocommerce-Price-amount amount"[^>]*>)[^<]*(</span>)~',fn($m)=>$m[1].esc_html(number_format_i18n($balance,2).' $3PM').$m[2],$html,1);}return preg_replace_callback('~(<span[^>]*class="wallet-usd-hint"[^>]*>)(.*?)(</span>)~s',fn($m)=>$m[1].rtrim($m[2]).(str_contains($m[2],'USD')?'':' USD').$m[3],$html,1);});},0);
        add_action('woocommerce_account_woo-wallet_endpoint',function(){if(ob_get_level())ob_end_flush();},999);
        add_action('woocommerce_checkout_create_order',[__CLASS__,'capture'],30);
        add_action('woocommerce_store_api_checkout_update_order_meta',[__CLASS__,'capture'],30);
        foreach(['woocommerce_payment_complete','woocommerce_order_status_processing','woocommerce_order_status_completed','woocommerce_order_status_cancelled','woocommerce_order_status_failed','woocommerce_order_status_refunded','woocommerce_order_refunded'] as $hook)
            add_action($hook,[__CLASS__,'sync'],5);
        add_action('threepm_hodl_retry',[__CLASS__,'sync'],10,2);
        add_action('woocommerce_refund_deleted',function($refund_id,$order_id){self::sync($order_id);},20,2);
        // Existing top-up and membership rules remain responsible for other orders.
        add_filter('process_woo_wallet_general_cashback',function($allowed,$order){return $order instanceof WC_Order && $order->get_meta('_threepm_hodl_policy')===self::VERSION ? false : $allowed;},999,2);
        add_action('admin_menu',function(){add_submenu_page('woocommerce','3PM HODL Rewards','3PM HODL Rewards','manage_woocommerce','threepm-hodl',[__CLASS__,'admin']);});
        add_action('admin_post_threepm_hodl_settings',[__CLASS__,'settings']);
    }
    static function install(){
        if(!current_user_can('manage_woocommerce')||get_option('threepm_hodl_schema')===self::VERSION)return;
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$table=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            order_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            coins decimal(24,8) NOT NULL DEFAULT 0,
            usd_per_coin decimal(24,12) NOT NULL,
            eligible_usd decimal(20,8) NOT NULL,
            history longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $charset;");
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table){
            update_option('threepm_hodl_schema',self::VERSION,false);
            add_rewrite_endpoint('hodl-wallet',EP_PAGES);flush_rewrite_rules(false);
        }
    }
    static function eligible($product){
        if(!$product||!class_exists('ThreePM_Suite'))return false;
        $id=$product->get_parent_id()?:$product->get_id();
        if(has_term(ThreePM_Suite::opt('topup_slug'),'product_cat',$id))return false;
        // Explicit exclusion keeps service contracts out of digital-goods rewards.
        if(get_post_meta($id,'_threepm_hodl_excluded',true)==='yes')return false;
        return $product->is_downloadable()||get_post_meta($id,'_threepm_collectible',true)==='yes';
    }
    static function capture($order){
        if(!get_option('threepm_hodl_enabled',false)||!$order instanceof WC_Order||!class_exists('ThreePM_Suite'))return;
        if($order->get_meta('_threepm_hodl_policy')||!$order->get_customer_id()||$order->get_currency('edit')!=='USD')return;
        $rate=$order->get_meta('_threepm_usd_per_coin',true);
        if(!is_numeric($rate)||$rate<=0)return;
        $eligible=false;
        foreach($order->get_items('line_item') as $item){
            if(!self::eligible($item->get_product()))continue;
            $amount=max(0,(float)$item->get_total());if(!$amount)continue;
            $item->update_meta_data('_threepm_hodl_eligible_usd',(string)$amount);
            if($item->get_id())$item->save_meta_data();$eligible=true;
        }
        if(!$eligible)return;
        $order->update_meta_data('_threepm_hodl_policy',self::VERSION);
        $order->update_meta_data('_threepm_hodl_customer',$order->get_customer_id());
        if($order->get_id())$order->save_meta_data();
    }
    /** Refunds without line assignments reduce the remaining eligible portion proportionally. */
    static function remaining($eligible,$unallocated,$remaining_gross){
        foreach([$eligible,$unallocated,$remaining_gross]as $n)if(!is_numeric($n)||!is_finite((float)$n)||$n<0)throw new InvalidArgumentException('Invalid refund amounts.');
        if($remaining_gross<=0)return 0.0;
        return max(0,(float)$eligible)*(1-min(1,(float)$unallocated/(float)$remaining_gross));
    }
    static function target($order){
        if(!$order->is_paid())return 0.0;
        $eligible=0;
        foreach($order->get_items('line_item')as $id=>$item){
            $base=$item->get_meta('_threepm_hodl_eligible_usd',true);
            if(is_numeric($base))$eligible+=max(0,(float)$base-max(0,(float)$order->get_total_refunded_for_item($id)));
        }
        $allocated=0;
        foreach($order->get_refunds()as $refund)foreach($refund->get_items(['line_item','shipping','fee'])as $item)
            $allocated+=max(0,-(float)$item->get_total())+max(0,-(float)$item->get_total_tax());
        $unallocated=max(0,(float)$order->get_total_refunded()-$allocated);
        return self::remaining($eligible,$unallocated,max(0,(float)$order->get_total('edit')-$allocated));
    }
    static function retry($order_id,$attempt){
        if($attempt>=5)return;
        $args=[absint($order_id),$attempt+1];
        if(function_exists('as_schedule_single_action'))as_schedule_single_action(time()+60*($attempt+1),'threepm_hodl_retry',$args,'threepm-hodl',true);
        elseif(!wp_next_scheduled('threepm_hodl_retry',$args))wp_schedule_single_event(time()+60*($attempt+1),'threepm_hodl_retry',$args);
    }
    static function sync($order_id,$attempt=0){
        if(!class_exists('ThreePM_Suite')||get_option('threepm_hodl_schema')!==self::VERSION)return;
        global $wpdb;$lock='3pm-hodl-'.get_current_blog_id().'-'.absint($order_id);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1){self::retry($order_id,$attempt);return;}
        try{
            $order=wc_get_order($order_id);
            if(!$order||$order->get_meta('_threepm_hodl_policy')!==self::VERSION)return;
            $uid=(int)$order->get_meta('_threepm_hodl_customer');
            if(!$uid||$uid!==$order->get_customer_id())throw new RuntimeException('Reward customer changed.');
            $rate=$order->get_meta('_threepm_usd_per_coin',true);
            $usd=self::target($order);$quote=ThreePM_Suite::cashback_quote($usd,$rate);
            $coins=number_format($quote['coins'],8,'.','');$table=self::table();
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE order_id=%d",$order_id),ARRAY_A);
            if($wpdb->last_error)throw new RuntimeException('Reward ledger unavailable.');
            if($existing&&((int)$existing['user_id']!==$uid||(float)$existing['usd_per_coin']!==(float)$rate))throw new RuntimeException('Reward identity or rate changed.');
            if($existing&&$existing['coins']===$coins)return;
            if(!$existing&&$quote['coins']<=0)return;
            $history=$existing?json_decode($existing['history'],true):[];
            if(!is_array($history))throw new RuntimeException('Reward history unavailable.');
            $history[]=['at'=>gmdate('c'),'before'=>$existing['coins']??'0.00000000','after'=>$coins,'order_status'=>$order->get_status(),'refunded_usd'=>(string)$order->get_total_refunded()];
            $data=['user_id'=>$uid,'coins'=>$coins,'usd_per_coin'=>$rate,'eligible_usd'=>$usd,'history'=>wp_json_encode($history),'updated_at'=>current_time('mysql',true)];
            $saved=$existing?$wpdb->update($table,$data,['order_id'=>$order_id]):$wpdb->insert($table,['order_id'=>$order_id]+$data);
            if($saved===false)throw new RuntimeException('Reward could not be recorded.');
        }catch(Throwable $e){if(function_exists('wc_get_logger'))wc_get_logger()->error('HODL reward needs review for order '.absint($order_id).': '.$e->getMessage(),['source'=>'threepm-hodl']);self::retry($order_id,$attempt);}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    static function screen(){
        if(!is_user_logged_in())return;
        global $wpdb;$uid=get_current_user_id();$table=self::table();
        echo '<section class="tpm-market threepm-hodl-panel">';
        if(get_option('threepm_hodl_schema')!==self::VERSION){echo '<p>HODL rewards are being set up.</p></section>';return;}
        $balance=$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(coins),0) FROM $table WHERE user_id=%d",$uid));
        if($wpdb->last_error){echo '<p>Your balance is temporarily unavailable. Please try again shortly.</p></section>';return;}
        echo '<div class="threepm-wallet-heading"><div class="threepm-wallet-title"><span class="threepm-wallet-symbol">$3PM</span><h2>HODL Wallet*</h2></div><p class="woo-wallet-price"><span class="woocommerce-Price-amount amount">'.esc_html(number_format_i18n((float)$balance,2)).' $3PM</span><span class="wallet-usd-hint">≈ $ '.esc_html(number_format_i18n((float)$balance*ThreePM_Suite::usd_per_coin(),2)).' USD</span></p></div><p class="threepm-wallet-description">* Your cashback stays locked until $3PM launches off-site. It cannot be spent, transferred or withdrawn before launch.</p>';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT order_id,coins,updated_at FROM $table WHERE user_id=%d ORDER BY updated_at DESC LIMIT 50",$uid));
        if(!$rows)echo '<p>You haven’t earned any HODL cashback yet.</p>';
        else{echo '<ul>';foreach($rows as $r)echo '<li>Order #'.absint($r->order_id).' — '.esc_html(number_format_i18n((float)$r->coins,2)).' $3PM</li>';echo '</ul>';}
        echo '</section>';
    }
    static function checks(){
        if(!class_exists('ThreePM_Suite')||!method_exists('ThreePM_Suite','cashback_quote'))return false;
        $basic=abs(ThreePM_Suite::cashback_quote(100,.05)['coins']-200)<.00000001
            &&abs(ThreePM_Suite::cashback_quote(100,.10)['coins']-100)<.00000001
            &&abs(self::remaining(100,25,100)-75)<.00000001
            &&self::remaining(100,100,100)===0.0
            &&ThreePM_Suite::cashback_quote(0,.05)['coins']==0;
        if(!$basic)return false;
        // In-memory order fixtures: never create an order, charge, refund or credit a real wallet.
        $fixture=function($paid,$gross,$bases,$line_refunds,$refund_amount,$allocated,$tax){
            return new class($paid,$gross,$bases,$line_refunds,$refund_amount,$allocated,$tax){
                function __construct(public $paid,public $gross,public $bases,public $line_refunds,public $refund_amount,public $allocated,public $tax){}
                function is_paid(){return $this->paid;}
                function get_total($context=''){return $this->gross;}
                function get_items($type){$out=[];foreach($this->bases as $id=>$base)$out[$id]=new class($base){function __construct(public $base){} function get_meta($key,$single){return $this->base;}};return $out;}
                function get_total_refunded_for_item($id){return $this->line_refunds[$id]??0;}
                function get_total_refunded(){return $this->refund_amount;}
                function get_refunds(){return [new class($this->allocated,$this->tax){
                    function __construct(public $amount,public $tax){}
                    function get_items($types){return [new class($this->amount,$this->tax){function __construct(public $amount,public $tax){} function get_total(){return -$this->amount;}function get_total_tax(){return -$this->tax;}}];}
                }];}
            };
        };
        $cases=[
            [$fixture(true,120,[1=>100],[],0,0,0),100],
            [$fixture(true,120,[1=>100],[1=>25],30,25,5),75],
            [$fixture(true,120,[1=>100],[],30,0,0),75],
            [$fixture(true,200,[1=>100,2=>''],[2=>50],50,50,0),100],
            [$fixture(true,120,[1=>100],[],20,0,20),100],
            [$fixture(true,120,[1=>100],[1=>100],120,100,20),0],
            [$fixture(false,120,[1=>100],[],0,0,0),0],
        ];
        foreach($cases as [$order,$expected])if(abs(self::target($order)-$expected)>.00000001)return false;
        foreach([[-1,.05],[100,0],[100,-1],[INF,.05]]as [$amount,$rate]){
            try{ThreePM_Suite::cashback_quote($amount,$rate);return false;}catch(InvalidArgumentException $e){}
        }
        return true;
    }
    static function admin(){
        if(!current_user_can('manage_woocommerce'))return;
        echo '<div class="wrap"><h1>3PM HODL Rewards</h1><p>10% cashback on downloadable products and explicitly marked collectibles, excluding top-ups. Rewards use the checkout’s saved rate and remain locked until the off-site launch.</p><p>Taxes, shipping and fees do not earn cashback. Refunds adjust the original reward. Existing orders are not backfilled.</p><p><a href="'.esc_url(admin_url('admin.php?page=threepm-coin-rate')).'">Update the shared coin rate</a></p><p>Calculation checks: '.(self::checks()?'Passed':'Not ready').'. Ledger: '.(get_option('threepm_hodl_schema')===self::VERSION?'Ready':'Not ready').'.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('threepm_hodl_settings');
        echo '<input type="hidden" name="action" value="threepm_hodl_settings"><label><input type="checkbox" name="enabled" value="1" '.checked(get_option('threepm_hodl_enabled',false),true,false).'> Enable HODL cashback for new eligible purchases</label><p><button class="button button-primary">Save reward settings</button></p></form></div>';
    }
    static function settings(){
        if(!current_user_can('manage_woocommerce'))wp_die('Access denied.','Forbidden',['response'=>403]);
        check_admin_referer('threepm_hodl_settings');
        if(isset($_POST['enabled'])&&(!self::checks()||get_option('threepm_hodl_schema')!==self::VERSION))wp_die('Reward checks must pass first.');
        update_option('threepm_hodl_enabled',isset($_POST['enabled']),false);
        wp_safe_redirect(admin_url('admin.php?page=threepm-hodl'));exit;
    }
}
ThreePM_Hodl::boot();
