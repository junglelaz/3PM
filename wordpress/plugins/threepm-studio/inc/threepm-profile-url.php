<?php
defined('ABSPATH')||exit;
final class ThreePM_Profile_URL {
 static function available($slug,$id=0){
  if(!$slug||in_array($slug,['wp-admin','wp-login','wp-json','feed','author','category','tag','artist','artists','release','music','my-account','cart','checkout','shop','search','sitemap','robots','page','attachment'],true))return false;
  global $wpdb;return !$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND ID<>%d AND post_status NOT IN ('trash','auto-draft','inherit') LIMIT 1",$slug,$id));
 }
 static function fields($id){$slug=$id?get_post_field('post_name',$id):'';$short=$slug?'https://3pm.to/'.$slug:'';return '<section class="threepm-profile-url"><h3>Profile URL</h3><label>Profile permalink<input type="text" name="artist_profile_slug" value="'.esc_attr($slug).'" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="100" placeholder="your-artist-name"></label><p>Use lowercase letters, numbers and hyphens. Save your profile to apply changes.</p><label>Short URL<input readonly data-profile-short-url value="'.esc_attr($short).'"></label><button type="button" data-copy-profile-url '.(!$short?'disabled':'').'>Copy short URL</button><p role="status" data-profile-copy-status></p></section>';}
}
add_filter('wp_insert_post_data',function($data,$raw){
 if(($data['post_type']??'')!=='artist'||!isset($_POST['artist_profile_slug']))return $data;
 $frontend=($_POST['op']??'')==='profile'&&wp_verify_nonce($_POST['_wpnonce']??'','threepm_market');$admin=wp_verify_nonce($_POST['threepm_native_links_nonce']??'','threepm_native_links');
 if(!$frontend&&!$admin)return $data;$id=(int)($raw['ID']??0);
 if($frontend){if(!class_exists('ThreePM_Market')||!ThreePM_Market::approved(get_current_user_id()))return $data;if($id)ThreePM_Market::native_owner($id,'artist',get_current_user_id());}elseif(!$id||!current_user_can('edit_post',$id))return $data;
 $slug=trim(wp_unslash($_POST['artist_profile_slug']));if($slug==='')return $data;
 if(strlen($slug)>100||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||!ThreePM_Profile_URL::available($slug,$id))wp_die('This profile URL is unavailable. Choose another using lowercase letters, numbers and hyphens.','Profile URL not saved',['response'=>409,'back_link'=>true]);
 $data['post_name']=$slug;return $data;
},20,2);
add_filter('post_type_link',function($url,$p){return $p->post_type==='artist'&&ThreePM_Profile_URL::available($p->post_name,$p->ID)?home_url('/'.$p->post_name.'/'):$url;},20,2);
add_filter('request',function($q){$slug=$q['pagename']??$q['name']??'';if(!$slug||str_contains($slug,'/')||isset($q['post_type'])||isset($q['feed']))return $q;$p=get_page_by_path($slug,OBJECT,'artist');return $p&&$p->post_status==='publish'&&ThreePM_Profile_URL::available($slug,$p->ID)?['post_type'=>'artist','name'=>$slug]:$q;});
add_action('wp_enqueue_scripts',function(){wp_enqueue_script('threepm-profile-url',THREEPM_STUDIO_URL.'/assets/js/threepm-profile-url.js',[],'0.2.39',true);});
add_action('admin_enqueue_scripts',function($hook){if(in_array($hook,['post.php','post-new.php'],true))wp_enqueue_script('threepm-profile-url',THREEPM_STUDIO_URL.'/assets/js/threepm-profile-url.js',[],'0.2.39',true);});
