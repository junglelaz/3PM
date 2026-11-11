<?php
defined('ABSPATH')||exit;
/** Native release-backed tracks with stable identities and ownership checks. */
final class ThreePM_Music_Library {
 static function boot(){
  add_action('admin_post_threepm_music',[__CLASS__,'save']);
  add_action('wp_ajax_threepm_music_layout',[__CLASS__,'layout']);
  add_action('wp_enqueue_scripts',function(){
   wp_enqueue_script('threepm-music-library',THREEPM_STUDIO_URL.'/assets/js/threepm-music-library.js',[],'0.2.29',true);
   wp_enqueue_style('threepm-studio',THREEPM_STUDIO_URL.'/assets/css/threepm-studio.css',[],'0.2.3');
  });
  add_filter('the_content',[__CLASS__,'profile'],35);
 }
 static function releases($uid,$public=false){return get_posts(['post_type'=>'release','post_status'=>$public?['publish']:['publish','draft'],'numberposts'=>-1,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid,'orderby'=>'date','order'=>'DESC']);}
 static function tracks($id){
  $rows=array_values(array_filter((array)get_post_meta($id,'track_repeatable',true),'is_array'));
  foreach($rows as $i=>&$t)if(empty($t['threepm_id']))$t['threepm_id']=substr(hash('sha256',$id.':'.$i.':'.($t['releasetrack_track_title']??'').':'.($t['releasetrack_mp3_demo']??'')),0,32);
  unset($t);return $rows;
 }
 static function revision($id){return hash('sha256',wp_json_encode([$id?get_post($id):null,$id?get_post_meta($id,'track_repeatable',true):[]]));}
 static function state($uid){$s=get_user_meta($uid,'_threepm_music_layout',true);return is_array($s)?array_merge(['order'=>[],'featured'=>[]],$s):['order'=>[],'featured'=>[]];}
 static function library($uid){
  $releases=self::releases($uid);$count=0;foreach($releases as $p)$count+=count(self::tracks($p->ID));
  $s=$count?'':'<p>You haven&#39;t added any Tracks yet.</p>';
  $s.='<div class="tpm-toolbar"><a class="tpm-button" href="'.esc_url(ThreePM_Artist_Studio::url(['studio'=>'editor','music_mode'=>'single'])).'">Add Track</a><a class="tpm-button" href="'.esc_url(ThreePM_Artist_Studio::url(['studio'=>'editor','music_mode'=>'release'])).'">Add Release</a></div>';
  if(!$count)return $s;
  $state=self::state($uid);$rank=array_flip($state['order']);$groups=[];$singles=[];
  foreach($releases as $p){$rows=self::tracks($p->ID);$type=get_post_meta($p->ID,'_threepm_release_type',true);foreach($rows as $t){$item=['release'=>$p,'track'=>$t];if($type==='single'||(!$type&&count($rows)===1))$singles[]=$item;else $groups[$p->ID]['items'][]=$item;}$groups[$p->ID]['title']=$p->post_title;}
  if($singles)$groups=['singles'=>['title'=>'Singles','items'=>$singles]]+$groups;
  $s.='<div data-music-library data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('threepm_music_layout')).'"><p>Star up to three tracks to feature them. Drag tracks within a release to set their profile order, or use the arrow buttons.</p><p class="threepm-sales-note">Net sales are after discounts and refunds, before artist/platform allocation. Tax and shipping are excluded.</p>';
  $s.='<div class="tpm-toolbar"><button type="button" data-music-save>Save track order and featured tracks</button></div>';
  foreach($groups as $group_id=>$group){if(empty($group['items']))continue;usort($group['items'],fn($a,$b)=>($rank[$a['track']['threepm_id']]??PHP_INT_MAX)<=>($rank[$b['track']['threepm_id']]??PHP_INT_MAX));$s.='<section class="threepm-release-group"><h2>'.esc_html($group['title']).'</h2>';if(is_numeric($group_id)&&class_exists('ThreePM_Sales_Stats'))$s.=ThreePM_Sales_Stats::bundles((int)$group_id);$s.='<div data-music-rows>';
   foreach($group['items']as $item){$p=$item['release'];$t=$item['track'];$id=$t['threepm_id'];$on=in_array($id,$state['featured'],true);
    $s.='<article class="tpm-manage-row" draggable="true" data-track-id="'.esc_attr($id).'"><div>'.get_the_post_thumbnail($p->ID,'thumbnail').'<h3>'.esc_html($t['releasetrack_track_title']??'Untitled track').'</h3><span>'.($p->post_status==='publish'?'Public':'Draft').'</span>';
    if(class_exists('ThreePM_Engagement_Tracking'))$s.=ThreePM_Engagement_Tracking::stats($p->ID,$id);
    if(class_exists('ThreePM_Sales_Stats'))$s.=ThreePM_Sales_Stats::track($p->ID,$t);
    $s.='</div><div class="tpm-actions"><a href="'.esc_url(ThreePM_Artist_Studio::url(['studio'=>'editor','music_release'=>$p->ID,'track'=>$id])).'">Edit</a><button type="button" data-track-feature aria-pressed="'.($on?'true':'false').'" aria-label="Feature '.esc_attr($t['releasetrack_track_title']??'track').'">'.($on?'★':'☆').'</button><button type="button" data-track-move="-1" aria-label="Move track up">↑</button><button type="button" data-track-move="1" aria-label="Move track down">↓</button></div></article>';
   }$s.='</div></section>';
  }
  return $s.'<button type="button" data-music-save>Save track order and featured tracks</button><p role="status" data-music-status></p></div>';
 }
 static function editor($uid){
  $id=absint($_GET['music_release']??0);$p=null;
  if($id){try{$p=ThreePM_Market::native_owner($id,'release',$uid);}catch(Throwable $e){return '<p>Release unavailable.</p>';}}
  $rows=$id?self::tracks($id):[];$type=$id?get_post_meta($id,'_threepm_release_type',true):'';
  if(!$type)$type=($id?count($rows)===1:(($_GET['music_mode']??'single')==='single'))?'single':'ep';
  $single=$type==='single';if(!$rows)$rows=array_fill(0,$single?1:2,[]);
  $s='<p><a href="'.esc_url(ThreePM_Artist_Studio::url(['studio'=>'library'])).'">← Music library</a></p><h2>'.($p?'Edit music':($single?'Add Track':'Add Release')).'</h2><form data-music-editor method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('threepm_music','_wpnonce',true,false);
  foreach(['action'=>'threepm_music','release_id'=>$id,'revision'=>self::revision($id)]as $k=>$v)$s.='<input type="hidden" name="'.$k.'" value="'.esc_attr($v).'">';
  $s.=ThreePM_Market_UI::field('title',$single?'Single title':'Release title',$p->post_title??'','text',true);
  $s.=ThreePM_Market_UI::select('release_type','Release type',['single'=>'Single','ep'=>'EP','mixtape'=>'Mixtape','album'=>'Album'],$type);
  $s.=ThreePM_Market_UI::field('description','Description',$p?wp_strip_all_tags($p->post_content):'','textarea').ThreePM_Market_UI::field('release_date','Release date',$id?get_post_meta($id,'general_release_details_release_date',true):gmdate('Y-m-d'),'date',true).ThreePM_Market_UI::field('label','Label',$id?get_post_meta($id,'general_release_details_label',true):'').ThreePM_Market_UI::field('cover','Cover image','','file');
  $s.='<div data-track-fields>';foreach($rows as $i=>$t)$s.=self::fields($i,$t);$s.='</div><button type="button" data-add-track>Add another track</button><p>Singles contain one track. EPs, mixtapes and albums require at least two tracks. Upload audio for each new track; it will be used by the public player.</p>';
  $s.=ThreePM_Market_UI::select('state','Visibility',['draft'=>'Draft','publish'=>'Public'],$p->post_status??'draft');
  return $s.'<button type="submit">Save music</button></form>';
 }
 static function fields($i,$t){
  $s='<fieldset data-track-fields-row><legend>Track '.($i+1).'</legend><input type="hidden" name="tracks['.$i.'][id]" value="'.esc_attr($t['threepm_id']??'').'">';
  foreach(['title'=>['Track title','releasetrack_track_title'],'artists'=>['Artist credits','releasetrack_artist_name'],'embed'=>['SoundCloud / YouTube URL','releasetrack_scurl'],'edition'=>['Limited edition quantity','threepm_edition_quantity']]as $k=>$v)$s.=ThreePM_Market_UI::field('tracks['.$i.']['.$k.']',$v[0],(in_array($k,['preview','embed','buy'],true)&&str_starts_with((string)($t[$v[1]]??''),'/')?home_url($t[$v[1]]):($t[$v[1]]??'')),in_array($k,['preview','embed','buy'],true)?'url':($k==='edition'?'number':'text'),$k==='title');
  $s.='<label>Track audio<input type="file" name="tracks['.$i.'][audio]" accept=".mp3,.wav,.m4a,.ogg"></label><p data-audio-status>'.(!empty($t['releasetrack_mp3_demo'])?'Audio is connected. Upload a file only to replace it.':'Upload the audio you want listeners to hear.').'</p>';
  return $s.'<p>Setting an edition quantity prepares the listing; it does not mint copies.</p><button type="button" data-remove-track>Remove track from release</button></fieldset>';
 }
 static function url($v){$v=trim((string)$v);if(!$v)return '';if(str_starts_with($v,'/')&&!str_starts_with($v,'//'))$v=home_url($v);if(!filter_var($v,FILTER_VALIDATE_URL)||strtolower((string)parse_url($v,PHP_URL_SCHEME))!=='https')throw new RuntimeException('Use a valid HTTPS URL.');return esc_url_raw($v);}
 static function primary_product($id){
  $product=(int)get_post_meta($id,'_threepm_primary_product_id',true);
  if($product&&get_post_type($product)==='product')return $product;
  $products=[];foreach(self::tracks($id)as $track){$p=ThreePM_Sales_Stats::product($track['releasetrack_buyurl']??'');if($p)$products[$p]=true;}
  return count($products)===1?(int)array_key_first($products):0;
 }
 static function audio_file($index){
  $files=$_FILES['tracks']??[];$error=$files['error'][$index]['audio']??UPLOAD_ERR_NO_FILE;
  if($error===UPLOAD_ERR_NO_FILE)return null;
  if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Audio upload failed. Check the file size and try again.');
  $out=[];foreach(['name','type','tmp_name','error','size']as $key)$out[$key]=$files[$key][$index]['audio']??null;
  if(!$out['tmp_name']||!is_uploaded_file($out['tmp_name'])||$out['size']>min(wp_max_upload_size(),64*1024*1024))throw new RuntimeException('Use an audio file within the upload limit (maximum 64 MB).');
  return $out;
 }
 static function upload_audio($file,$uid){
  require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
  $mimes=['mp3'=>'audio/mpeg','wav'=>'audio/wav','m4a'=>'audio/mpeg','ogg'=>'audio/ogg'];
  $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],$mimes);
  $metadata=wp_read_audio_metadata($file['tmp_name']);
  if(empty($check['type'])||empty($metadata['length']))throw new RuntimeException('Use a valid MP3, WAV, M4A or OGG audio file.');
  $_FILES['threepm_track_audio']=$file;
  try{$attachment=media_handle_upload('threepm_track_audio',0,['post_author'=>$uid],['test_form'=>false,'mimes'=>$mimes]);}
  finally{unset($_FILES['threepm_track_audio']);}
  if(is_wp_error($attachment))throw new RuntimeException('The audio could not be saved. Please try again.');
  return $attachment;
 }
 static function save(){
  if(!is_user_logged_in()||!ThreePM_Market::approved(get_current_user_id()))wp_die('Approved Artist status required.','Forbidden',['response'=>403]);
  check_admin_referer('threepm_music');global $wpdb;$lock='3pm-music-'.get_current_blog_id();$locked=false;
  try{
   $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))===1;if(!$locked)throw new RuntimeException('Another save is underway. Try again.');
   $uid=get_current_user_id();$id=absint($_POST['release_id']??0);if($id)ThreePM_Market::native_owner($id,'release',$uid);
   if(!hash_equals(self::revision($id),(string)($_POST['revision']??'')))throw new RuntimeException('This release changed. Refresh before saving.');
   $old=[];foreach(self::tracks($id)as $t)$old[$t['threepm_id']]=$t;
   $title=ThreePM_Market::input('title',160);$type=ThreePM_Market::input('release_type',20);$state=ThreePM_Market::input('state',20);
   if(!$title||!in_array($type,['single','ep','mixtape','album'],true)||!in_array($state,['publish','draft'],true))throw new RuntimeException('Check the title, type and visibility.');
   $date=ThreePM_Market::input('release_date',10);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new RuntimeException('Use a valid release date.');
   $input=wp_unslash($_POST['tracks']??[]);if(!is_array($input)||count($input)>50)throw new RuntimeException('Use at most 50 tracks.');$tracks=[];$seen=[];
   foreach($input as $upload_index=>$row){
    if(!is_array($row))throw new RuntimeException('Invalid track.');
    $tid=(string)($row['id']??'');if($tid&&!isset($old[$tid]))throw new RuntimeException('Track does not belong to this release.');if(!$tid)$tid=str_replace('-','',wp_generate_uuid4());if(isset($seen[$tid]))throw new RuntimeException('Duplicate track.');$seen[$tid]=true;
    $t=$old[$tid]??[];$name=sanitize_text_field($row['title']??'');if(!$name)throw new RuntimeException('Every track needs a title.');
    $quantity=trim((string)($row['edition']??''));if($quantity!==''&&(!ctype_digit($quantity)||(int)$quantity<1||(int)$quantity>999999))throw new RuntimeException('Edition quantity must be a whole number from 1 to 999999.');
    if(!empty($t['threepm_edition_locked'])&&($quantity!==(string)($t['threepm_edition_quantity']??'')||$name!==($t['releasetrack_track_title']??'')))throw new RuntimeException('An activated edition cannot change its title or quantity.');
    foreach(['title'=>'releasetrack_track_title','artists'=>'releasetrack_artist_name','embed'=>'releasetrack_scurl']as $k=>$target)$t[$target]=in_array($k,['preview','embed','buy'],true)?self::url($row[$k]??''):sanitize_text_field($row[$k]??'');
    $t['threepm_upload_index']=$upload_index;$t['threepm_id']=$tid;$t['threepm_edition_quantity']=$quantity===''?null:(int)$quantity;$t['icon_type']=$t['icon_type']??'cart';$tracks[]=$t;
   }
   foreach($old as $tid=>$t)if(!isset($seen[$tid])&&!empty($t['threepm_edition_locked']))throw new RuntimeException('An activated collectible track cannot be removed.');
   if(($type==='single'&&count($tracks)!==1)||($type!=='single'&&count($tracks)<2))throw new RuntimeException('Singles need exactly one track; releases need two or more.');
   $primary=self::primary_product($id);
   foreach($tracks as &$track){
    $index=$track['threepm_upload_index'];unset($track['threepm_upload_index']);
    $file=self::audio_file($index);
    if($file){
     if(!empty($track['threepm_edition_locked']))throw new RuntimeException('An activated collectible cannot replace its audio.');
     $track['threepm_audio_attachment']=self::upload_audio($file,$uid);
     $track['releasetrack_mp3_demo']=wp_get_attachment_url($track['threepm_audio_attachment']);
    }
    if($state==='publish'&&empty($track['releasetrack_mp3_demo'])&&(empty($old[$track['threepm_id']]['releasetrack_scurl'])))throw new RuntimeException('Upload audio for each new track before publishing.');
    if($primary)$track['releasetrack_buyurl']=get_permalink($primary);
   }unset($track);
   $cover=ThreePM_Market::upload('cover',$uid);$post=['ID'=>$id,'post_type'=>'release','post_title'=>$title,'post_status'=>$state];if(!$id)$post['post_author']=$uid;
   if(!$id||!get_post_meta($id,'_elementor_data',true))$post['post_content']=wpautop(esc_html(ThreePM_Market::input('description')));
   $id=wp_insert_post(wp_slash($post),true);if(is_wp_error($id))throw new RuntimeException('Could not save release.');
   foreach(['_threepm_artist_owner'=>$uid,'_threepm_release_type'=>$type,'general_release_details_release_date'=>$date,'general_release_details_label'=>ThreePM_Market::input('label',160),'track_repeatable'=>$tracks]as $k=>$v)update_post_meta($id,$k,$v);
   if($primary)update_post_meta($id,'_threepm_primary_product_id',$primary);
   if($cover)set_post_thumbnail($id,$cover);do_action('litespeed_purge_all');
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));wp_safe_redirect(ThreePM_Artist_Studio::url(['studio'=>'library','saved'=>1]));exit;
  }catch(Throwable $e){if($locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));wp_die(esc_html($e->getMessage()),'Music not saved',['response'=>400,'back_link'=>true]);}
 }
 static function layout(){
  check_ajax_referer('threepm_music_layout','nonce');$uid=get_current_user_id();if(!$uid||!ThreePM_Market::approved($uid))wp_send_json_error(['message'=>'Artist approval required.'],403);
  $valid=[];foreach(self::releases($uid)as $p)foreach(self::tracks($p->ID)as $t)$valid[]=$t['threepm_id'];
  $order=$_POST['order']??[];$featured=$_POST['featured']??[];
  if(!is_array($order)||!is_array($featured)||array_filter($order,'is_array')||array_filter($featured,'is_array'))wp_send_json_error(['message'=>'Invalid layout.'],400);
  if(count($featured)>3||count(array_unique($featured))!==count($featured)||count(array_unique($order))!==count($order)||array_diff($order,$valid)||array_diff($valid,$order)||array_diff($featured,$valid))wp_send_json_error(['message'=>'Library changed or more than three tracks selected. Refresh and try again.'],409);
  update_user_meta($uid,'_threepm_music_layout',['order'=>array_values($order),'featured'=>array_values($featured)]);do_action('litespeed_purge_all');wp_send_json_success(['message'=>'Profile order and featured tracks saved.']);
 }
 static function profile($html){
  if(!is_singular('artist')||!in_the_loop()||!is_main_query())return $html;
  $uid=(int)get_post_meta(get_the_ID(),'_threepm_artist_owner',true);if(!$uid||!ThreePM_Market::approved($uid))return $html;
  $state=self::state($uid);$rows=[];foreach(self::releases($uid,true)as $p)foreach(self::tracks($p->ID)as $t)$rows[]=['release'=>$p,'track'=>$t];
  $featured=array_flip(array_slice($state['featured'],0,3));$rank=array_flip($state['order']);
  $rows=array_values(array_filter($rows,fn($r)=>isset($featured[$r['track']['threepm_id']])&&!empty($r['track']['releasetrack_mp3_demo'])));
  usort($rows,fn($a,$b)=>($rank[$a['track']['threepm_id']]??PHP_INT_MAX)<=>($rank[$b['track']['threepm_id']]??PHP_INT_MAX));
  if(!$rows)return $html;$out='<section class="threepm-featured-tracks" aria-label="Featured Tracks"><h2>Featured Tracks</h2><div class="threepm-featured-list">';
  foreach($rows as $r){$t=$r['track'];$p=$r['release'];$title=$t['releasetrack_track_title'];$out.='<div class="threepm-featured-track"><a href="#" class="threepm-featured-play" data-featured-release="'.(int)$p->ID.'" data-featured-track="'.esc_attr($t['threepm_id']).'" data-release-title="'.esc_attr($p->post_title).'" aria-label="'.esc_attr('Play '.$title).'" data-playtrack="'.esc_url($t['releasetrack_mp3_demo']).'" data-title="'.esc_attr($title).'" data-author="'.esc_attr($t['releasetrack_artist_name']??get_the_title()).'" data-releasepage="'.esc_url(get_permalink($p)).'" data-cover="'.esc_url(get_the_post_thumbnail_url($p,'medium')).'" data-buyurl="'.esc_url($t['releasetrack_buyurl']??'').'" data-icontype="cart"><span aria-hidden="true">▶</span></a><span class="threepm-featured-title">'.esc_html($title).'</span></div>';}
  return $out.'</div></section>'.$html;
 }
}
ThreePM_Music_Library::boot();
