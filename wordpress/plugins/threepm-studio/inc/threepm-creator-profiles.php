<?php
defined('ABSPATH')||exit;
final class ThreePM_Creator_Profiles {
 static function eligible($id){
  $uid=(int)get_post_meta($id,'_threepm_artist_owner',true);
  return $uid&&class_exists('ThreePM_Market')&&ThreePM_Market::approved($uid)&&get_post_status($id)==='publish'&&get_the_title($id)&&trim((string)get_post_meta($id,'_threepm_profile_bio',true))&&get_post_thumbnail_id($id)&&trim((string)get_post_meta($id,'_threepm_profile_websites',true));
 }
 static function root_available($p){
  if(!$p||!$p->post_name)return false;
  $reserved=['wp-admin','wp-json','feed','author','category','tag','artist','artists','music','my-account','cart','checkout','shop','search','robots.txt','sitemap.xml'];
  if(in_array($p->post_name,$reserved,true))return false;
  global $wpdb;
  return !(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND ID<>%d AND post_status NOT IN ('trash','auto-draft') AND post_type IN ('page','post','product','artist') LIMIT 1",$p->post_name,$p->ID));
 }
 static function boot(){
  add_filter('post_type_link',function($url,$p){return $p->post_type==='artist'&&self::root_available($p)?home_url('/'.$p->post_name.'/'):$url;},20,2);
  add_filter('request',function($q){
   $slug=$q['pagename']??$q['name']??'';
   if(!$slug||str_contains($slug,'/')||isset($q['post_type'])||isset($q['feed']))return $q;
   $p=get_page_by_path($slug,OBJECT,'artist');
   if($p&&$p->post_status==='publish'&&self::root_available($p))return ['post_type'=>'artist','name'=>$slug];
   return $q;
  });
  add_filter('the_content',function($html){
   if(!is_singular('artist')||!in_the_loop()||!is_main_query())return $html;
   $p=get_post();if(!self::root_available($p))return $html;
   $short='https://3pm.to/'.$p->post_name;
   return $html.'<div class="tpm-market"><label>Artist shortlink<input readonly aria-label="Artist shortlink" value="'.esc_attr($short).'"></label><button type="button" data-threepm-copy="'.esc_attr($short).'">Copy link</button><span role="status" class="threepm-copy-status"></span></div>';
  },50);
  add_action('wp_enqueue_scripts',function(){wp_enqueue_script('threepm-profile-copy',THREEPM_STUDIO_URL.'/assets/js/threepm-profile-copy.js',[],'1.0',true);});
 }
}
ThreePM_Creator_Profiles::boot();
