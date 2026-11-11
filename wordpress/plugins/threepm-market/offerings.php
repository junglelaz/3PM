<?php
/** WordPress projection of versioned service records. Agreements remain immutable snapshots. */
final class ThreePM_Market_Offerings {
    const TYPE='service_offering';
    public static function boot(){
        add_action('init',[__CLASS__,'register']);
        add_filter('manage_'.self::TYPE.'_posts_columns',function($cols){return ['cb'=>$cols['cb']??'','title'=>'Service offering','creator'=>'Creator','category'=>'Service category','price'=>'Price','rating'=>'Rating','market_state'=>'Market status','date'=>'Updated'];});
        add_action('manage_'.self::TYPE.'_posts_custom_column',[__CLASS__,'column'],10,2);
        add_filter('post_row_actions',[__CLASS__,'actions'],10,2);
        add_filter('bulk_actions-edit-'.self::TYPE,function(){return [];});
        add_action('admin_menu',function(){add_submenu_page('edit.php?post_type='.self::TYPE,'Manage service offering','Manage offering','manage_options','threepm-offering-editor',[__CLASS__,'editor']);});
        add_action('load-post.php',function(){if(!empty($_GET['post'])&&get_post_type(absint($_GET['post']))===self::TYPE){wp_safe_redirect(self::editor_url((int)get_post_meta(absint($_GET['post']),'_threepm_service_record',true)));exit;}});
        add_filter('template_include',function($template){return is_singular(self::TYPE)?__DIR__.'/templates/single-service-offering.php':$template;});
        add_action('template_redirect',[__CLASS__,'guard'],1);
    }
    public static function register(){
        register_post_type(self::TYPE,['labels'=>['name'=>'Service Offerings','singular_name'=>'Service Offering','menu_name'=>'Service Offerings','all_items'=>'All Service Offerings','edit_item'=>'Manage Service Offering','view_item'=>'View Service Offering','search_items'=>'Search Service Offerings','not_found'=>'No service offerings yet. Creators can add them from My Services.'],
            'public'=>true,'show_ui'=>true,'show_in_rest'=>false,'has_archive'=>false,'rewrite'=>['slug'=>'service-offering','with_front'=>false],'menu_icon'=>'dashicons-store','supports'=>false,'exclude_from_search'=>true,
            'capabilities'=>['edit_posts'=>'manage_options','edit_others_posts'=>'manage_options','edit_post'=>'manage_options','read_private_posts'=>'manage_options','publish_posts'=>'do_not_allow','delete_posts'=>'do_not_allow','delete_post'=>'do_not_allow','create_posts'=>'do_not_allow'],'map_meta_cap'=>false]);
    }
    public static function post_id($record){$ids=get_posts(['post_type'=>self::TYPE,'post_status'=>['publish','draft','private','pending','trash'],'fields'=>'ids','numberposts'=>1,'meta_key'=>'_threepm_service_record','meta_value'=>(int)$record]);return (int)($ids[0]??0);}
    public static function public_record($r){return $r&&$r['kind']==='service'&&$r['state']==='published'&&empty($r['data']['moderation_hold'])&&ThreePM_Market::approved((int)$r['owner']);}
    public static function sync($r){
        if($r['kind']!=='service')return;$id=self::post_id($r['id']);$t=$r['data']['terms'];
        $post=['post_type'=>self::TYPE,'post_status'=>self::public_record($r)?'publish':'draft','post_title'=>$t['title']?:'Untitled service offering','post_content'=>'','post_excerpt'=>'','post_author'=>(int)$r['owner'],'comment_status'=>'closed','ping_status'=>'closed','meta_input'=>['_threepm_service_record'=>(int)$r['id'],'_threepm_service_category'=>$t['category'],'_threepm_service_state'=>$r['state'],'_threepm_service_price'=>$t['price_coin_micros']]];
        if($id)$post['ID']=$id;else $post['post_name']='offering-'.$r['id'].'-'.sanitize_title($t['title']);
        $result=wp_insert_post(wp_slash($post),true);if(is_wp_error($result))throw new RuntimeException('The service was saved but its public page needs synchronization. Save again to retry.');
        clean_post_cache($result);do_action('litespeed_purge_post',$result);$market=(int)get_option('threepm_market_page_market');if($market)do_action('litespeed_purge_post',$market);
        return $result;
    }
    public static function permalink($r){if(!self::public_record($r))return add_query_arg('preview_offering',$r['id'],ThreePM_Market::url('services'));$id=self::post_id($r['id']);return $id?get_permalink($id):add_query_arg('record',$r['id'],ThreePM_Market::url('market'));}
    public static function editor_url($id){return add_query_arg(['post_type'=>self::TYPE,'page'=>'threepm-offering-editor','record'=>$id],admin_url('edit.php'));}
    public static function actions($actions,$post){if($post->post_type!==self::TYPE)return $actions;$id=(int)get_post_meta($post->ID,'_threepm_service_record',true);return ['manage'=>'<a href="'.esc_url(self::editor_url($id)).'">Manage offering</a>','view'=>'<a href="'.esc_url(get_permalink($post)).'">View</a>'];}
    public static function column($column,$id){$r=ThreePM_Market_Store::get((int)get_post_meta($id,'_threepm_service_record',true));if(!$r)return;$t=$r['data']['terms'];$a=ThreePM_Market::account((int)$r['owner']);$values=['rating'=>ThreePM_Market_Reviews::label((int)$r['id']),'creator'=>($a['data']['artist_name']??'Creator').' (#'.$r['owner'].')','category'=>ThreePM_Market_Domain::CATEGORIES[$t['category']]??'Service','price'=>ThreePM_Market_Domain::price_label($t['price_coin_micros']),'market_state'=>ucfirst($r['state']).(!empty($r['data']['moderation_hold'])?' · Admin hold':'')];echo esc_html($values[$column]??'');}
    public static function editor(){if(!ThreePM_Market::admin())wp_die('3PM administrator access required.');$id=absint($_GET['record']??0);$r=$id?ThreePM_Market_Store::get($id):null;if(!$r||$r['kind']!=='service'){echo '<div class="wrap"><h1>Manage service offerings</h1><p>Select an offering from All Service Offerings. New offerings are created by approved creators in My Services.</p></div>';return;}echo '<div class="wrap">'.ThreePM_Market_UI::shell('Manage service offering',ThreePM_Market_UI::service_form($r,true)).'</div>';}
    public static function guard(){
        if(!is_singular(self::TYPE))return;$r=ThreePM_Market_Store::get((int)get_post_meta(get_queried_object_id(),'_threepm_service_record',true));
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();
        if(!self::public_record($r)){
            if($r&&is_user_logged_in()&&((int)$r['owner']===get_current_user_id()||ThreePM_Market::admin())){header('X-Robots-Tag: noindex, nofollow');return;}
            global $wp_query;$wp_query->set_404();status_header(404);
        }
    }
}
