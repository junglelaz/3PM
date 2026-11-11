<?php
defined('ABSPATH')||exit;
final class ThreePM_Gallery_Editor {
 static function boot(){add_action('wp_ajax_threepm_gallery_edit',[__CLASS__,'save']);add_action('wp_enqueue_scripts',function(){wp_enqueue_script('threepm-gallery-editor',THREEPM_STUDIO_URL.'/assets/js/threepm-gallery-editor.js',[],'0.2.10',true);});}
 static function revision($rows){return hash('sha256',wp_json_encode($rows));}
 static function widgets($elements){$out=[];foreach($elements as $e){if(($e['widgetType']??'')==='vice-elementor-moderngallery')$out[$e['id']]=$e['settings']['gallery']??[];$out+=self::widgets($e['elements']??[]);}return $out;}
 static function replace(&$elements,$widget,$rows){foreach($elements as &$e){if(($e['id']??'')===$widget&&($e['widgetType']??'')==='vice-elementor-moderngallery'){$e['settings']['gallery']=$rows;return true;}if(isset($e['elements'])&&self::replace($e['elements'],$widget,$rows))return true;}return false;}
 static function rows($id,$source){
  if($source==='profile')return array_values(array_filter((array)get_post_meta($id,'_threepm_market_gallery',true)));
  if($source==='gallery')return array_values(array_filter((array)get_post_meta($id,'galleryitem',true),'is_array'));
  $widgets=self::widgets(json_decode(get_post_meta($id,'_elementor_data',true),true)?:[]);if(!array_key_exists($source,$widgets))throw new RuntimeException('Gallery unavailable.');return array_values($widgets[$source]);
 }
 static function descriptions($id,$source){$all=get_post_meta($id,'_threepm_gallery_descriptions',true);return is_array($all)?($all[$source]??[]):[];}
 static function image_key($row){return hash('sha256',wp_json_encode($row));}
 static function panel($id,$source,$rows){
  $descriptions=self::descriptions($id,$source);
  $s='<div class="threepm-gallery-editor" data-gallery-editor data-description-revision="'.esc_attr(self::revision($descriptions)).'" data-post="'.(int)$id.'" data-source="'.esc_attr($source).'" data-revision="'.esc_attr(self::revision($rows)).'" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('threepm_gallery_'.$id)).'"><p class="threepm-gallery-help">Drag to reorder. Select × to remove an item, then save your gallery. Original media files are kept.</p><div class="threepm-gallery-grid">';
  foreach($rows as $key=>$row){$image=is_array($row)?($row['id']??$row['image']??0):$row;$title=is_array($row)?($row['title']??''):get_the_title((int)$image);$title=$title?:'Gallery item '.($key+1);$description=$descriptions[self::image_key($row)]??(is_numeric($image)?get_post_meta((int)$image,'_wp_attachment_image_alt',true):'');$thumb=is_numeric($image)?wp_get_attachment_image((int)$image,'medium',false,['draggable'=>'false','alt'=>$title]):'';if(!$thumb&&is_array($row)&&!empty($row['url']))$thumb='<img src="'.esc_url($row['url']).'" alt="'.esc_attr($title).'" draggable="false">';if(!$thumb)$thumb='<span class="threepm-gallery-fallback">'.(is_array($row)&&!empty($row['video'])?'Video':'Image').'</span>';
   $s.='<figure draggable="true" data-gallery-key="'.(int)$key.'">'.$thumb.'<button type="button" class="threepm-gallery-remove" data-gallery-remove aria-label="Remove '.esc_attr($title).'">×</button><figcaption>'.esc_html($title).'</figcaption><button type="button" data-gallery-edit aria-expanded="false">Edit</button><label class="threepm-gallery-description" hidden>Image description<textarea maxlength="1000" data-gallery-description aria-label="Image description for '.esc_attr($title).'">'.esc_textarea($description).'</textarea></label><div class="threepm-gallery-move"><button type="button" data-gallery-move="-1" aria-label="Move '.esc_attr($title).' earlier">←</button><button type="button" data-gallery-move="1" aria-label="Move '.esc_attr($title).' later">→</button></div></figure>';
  }
  return $s.'</div><div class="threepm-gallery-toolbar"><button type="button" data-gallery-save disabled>Save gallery</button><button type="button" data-gallery-undo hidden>Undo removal</button></div><p role="status" data-gallery-status>'.(!$rows?'No gallery items added yet.':'').'</p></div>';
 }
 static function profile($html,$id){
  if(class_exists('ThreePM_Profile_Links'))$html=ThreePM_Profile_Links::form($html,$id);if(!$id)return $html;$start=strpos($html,'<h3>Gallery</h3>');$end=strpos($html,'<label>Profile visibility',$start?:0);if($start===false||$end===false)return $html;
  $s='<h3>Gallery</h3>';$widgets=self::widgets(json_decode(get_post_meta($id,'_elementor_data',true),true)?:[]);
  foreach($widgets as $widget=>$rows)$s.=self::panel($id,$widget,array_values($rows));
  $added=self::rows($id,'profile');if($added||!$widgets)$s.=self::panel($id,'profile',$added);
  $s.='<p>Add an image using the fields below, then save your profile.</p>'.ThreePM_Market_UI::field('gallery_image','Add gallery image','','file').ThreePM_Market_UI::field('gallery_alt','Image description / alt text');
  return substr($html,0,$start).$s.substr($html,$end);
 }
 static function ordered($rows,$order){if(!is_array($order)||count($order)>count($rows)||count(array_unique($order,SORT_REGULAR))!==count($order))throw new RuntimeException('Invalid gallery order.');$new=[];foreach($order as $index){if(!is_int($index)||!array_key_exists($index,$rows))throw new RuntimeException('Unknown gallery item.');$new[]=$rows[$index];}return $new;}
 static function save(){
  $lock='';try{
   $uid=get_current_user_id();if(!$uid||(!ThreePM_Market::approved($uid)&&!current_user_can('manage_options')))throw new RuntimeException('Approved Artist access required.');$id=absint($_POST['post']??0);check_ajax_referer('threepm_gallery_'.$id,'nonce');
   $type=get_post_type($id);if(!in_array($type,['artist','mediagallery'],true))throw new RuntimeException('Gallery unavailable.');if(!current_user_can('manage_options')||!current_user_can('edit_post',$id))ThreePM_Market::native_owner($id,$type,$uid);
   $source=sanitize_key($_POST['source']??'');if(($type==='mediagallery'&&$source!=='gallery')||($type==='artist'&&$source==='gallery'))throw new RuntimeException('Invalid gallery source.');
   global $wpdb;$lock='threepm-gallery-'.get_current_blog_id().'-'.$id;if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)throw new RuntimeException('Gallery is busy. Retry.');
   $rows=self::rows($id,$source);if(!hash_equals(self::revision($rows),(string)($_POST['revision']??'')))throw new RuntimeException('This gallery changed in another tab. Reload before editing.');
   $order=json_decode(wp_unslash($_POST['order']??''),true);$new=self::ordered($rows,$order);
   $descriptions=self::descriptions($id,$source);if(!hash_equals(self::revision($descriptions),(string)($_POST['description_revision']??'')))throw new RuntimeException('Descriptions changed in another tab. Reload before editing.');
   $submitted=json_decode(wp_unslash($_POST['descriptions']??''),true);if(!is_array($submitted)||count($submitted)!==count($order))throw new RuntimeException('Invalid descriptions.');
   $new_descriptions=[];foreach($order as $pos=>$index){if(!is_string($submitted[$pos])||strlen($submitted[$pos])>4000)throw new RuntimeException('Description is too long.');$new_descriptions[self::image_key($rows[$index])]=sanitize_textarea_field($submitted[$pos]);}

   if($source==='profile'||$source==='gallery'){
    $key=$source==='profile'?'_threepm_market_gallery':'galleryitem';$raw=get_post_meta($id,$key,true);
    if($new!==$rows&&!update_post_meta($id,$key,$new,$raw))throw new RuntimeException('Gallery changes could not be saved.');
   }else{
    if(!class_exists('Elementor\\Plugin'))throw new RuntimeException('Profile editor unavailable.');$doc=\Elementor\Plugin::$instance->documents->get($id);if(!$doc)throw new RuntimeException('Profile unavailable.');$data=$doc->get_elements_data();
    $current=self::widgets($data);if(!isset($current[$source])||!hash_equals(self::revision(array_values($current[$source])),self::revision($rows)))throw new RuntimeException('Profile changed. Reload before editing.');
    if(!self::replace($data,$source,$new))throw new RuntimeException('Gallery unavailable.');wp_save_post_revision($id);$doc->save(['elements'=>$data]);
    if(self::rows($id,$source)!==$new)throw new RuntimeException('Profile gallery did not save.');\Elementor\Plugin::$instance->files_manager->clear_cache();
   }
   $all=get_post_meta($id,'_threepm_gallery_descriptions',true);$all=is_array($all)?$all:[];$all[$source]=$new_descriptions;update_post_meta($id,'_threepm_gallery_descriptions',$all);
   do_action('litespeed_purge_post',$id);clean_post_cache($id);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$lock='';
   wp_send_json_success(['revision'=>self::revision($new),'description_revision'=>self::revision($new_descriptions),'message'=>'Gallery saved.']);
  }catch(Throwable $e){if($lock){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}wp_send_json_error(['message'=>$e->getMessage()],400);}
 }
}
ThreePM_Gallery_Editor::boot();
