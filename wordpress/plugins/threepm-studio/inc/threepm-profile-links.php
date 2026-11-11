<?php
defined('ABSPATH')||exit;
final class ThreePM_Profile_Links {
 const SOCIAL=['instagram'=>['Instagram','https://www.instagram.com/'],'tiktok'=>['TikTok','https://www.tiktok.com/@'],'youtube'=>['YouTube','https://www.youtube.com/@'],'facebook'=>['Facebook','https://www.facebook.com/'],'twitter'=>['X','https://x.com/'],'soundcloud'=>['SoundCloud','https://soundcloud.com/'],'mixcloud'=>['Mixcloud','https://www.mixcloud.com/'],'beatport'=>['Beatport','https://www.beatport.com/artist/']];
 static $pending=null;
 static function boot(){add_action('admin_post_threepm_market',[__CLASS__,'prepare'],2);add_action('wp_after_insert_post',[__CLASS__,'persist'],10,2);add_action('add_meta_boxes',[__CLASS__,'boxes']);add_action('save_post',[__CLASS__,'admin_save'],1000,2);}
 static function form($html,$id){
  $raw=(string)get_post_meta($id,'_threepm_profile_websites',true);$web=[];$social=[];$primary=(string)get_post_meta($id,'_artist_website',true);if($primary)$web[]=[get_post_meta($id,'_threepm_primary_website_label',true)?:'Website',$primary];
  foreach(self::SOCIAL as $key=>$config){$v=(string)get_post_meta($id,'_artist_'.$key,true);if($v)$social[$key]=$v;}
  foreach(preg_split('/\R/',$raw,-1,PREG_SPLIT_NO_EMPTY)as $line){$pair=explode('|',$line,2);if(count($pair)!==2)continue;[$label,$url]=array_map('trim',$pair);$found=false;foreach(self::SOCIAL as $key=>$config){if(strtolower($label)===strtolower($config[0])){$social[$key]=$url;$found=true;break;}}if(!$found&&$url!==$primary)$web[]=[$label,$url];}
  if(!$web&&($v=get_post_meta($id,'_artist_website',true)))$web[]=['Website',$v];
  if(count($web)>2)return $html; // Keep legacy links intact until explicitly migrated.
  $initial=['websites'=>$raw];foreach(array_merge(array_keys(self::SOCIAL),['website'])as $k)$initial[$k]=(string)get_post_meta($id,'_artist_'.$k,true);
  $country=(string)get_post_meta($id,'_threepm_profile_country',true);$genre=(string)get_post_meta($id,'_threepm_profile_genre',true);$details=is_admin()?'':ThreePM_Market_UI::field('artist_country','Country',$country).ThreePM_Market_UI::field('artist_genre','Genre',$genre);
  $s=ThreePM_Profile_URL::fields($id).$details.ThreePM_Market_UI::field('artist_languages','Languages',get_post_meta($id,'_artist_languages',true)).ThreePM_Market_UI::field('artist_contact','Contact email',get_post_meta($id,'_artist_contact',true),'email').'<input type="hidden" name="threepm_initial_links" value="'.esc_attr(wp_json_encode($initial)).'"><section class="threepm-profile-links" data-profile-links><input type="hidden" name="structured_links" value="1"><h3>Websites</h3><p>Add up to two external websites.</p><div class="threepm-website-fields">';
  for($i=0;$i<2;$i++)$s.='<div>'.ThreePM_Market_UI::field('website_label['.$i.']','Website '.($i+1).' label',$web[$i][0]??'').ThreePM_Market_UI::field('website_url['.$i.']','Website '.($i+1).' URL',$web[$i][1]??'','url').'</div>';
  $s.='</div><h3>Social media</h3><p>Choose a platform and enter your username. Add up to eight links.</p><div data-social-rows>';$keys=array_keys($social);$count=max(1,count($keys));
  for($i=0;$i<8;$i++){$key=$keys[$i]??'instagram';$config=self::SOCIAL[$key];$value=isset($keys[$i])?($social[$key]??''):'';if(str_starts_with($value,$config[1]))$value=substr($value,strlen($config[1]));$s.='<div class="threepm-social-row" data-social-row '.($i>=$count?'hidden':'').'><label>Social platform '.($i+1).'<select name="social_platform['.$i.']" data-social-platform>';foreach(self::SOCIAL as $k=>$c)$s.='<option value="'.$k.'" data-prefix="'.esc_attr($c[1]).'" '.selected($key,$k,false).'>'.$c[0].'</option>';$s.='</select></label><label><span data-social-label>'.$config[0].' username</span><span class="threepm-social-input"><span data-social-prefix>'.esc_html($config[1]).'</span><input name="social_username['.$i.']" type="text" maxlength="180" value="'.esc_attr($value).'" autocomplete="off" placeholder="username"></span></label><button type="button" data-social-remove aria-label="Remove social link '.($i+1).'">×</button></div>';}
  $s.='</div><button type="button" data-social-add '.($count>=8?'hidden':'').'>+ Add social link</button></section>';
  return preg_replace('/<label[^>]*>External links.*?<\/label>/s',$s,$html,1);
 }
 static function prepare(){
  if(($_POST['op']??'')!=='profile'||empty($_POST['structured_links']))return;
  check_admin_referer('threepm_market');if(!ThreePM_Market::approved(get_current_user_id()))wp_die('Approved Artist access required.');
  self::parse();
 }
 static function parse(){
  try{$links=[];for($i=0;$i<2;$i++){$url=trim(wp_unslash($_POST['website_url'][$i]??''));$label=sanitize_text_field(wp_unslash($_POST['website_label'][$i]??''));if(!$url)continue;if(str_contains($label,'|'))throw new RuntimeException('Website labels cannot contain |.');$links[]=($label?:'Website '.($i+1)).' | '.ThreePM_Market::safe_url($url);}
   $social=array_fill_keys(array_keys(self::SOCIAL),'');$seen=[];
   for($i=0;$i<8;$i++){$value=trim(wp_unslash($_POST['social_username'][$i]??''));if(!$value)continue;$key=sanitize_key($_POST['social_platform'][$i]??'');if(!isset(self::SOCIAL[$key]))throw new RuntimeException('Choose a valid social platform.');$prefix=self::SOCIAL[$key][1];
    if(preg_match('~^https?://~i',$value)){$host=strtolower((string)wp_parse_url($value,PHP_URL_HOST));$expected=strtolower((string)wp_parse_url($prefix,PHP_URL_HOST));if(preg_replace('/^www\./','',$host)!==preg_replace('/^www\./','',$expected))throw new RuntimeException('Use a link from the selected social platform.');$url=ThreePM_Market::safe_url($value);}else{$value=ltrim($value,'@');if(!preg_match('~^[a-zA-Z0-9_.\-/]{1,180}$~D',$value)||str_contains($value,'..'))throw new RuntimeException('Enter a valid social username.');$url=$prefix.$value;}if(isset($seen[$key])){if(rtrim($social[$key],'/')===rtrim($url,'/'))continue;throw new RuntimeException('Two different links were entered for '.self::SOCIAL[$key][0].'. Keep one link for that platform.');}$seen[$key]=true;$social[$key]=$url;
   }
   $primary=$links?explode(' | ',array_shift($links),2):['',''];$social['website']=$primary[1];$social['_label']=$primary[0];$_POST['links']=wp_slash(implode("\n",$links));if(isset($_POST['artist_languages']))$social['languages']=sanitize_text_field(wp_unslash($_POST['artist_languages']));if(isset($_POST['artist_contact'])){$email=trim(wp_unslash($_POST['artist_contact']));if($email!==''&&!is_email($email))throw new RuntimeException('Enter a valid contact email address.');$social['contact']=sanitize_email($email);}foreach(['country','genre']as $field)if(isset($_POST['artist_'.$field]))$social['_profile_'.$field]=sanitize_text_field(wp_unslash($_POST['artist_'.$field]));self::$pending=$social;
  }catch(Throwable $e){wp_die(esc_html($e->getMessage()),'Profile not saved',['response'=>400,'back_link'=>true]);}
 }
 static function boxes(){add_meta_box('threepm-profile-controls','3PM profile links & gallery',[__CLASS__,'admin_box'],'artist','normal','default');add_meta_box('threepm-gallery-controls','3PM gallery images',[__CLASS__,'admin_box'],'mediagallery','normal','default');}
 static function admin_box($post){
  if($post->post_type==='artist'){wp_nonce_field('threepm_native_links','threepm_native_links_nonce');echo self::form('<label>External links</label>',$post->ID);$widgets=ThreePM_Gallery_Editor::widgets(json_decode(get_post_meta($post->ID,'_elementor_data',true),true)?:[]);foreach($widgets as $key=>$rows)echo ThreePM_Gallery_Editor::panel($post->ID,$key,array_values($rows));$rows=ThreePM_Gallery_Editor::rows($post->ID,'profile');if($rows)echo ThreePM_Gallery_Editor::panel($post->ID,'profile',$rows);}
  else echo ThreePM_Gallery_Editor::panel($post->ID,'gallery',ThreePM_Gallery_Editor::rows($post->ID,'gallery'));
 }
 static function admin_save($id,$post){
  if($post->post_type!=='artist'||empty($_POST['threepm_native_links_nonce'])||!wp_verify_nonce($_POST['threepm_native_links_nonce'],'threepm_native_links')||!current_user_can('edit_post',$id)||wp_is_post_revision($id)||(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||empty($_POST['structured_links']))return;
  self::parse();$initial=json_decode(wp_unslash($_POST['threepm_initial_links']??''),true);if(!is_array($initial))return;foreach(array_merge(array_keys(self::SOCIAL),['website'])as $key){$current=(string)get_post_meta($id,'_artist_'.$key,true);if($current!==($initial[$key]??''))self::$pending[$key]=$current;}if((string)get_post_meta($id,'_threepm_profile_websites',true)===($initial['websites']??''))update_post_meta($id,'_threepm_profile_websites',wp_unslash($_POST['links']));self::persist($id,$post);if(class_exists('ThreePM_Artist_Profiles'))ThreePM_Artist_Profiles::sync_artist_dashboard($id);do_action('litespeed_purge_post',$id);
 }
 static function persist($id,$post){if(self::$pending===null||$post->post_type!=='artist')return;foreach(self::$pending as $key=>$url)update_post_meta($id,$key==='_label'?'_threepm_primary_website_label':(str_starts_with($key,'_profile_')?'_threepm'.$key:'_artist_'.$key),$url);self::$pending=null;}
}
ThreePM_Profile_Links::boot();
