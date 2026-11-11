<?php
defined('ABSPATH')||exit;
/** Native release-backed tracks with stable identities and ownership checks. */
final class ThreePM_Music_Library {
 static function boot(){
  add_action('admin_post_threepm_music',[__CLASS__,'save']);
  add_action('wp_ajax_threepm_music_layout',[__CLASS__,'layout']);
  add_action('wp_enqueue_scripts',function(){
   wp_enqueue_script('threepm-music-library',get_stylesheet_directory_uri().'/assets/js/threepm-music-library.js',[],'1.0.0',true);
   wp_enqueue_style('threepm-studio',get_stylesheet_directory_uri().'/assets/css/threepm-studio.css',[],'1.0.0');
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
  $s.='<div data-music-library data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('threepm_music_layout')).'"><p>Star up to three tracks to feature them. Drag tracks within a release to set their profile order, or use the arrow buttons.</p>';
  foreach($groups as $group){if(empty($group['items']))continue;usort($group['items'],fn($a,$b)=>($rank[$a['track']['threepm_id']]??PHP_INT_MAX)<=>($rank[$b['track']['threepm_id']]??PHP_INT_MAX));$s.='<section class="threepm-release-group"><h2>'.esc_html($group['title']).'</h2><div data-music-rows>';
   foreach($group['items']as $item){$p=$item['release'];$t=$item['track'];$id=$t['threepm_id'];$on=in_array($id,$state['featured'],true);
    $s.='<article class="tpm-manage-row" draggable="true" data-track-id="'.esc_attr($id).'"><div>'.get_the_post_thumbnail($p->ID,'thumbnail').'<h3>'.esc_html($t['releasetrack_track_title']??'Untitled track').'</h3><span>'.($p->post_status==='publish'?'Public':'Draft').'</span>';
    if(class_exists('ThreePM_Engagement_Tracking'))$s.=ThreePM_Engagement_Tracking::stats($p->ID,$id);
    $s.='</div><div class="tpm-actions"><a href="'.esc_url(ThreePM_Artist_Studio::url(['studio'=>'editor','release'=>$p->ID,'track'=>$id])).'">Edit</a><button type="button" data-track-feature aria-pressed="'.($on?'true':'false').'" aria-label="Feature '.esc_attr($t['releasetrack_track_title']??'track').'">'.($on?'★':'☆').'</button><button type="button" data-track-move="-1" aria-label="Move track up">↑</button><button type="button" data-track-move="1" aria-label="Move track down">↓</button></div></article>';
   }$s.='</div></section>';
  }
  return $s.'<button type="button" data-music-save>Save profile order and featured tracks</button><p role="status" data-music-status></p></div>';
 }
 static function editor($uid){
  $id=absint($_GET['release']??0);$p=null;
  if($id){try{$p=ThreePM_Market::native_owner($id,'release',$uid);}catch(Throwable $e){return '<p>Release unavailable.</p>';}}
  $rows=$id?self::tracks($id):[];$type=$id?get_post_meta($id,'_threepm_release_type',true):'';
  if(!$type)$type=($id?count($rows)===1:(($_GET['music_mode']??'single')==='single'))?'single':'ep';
  $single=$type==='single';if(!$rows)$rows=array_fill(0,$single?1:2,[]);
  $s='<p><a href="'.esc_url(ThreePM_Artist_Studio::url(['studio'=>'library'])).'">← Music library</a></p><h2>'.($p?'Edit music':($single?'Add Track':'Add Release')).'</h2><form data-music-editor method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('threepm_music','_wpnonce',true,false);
  foreach(['action'=>'threepm_music','release_id'=>$id,'revision'=>self::revision($id)]as $k=>$v)$s.='<input type="hidden" name="'.$k.'" value="'.esc_attr($v).'">';
  $s.=ThreePM_Market_UI::field('title',$single?'Single title':'Release title',$p->post_title??'','text',true);
  $s.=ThreePM_Market_UI::select('release_type','Release type',['single'=>'Single','ep'=>'EP','mixtape'=>'Mixtape','album'=>'Album'],$type);
  $s.=ThreePM_Market_UI::field('description','Description',$p?wp_strip_all_tags($p->post_content):'','textarea').ThreePM_Market_UI::field('release_date','Release date',$id?get_post_meta($id,'general_release_details_release_date',true):gmdate('Y-m-d'),'date',true).ThreePM_Market_UI::field('label','Label',$id?get_post_meta($id,'general_release_details_label',true):'').ThreePM_Market_UI::field('cover','Cover image','','file');
  $s.='<div data-track-fields>';foreach($rows as $i=>$t)$s.=self::fields($i,$t);$s.='</div><button type="button" data-add-track>Add another track</button><p>Singles contain one track. EPs, mixtapes and albums require at least two tracks. Audio preview URLs are public.</p>';
  $s.=ThreePM_Market_UI::select('state','Visibility',['draft'=>'Draft','publish'=>'Public'],$p->post_status??'draft');
  return $s.'<button type="submit">Save music</button></form>';
 }
 static function fields($i,$t){
  $s='<fieldset data-track-fields-row><legend>Track '.($i+1).'</legend><input type="hidden" name="tracks['.$i.'][id]" value="'.esc_attr($t['threepm_id']??'').'">';
  foreach(['title'=>['Track title','releasetrack_track_title'],'artists'=>['Artist credits','releasetrack_artist_name'],'preview'=>['Public audio preview URL','releasetrack_mp3_demo'],'embed'=>['SoundCloud / YouTube URL','releasetrack_scurl'],'buy'=>['Existing purchase link','releasetrack_buyurl'],'edition'=>['Limited edition quantity','threepm_edition_quantity']]as $k=>$v)$s.=ThreePM_Market_UI::field('tracks['.$i.']['.$k.']',$v[0],$t[$v[1]]??'',in_array($k,['preview','embed','buy'],true)?'url':($k==='edition'?'number':'text'),$k==='title');
  return $s.'<p>Setting an edition quantity prepares the listing; it does not mint copies.</p><button type="button" data-remove-track>Remove track from release</button></fieldset>';
 }
 static function url($v){$v=trim((string)$v);if(!$v)return '';if(str_starts_with($v,'/')&&!str_starts_with($v,'//'))$v=home_url($v);if(!filter_var($v,FILTER_VALIDATE_URL)||strtolower((string)parse_url($v,PHP_URL_SCHEME))!=='https')throw new RuntimeException('Use a valid HTTPS URL.');return esc_url_raw($v);}
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
   foreach($input as $row){
    if(!is_array($row))throw new RuntimeException('Invalid track.');
    $tid=(string)($row['id']??'');if($tid&&!isset($old[$tid]))throw new RuntimeException('Track does not belong to this release.');if(!$tid)$tid=str_replace('-','',wp_generate_uuid4());if(isset($seen[$tid]))throw new RuntimeException('Duplicate track.');$seen[$tid]=true;
    $t=$old[$tid]??[];$name=sanitize_text_field($row['title']??'');if(!$name)throw new RuntimeException('Every track needs a title.');
    $quantity=trim((string)($row['edition']??''));if($quantity!==''&&(!ctype_digit($quantity)||(int)$quantity<1||(int)$quantity>999999))throw new RuntimeException('Edition quantity must be a whole number from 1 to 999999.');
    if(!empty($t['threepm_edition_locked'])&&($quantity!==(string)($t['threepm_edition_quantity']??'')||$name!==($t['releasetrack_track_title']??'')))throw new RuntimeException('An activated edition cannot change its title or quantity.');
    foreach(['title'=>'releasetrack_track_title','artists'=>'releasetrack_artist_name','preview'=>'releasetrack_mp3_demo','embed'=>'releasetrack_scurl','buy'=>'releasetrack_buyurl']as $k=>$target)$t[$target]=in_array($k,['preview','embed','buy'],true)?self::url($row[$k]??''):sanitize_text_field($row[$k]??'');
    $t['threepm_id']=$tid;$t['threepm_edition_quantity']=$quantity===''?null:(int)$quantity;$t['icon_type']=$t['icon_type']??'cart';$tracks[]=$t;
   }
   foreach($old as $tid=>$t)if(!isset($seen[$tid])&&!empty($t['threepm_edition_locked']))throw new RuntimeException('An activated collectible track cannot be removed.');
   if(($type==='single'&&count($tracks)!==1)||($type!=='single'&&count($tracks)<2))throw new RuntimeException('Singles need exactly one track; releases need two or more.');
   $cover=ThreePM_Market::upload('cover',$uid);$post=['ID'=>$id,'post_type'=>'release','post_title'=>$title,'post_status'=>$state];if(!$id)$post['post_author']=$uid;
   if(!$id||!get_post_meta($id,'_elementor_data',true))$post['post_content']=wpautop(esc_html(ThreePM_Market::input('description')));
   $id=wp_insert_post(wp_slash($post),true);if(is_wp_error($id))throw new RuntimeException('Could not save release.');
   foreach(['_threepm_artist_owner'=>$uid,'_threepm_release_type'=>$type,'general_release_details_release_date'=>$date,'general_release_details_label'=>ThreePM_Market::input('label',160),'track_repeatable'=>$tracks]as $k=>$v)update_post_meta($id,$k,$v);
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
  $featured=array_flip($state['featured']);$rank=array_flip($state['order']);usort($rows,function($a,$b)use($featured,$rank){$a=$a['track']['threepm_id'];$b=$b['track']['threepm_id'];return (isset($featured[$b])<=>isset($featured[$a]))?: (($rank[$a]??PHP_INT_MAX)<=>($rank[$b]??PHP_INT_MAX));});
  if(!$rows)return $html;$out='<section class="tpm-market"><h2>Music</h2>';
  foreach($rows as $r){$t=$r['track'];$out.='<article><h3>'.(isset($featured[$t['threepm_id']])?'★ ':'').esc_html($t['releasetrack_track_title']).'</h3><a href="'.esc_url(get_permalink($r['release'])).'">'.esc_html($r['release']->post_title).'</a>';if(!empty($t['releasetrack_mp3_demo']))$out.='<audio controls preload="none" src="'.esc_url($t['releasetrack_mp3_demo']).'"></audio>';$out.='</article>';}
  return $out.'</section>'.$html;
 }
}
ThreePM_Music_Library::boot();

