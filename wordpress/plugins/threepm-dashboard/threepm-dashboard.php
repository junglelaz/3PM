<?php
/**
 * Plugin Name: 3PM Account Insights
 * Description: Private creator statistics, dated engagement tracking and a saved dashboard layout.
 * Version: 1.1.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */
defined('ABSPATH') || exit;
final class ThreePM_Account_Insights {
    private static $before=[];
    static function table(){global $wpdb;return $wpdb->prefix.'threepm_insight_days';}
    static function boot(){
        add_action('init',[__CLASS__,'install']);
        add_action('woocommerce_account_dashboard',[__CLASS__,'screen']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
        add_action('wp_ajax_threepm_insights',[__CLASS__,'ajax']);
        add_action('wp_ajax_threepm_insight_layout',[__CLASS__,'layout']);
        foreach(['wp_ajax_','wp_ajax_nopriv_'] as $prefix)add_action($prefix.'threepm_profile_view',[__CLASS__,'view']);
        add_filter('update_post_metadata',function($check,$id,$key,$value){if($key==='_threepm_streams')self::$before[$id]=(array)get_post_meta($id,$key,true);return $check;},10,4);
        add_action('updated_post_meta',[__CLASS__,'stream'],10,4);
        add_action('added_post_meta',[__CLASS__,'stream'],10,4);
    }
    static function install(){
        if(get_option('threepm_insights_schema')==='1')return;
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=self::table();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (post_id bigint(20) unsigned NOT NULL, metric varchar(16) NOT NULL, day date NOT NULL, amount bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (post_id,metric,day), KEY metric_day (metric,day)) $c;");
        if(!$wpdb->last_error){add_option('threepm_insights_since',gmdate('c'),'','no');update_option('threepm_insights_schema','1',false);}
    }
    static function increment($id,$metric,$amount=1){global $wpdb;$t=self::table();return $wpdb->query($wpdb->prepare("INSERT INTO $t (post_id,metric,day,amount) VALUES (%d,%s,%s,%d) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)",$id,$metric,wp_date('Y-m-d'),$amount));}
    static function stream($meta,$id,$key,$value){
        if($key!=='_threepm_streams'||!is_array($value))return;
        $old=self::$before[$id]??[];unset(self::$before[$id]);$delta=0;
        foreach($value as $track=>$n)$delta+=max(0,(int)$n-(int)($old[$track]??0));
        if($delta)self::increment($id,'streams',$delta);
    }
    static function assets(){
        $base=plugin_dir_url(__FILE__);
        if(function_exists('is_account_page')&&is_account_page()){
            wp_enqueue_style('threepm-insights',$base.'assets/dashboard.css',[],'1.1.5');
            wp_enqueue_script('threepm-insights',$base.'assets/dashboard.js',[],'1.1.5',true);
        }
        if(is_singular('artist')){
            wp_enqueue_script('threepm-profile-views',$base.'assets/profile-views.js',[],'1.1.5',true);
            wp_add_inline_script('threepm-profile-views','window.threepmProfileView='.wp_json_encode(['id'=>get_queried_object_id(),'endpoint'=>admin_url('admin-ajax.php')]).';','before');
        }
    }
    static function view(){
        $id=absint($_POST['profile']??0);
        if(get_post_type($id)!=='artist'||get_post_status($id)!=='publish')wp_send_json_error(null,404);
        $owner=(int)get_post_meta($id,'_threepm_artist_owner',true);
        if(current_user_can('manage_options')||($owner&&$owner===get_current_user_id()))wp_send_json_success(['excluded'=>true]);
        $actor=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'').':'.wp_date('Y-m-d'),wp_salt('auth'));
        $key='3pm_pview_'.hash('sha256',$id.':'.$actor);global $wpdb;$lock='3pm-view-'.substr($key,-40);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1)wp_send_json_error(null,503);
        $ok=true;if(!get_transient($key)){$ok=self::increment($id,'views')!==false;if($ok)set_transient($key,1,DAY_IN_SECONDS);}
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        if(!$ok)wp_send_json_error(null,503);wp_send_json_success();
    }
    static function period($value){return in_array((string)$value,['all','30','60','90'],true)?(string)$value:'all';}
    static function since($period){return $period==='all'?0:(new DateTimeImmutable('today',wp_timezone()))->modify('-'.((int)$period-1).' days')->getTimestamp();}
    static function owned($uid,$types){return get_posts(['post_type'=>$types,'post_status'=>'publish','numberposts'=>-1,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid]);}
    static function days($ids,$metric,$since){
        if(!$ids)return 0;global $wpdb;$t=self::table();$placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql="SELECT COALESCE(SUM(amount),0) FROM $t WHERE post_id IN ($placeholders) AND metric=%s";$args=array_merge($ids,[$metric]);
        if($since){$sql.=' AND day >= %s';$args[]=wp_date('Y-m-d',$since);}
        return (int)$wpdb->get_var($wpdb->prepare($sql,...$args));
    }
    static function metrics($uid,$period){
        $since=self::since($period);$releases=self::owned($uid,['release']);$profiles=self::owned($uid,['artist']);$streams=0;$tracks=0;$products=[];
        foreach($releases as $release){
            $rows=array_filter((array)get_post_meta($release->ID,'track_repeatable',true),'is_array');
            $date=get_post_meta($release->ID,'general_release_details_release_date',true);
            $stamp=$date?strtotime($date.' 00:00:00 '.wp_timezone_string()):get_post_timestamp($release,'date');
            if($stamp&&$stamp<=time()&&(!$since||$stamp>=$since))$tracks+=count($rows);
            foreach((array)get_post_meta($release->ID,'_threepm_streams',true) as $n)$streams+=(int)$n;
            $primary=absint(get_post_meta($release->ID,'_threepm_primary_product_id',true));if($primary)$products[$primary]=true;
            foreach($rows as $track){if(class_exists('ThreePM_Sales_Stats')){$p=ThreePM_Sales_Stats::product($track['releasetrack_buyurl']??'');if($p)$products[$p]=true;}}
        }
        foreach(self::owned($uid,['product']) as $p)$products[$p->ID]=true;
        if($since)$streams=self::days(wp_list_pluck($releases,'ID'),'streams',$since);
        $views=self::days(wp_list_pluck($profiles,'ID'),'views',$since);
        $sinceLabel=wp_date(get_option('date_format'),strtotime(get_option('threepm_insights_since',gmdate('c'))));
        $result=[
            'streams'=>['label'=>'Track streams','value'=>number_format_i18n($streams),'note'=>$since?'Dated measurement since '.$sinceLabel.'.':'Recorded 3PM audio streams; external players excluded.'],
            'views'=>['label'=>'Profile views','value'=>number_format_i18n($views),'note'=>'Measured since '.$sinceLabel.'; your own visits excluded.'],
            'sales'=>['label'=>'Product sales','value'=>'0','note'=>'Units sold, less refunded quantities.'],
            'service_revenue'=>['label'=>'Service revenue','value'=>'Not live yet','note'=>'Awaiting verified service payment and settlement integration.'],
            'agreements'=>['label'=>'Service agreements','value'=>'0','note'=>'Accepted agreements; draft requests excluded.'],
            'active_agreements'=>['label'=>'Active service agreements','value'=>'0','note'=>$since?'Agreements active during this period.':'Agreements active now.'],
            'licenses'=>['label'=>'Sync licences sold','value'=>'Not live yet','note'=>'The licence tool currently saves drafts; payments are not enabled.'],
            'active_licenses'=>['label'=>'Active sync licences','value'=>'Not live yet','note'=>'Coverage starts only after signing and verified payment.'],
            'tracks'=>['label'=>'Tracks released','value'=>number_format_i18n($tracks),'note'=>'Published tracks, filtered by their release date.'],
        ];
        $sales=self::sales($uid,array_keys($products),$since);$result['sales']['value']=number_format_i18n($sales);
        if(!$products)$result['sales']['note']='No shop products linked to your published releases or artist account yet.';
        if(class_exists('ThreePM_Market_Store')){
            global $wpdb;$records=$wpdb->get_results($wpdb->prepare('SELECT state,payload FROM '.ThreePM_Market_Store::table()." WHERE kind='engagement' AND owner=%d",$uid),ARRAY_A);$total=0;$active=0;
            foreach($records as $record){$d=json_decode($record['payload'],true);$accepted=0;$start=0;$end=0;
                foreach(($d['history']??[]) as $event){$at=strtotime($event['at']??'');if(!$at)continue;
                    if(($event['event']??'')==='accept'&&!$accepted)$accepted=$at;
                    if(($event['state']??'')==='active'&&!$start)$start=$at;
                    if(in_array($event['state']??'',['completed','cancelled','refunded','settled'],true))$end=$at;
                }
                if($accepted&&(!$since||$accepted>=$since))$total++;
                if($start&&($since?(!$end||$end>=$since):!$end))$active++;
            }
            $result['agreements']['value']=number_format_i18n($total);$result['active_agreements']['value']=number_format_i18n($active);
        }
        return $result;
    }
    static function sales($uid,$products,$since){
        if(!$products||!function_exists('wc_get_orders'))return 0;
        $key='3pm_ins_sales_'.md5($uid.':'.implode(',',$products).':'.$since.':'.get_option('threepm_sales_cache_version','1'));$cached=get_transient($key);if($cached!==false)return (int)$cached;
        $units=0;$page=1;do{
            $args=['status'=>['processing','completed','refunded'],'limit'=>100,'page'=>$page,'paginate'=>true];if($since)$args['date_paid']='>='.$since;
            $batch=wc_get_orders($args);
            foreach($batch->orders as $order){if(!$order->get_date_paid()||$order->get_status()==='refunded')continue;
                foreach($order->get_items() as $id=>$item){if(!in_array((int)$item->get_product_id(),$products,true)&&!in_array((int)$item->get_variation_id(),$products,true))continue;
                    if((float)$item->get_total()>0&&(float)$order->get_total_refunded_for_item($id)>=(float)$item->get_total())continue;
                    $units+=max(0,(int)$item->get_quantity()-abs((int)$order->get_qty_refunded_for_item($id)));
                }
            }$page++;
        }while($page<=$batch->max_num_pages);
        set_transient($key,$units,MINUTE_IN_SECONDS);return $units;
    }
    static function ajax(){check_ajax_referer('threepm_insights','nonce');if(!is_user_logged_in())wp_send_json_error(null,401);$period=self::period($_POST['period']??'all');if(($_POST['mode']??'')==='fan'){update_user_meta(get_current_user_id(),'_threepm_fan_period',$period);wp_send_json_success(['metrics'=>ThreePM_Fan_Insights::metrics(get_current_user_id(),$period)]);}if(!ThreePM_Fan_Insights::approved(get_current_user_id()))wp_send_json_error(null,403);update_user_meta(get_current_user_id(),'_threepm_insight_period',$period);wp_send_json_success(['metrics'=>self::metrics(get_current_user_id(),$period)]);}
    static function layout(){check_ajax_referer('threepm_insights','nonce');if(!is_user_logged_in())wp_send_json_error(null,401);$fan=($_POST['mode']??'')==='fan';$keys=$fan?['membership','songs','listening','purchases','membership_age','favourites']:['streams','views','sales','service_revenue','agreements','active_agreements','licenses','active_licenses','tracks'];$order=json_decode(wp_unslash($_POST['order']??'[]'),true);if(!is_array($order)||count($order)!==count($keys)||count(array_unique($order))!==count($keys)||array_diff($order,$keys))wp_send_json_error(null,400);update_user_meta(get_current_user_id(),$fan?'_threepm_fan_layout':'_threepm_insight_layout',$order);wp_send_json_success();}
    static function screen(){ThreePM_Fan_Insights::screen();}
    static function artist_screen(){
        $uid=get_current_user_id();if(!$uid)return;$period=self::period(get_user_meta($uid,'_threepm_insight_period',true));$metrics=self::metrics($uid,$period);$order=(array)get_user_meta($uid,'_threepm_insight_layout',true);$order=array_values(array_unique(array_merge(array_intersect($order,array_keys($metrics)),array_keys($metrics))));
        echo '<section class="threepm-insights" data-insights data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('threepm_insights')).'"><header><div><p class="insight-eyebrow">YOUR 3PM ACTIVITY</p><h2>Your overview</h2></div><label>Time period<select data-insight-period>';
        foreach(['all'=>'All time','30'=>'Last 30 days','60'=>'Last 60 days','90'=>'Last 90 days'] as $key=>$label)echo '<option value="'.$key.'" '.selected($period,(string)$key,false).'>'.$label.'</option>';
        echo '</select></label></header><p class="insight-help">Drag tiles to arrange your dashboard, or use their arrow buttons. Your layout is saved to your account.</p><div class="insight-grid">';
        foreach($order as $key){$m=$metrics[$key];echo '<article class="insight-tile" data-metric="'.esc_attr($key).'" draggable="true"><div class="insight-tile-head"><h3>'.esc_html($m['label']).'</h3><span aria-hidden="true" class="insight-grip">⠿</span></div><strong class="insight-value">'.esc_html($m['value']).'</strong><p class="insight-note">'.esc_html($m['note']).'</p><div class="insight-move"><button type="button" data-move="-1" aria-label="Move '.esc_attr($m['label']).' earlier">←</button><button type="button" data-move="1" aria-label="Move '.esc_attr($m['label']).' later">→</button></div></article>';}
        echo '</div><p role="status" aria-live="polite" data-insight-status></p><button type="button" data-insight-retry hidden>Retry</button><p class="insight-help">Figures belong to your artist account. Date ranges include today in the site timezone. Sales may take up to a minute to update.</p></section>';
    }
}
require_once __DIR__.'/fan.php';
ThreePM_Account_Insights::boot();
