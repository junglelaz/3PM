<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/threepm-track-editions.php';
require_once __DIR__.'/threepm-artist-studio.php';
final class ThreePM_Creator_Content {
 const TYPES=['podcast'=>'Podcasts','event'=>'Events','mediagallery'=>'Galleries'];
 static function boot(){
  add_action('wp_loaded',function(){
   if(!class_exists('ThreePM_Market_UI'))return;
   remove_all_actions('woocommerce_account_artist-dashboard_endpoint');
   add_action('woocommerce_account_artist-dashboard_endpoint',function(){echo self::dashboard();});
   add_shortcode('threepm_artist_dashboard',[__CLASS__,'dashboard']);
  });
  add_action('admin_post_threepm_creator_content',[__CLASS__,'save']);
  add_filter('the_content',[__CLASS__,'profile'],40);
 }
 static function dashboard(){return ThreePM_Artist_Studio::render();}
 static function content_section($selected){
  $uid=get_current_user_id();$base='';
  if(!ThreePM_Market::approved($uid))return '';
  $s='<section class="tpm-market">';
  foreach(self::TYPES as $type=>$label){
   if($selected!==$type)continue;
   $items=get_posts(['post_type'=>$type,'post_status'=>['draft','publish'],'numberposts'=>100,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid]);
   $singular=['podcast'=>'Podcast','event'=>'Event','mediagallery'=>'Gallery'][$type];
   if(!$items)$s.='<p>You haven&#39;t added any '.esc_html($label).' yet.</p>';
   $s.='<p><a class="tpm-button" href="'.esc_url(add_query_arg(['creator_type'=>$type,'creator_new'=>1],ThreePM_Market::url())).'">Add '.esc_html($singular).'</a></p>';
   $s.=ThreePM_Library_Presentation::listing($uid,$type);
   if(empty($_GET['creator_new'])&&empty($_GET['creator_item']))continue;
   $s.='<details open><summary>'.esc_html($label).' editor</summary>';
   $id=absint($_GET['creator_item']??0);$p=null;
   if(($type===($_GET['creator_type']??''))&&$id){try{$p=ThreePM_Market::native_owner($id,$type,$uid);}catch(Throwable $e){}}
   $id=$p?$p->ID:0;
   $s.='<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('threepm_creator_content','_wpnonce',true,false);
   foreach(['action'=>'threepm_creator_content','type'=>$type,'item'=>$id,'modified'=>$p->post_modified_gmt??'']as $k=>$v)$s.='<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
   $s.=ThreePM_Market_UI::field('title','Title',$p->post_title??'','text',true);
   $s.=ThreePM_Market_UI::field('description','Description',$p?wp_strip_all_tags($p->post_content):'','textarea');
   $s.=ThreePM_Market_UI::field('cover','Cover image — up to 5 MB','','file');
   foreach(self::fields($type)as $key=>$label)$s.=ThreePM_Market_UI::field($key,$label,$id?get_post_meta($id,$key,true):'',str_contains($key,'url')||$key==='eventwebsite'?'url':(str_contains($key,'date')?'date':'text'));
   if($type==='mediagallery'&&$id)$s.=ThreePM_Gallery_Editor::panel($id,'gallery',ThreePM_Gallery_Editor::rows($id,'gallery'));
   if($type==='mediagallery')$s.=ThreePM_Market_UI::field('gallery_image','Add gallery image — one per save','','file');
   $s.=ThreePM_Market_UI::select('state','Visibility',['draft'=>'Draft','publish'=>'Public'],$p->post_status??'draft');
   $s.='<button type="submit">Save '.esc_html(strtolower($label)).'</button></form></details>';
  }
  return $base.$s.'</section>';
 }
 static function fields($type){
  if($type==='podcast')return ['_podcast_artist'=>'Artist name','_podcast_name'=>'Podcast name','_podcast_date'=>'Date','_podcast_resourceurl'=>'MP3, SoundCloud or Mixcloud URL'];
  if($type==='event')return ['eventdate'=>'Date','eventartists'=>'Artists','eventlocation'=>'Venue','eventstreet'=>'Street','eventcity'=>'City','eventwebsite'=>'Event website'];
  return [];
 }
 static function save(){
  if(!is_user_logged_in()||!ThreePM_Market::approved(get_current_user_id()))wp_die('Approved Artist status is required.','Forbidden',['response'=>403]);
  check_admin_referer('threepm_creator_content');
  try{
   $uid=get_current_user_id();$type=ThreePM_Market::input('type',30);
   if(!isset(self::TYPES[$type])||!post_type_exists($type))throw new RuntimeException('Invalid content type.');
   $id=absint(ThreePM_Market::input('item',12));$p=null;
   if($id){$p=ThreePM_Market::native_owner($id,$type,$uid);if($p->post_modified_gmt!==ThreePM_Market::input('modified',30))throw new RuntimeException('Content changed. Refresh before saving.');if(get_post_meta($id,'_elementor_data',true))throw new RuntimeException('This item uses an Elementor layout. Edit it with your administrator.');}
   $title=ThreePM_Market::input('title',160);if(!$title)throw new RuntimeException('Title is required.');
   $meta=[];foreach(self::fields($type)as $key=>$label){$v=ThreePM_Market::input($key,1000);if($v!==''&&(str_contains($key,'url')||$key==='eventwebsite'))$v=ThreePM_Market::safe_url($v);if(str_contains($key,'date')&&$v){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Use a valid date.');}$meta[$key]=$v;}
   $state=ThreePM_Market::input('state',20);if(!in_array($state,['draft','publish'],true))throw new RuntimeException('Invalid visibility.');
   if($state==='publish'&&$type==='podcast'&&empty($meta['_podcast_resourceurl']))throw new RuntimeException('Add a podcast audio URL.');
   if($state==='publish'&&$type==='event'&&empty($meta['eventdate']))throw new RuntimeException('Add an event date.');
   $gallery=$id?(array)get_post_meta($id,'galleryitem',true):[];$gallery=array_values(array_filter($gallery,'is_array'));
   if(count($gallery)>=50&&!empty($_FILES['gallery_image']['name']))throw new RuntimeException('Maximum 50 gallery images.');
   $cover=ThreePM_Market::upload('cover',$uid);$image=$type==='mediagallery'?ThreePM_Market::upload('gallery_image',$uid):0;
   if($image)$gallery[]=['title'=>$title,'image'=>$image,'video'=>''];
   if($state==='publish'&&$type==='mediagallery'&&!$gallery)throw new RuntimeException('Add a gallery image.');
   $id=wp_insert_post(wp_slash(['ID'=>$id,'post_type'=>$type,'post_title'=>$title,'post_content'=>wpautop(esc_html(ThreePM_Market::input('description'))),'post_status'=>$state,'post_author'=>$uid]),true);
   if(is_wp_error($id))throw new RuntimeException('Content could not be saved.');
   update_post_meta($id,'_threepm_artist_owner',$uid);
   foreach($meta as $key=>$v)update_post_meta($id,$key,$v);
   if($type==='mediagallery')update_post_meta($id,'galleryitem',$gallery);
   if($cover)set_post_thumbnail($id,$cover);
   do_action('litespeed_purge_all');
   wp_safe_redirect(add_query_arg(['creator_type'=>$type,'creator_item'=>$id,'saved'=>1],ThreePM_Market::url()));exit;
  }catch(Throwable $e){wp_die(esc_html($e->getMessage()),'Content not saved',['response'=>400,'back_link'=>true]);}
 }
 static function profile($content){
  if(!is_singular('artist')||!in_the_loop()||!is_main_query())return $content;
  $uid=(int)get_post_meta(get_the_ID(),'_threepm_artist_owner',true);
  if(!$uid||!class_exists('ThreePM_Market')||!ThreePM_Market::approved($uid))return $content;
  foreach(self::TYPES as $type=>$label){
   $posts=get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>100,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid]);
   if(!$posts)continue;$posts=ThreePM_Library_Presentation::sorted($posts,$uid,$type);$content.='<section class="tpm-market"><h2>'.esc_html($label).'</h2>';
   foreach($posts as $p)$content.='<p><a href="'.esc_url(get_permalink($p)).'">'.esc_html($p->post_title).'</a></p>';
   $content.='</section>';
  }
  return $content;
 }
}
ThreePM_Creator_Content::boot();
