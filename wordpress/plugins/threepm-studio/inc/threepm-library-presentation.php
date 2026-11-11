<?php
defined('ABSPATH')||exit;
/** Artist-controlled presentation of native WordPress content. */
final class ThreePM_Library_Presentation {
 const TYPES=['podcast','event','mediagallery'];
 static function boot(){
  add_action('wp_ajax_threepm_library_layout',[__CLASS__,'save']);
  add_action('wp_enqueue_scripts',function(){
   wp_enqueue_script('threepm-library-layout',THREEPM_STUDIO_URL.'/assets/js/threepm-library-layout.js',[],'1.0.0',true);
  });
 }
 static function ids($uid,$type,$public=false){
  return array_map('intval',get_posts(['post_type'=>$type,'post_status'=>$public?['publish']:['publish','draft'],'numberposts'=>-1,'fields'=>'ids','meta_key'=>'_threepm_artist_owner','meta_value'=>$uid]));
 }
 static function state($uid,$type){
  $v=get_user_meta($uid,'_threepm_layout_'.$type,true);
  return is_array($v)?array_merge(['order'=>[],'featured'=>[]],$v):['order'=>[],'featured'=>[]];
 }
 static function sorted($posts,$uid,$type){
  $state=self::state($uid,$type);$rank=array_flip(array_merge($state['featured'],$state['order']));
  // Featured appears before the ordinary saved order.
  $featured=array_flip($state['featured']);$order=array_flip($state['order']);
  usort($posts,function($a,$b)use($featured,$order){
   $fa=isset($featured[$a->ID]);$fb=isset($featured[$b->ID]);
   if($fa!==$fb)return $fa?-1:1;
   return ($order[$a->ID]??PHP_INT_MAX)<=>($order[$b->ID]??PHP_INT_MAX);
  });
  return $posts;
 }
 static function save(){
  check_ajax_referer('threepm_library_layout','nonce');
  $uid=get_current_user_id();
  if(!$uid||!ThreePM_Market::approved($uid))wp_send_json_error(['message'=>'Approved Artist status required.'],403);
  $type=sanitize_key($_POST['type']??'');
  if(!in_array($type,self::TYPES,true))wp_send_json_error(['message'=>'Invalid library.'],400);
  $valid=self::ids($uid,$type);$order=$_POST['order']??[];$featured=$_POST['featured']??[];
  if(!is_array($order)||!is_array($featured))wp_send_json_error(['message'=>'Invalid selection.'],400);
  $order=array_map('absint',$order);$featured=array_map('absint',$featured);
  if(count($featured)>1||count($order)!==count(array_unique($order))||array_diff($order,$valid)||array_diff($valid,$order)||array_diff($featured,$valid))
   wp_send_json_error(['message'=>'Library changed or selection is invalid. Refresh and try again.'],409);
  update_user_meta($uid,'_threepm_layout_'.$type,['order'=>$order,'featured'=>$featured]);
  do_action('litespeed_purge_all');
  wp_send_json_success(['message'=>'Profile order and featured item saved.']);
 }
 static function listing($uid,$type){
  $posts=get_posts(['post_type'=>$type,'post_status'=>['draft','publish'],'numberposts'=>-1,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid]);
  $state=self::state($uid,$type);$order=array_flip($state['order']);
  usort($posts,fn($a,$b)=>($order[$a->ID]??PHP_INT_MAX)<=>($order[$b->ID]??PHP_INT_MAX));
  if(!$posts)return '';
  $s='<div class="threepm-library" data-library-type="'.esc_attr($type).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('threepm_library_layout')).'"><p>Drag items to set their profile order, or use Move up / Move down. Choose one featured item.</p><div data-library-items>';
  foreach($posts as $p){
   $on=in_array($p->ID,$state['featured'],true);
   $metrics='';
   if($type==='podcast'&&class_exists('ThreePM_Engagement_Tracking'))$metrics=ThreePM_Engagement_Tracking::stats($p->ID,'podcast');
   if($type==='mediagallery')$metrics='<div class="threepm-metrics"><span>'.number_format_i18n(max(0,(int)get_post_meta($p->ID,'_threepm_measured_views',true))).' views on 3PM</span></div>';
   $s.='<article class="tpm-manage-row" draggable="true" data-item-id="'.$p->ID.'"><div>'.get_the_post_thumbnail($p->ID,'thumbnail').'<h3>'.esc_html($p->post_title).'</h3><span>'.($p->post_status==='publish'?'Public':'Draft').'</span>'.$metrics.'</div><div class="tpm-actions"><a href="'.esc_url(add_query_arg(['creator_type'=>$type,'creator_item'=>$p->ID],ThreePM_Market::url())).'">Edit</a><button type="button" data-feature aria-pressed="'.($on?'true':'false').'" aria-label="Feature '.esc_attr($p->post_title).'"><svg width="20" height="20" viewBox="0 0 24 24" fill="'.($on?'currentColor':'none').'" stroke="currentColor" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z"/></svg></button><button type="button" data-move="-1">Move up</button><button type="button" data-move="1">Move down</button></div></article>';
  }
  return $s.'</div><button type="button" data-save-layout>Save profile order & featured item</button><p role="status" data-layout-status></p></div>';
 }
}
ThreePM_Library_Presentation::boot();
