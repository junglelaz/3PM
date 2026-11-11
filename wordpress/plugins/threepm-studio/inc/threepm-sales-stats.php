<?php
defined('ABSPATH') || exit;
/** Read-only WooCommerce reporting. Bundles are never attributed in full to every track. */
final class ThreePM_Sales_Stats {
    static function boot(){
        foreach(['woocommerce_order_status_changed','woocommerce_order_refunded','woocommerce_refund_deleted','woocommerce_saved_order_items'] as $hook)
            add_action($hook,function(){update_option('threepm_sales_cache_version',wp_generate_uuid4(),false);});
    }
    static function product($url){
        if(!$url)return 0;
        if(str_starts_with($url,'/')&&!str_starts_with($url,'//'))$url=home_url($url);
        if(strtolower((string)wp_parse_url($url,PHP_URL_HOST))!==strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)))return 0;
        $id=url_to_postid($url);return get_post_type($id)==='product'?(int)$id:0;
    }
    static function report($product_id){
        static $request=[];
        if(isset($request[$product_id]))return $request[$product_id];
        $key='3pm_sales_'.md5($product_id.':'.get_option('threepm_sales_cache_version','1'));
        $cached=get_transient($key);if(is_array($cached))return $request[$product_id]=$cached;
        $totals=[];$page=1;
        do{
            $result=wc_get_orders(['status'=>['processing','completed','refunded'],'limit'=>100,'page'=>$page,'paginate'=>true,'orderby'=>'ID','order'=>'ASC']);
            foreach($result->orders as $order){
                if(!$order->get_date_paid())continue;
                $currency=$order->get_currency('edit');$net=0;$units=0;
                foreach($order->get_items('line_item')as $id=>$item){
                    if(!in_array($product_id,[(int)$item->get_product_id(),(int)$item->get_variation_id()],true))continue;
                    $net+=max(0,(float)$item->get_total()-(float)$order->get_total_refunded_for_item($id));
                    $units+=max(0,(int)$item->get_quantity()-abs((int)$order->get_qty_refunded_for_item($id)));
                }
                if(!$net)continue;
                $allocated=0;
                foreach($order->get_refunds()as $refund)foreach($refund->get_items(['line_item','shipping','fee'])as $item)
                    $allocated+=max(0,-(float)$item->get_total())+max(0,-(float)$item->get_total_tax());
                $unallocated=max(0,(float)$order->get_total_refunded()-$allocated);
                $net=ThreePM_Hodl::remaining($net,$unallocated,max(0,(float)$order->get_total('edit')-$allocated));
                if($order->get_status()==='refunded')$net=0;
                if($net<=0)continue;
                if(!isset($totals[$currency]))$totals[$currency]=['purchases'=>0,'units'=>0,'net'=>0];
                $totals[$currency]['purchases']++;$totals[$currency]['units']+=$units;$totals[$currency]['net']+=$net;
            }
            $page++;
        }while($page<=$result->max_num_pages);
        set_transient($key,$totals,5*MINUTE_IN_SECONDS);return $request[$product_id]=$totals;
    }
    static function display($product_id,$label=''){
        if(!$product_id)return '<p class="threepm-metrics">Purchases: not linked to a shop product.</p>';
        $report=self::report($product_id);
        $out='<div class="threepm-metrics">';
        if($label)$out.='<strong>'.esc_html($label).'</strong> ';
        if(!$report)$out.='<span>0 purchases · '.wp_kses_post(wc_price(0)).' net sales</span>';
        foreach($report as $currency=>$v)$out.='<span>'.number_format_i18n($v['purchases']).' purchases · '.wp_kses_post(wc_price($v['net'],['currency'=>$currency])).' net sales</span> ';
        return $out.'<small>After discounts and refunds, before artist/platform allocation. Excludes tax and shipping.</small></div>';
    }
    static function track($release_id,$track){
        $product=self::product($track['releasetrack_buyurl']??'');if(!$product)return self::display(0);
        $matches=0;foreach(ThreePM_Music_Library::tracks($release_id)as $row)if(self::product($row['releasetrack_buyurl']??'')===$product)$matches++;
        return $matches>1?'<p class="threepm-metrics">Sold in the release bundle; sales are shown once above.</p>':self::display($product);
    }
    static function bundles($release_id){
        $products=[];foreach(ThreePM_Music_Library::tracks($release_id)as $track){$p=self::product($track['releasetrack_buyurl']??'');if($p)$products[$p]=($products[$p]??0)+1;}
        $out='';foreach($products as $p=>$count)if($count>1)$out.=self::display($p,'Release bundle');return $out;
    }
}
ThreePM_Sales_Stats::boot();
