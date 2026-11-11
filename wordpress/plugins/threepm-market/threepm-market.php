<?php
/**
 * Plugin Name: 3PM Artist & Service Market
 * Description: Controlled artist accounts, native profiles/releases and private service agreements. Financial activation remains closed until the escrow and wallet adapters are verified.
 * Version: 0.3.8
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */
defined('ABSPATH')||exit;
require_once __DIR__.'/reviews.php';require_once __DIR__.'/offerings.php';require_once __DIR__.'/domain.php';require_once __DIR__.'/store.php';require_once __DIR__.'/agreement.php';require_once __DIR__.'/delivery.php';require_once __DIR__.'/escrow-verifier.php';
final class ThreePM_Market {
    public static function boot(){
        ThreePM_Market_Offerings::boot();
        add_filter('wp_nav_menu_objects',function($items){foreach($items as $item){if(rtrim((string)parse_url($item->url,PHP_URL_PATH),'/')==='/services'){$item->url=self::url('market');$item->title='Service Market';}}return $items;},30);
        add_filter('wp_nav_menu_objects',[__CLASS__,'artist_account_menu'],40);
        add_action('admin_enqueue_scripts',function(){if(($_GET['post_type']??'')===ThreePM_Market_Offerings::TYPE){wp_enqueue_style('threepm-market',plugins_url('assets/market.css',__FILE__),[],'0.3.8');wp_enqueue_script('threepm-market',plugins_url('assets/market.js',__FILE__),[],(string)filemtime(__DIR__.'/assets/market.js'),true);}});
        add_action('init',function(){add_rewrite_endpoint('artist-dashboard',EP_PAGES);add_rewrite_endpoint('my-services',EP_PAGES);add_rewrite_endpoint('service-engagements',EP_PAGES);});
        add_filter('woocommerce_account_menu_items',function($items){$logout=$items['customer-logout']??null;unset($items['customer-logout']);unset($items['artist-dashboard']);if(self::approved(get_current_user_id()))$items['artist-dashboard']='Artist Dashboard';$items['my-services']='My Services';$items['service-engagements']='Service engagements';$items['service-market']='Service Market';if($logout)$items['customer-logout']=$logout;return $items;});
        add_filter('woocommerce_get_endpoint_url',function($url,$endpoint){return $endpoint==='service-market'?self::url('market'):$url;},10,2);
        add_action('woocommerce_account_my-services_endpoint',function(){echo ThreePM_Market_UI::creator_services();});
        add_action('woocommerce_account_artist-dashboard_endpoint',function(){echo ThreePM_Market_UI::dashboard();});
        add_action('woocommerce_account_service-engagements_endpoint',function(){echo ThreePM_Market_UI::engagements();});
        add_shortcode('threepm_artist_dashboard',['ThreePM_Market_UI','dashboard']);
        add_shortcode('threepm_service_market',['ThreePM_Market_UI','market']);
        add_shortcode('threepm_service_engagements',['ThreePM_Market_UI','engagements']);
        add_action('admin_post_threepm_market',[__CLASS__,'post']);add_action('admin_post_threepm_delivery',['ThreePM_Market_Delivery','download']);
        add_action('admin_menu',function(){add_submenu_page('edit.php?post_type=service_offering','Service reviews','Service reviews','manage_options','threepm-service-reviews',['ThreePM_Market_Reviews','admin']);add_users_page('3PM Artist approvals','3PM Artist approvals','manage_options','threepm-artist-approvals',['ThreePM_Market_UI','admin']);});
        add_action('wp_enqueue_scripts',function(){wp_enqueue_style('threepm-market',plugins_url('assets/market.css',__FILE__),[],'0.3.8');wp_enqueue_script('threepm-market',plugins_url('assets/market.js',__FILE__),[],(string)filemtime(__DIR__.'/assets/market.js'),true);});
        add_action('template_redirect',[__CLASS__,'privacy']);
        add_filter('the_content',[__CLASS__,'artist_extras'],30);
    }
    public static function artist_account_menu($items){
        $approved=self::approved(get_current_user_id());$parents=[];$existing=[];
        foreach($items as $key=>$item){
            $path=rtrim((string)parse_url($item->url,PHP_URL_PATH),'/');
            if($path==='/my-account/artist-dashboard'){
                if(!$approved){unset($items[$key]);continue;}
                $existing[(int)$item->menu_item_parent]=true;
            }
            if($path==='/my-account'&&!(int)$item->menu_item_parent)$parents[]=$item;
        }
        if($approved)foreach($parents as $parent){
            if(isset($existing[(int)$parent->ID]))continue;
            $item=clone $parent;$item->ID=-1000000-(int)$parent->ID;$item->db_id=$item->ID;
            $item->menu_item_parent=(string)$parent->ID;$item->object_id=$item->ID;
            $item->title='Artist Dashboard';$item->url=self::url();$item->type='custom';$item->object='custom';
            $item->classes=['menu-item','menu-item-type-custom','threepm-artist-dashboard'];
            $item->target='';$item->attr_title='';$item->description='';$item->xfn='';
            $item->current=false;$item->current_item_ancestor=false;$item->current_item_parent=false;
            $item->menu_order=count($items)+1;$items[]=$item;
            if(!in_array('menu-item-has-children',$parent->classes,true))$parent->classes[]='menu-item-has-children';
        }
        return array_values($items);
    }
    public static function activate(){
        ThreePM_Market_Offerings::register();ThreePM_Market_Store::install();ThreePM_Market_Delivery::install();
        foreach(['dashboard'=>['Artist Dashboard','artist-dashboard','threepm_artist_dashboard'],'market'=>['Service Market','service-market','threepm_service_market'],'engagements'=>['Service Engagements','service-engagements','threepm_service_engagements']] as $key=>$p){
            if(get_option('threepm_market_page_'.$key))continue;
            $id=wp_insert_post(['post_type'=>'page','post_status'=>$key==='market'?'publish':'draft','post_title'=>$p[0],'post_name'=>$p[1],'post_content'=>'['.$p[2].']'],true);
            if(is_wp_error($id))throw new RuntimeException('Could not create market page.');update_option('threepm_market_page_'.$key,$id,false);
        }
        add_rewrite_endpoint('artist-dashboard',EP_PAGES);add_rewrite_endpoint('my-services',EP_PAGES);add_rewrite_endpoint('service-engagements',EP_PAGES);flush_rewrite_rules(false);
    }
    public static function admin(){return current_user_can('manage_options')&&in_array('administrator',(array)wp_get_current_user()->roles,true);}
    public static function account($uid){return ThreePM_Market_Store::key('artist:'.$uid);}
    public static function approved($uid){$a=self::account($uid);return $a&&$a['state']==='approved';}
    public static function url($view='dashboard',$id=0){
        if($view==='market'){$page=(int)get_option('threepm_market_page_market');$url=$page?get_permalink($page):home_url('/service-market/');}
        else $url=function_exists('wc_get_account_endpoint_url')?wc_get_account_endpoint_url($view==='dashboard'?'artist-dashboard':($view==='services'?'my-services':'service-engagements')):get_permalink((int)get_option('threepm_market_page_'.$view));
        return $id?add_query_arg('record',$id,$url):$url;
    }
    public static function privacy(){
        $ids=[(int)get_option('threepm_market_page_dashboard'),(int)get_option('threepm_market_page_engagements')];
        if((is_user_logged_in()&&is_page((int)get_option('threepm_market_page_market')))||is_page(array_filter($ids))||(function_exists('is_wc_endpoint_url')&&(is_wc_endpoint_url('artist-dashboard')||is_wc_endpoint_url('my-services')||is_wc_endpoint_url('service-engagements')))){
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
    }
    public static function input($key,$max=12000){return ThreePM_Market_Domain::text(isset($_POST[$key])?wp_unslash($_POST[$key]):'',$max);}
    public static function record($id,$kind=null){$r=ThreePM_Market_Store::get($id);if(!$r||($kind&&$r['kind']!==$kind)||!ThreePM_Market_Domain::can_view($r,get_current_user_id(),self::admin()))throw new RuntimeException('Record unavailable.');return $r;}
    public static function version($r){if((string)$r['version']!==self::input('version',12))throw new RuntimeException('This form is out of date. Refresh before saving.');}
    public static function own($r){if((int)$r['owner']!==get_current_user_id())throw new RuntimeException('You do not own this record.');}
    public static function post(){
        if(!is_user_logged_in())wp_die('Sign in to continue.',403);
        check_admin_referer('threepm_market');$op=self::input('op',40);$id=absint(self::input('record',12));$uid=get_current_user_id();$lock='';
        global $wpdb;
        try{
            // Serialize market writes, including cross-account legacy ownership assignments.
            $lock_owner=$uid;
            if($id){$candidate=ThreePM_Market_Store::get($id);if($candidate)$lock_owner=(int)$candidate['owner'];}
            if($op==='request'){$s=ThreePM_Market_Store::get(absint(self::input('listing',12)));if($s)$lock_owner=(int)$s['owner'];}
            $lock='3pm-market-'.get_current_blog_id();
            if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock))!==1)throw new RuntimeException('Another update is underway. Please retry shortly.');
            $r=$id?self::record($id):null;if($r)self::version($r);
            $redirect=self::dispatch($op,$r,$uid);
        }catch(Throwable $e){if($lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));wp_die(esc_html($e->getMessage()),'3PM — update not saved',['response'=>400,'back_link'=>true]);}
        if($lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));wp_safe_redirect(add_query_arg('saved','1',$redirect));exit;
    }
    public static function dispatch($op,$r,$uid){
        if($op==='review'){
            ThreePM_Market_Reviews::submit($r,$uid,self::input('rating',3),self::input('review',3000));return self::url('engagements',$r['id']);
        }
        if($op==='review_moderation'){
            if(!self::admin()||!$r||$r['kind']!=='review')throw new RuntimeException('Administrator review access required.');
            $state=self::input('state',20);$reason=self::input('reason',2000);if(!in_array($state,['published','hidden'],true)||!$reason)throw new RuntimeException('Choose visibility and give a moderation reason.');
            $d=$r['data'];$d['moderation_reason']=$reason;$r=ThreePM_Market_Store::save($r,$state,$d,'review_moderated');ThreePM_Market_Reviews::purge($r);return admin_url('edit.php?post_type=service_offering&page=threepm-service-reviews');
        }
        if($op==='apply'){
            $name=self::input('artist_name',160);$bio=self::input('bio');if(!$name||!$bio)throw new RuntimeException('Artist name and introduction are required.');
            $a=self::account($uid);$data=['artist_name'=>$name,'bio'=>$bio,'profile_id'=>$a['data']['profile_id']??0];
            if($a){if(!in_array($a['state'],['rejected','revoked'],true))throw new RuntimeException('An application or approval already exists.');self::version($a);ThreePM_Market_Store::save($a,'pending',$data,'reapplied');}
            else ThreePM_Market_Store::create('artist:'.$uid,'artist',$uid,0,'pending',$data);
            return self::url();
        }
        if($op==='decision'){
            if(!self::admin()||!$r||$r['kind']!=='artist')throw new RuntimeException('Only 3PM administrators can decide Artist status.');
            $state=self::input('decision',20);if(!in_array($state,['approved','rejected','suspended','revoked'],true))throw new RuntimeException('Invalid decision.');
            $reason=self::input('reason',2000);if(!$reason)throw new RuntimeException('A decision reason is required.');
            $d=$r['data'];$d['decision_reason']=$reason;
            $release_ids=array_filter(array_map('trim',explode(',',self::input('release_ids',600))));
            if(count($release_ids)>50)throw new RuntimeException('Link at most 50 releases per decision.');
            foreach($release_ids as $release_id){
                if(!ctype_digit($release_id))throw new RuntimeException('Release IDs must be comma-separated whole numbers.');
                $release=get_post((int)$release_id);$release_owner=(int)get_post_meta((int)$release_id,'_threepm_artist_owner',true);
                if(!$release||$release->post_type!=='release'||($release_owner&&$release_owner!==(int)$r['owner']))throw new RuntimeException('A release is unavailable or belongs to another Artist.');
            }
            $profile_id=absint(self::input('profile_id',12));
            if($profile_id && $profile_id!==(int)($d['profile_id']??0)){
                if(!method_exists('ThreePM_Artist_Profiles','sync_artist_dashboard'))throw new RuntimeException('Install the profile integration update before linking an existing profile.');
                $profile=get_post($profile_id);$old_owner=(int)get_post_meta($profile_id,'_threepm_artist_owner',true);
                if(!$profile||$profile->post_type!=='artist'||($old_owner&&$old_owner!==(int)$r['owner']))throw new RuntimeException('Profile is unavailable or already belongs to another Artist.');
                if(!empty($d['profile_id']))throw new RuntimeException('This Artist already has a profile. Reassignment requires a separate migration.');
                update_post_meta($profile_id,'_threepm_artist_owner',(int)$r['owner']);$d['profile_id']=$profile_id;
                $d['artist_name']=$profile->post_title;$d['bio']=ThreePM_Artist_Profiles::dashboard_bio($profile_id);
                if(!$d['bio'])$d['bio']=wp_strip_all_tags($profile->post_content);
            }
            $d['linked_release_ids']=array_values(array_unique(array_merge($d['linked_release_ids']??[],array_map('intval',$release_ids))));
            ThreePM_Market_Store::save($r,$state,$d,'administrator_'.$state);
            foreach(ThreePM_Market_Store::list('service',(int)$r['owner']) as $offering)ThreePM_Market_Offerings::sync($offering);
            foreach($release_ids as $release_id)update_post_meta((int)$release_id,'_threepm_artist_owner',(int)$r['owner']);
            return admin_url('users.php?page=threepm-artist-approvals');
        }
        if(in_array($op,['profile','release','service'],true)&&!self::approved($uid)&&!($op==='service'&&self::admin()&&$r))throw new RuntimeException('Approved Artist status is required.');
        if($op==='profile'){self::save_profile($uid);return self::url();}
        if($op==='release'){self::save_release($uid);return add_query_arg('tab','releases',self::url());}
        if($op==='service'){
            if($r){if($r['kind']!=='service')throw new RuntimeException('Invalid listing.');if(!self::admin())self::own($r);}
            $state=self::input('state',20);if(!in_array($state,['draft','published','paused','archived'],true))throw new RuntimeException('Invalid listing state.');
            $terms=ThreePM_Market_Domain::listing(wp_unslash($_POST['terms']??[]),$state==='published');
            $hold=!empty($r['data']['moderation_hold']);if(self::admin())$hold=self::input('moderation_hold',1)==='1';
            if($state==='published'&&($hold||!self::approved((int)($r['owner']??$uid))))throw new RuntimeException('Publishing requires an approved creator and no administrator hold.');
            $d=['terms'=>$terms,'moderation_hold'=>$hold,'moderation_note'=>self::admin()?self::input('moderation_note',2000):($r['data']['moderation_note']??'')];if($r)$r=ThreePM_Market_Store::save($r,$state,$d,'service_saved');else $r=ThreePM_Market_Store::create('service:'.wp_generate_uuid4(),'service',$uid,0,$state,$d);
            ThreePM_Market_Offerings::sync($r);
            return self::admin()&&self::input('admin_editor',1)==='1'?ThreePM_Market_Offerings::editor_url($r['id']):self::url('services',$r['id']);
        }
        if($op==='request'){
            $s=ThreePM_Market_Store::get(absint(self::input('listing',12)));
            if(!$s||$s['kind']!=='service'||$s['state']!=='published'||!empty($s['data']['moderation_hold'])||!self::approved((int)$s['owner'])||(int)$s['owner']===$uid)throw new RuntimeException('Service unavailable for this account.');
            if((string)$s['version']!==self::input('listing_version',12))throw new RuntimeException('The service changed. Review it again.');
            $project=self::input('project');$legal=self::input('legal_name',200);if(!$project||!$legal)throw new RuntimeException('Your legal name and project requirements are required.');
            $key=self::input('request_key',36);if(!preg_match('/^[a-f0-9-]{36}$/D',$key))throw new RuntimeException('Invalid request identifier.');
            $d=['service_id'=>$s['id'],'service_version'=>$s['version'],'service'=>$s['data']['terms'],'project'=>$project,'client_legal_name'=>$legal,'policy'=>ThreePM_Market_Domain::POLICY,'funding'=>'not_requested','settlement'=>'not_started'];
            $e=ThreePM_Market_Store::create('engagement:'.$uid.':'.$key,'engagement',(int)$s['owner'],$uid,'submitted',$d);
            return self::url('engagements',$e['id']);
        }
        if($op==='engagement'){
            if(!$r||$r['kind']!=='engagement')throw new RuntimeException('Engagement unavailable.');
            $event=self::input('event',30);
            if($event==='resolve'){
                if(!self::admin()||$r['state']!=='disputed')throw new RuntimeException('Only 3PM administrators may propose a resolution for a disputed engagement.');
                $reason=self::input('resolution_reason',40);$note=self::input('note');$refund=ThreePM_Market_Domain::refund_coins(self::input('refund_tokens',30));
                if(!$note)throw new RuntimeException('Document the decision and supporting evidence.');
                $allocation=ThreePM_Market_Domain::settlement($r['data']['service']['price_coin_micros'],$refund,$reason);
                $d=$r['data'];$d['resolution']=['allocation_coin_micros'=>$allocation,'reason'=>$reason,'note'=>$note,'decided_by'=>$uid,'at'=>gmdate('c'),'execution'=>'awaiting_verified_processing_costs_and_authorized_wallet','processing_policy'=>ThreePM_Market_Domain::POLICY];
                $d['resolution']['hash']=ThreePM_Market_Domain::hash($d['resolution']);
                ThreePM_Market_Store::save($r,'resolution_pending',$d,'resolution_proposed');return self::url('engagements',$r['id']);
            }
            $state=ThreePM_Market_Domain::transition($r,$event,$uid,self::admin());$d=$r['data'];
            if($event==='offer'){
                if(!self::approved($uid))throw new RuntimeException('Approved Artist status required for new offers.');
                $name=self::input('legal_name',200);$deadline=self::input('deadline',10);$scope=self::input('final_scope');
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$deadline,new DateTimeZone('UTC'));
                if(!$name||!$scope||!$date||$date->format('Y-m-d')!==$deadline||$deadline<=gmdate('Y-m-d'))throw new RuntimeException('Provide your legal name, final scope and a future deadline.');
                $d['agreement']=['template'=>'3pm-service-draft-v2-token-prices','policy'=>ThreePM_Market_Domain::POLICY,'artist_user'=>$r['owner'],'client_user'=>$r['peer'],'artist_legal_name'=>$name,'client_legal_name'=>$d['client_legal_name'],'service'=>$d['service'],'project'=>$d['project'],'final_scope'=>$scope,'deadline_utc'=>$deadline,'review_days'=>7,'automatic_release'=>false,'split'=>ThreePM_Market_Domain::allocation($d['service']['price_coin_micros']),'artist_fault_refund'=>'Service payment less actual receiving and refund-processing costs; platform fee waived','other_refund'=>'15% original platform fee retained plus actual receiving and refund-processing costs deducted'];
                $document=ThreePM_Market_Agreement::freeze($d['agreement']);
                $d['agreement']=$document['terms'];$d['agreement_hash']=$document['terms_hash'];$d['agreement_document']=$document;$d['artist_offer_at']=gmdate('c');
            }
            // The draft workspace must not misrepresent acceptance, deliverability or settlement.
            if($event==='accept')throw new RuntimeException('Contract signing opens after the escrow, identity and wallet integrations pass validation. Your draft remains available.');
            if($event==='revision'){
                $used=(int)($d['revisions_used']??0);if($used>=$d['service']['revisions'])throw new RuntimeException('Included revisions are exhausted. Open a dispute or agree a change order.');$d['revisions_used']=$used+1;
            }
            if(in_array($event,['deliver','redeliver','revision','dispute'],true)){$note=self::input('note');if(!$note)throw new RuntimeException('Add an explanation.');$d['updates'][]=['event'=>$event,'by'=>$uid,'at'=>gmdate('c'),'note'=>$note];}
            if(in_array($event,['deliver','redeliver'],true)){
                $file=ThreePM_Market_Delivery::upload($r);if($file)$d['deliveries'][]=$file;
                $d['review_due']=gmdate('c',time()+7*DAY_IN_SECONDS);
            }
            ThreePM_Market_Store::save($r,$state,$d,$event);return self::url('engagements',$r['id']);
        }
        throw new RuntimeException('Unsupported action.');
    }
    public static function native_owner($id,$type,$uid){$p=get_post($id);if(!$p||$p->post_type!==$type||(int)get_post_meta($id,'_threepm_artist_owner',true)!==$uid)throw new RuntimeException('Content unavailable to your Artist account.');return $p;}
    public static function safe_url($url){$url=trim($url);if($url==='')return '';if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')throw new RuntimeException('External links must use HTTPS.');return esc_url_raw($url,['https']);}
    public static function upload($field,$uid){
        if(empty($_FILES[$field]['name']))return 0;
        if(($_FILES[$field]['error']??1)!==UPLOAD_ERR_OK||($_FILES[$field]['size']??0)>5*1024*1024)throw new RuntimeException('Use an image of at most 5 MB.');
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
        $check=wp_check_filetype_and_ext($_FILES[$field]['tmp_name'],$_FILES[$field]['name'],['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']);
        if(empty($check['type'])||!wp_getimagesize($_FILES[$field]['tmp_name']))throw new RuntimeException('Use a valid JPEG, PNG or WebP image.');
        $id=media_handle_upload($field,0,['post_author'=>$uid],['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']]);
        if(is_wp_error($id))throw new RuntimeException('Image upload failed.');return $id;
    }
    public static function save_profile($uid){
        if(!post_type_exists('artist'))throw new RuntimeException('The native Artist content system is unavailable.');
        $a=self::account($uid);self::version($a);$d=$a['data'];$id=(int)($d['profile_id']??0);if($id)self::native_owner($id,'artist',$uid);
        if($id && get_post_meta($id,'_elementor_data',true) && !method_exists('ThreePM_Artist_Profiles','sync_artist_dashboard'))throw new RuntimeException('The existing profile needs its dashboard integration update before editing.');
        $name=self::input('artist_name',160);$bio=self::input('bio');if(!$name||!$bio)throw new RuntimeException('Artist name and biography are required.');
        $links=[];foreach(array_filter(explode("\n",self::input('links',6000)))as $line){$p=explode('|',$line,2);if(count($p)!==2)throw new RuntimeException('Use Label | https://address for each link.');$links[]=['label'=>trim($p[0]),'url'=>self::safe_url($p[1])];}if(count($links)>15)throw new RuntimeException('Use at most 15 links.');
        $publish=self::input('state',20)==='publish';
        if($id && count(array_filter((array)get_post_meta($id,'_threepm_market_gallery',true)))>=20 && !empty($_FILES['gallery_image']['name']))throw new RuntimeException('Remove a gallery image before adding another.');
        $photo=self::upload('photo',$uid);$gallery=self::upload('gallery_image',$uid);
        $post=['post_type'=>'artist','post_title'=>$name,'post_content'=>wpautop(esc_html($bio)),'post_status'=>$publish?'publish':'draft'];
        if($id){$post['ID']=$id;if(get_post_meta($id,'_elementor_data',true))unset($post['post_content']);}else $post['post_author']=$uid;
        $id=wp_insert_post(wp_slash($post),true);if(is_wp_error($id))throw new RuntimeException('Profile save failed.');
        update_post_meta($id,'_threepm_artist_owner',$uid);update_post_meta($id,'_threepm_profile_bio',$bio);
        update_post_meta($id,'_threepm_profile_websites',implode("\n",array_map(fn($l)=>$l['label'].' | '.$l['url'],$links)));
        if($photo){set_post_thumbnail($id,$photo);update_post_meta($id,'_threepm_profile_photo',wp_get_attachment_url($photo));}
        $images=(array)get_post_meta($id,'_threepm_market_gallery',true);$images=array_filter(array_map('absint',$images));
        $remove=absint(self::input('remove_image',12));if($remove)$images=array_diff($images,[$remove]);
        if($gallery&&count($images)<20){$images[]=$gallery;update_post_meta($gallery,'_wp_attachment_image_alt',self::input('gallery_alt',300));}
        update_post_meta($id,'_threepm_market_gallery',array_values($images));
        if(method_exists('ThreePM_Artist_Profiles','sync_artist_dashboard'))ThreePM_Artist_Profiles::sync_artist_dashboard($id);
        $d['profile_id']=$id;$d['artist_name']=$name;$d['bio']=$bio;ThreePM_Market_Store::save($a,'approved',$d,'profile_saved');clean_post_cache($id);do_action('litespeed_purge_post',$id);
    }
    public static function save_release($uid){
        if(!post_type_exists('release'))throw new RuntimeException('The native Release system is unavailable.');
        $id=absint(self::input('release_id',12));if($id){self::native_owner($id,'release',$uid);if(self::input('modified',30)!==get_post_field('post_modified_gmt',$id))throw new RuntimeException('This release changed. Refresh before saving.');}
        $title=self::input('title',160);$type=self::input('release_type',20);if(!$title||!in_array($type,['single','ep','mixtape','album'],true))throw new RuntimeException('Enter a title and release type.');
        $date=self::input('release_date',10);$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)throw new RuntimeException('Enter a valid release date.');
        $tracks=[];$input=wp_unslash($_POST['tracks']??[]);if(!is_array($input)||count($input)>50)throw new RuntimeException('Use at most 50 tracks.');
        foreach($input as $t){if(!is_array($t))throw new RuntimeException('Invalid track.');$name=ThreePM_Market_Domain::text($t['title']??'',200);if(!$name)continue;$tracks[]=['releasetrack_track_title'=>$name,'releasetrack_artist_name'=>ThreePM_Market_Domain::text($t['artists']??'',300),'releasetrack_mp3_demo'=>self::safe_url($t['preview']??''),'releasetrack_scurl'=>self::safe_url($t['embed']??''),'releasetrack_buyurl'=>self::safe_url($t['buy']??''),'icon_type'=>'cart'];}
        $state=self::input('state',20)==='publish'?'publish':'draft';if($state==='publish'&&!$tracks)throw new RuntimeException('Add at least one track before publishing.');
        $cover=self::upload('cover',$uid);$post=['post_type'=>'release','post_title'=>$title,'post_content'=>wpautop(esc_html(self::input('description'))),'post_status'=>$state];if($id){$post['ID']=$id;if(get_post_meta($id,'_elementor_data',true))unset($post['post_content']);}else $post['post_author']=$uid;
        $id=wp_insert_post(wp_slash($post),true);if(is_wp_error($id))throw new RuntimeException('Release save failed.');
        update_post_meta($id,'_threepm_artist_owner',$uid);update_post_meta($id,'_threepm_release_type',$type);update_post_meta($id,'general_release_details_release_date',$date);update_post_meta($id,'general_release_details_label',self::input('label',160));update_post_meta($id,'track_repeatable',$tracks);if($cover)set_post_thumbnail($id,$cover);do_action('litespeed_purge_post',$id);
    }
    public static function artist_extras($content){
        if(!is_singular('artist')||!in_the_loop()||!is_main_query())return $content;$id=get_the_ID();$uid=(int)get_post_meta($id,'_threepm_artist_owner',true);if(!$uid)return $content;
        $out='<section class="tpm-market"><h2>Creator rating</h2><p>'.ThreePM_Market_Reviews::display(0,$uid).'</p><h2>Artist links</h2>';
        foreach(array_filter(explode("\n",(string)get_post_meta($id,'_threepm_profile_websites',true))) as $line){$link=explode('|',$line,2);if(count($link)===2)$out.='<p><a rel="noopener noreferrer" href="'.esc_url(trim($link[1])).'">'.esc_html(trim($link[0])).'</a></p>';}
        $out.='<h2>Gallery</h2><div class="tpm-cards">';foreach((array)get_post_meta($id,'_threepm_market_gallery',true)as $image)$out.=wp_get_attachment_image((int)$image,'large');$out.='</div><h2>Music releases</h2>';
        foreach(get_posts(['post_type'=>'release','post_status'=>'publish','numberposts'=>50,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid])as $p)$out.='<p><a href="'.esc_url(get_permalink($p)).'">'.esc_html($p->post_title).'</a></p>';
        $out.='<h2>Services</h2>';foreach(ThreePM_Market_Store::list('service',0,true)as $s)if((int)$s['owner']===$uid&&self::approved($uid))$out.=ThreePM_Market_UI::service_card($s);
        return $content.$out.'</section>';
    }
}
require_once __DIR__.'/ui.php';ThreePM_Market::boot();register_activation_hook(__FILE__,['ThreePM_Market','activate']);
