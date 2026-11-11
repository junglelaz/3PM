<?php
defined('ABSPATH')||exit;
/** First-party gallery view collection; no third-party analytics service. */
final class ThreePM_Gallery_Analytics {
 static function boot(){
  add_action('wp_ajax_threepm_gallery_view',[__CLASS__,'record']);
  add_action('wp_ajax_nopriv_threepm_gallery_view',[__CLASS__,'record']);
  add_filter('the_content',function($html){
   if(!is_singular('mediagallery')||!in_the_loop()||!is_main_query()||get_post_status(get_the_ID())!=='publish')return $html;
   $id=get_the_ID();
   return '<div data-threepm-gallery-view="'.$id.'" data-view-nonce="'.esc_attr(wp_create_nonce('threepm_gallery_view_'.$id)).'" data-view-endpoint="'.esc_url(admin_url('admin-ajax.php')).'">'.$html.'</div>';
  },99);
  add_action('wp_enqueue_scripts',function(){wp_enqueue_script('threepm-gallery-analytics',THREEPM_STUDIO_URL.'/assets/js/threepm-gallery-analytics.js',[],'1.0.0',true);});
 }
 static function record(){
  $id=absint($_POST['id']??0);
  if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce']??'')),'threepm_gallery_view_'.$id))wp_send_json_error([],403);
  if(get_post_type($id)!=='mediagallery'||get_post_status($id)!=='publish')wp_send_json_error([],404);
  $owner=(int)get_post_meta($id,'_threepm_artist_owner',true);
  if(current_user_can('manage_options')||($owner&&get_current_user_id()===$owner))wp_send_json_success(['counted'=>false]);
  // One view per network address and gallery per day. Raw addresses are never stored.
  $fingerprint=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'').':'.$id.':'.gmdate('Y-m-d'),wp_salt('nonce'));
  $key='threepm_gv_'.$fingerprint;
  global $wpdb;$lock='threepm-gallery-'.$id;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1)wp_send_json_error([],503);
  try{
   if(get_transient($key)){ $counted=false; }
   else {
    $count=(int)get_post_meta($id,'_threepm_measured_views',true);
    $saved=update_post_meta($id,'_threepm_measured_views',$count+1);
    if($saved===false)throw new RuntimeException('Counter unavailable.');
    if(!get_post_meta($id,'_threepm_views_since',true))update_post_meta($id,'_threepm_views_since',gmdate('c'));
    set_transient($key,1,DAY_IN_SECONDS);$counted=true;
   }
  }catch(Throwable $error){$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));wp_send_json_error([],503);}
  $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  wp_send_json_success(['counted'=>$counted]);
 }
}
ThreePM_Gallery_Analytics::boot();
